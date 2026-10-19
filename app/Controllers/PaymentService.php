<?php

namespace App\Controllers;

/**
 * Account-wise payment methods and the payment ledger.
 *
 * Every money movement made through the app goes through recordPayment(), which
 *   1. checks the account (and transfer target) belong to the logged in user,
 *   2. resolves the payment method - it must belong to that account; when none
 *      is given the account's default method is used,
 *   3. locks the account row and checks available funds
 *      (balance + credit limit when paying with a credit card),
 *   4. writes one `payment` row (FULL / PARTIAL / INSTALLMENT),
 *   5. posts the matching `transaction` row(s) and moves the account balance(s),
 *   6. allocates the payment against payables (`duepaid`) for due settlements,
 * all inside one DB transaction.
 *
 * Service methods are protected on purpose: they are never reachable by URL,
 * only the controller actions listed in Routes.php are.
 */
class PaymentService extends AccountService
{
    use Traits\BudgetRules;
    use Traits\RecurringPayments;
    use Traits\InterestPosting;
    use Traits\InternalTransfers;

    protected $paymentMethodTypes = [
        'CASH'          => 'Cash',
        'DEBIT_CARD'    => 'Debit Card',
        'CREDIT_CARD'   => 'Credit Card',
        'BANK_TRANSFER' => 'Bank Transfer',
        'ONLINE'        => 'Online / QR',
        'CHEQUE'        => 'Cheque',
        'WALLET'        => 'Mobile Wallet',
    ];

    protected $paymentCategories = ['expense', 'purchase', 'travel', 'charity', 'income', 'transfer', 'settlement', 'fee'];

    protected $settlementTypes = ['FULL', 'PARTIAL', 'INSTALLMENT'];

    protected function db()
    {
        return \Config\Database::connect();
    }

    protected function paymentError(string $id, string $category, string $message, $data = null): array
    {
        $error = [
            'error_id'       => $id,
            'category'       => 'Data_Issue',
            'error_category' => $category,
            'error_message'  => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return $error;
    }

    // ------------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------------

    /** Active account owned by the user, or an error array. */
    protected function getOwnedAccount(string $userID, $accountID, bool $forUpdate = false)
    {
        if (! $this->isSessionID($accountID)) {
            return $this->paymentError('0021', 'Account', 'Please select a valid account.');
        }

        $sql = 'SELECT sessionid AS AccountSessionID, name AS AccountName, currentbalance AS AccountCurrentBalance
                  FROM account WHERE sessionid = ? AND usersessionid = ? AND status = ?' . ($forUpdate ? ' FOR UPDATE' : '');

        $row = $this->db()->query($sql, [$accountID, $userID, 'A'])->getRowArray();

        if (empty($row)) {
            return $this->paymentError('0021', 'Account', 'The selected account was not found or is no longer active.');
        }

        return $row;
    }

    // ------------------------------------------------------------------
    // Payment methods (account wise)
    // ------------------------------------------------------------------

    protected function paymentMethodTypeLabel(string $type): string
    {
        return $this->paymentMethodTypes[$type] ?? $type;
    }

    /** Funds a payment can draw: balance, plus the limit when paying with a credit card. */
    protected function availableFunds(array $account, $method): float
    {
        $limit = (is_array($method) && $method['PaymentMethodType'] === 'CREDIT_CARD') ? (float) $method['PaymentMethodCreditLimit'] : 0.0;

        return round((float) $account['AccountCurrentBalance'] + $limit, 2);
    }

    /**
     * Active payment methods of the user (optionally one account), joined with
     * their account. Always returns a list (possibly empty).
     */
    protected function paymentMethodList(string $userID, string $accountID = ''): array
    {
        $builder = $this->db()->table('paymentmethod pm')
            ->select('pm.sessionid AS PaymentMethodSessionID, pm.name AS PaymentMethodName, pm.type AS PaymentMethodType,
                      pm.reference AS PaymentMethodReference, pm.creditlimit AS PaymentMethodCreditLimit,
                      pm.isdefault AS PaymentMethodIsDefault, pm.showindropdown AS PaymentMethodShowInDropdown,
                      a.sessionid AS AccountSessionID, a.name AS AccountName,
                      a.currentbalance AS AccountCurrentBalance', false)
            ->join('account a', 'a.sessionid = pm.accountsessionid')
            ->where('pm.usersessionid', $userID)
            ->where('a.usersessionid', $userID)
            ->where('pm.status', 'A')
            ->where('a.status', 'A');

        if ($accountID !== '') {
            $builder->where('pm.accountsessionid', $accountID);
        }

        $rows = $builder->orderBy('a.name', 'ASC')->orderBy('pm.isdefault', 'ASC')->orderBy('pm.name', 'ASC')->get()->getResultArray();

        foreach ($rows as $key => $row) {
            $rows[$key]['PaymentMethodTypeLabel'] = $this->paymentMethodTypeLabel($row['PaymentMethodType']);
            $rows[$key]['AvailableFunds'] = $this->availableFunds($row, $row);
            $rows[$key]['PaymentMethodLabel'] = $row['PaymentMethodName']
                . ($row['PaymentMethodReference'] ? ' ••' . $row['PaymentMethodReference'] : '');
        }

        return $rows;
    }

    /** sessionid => display name, for transaction tables. */
    protected function paymentMethodMap(string $userID): array
    {
        $rows = $this->db()->table('paymentmethod')
            ->select('sessionid, name, reference')
            ->where('usersessionid', $userID)
            ->get()->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['sessionid']] = $row['name'] . ($row['reference'] ? ' ••' . $row['reference'] : '');
        }

        return $map;
    }

    protected function getPaymentMethod(string $userID, $methodID)
    {
        if (! $this->isSessionID($methodID)) {
            return $this->paymentError('0023', 'Payment Method', 'Please select a valid payment method.');
        }

        $row = $this->db()->table('paymentmethod')
            ->select('sessionid AS PaymentMethodSessionID, accountsessionid AS AccountSessionID, name AS PaymentMethodName,
                      type AS PaymentMethodType, reference AS PaymentMethodReference, creditlimit AS PaymentMethodCreditLimit,
                      isdefault AS PaymentMethodIsDefault', false)
            ->where(['sessionid' => $methodID, 'usersessionid' => $userID, 'status' => 'A'])
            ->get()->getRowArray();

        if (empty($row)) {
            return $this->paymentError('0023', 'Payment Method', 'The selected payment method was not found or has been removed.');
        }

        return $row;
    }

    /** Default method of an account (falls back to its first active method). Null when the account has none. */
    protected function getDefaultPaymentMethod(string $userID, string $accountID)
    {
        $row = $this->db()->table('paymentmethod')
            ->select('sessionid')
            ->where(['accountsessionid' => $accountID, 'usersessionid' => $userID, 'status' => 'A'])
            ->orderBy('isdefault', 'ASC')->orderBy('id', 'ASC')
            ->limit(1)->get()->getRowArray();

        return empty($row) ? null : $this->getPaymentMethod($userID, $row['sessionid']);
    }

    /**
     * Method used for a payment from $accountID. An explicit method must belong
     * to that account; otherwise the account default is used (null if none).
     */
    protected function resolvePaymentMethod(string $userID, string $accountID, $methodID)
    {
        if ($methodID === null || $methodID === '') {
            return $this->getDefaultPaymentMethod($userID, $accountID);
        }

        $method = $this->getPaymentMethod($userID, $methodID);

        if (isset($method['error_id'])) {
            return $method;
        }

        if ($method['AccountSessionID'] !== $accountID) {
            return $this->paymentError('0023', 'Payment Method', 'The selected payment method does not belong to the selected account.');
        }

        return $method;
    }

    /**
     * Validate payment method form input. Card numbers are never stored: a long
     * digit string is reduced to its last 4 digits.
     */
    protected function normalisePaymentMethodInput(array $input, bool $isUpdate = false)
    {
        $clean = [];

        if (! $isUpdate || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 50) {
                return $this->paymentError('0031', 'Payment Method', 'Payment method name is required (max 50 characters).');
            }
            $clean['PaymentMethodName'] = $name;
        }

        if (! $isUpdate || array_key_exists('type', $input)) {
            $type = strtoupper(trim((string) ($input['type'] ?? '')));

            if (! array_key_exists($type, $this->paymentMethodTypes)) {
                return $this->paymentError('0032', 'Payment Method', 'Please choose a valid payment method type.');
            }
            $clean['PaymentMethodType'] = $type;
        }

        if (array_key_exists('reference', $input)) {
            $reference = preg_replace('/[^A-Za-z0-9]/', '', (string) $input['reference']);

            if (ctype_digit($reference) && strlen($reference) > 4) {
                $reference = substr($reference, -4);
            }
            $clean['PaymentMethodReference'] = ($reference === '') ? null : substr($reference, 0, 20);
        }

        if (array_key_exists('credit_limit', $input) && $input['credit_limit'] !== '') {
            $limit = $this->toAmount($input['credit_limit']);

            if ($limit === null || $limit < 0) {
                return $this->paymentError('0033', 'Payment Method', 'Credit limit must be zero or a positive amount.');
            }
            $clean['PaymentMethodCreditLimit'] = number_format($limit, 2, '.', '');
        }

        if (array_key_exists('is_default', $input)) {
            $clean['PaymentMethodIsDefault'] = filter_var($input['is_default'], FILTER_VALIDATE_BOOLEAN) ? 'Y' : 'N';
        }

        if (array_key_exists('show_in_dropdown', $input)) {
            $clean['PaymentMethodShowInDropdown'] = filter_var($input['show_in_dropdown'], FILTER_VALIDATE_BOOLEAN) ? 'Y' : 'N';
        }

        return $clean;
    }

    protected function clearDefaultPaymentMethod(string $userID, string $accountID): void
    {
        $this->db()->table('paymentmethod')
            ->set('isdefault', 'N')
            ->where(['accountsessionid' => $accountID, 'usersessionid' => $userID])
            ->update();
    }

    /** One debit card per account: a second active debit card on the same account is refused. */
    protected function debitCardConflict(string $userID, string $accountID, string $type, string $exceptMethodID = '')
    {
        if ($type !== 'DEBIT_CARD') {
            return null;
        }

        $builder = $this->db()->table('paymentmethod')
            ->where(['accountsessionid' => $accountID, 'usersessionid' => $userID, 'type' => 'DEBIT_CARD', 'status' => 'A']);

        if ($exceptMethodID !== '') {
            $builder->where('sessionid !=', $exceptMethodID);
        }

        if ($builder->countAllResults() > 0) {
            return $this->paymentError('0068', 'Payment Method', 'This account already has a debit card. A bank can have many accounts, but each account takes one debit card.');
        }

        return null;
    }

    protected function paymentMethodCreateProccess(string $userID, $accountID, array $input)
    {
        $account = $this->getOwnedAccount($userID, $accountID);

        if (isset($account['error_id'])) {
            return $account;
        }

        $clean = $this->normalisePaymentMethodInput($input);

        if (isset($clean['error_id'])) {
            return $clean;
        }

        if ($clean['PaymentMethodType'] !== 'CREDIT_CARD') {
            $clean['PaymentMethodCreditLimit'] = '0.00';
        }

        $conflict = $this->debitCardConflict($userID, $accountID, $clean['PaymentMethodType']);

        if ($conflict !== null) {
            return $conflict;
        }

        return $this->runAtomic(function () use ($userID, $accountID, $clean) {
            $hasMethods = $this->getDefaultPaymentMethod($userID, $accountID) !== null;
            $makeDefault = ! $hasMethods || ($clean['PaymentMethodIsDefault'] ?? 'N') === 'Y';

            if ($makeDefault) {
                $this->clearDefaultPaymentMethod($userID, $accountID);
            }

            $now = time();
            $insert = $this->insertDatatoDB('paymentmethod', $clean + [
                'AccountSessionID'             => $accountID,
                'UserSessionID'                => $userID,
                'PaymentMethodIsDefault'       => $makeDefault ? 'Y' : 'N',
                'PaymentMethodStatus'          => 'A',
                'PaymentMethodCreatedDateTime' => $now,
                'PaymentMethodUpdatedDateTime' => $now,
            ]);

            if (isset($insert['error_id'])) {
                return $insert;
            }

            $row = $this->db()->table('paymentmethod')->select('sessionid')->where('id', $insert['id'])->get()->getRowArray();

            return ['success' => true, 'PaymentMethodSessionID' => $row['sessionid']];
        });
    }

    protected function paymentMethodUpdateProccess(string $userID, $methodID, array $input)
    {
        $method = $this->getPaymentMethod($userID, $methodID);

        if (isset($method['error_id'])) {
            return $method;
        }

        $clean = $this->normalisePaymentMethodInput($input, true);

        if (isset($clean['error_id'])) {
            return $clean;
        }

        $type = $clean['PaymentMethodType'] ?? $method['PaymentMethodType'];

        if ($type !== 'CREDIT_CARD') {
            $clean['PaymentMethodCreditLimit'] = '0.00';
        }

        $conflict = $this->debitCardConflict($userID, $method['AccountSessionID'], $type, $method['PaymentMethodSessionID']);

        if ($conflict !== null) {
            return $conflict;
        }

        // While a credit card account owes money, its limit cannot drop below the
        // amount owed and the method cannot stop being a credit card.
        $account = $this->getOwnedAccount($userID, $method['AccountSessionID']);

        if (isset($account['error_id'])) {
            return $account;
        }

        $owed = round(-(float) $account['AccountCurrentBalance'], 2);

        if ($owed > 0 && $method['PaymentMethodType'] === 'CREDIT_CARD') {
            if ($type !== 'CREDIT_CARD') {
                return $this->paymentError('0034', 'Payment Method', 'The account owes ' . number_format($owed, 2) . '; settle it before changing the card type.');
            }

            if (isset($clean['PaymentMethodCreditLimit']) && (float) $clean['PaymentMethodCreditLimit'] < $owed) {
                return $this->paymentError('0034', 'Payment Method', 'The account owes ' . number_format($owed, 2) . '; the credit limit cannot be lower than that.');
            }
        }

        return $this->runAtomic(function () use ($userID, $method, $clean) {
            if (($clean['PaymentMethodIsDefault'] ?? '') === 'Y') {
                $this->clearDefaultPaymentMethod($userID, $method['AccountSessionID']);
            } elseif (($clean['PaymentMethodIsDefault'] ?? '') === 'N' && $method['PaymentMethodIsDefault'] === 'Y') {
                unset($clean['PaymentMethodIsDefault']); // keep one default per account; set another as default instead
            }

            $clean['PaymentMethodUpdatedDateTime'] = time();

            $updated = $this->updateDBData('paymentmethod', ['PaymentMethodSessionID' => $method['PaymentMethodSessionID'], 'UserSessionID' => $userID], $clean);

            return isset($updated['error_id']) ? $updated : ['success' => true, 'PaymentMethodSessionID' => $method['PaymentMethodSessionID']];
        });
    }

    /** Soft delete; history keeps the method name. If it was the default, the next method becomes default. */
    protected function paymentMethodDeleteProccess(string $userID, $methodID)
    {
        $method = $this->getPaymentMethod($userID, $methodID);

        if (isset($method['error_id'])) {
            return $method;
        }

        if ($method['PaymentMethodType'] === 'CREDIT_CARD') {
            $account = $this->getOwnedAccount($userID, $method['AccountSessionID']);

            if (! isset($account['error_id']) && (float) $account['AccountCurrentBalance'] < 0) {
                return $this->paymentError('0034', 'Payment Method', 'The account still owes ' . number_format(-(float) $account['AccountCurrentBalance'], 2) . '; settle it before removing this card.');
            }
        }

        return $this->runAtomic(function () use ($userID, $method) {
            $updated = $this->updateDBData(
                'paymentmethod',
                ['PaymentMethodSessionID' => $method['PaymentMethodSessionID'], 'UserSessionID' => $userID],
                ['PaymentMethodStatus' => 'D', 'PaymentMethodIsDefault' => 'N', 'PaymentMethodUpdatedDateTime' => time()]
            );

            if (isset($updated['error_id'])) {
                return $updated;
            }

            if ($method['PaymentMethodIsDefault'] === 'Y') {
                $next = $this->getDefaultPaymentMethod($userID, $method['AccountSessionID']);

                if (is_array($next) && ! isset($next['error_id'])) {
                    $this->db()->table('paymentmethod')->set('isdefault', 'Y')->where('sessionid', $next['PaymentMethodSessionID'])->update();
                }
            }

            return ['success' => true];
        });
    }

    /** Used when an account is removed. */
    protected function deactivateAccountPaymentMethods(string $userID, string $accountID): void
    {
        $this->db()->table('paymentmethod')
            ->set(['status' => 'D', 'isdefault' => 'N', 'updateddatetime' => time()])
            ->where(['accountsessionid' => $accountID, 'usersessionid' => $userID])
            ->update();
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /** Budget for the transaction rows: the given one if it is the user's, else the user's Default budget. */
    protected function resolveBudget(string $userID, $budgetID)
    {
        if ($this->isSessionID($budgetID)) {
            $budget = $this->budgetList($userID, $budgetID);

            if (! isset($budget['error_id'])) {
                return $budgetID;
            }

            return $this->paymentError('0035', 'Budget', 'The selected budget was not found.');
        }

        $default = $this->getDefaultBudgetID($userID);

        return isset($default['error_id']) ? null : $default['BudgetSessionID'];
    }

    /**
     * Record one payment, receipt or internal transfer with its transactions.
     *
     * $p = [
     *   'direction'   => 'OUT' | 'IN' | 'TRANSFER',
     *   'account'     => account sessionid (paid from / received into / transfer from),
     *   'method'      => payment method sessionid of that account ('' = account default),
     *   'to_account'  => transfer target account (TRANSFER only),
     *   'budget'      => budget sessionid ('' = Default budget),
     *   'amount'      => > 0,
     *   'category'    => expense | purchase | travel | charity | income | transfer | settlement,
     *   'settlement'  => FULL | PARTIAL | INSTALLMENT,
     *   'link_type', 'link_id' => what the payment is for (purchase, paymentplan, travel ...),
     *   'description', 'reference', 'date' (Y-m-d, default today),
     *   'allocations' => [['payable' => sessionid, 'amount' => x], ...]  (due settlement)
     * ]
     */
    protected function recordPayment(string $userID, array $p)
    {
        $direction = strtoupper((string) ($p['direction'] ?? 'OUT'));

        if (! in_array($direction, ['OUT', 'IN', 'TRANSFER'], true)) {
            return $this->paymentError('0036', 'Payment', 'Unknown payment direction.');
        }

        $amount = $this->toAmount($p['amount'] ?? null);

        if ($amount === null || $amount <= 0) {
            return $this->paymentError('0024', 'Payment Amount', 'The payment amount must be greater than zero.');
        }

        $accountID = (string) ($p['account'] ?? '');
        $account = $this->getOwnedAccount($userID, $accountID);

        if (isset($account['error_id'])) {
            return $account;
        }

        $method = $this->resolvePaymentMethod($userID, $accountID, $p['method'] ?? '');

        if (isset($method['error_id'])) {
            return $method;
        }

        $toAccount = null;

        if ($direction === 'TRANSFER') {
            $toAccountID = (string) ($p['to_account'] ?? '');

            if ($toAccountID === $accountID) {
                return $this->paymentError('0025', 'Internal Transfer', 'Please choose two different accounts for an internal transfer.');
            }

            $toAccount = $this->getOwnedAccount($userID, $toAccountID);

            if (isset($toAccount['error_id'])) {
                return $toAccount;
            }
        }

        $budgetID = $this->resolveBudget($userID, $p['budget'] ?? '');

        if (is_array($budgetID)) {
            return $budgetID;
        }

        $category = in_array($p['category'] ?? '', $this->paymentCategories, true)
            ? $p['category']
            : ($direction === 'IN' ? 'income' : ($direction === 'TRANSFER' ? 'transfer' : 'expense'));

        $settlement = strtoupper((string) ($p['settlement'] ?? 'FULL'));
        $settlement = in_array($settlement, $this->settlementTypes, true) ? $settlement : 'FULL';

        $date = $this->validDate($p['date'] ?? '') ?? date('Y-m-d');

        if ($date > date('Y-m-d')) {
            return $this->paymentError('0037', 'Payment Date', 'A payment date cannot be in the future.');
        }

        $description = trim((string) ($p['description'] ?? ''));

        if ($description === '') {
            $description = ($direction === 'TRANSFER') ? 'Internal transfer' : ucfirst($category);
        }

        $reference = mb_substr(trim((string) ($p['reference'] ?? '')), 0, 50);
        $allocations = is_array($p['allocations'] ?? null) ? $p['allocations'] : [];

        // Use case "Budget plan overboard": spending past a budget needs the user's confirmation
        if ($direction === 'OUT' && ! filter_var($p['allow_over_budget'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $overBudget = $this->checkBudgetHeadroom($userID, $budgetID, $amount, $date);

            if ($overBudget !== null) {
                return $overBudget;
            }
        }

        return $this->runAtomic(function () use ($userID, $direction, $amount, $account, $method, $toAccount, $budgetID, $category, $settlement, $date, $description, $reference, $allocations, $p) {
            $db = $this->db();
            $now = time();
            $methodID = is_array($method) ? $method['PaymentMethodSessionID'] : null;

            // Authoritative funds check on a locked row (prevents two concurrent payments overdrawing)
            if ($direction !== 'IN') {
                $locked = $this->getOwnedAccount($userID, $account['AccountSessionID'], true);

                if (isset($locked['error_id'])) {
                    return $locked;
                }

                $available = $this->availableFunds($locked, $method);

                if ($available < $amount) {
                    return $this->paymentError(
                        '0019',
                        'Account Balance',
                        'Insufficient funds in ' . $locked['AccountName'] . ': available ' . number_format($available, 2) . ', required ' . number_format($amount, 2) . '.',
                        ['available' => $available, 'required' => $amount]
                    );
                }
            }

            $paymentData = [
                'UserSessionID'          => $userID,
                'AccountSessionID'       => $account['AccountSessionID'],
                'PaymentDirection'       => $direction,
                'PaymentCategory'        => $category,
                'PaymentSettlementType'  => $settlement,
                'PaymentAmount'          => number_format($amount, 2, '.', ''),
                'PaymentDescription'     => mb_substr($description, 0, 100),
                'PaymentDate'            => $date,
                'PaymentDateTime'        => ($date === date('Y-m-d')) ? $now : strtotime($date . ' ' . date('H:i:s')),
                'PaymentStatus'          => 'A',
                'PaymentCreatedDateTime' => $now,
                'PaymentUpdatedDateTime' => $now,
            ];

            $optional = [
                'PaymentMethodSessionID' => $methodID,
                'ToAccountSessionID'     => $toAccount['AccountSessionID'] ?? null,
                'BudgetSessionID'        => $budgetID,
                'PaymentLinkType'        => isset($p['link_type']) ? mb_substr((string) $p['link_type'], 0, 20) : null,
                'PaymentLinkSessionID'   => isset($p['link_id']) ? mb_substr((string) $p['link_id'], 0, 32) : null,
                'PaymentReference'       => ($reference !== '') ? $reference : null,
                'PaymentChannel'         => isset(\App\Libraries\BankRateSync::CHANNELS[$p['channel'] ?? '']) ? $p['channel'] : null,
            ];

            foreach ($optional as $key => $value) {
                if ($value !== null && $value !== '') {
                    $paymentData[$key] = $value;
                }
            }

            $insert = $this->insertDatatoDB('payment', $paymentData);

            if (isset($insert['error_id'])) {
                return $insert;
            }

            $paymentID = $db->table('payment')->select('sessionid')->where('id', $insert['id'])->get()->getRow()->sessionid;
            $link = ['PaymentSessionID' => $paymentID, 'PaymentMethodSessionID' => $methodID, 'date' => $date];
            $transactions = [];

            if ($direction === 'TRANSFER') {
                $transactions[] = $this->postTransaction($userID, $account['AccountSessionID'], 'expense', $amount, 'Transfer to ' . $toAccount['AccountName'], $budgetID, $link);
                $transactions[] = $this->postTransaction($userID, $toAccount['AccountSessionID'], 'income', $amount, 'Transfer from ' . $account['AccountName'], $budgetID, ['PaymentMethodSessionID' => null] + $link);
            } else {
                $transactions[] = $this->postTransaction($userID, $account['AccountSessionID'], ($direction === 'IN') ? 'income' : 'expense', $amount, $description, $budgetID, $link);
            }

            foreach ($transactions as $transaction) {
                if (isset($transaction['error_id'])) {
                    return $transaction;
                }
            }

            $allocated = [];
            $allocatedTotal = 0.0;

            foreach ($allocations as $allocation) {
                $result = $this->applyAllocation($userID, $paymentID, $direction, $allocation['payable'] ?? '', $allocation['amount'] ?? 0, $date);

                if (isset($result['error_id'])) {
                    return $result;
                }

                $allocated[] = $result;
                $allocatedTotal += $result['DuePaidSettledPortion'];
            }

            if ($allocations !== [] && round($allocatedTotal, 2) !== $amount) {
                return $this->paymentError('0038', 'Settlement', 'Allocated amount does not match the payment amount.');
            }

            return [
                'success' => true,
                'data'    => [
                    'PaymentSessionID'       => $paymentID,
                    'PaymentAmount'          => $amount,
                    'PaymentDirection'       => $direction,
                    'PaymentSettlementType'  => $settlement,
                    'PaymentMethodSessionID' => $methodID,
                    'Transactions'           => $transactions,
                    'Allocations'            => $allocated,
                ],
            ];
        });
    }

    /** Settle $portion of one payable with $paymentID (row locked, must be the user's and outstanding). */
    protected function applyAllocation(string $userID, string $paymentID, string $direction, $payableID, $portion, string $date)
    {
        $portion = $this->toAmount($portion);

        if (! $this->isSessionID($payableID) || $portion === null || $portion <= 0) {
            return $this->paymentError('0039', 'Settlement', 'Invalid due selected for settlement.');
        }

        $db = $this->db();
        $payable = $db->query(
            'SELECT p.sessionid, p.paymentplansessionid, p.dueamount, p.paidamount, p.status, pp.paymentplanlink,
                    COALESCE(pp.direction, IF(pp.paymentplanlink = \'income\', \'IN\', \'OUT\')) AS direction
               FROM payable p JOIN paymentplan pp ON pp.sessionid = p.paymentplansessionid
              WHERE p.sessionid = ? AND pp.usersessionid = ? FOR UPDATE',
            [$payableID, $userID]
        )->getRowArray();

        if (empty($payable) || $payable['status'] === 'C') {
            return $this->paymentError('0039', 'Settlement', 'A selected due was not found.');
        }

        $expected = $payable['direction'];

        if ($expected !== $direction) {
            return $this->paymentError('0040', 'Settlement', 'Receivables and payables must be settled separately.');
        }

        $outstanding = round((float) $payable['dueamount'] - (float) $payable['paidamount'], 2);

        if ($portion > $outstanding) {
            return $this->paymentError('0041', 'Settlement', 'Settlement of ' . number_format($portion, 2) . ' is more than the outstanding ' . number_format($outstanding, 2) . '.');
        }

        $now = time();
        $insert = $this->insertDatatoDB('duepaid', [
            'PayableSessionID'       => $payableID,
            'PaymentSessionID'       => $paymentID,
            'DuePaidSettledPortion'  => number_format($portion, 2, '.', ''),
            'DuePaidStatus'          => 'A',
            'DuePaidCreatedDateTime' => $now,
            'DuePaidUpdatedDateTime' => $now,
        ]);

        if (isset($insert['error_id'])) {
            return $insert;
        }

        $paid = round((float) $payable['paidamount'] + $portion, 2);
        $settled = $paid >= (float) $payable['dueamount'];

        $update = ['paidamount' => number_format($paid, 2, '.', ''), 'status' => $settled ? 'S' : 'T', 'updateddatetime' => $now];
        if ($settled) {
            $update['paiddate'] = $date;
        }
        $db->table('payable')->set($update)->where('sessionid', $payableID)->update();

        // Close the plan once nothing is left on it
        $open = $db->table('payable')
            ->where('paymentplansessionid', $payable['paymentplansessionid'])
            ->whereIn('status', ['P', 'T'])
            ->countAllResults();

        if ($open === 0) {
            $db->table('paymentplan')->set(['status' => 'S', 'updateddatetime' => $now])->where('sessionid', $payable['paymentplansessionid'])->update();
        }

        return [
            'PayableSessionID'      => $payableID,
            'DuePaidSettledPortion' => $portion,
            'PayableOutstanding'    => round((float) $payable['dueamount'] - $paid, 2),
            'PayableStatus'         => $settled ? 'S' : 'T',
        ];
    }

    // ------------------------------------------------------------------
    // Dues (payables / receivables)
    // ------------------------------------------------------------------

    /**
     * Outstanding installments of the user's active payment plans, oldest first.
     * $payableIDs / $planID narrow the list (used when settling).
     */
    protected function outstandingPayables(string $userID, array $payableIDs = [], string $planID = ''): array
    {
        $sql = 'SELECT p.sessionid AS PayableSessionID, p.duedate AS PayableDueDate, p.dueamount AS PayableDueAmount,
                       p.paidamount AS PayablePaidAmount, ROUND(p.dueamount - p.paidamount, 2) AS PayableOutstanding,
                       p.status AS PayableStatus, pp.sessionid AS PaymentPlanSessionID, pp.paymentplanlink AS PaymentPlanLink,
                       pp.paymentplan AS PaymentPlan, pp.period AS PaymentPlanPeriod,
                       COALESCE(pp.description, pu.description, pp.paymentplanlink) AS PaymentPlanDescription,
                       COALESCE(pp.direction, IF(pp.paymentplanlink = \'income\', \'IN\', \'OUT\')) AS PaymentPlanDirection,
                       pi.sessionid AS PlanItemSessionID, pi.accountsessionid AS PlanItemAccountSessionID,
                       pi.paymentmethodsessionid AS PlanItemMethodSessionID, pi.budgetsessionid AS PlanItemBudgetSessionID,
                       pi.toaccountsessionid AS ToAccountSessionID, ta.name AS ToAccountName,
                       seq.InstallmentNo
                  FROM payable p
                  JOIN paymentplan pp ON pp.sessionid = p.paymentplansessionid
             LEFT JOIN planitem pi ON pp.paymentplanlink = \'planitem\' AND pi.sessionid = pp.paymentplansessionid
             LEFT JOIN account ta ON ta.sessionid = pi.toaccountsessionid
                  JOIN (SELECT sessionid, ROW_NUMBER() OVER (PARTITION BY paymentplansessionid ORDER BY duedate, id) AS InstallmentNo
                          FROM payable) seq ON seq.sessionid = p.sessionid
             LEFT JOIN purchase pu ON pp.paymentplanlink = \'purchase\' AND pu.sessionid = pp.paymentplansessionid
                 WHERE pp.usersessionid = ? AND pp.status = \'A\' AND p.status IN (\'P\', \'T\') AND p.dueamount > p.paidamount';
        $bind = [$userID];

        $payableIDs = array_values(array_filter($payableIDs, function ($id) {
            return $this->isSessionID($id);
        }));

        if ($payableIDs !== []) {
            $sql .= ' AND p.sessionid IN (' . implode(',', array_fill(0, count($payableIDs), '?')) . ')';
            $bind = array_merge($bind, $payableIDs);
        }

        if ($planID !== '') {
            $sql .= ' AND pp.sessionid = ?';
            $bind[] = $planID;
        }

        $rows = $this->db()->query($sql . ' ORDER BY p.duedate ASC, p.id ASC', $bind)->getResultArray();
        $today = date('Y-m-d');

        foreach ($rows as $key => $row) {
            $rows[$key]['Direction'] = $row['PaymentPlanDirection'];
            $rows[$key]['DueState'] = ($row['PayableDueDate'] < $today) ? 'Overdue' : (($row['PayableDueDate'] === $today) ? 'Due today' : 'Upcoming');
        }

        return $rows;
    }

    /**
     * Dues for the Dued tab. Monthly plan dues are generated a year ahead, so
     * only those due by the end of next month are listed (the month view shows
     * the rest); every other due is listed as before.
     */
    protected function duedTabPayables(string $userID): array
    {
        $until = date('Y-m-t', strtotime(date('Y-m-01') . ' +1 month'));

        return array_values(array_filter($this->outstandingPayables($userID), static function ($due) use ($until) {
            return $due['PaymentPlanLink'] !== 'planitem' || $due['PayableDueDate'] <= $until;
        }));
    }

    /**
     * Settle selected dues, fully or partially, with one payment.
     * Partial amounts are applied oldest due first.
     *
     * $r = ['payable_ids' => [...]] or ['payment_plan_id' => x],
     *      'mode' => full|partial, 'amount' (partial), 'account', 'method', 'budget', 'description', 'reference', 'date'
     */
    protected function settlePayables(string $userID, array $r)
    {
        $payableIDs = is_array($r['payable_ids'] ?? null) ? $r['payable_ids'] : [];
        $planID = (string) ($r['payment_plan_id'] ?? '');

        if ($planID !== '' && ! $this->isSessionID($planID)) {
            return $this->paymentError('0039', 'Settlement', 'Invalid payment plan selected.');
        }

        if ($payableIDs === [] && $planID === '') {
            return $this->paymentError('0039', 'Settlement', 'Please select at least one due to settle.');
        }

        foreach ($payableIDs as $payableID) {
            if (! $this->isSessionID($payableID)) {
                return $this->paymentError('0039', 'Settlement', 'Invalid due selected for settlement.');
            }
        }

        $dues = $this->outstandingPayables($userID, $payableIDs, $planID);

        if ($dues === [] || ($payableIDs !== [] && count($dues) !== count(array_unique($payableIDs)))) {
            return $this->paymentError('0039', 'Settlement', 'Some selected dues are already settled or not available. Please refresh and retry.');
        }

        $directions = array_unique(array_column($dues, 'Direction'));

        if (count($directions) > 1) {
            return $this->paymentError('0040', 'Settlement', 'Receivables, payables and transfers must be settled separately.');
        }

        $direction = reset($directions);
        $toAccount = null;

        // A transfer due moves money to the plan item's target account (e.g. a card pay-down)
        if ($direction === 'TRANSFER') {
            $targets = array_unique(array_map('strval', array_column($dues, 'ToAccountSessionID')));

            if (count($targets) > 1) {
                return $this->paymentError('0074', 'Settlement', 'The selected transfers go to different accounts; settle them separately.');
            }

            $toAccount = $targets[0] ?? '';

            if ($toAccount === '') {
                return $this->paymentError('0075', 'Settlement', 'This transfer has no account to transfer to. Set one on the plan item.');
            }
        }
        $totalOutstanding = round(array_sum(array_column($dues, 'PayableOutstanding')), 2);
        $mode = strtolower((string) ($r['mode'] ?? 'full'));

        if ($mode === 'partial') {
            $amount = $this->toAmount($r['amount'] ?? null);

            if ($amount === null || $amount <= 0) {
                return $this->paymentError('0024', 'Payment Amount', 'Please enter the partial amount to pay.');
            }

            if ($amount > $totalOutstanding) {
                return $this->paymentError('0041', 'Settlement', 'The amount is more than the outstanding total of ' . number_format($totalOutstanding, 2) . '.');
            }
        } else {
            $amount = $totalOutstanding;
        }

        $allocations = [];
        $remaining = $amount;

        foreach ($dues as $due) {
            if ($remaining <= 0) {
                break;
            }

            $portion = min((float) $due['PayableOutstanding'], $remaining);
            $allocations[] = ['payable' => $due['PayableSessionID'], 'amount' => $portion];
            $remaining = round($remaining - $portion, 2);
        }

        $plans = array_unique(array_column($dues, 'PaymentPlanSessionID'));
        $description = trim((string) ($r['description'] ?? ''));

        if ($description === '') {
            $what = (count($plans) === 1) ? $dues[0]['PaymentPlanDescription'] : count($dues) . ' dues';
            $description = ['IN' => 'Collection: ', 'TRANSFER' => 'Transfer: '][$direction] ?? 'Settlement: ';
            $description .= $what;
        }

        $settlement = ($amount >= $totalOutstanding) ? 'FULL' : 'PARTIAL';
        $linkType = (count($plans) === 1) ? 'paymentplan' : 'payable';
        $linkID = (count($plans) === 1) ? $plans[0] : null;

        // A transfer runs the internal transaction process: the transfer kind and channel, and the bank's charge
        if ($direction === 'TRANSFER') {
            return $this->internalTransfer($userID, [
                'from_account'      => $r['account'] ?? '',
                'to_account'        => $toAccount,
                'payment_method'    => $r['method'] ?? '',
                'budget'            => $r['budget'] ?? '',
                'amount'            => $amount,
                'description'       => $description,
                'reference'         => $r['reference'] ?? '',
                'payment_date'      => $r['date'] ?? '',
                'settlement'        => $settlement,
                'link_type'         => $linkType,
                'link_id'           => $linkID,
                'allocations'       => $allocations,
                'allow_over_budget' => $r['allow_over_budget'] ?? false,
            ]);
        }

        return $this->recordPayment($userID, [
            'direction'   => $direction,
            'account'     => $r['account'] ?? '',
            'method'      => $r['method'] ?? '',
            'budget'      => $r['budget'] ?? '',
            'amount'      => $amount,
            'category'    => 'settlement',
            'settlement'  => $settlement,
            'link_type'   => $linkType,
            'link_id'     => $linkID,
            'description' => $description,
            'reference'   => $r['reference'] ?? '',
            'date'        => $r['date'] ?? '',
            'allocations' => $allocations,
            'allow_over_budget' => $r['allow_over_budget'] ?? false,
        ]);
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    /** Payments with method, account, linked transactions and due allocations. */
    protected function paymentHistory(string $userID, string $accountID = '', int $limit = 200): array
    {
        $builder = $this->db()->table('payment pay')
            ->select('pay.sessionid AS PaymentSessionID, pay.date AS PaymentDate, pay.direction AS PaymentDirection,
                      pay.category AS PaymentCategory, pay.settlementtype AS PaymentSettlementType, pay.amount AS PaymentAmount,
                      pay.description AS PaymentDescription, pay.reference AS PaymentReference, pay.channel AS PaymentChannel,
                      a.sessionid AS AccountSessionID, a.name AS AccountName, ta.name AS ToAccountName,
                      pm.name AS PaymentMethodName, pm.type AS PaymentMethodType, pm.reference AS PaymentMethodReference', false)
            ->join('account a', 'a.sessionid = pay.accountsessionid')
            ->join('account ta', 'ta.sessionid = pay.toaccountsessionid', 'left')
            ->join('paymentmethod pm', 'pm.sessionid = pay.paymentmethodsessionid', 'left')
            ->where('pay.usersessionid', $userID)
            ->where('pay.status', 'A');

        if ($accountID !== '') {
            $builder->groupStart()->where('pay.accountsessionid', $accountID)->orWhere('pay.toaccountsessionid', $accountID)->groupEnd();
        }

        $payments = $builder->orderBy('pay.datetime', 'DESC')->orderBy('pay.id', 'DESC')->limit($limit)->get()->getResultArray();

        if ($payments === []) {
            return [];
        }

        $ids = array_column($payments, 'PaymentSessionID');

        $transactions = $this->db()->table('transaction t')
            ->select('t.paymentsessionid, t.description, t.amount, t.paymenttype, t.balanceafter, a.name AS accountname')
            ->join('account a', 'a.sessionid = t.accountsessionid')
            ->whereIn('t.paymentsessionid', $ids)
            ->orderBy('t.id', 'ASC')
            ->get()->getResultArray();

        $allocations = $this->db()->table('duepaid dp')
            ->select('dp.paymentsessionid, dp.settledportion, p.duedate, p.dueamount, p.paidamount, p.status')
            ->join('payable p', 'p.sessionid = dp.payablesessionid')
            ->whereIn('dp.paymentsessionid', $ids)
            ->orderBy('p.duedate', 'ASC')
            ->get()->getResultArray();

        $byPayment = [];
        foreach ($transactions as $row) {
            $byPayment[$row['paymentsessionid']]['Transactions'][] = $row;
        }
        foreach ($allocations as $row) {
            $byPayment[$row['paymentsessionid']]['Allocations'][] = $row;
        }

        foreach ($payments as $key => $payment) {
            $payments[$key]['PaymentMethodLabel'] = $payment['PaymentMethodName']
                ? $payment['PaymentMethodName'] . ($payment['PaymentMethodReference'] ? ' ••' . $payment['PaymentMethodReference'] : '')
                : '-';
            $payments[$key]['Transactions'] = $byPayment[$payment['PaymentSessionID']]['Transactions'] ?? [];
            $payments[$key]['Allocations'] = $byPayment[$payment['PaymentSessionID']]['Allocations'] ?? [];
        }

        return $payments;
    }

    /** Paid out / received totals per account + payment method. */
    protected function paymentMethodSummary(string $userID): array
    {
        return $this->db()->query(
            'SELECT a.name AS AccountName, COALESCE(pm.name, \'(no method)\') AS PaymentMethodName, pm.type AS PaymentMethodType,
                    SUM(CASE WHEN pay.direction IN (\'OUT\', \'TRANSFER\') THEN pay.amount ELSE 0 END) AS TotalOut,
                    SUM(CASE WHEN pay.direction = \'IN\' THEN pay.amount ELSE 0 END) AS TotalIn,
                    COUNT(*) AS PaymentCount
               FROM payment pay
               JOIN account a ON a.sessionid = pay.accountsessionid
          LEFT JOIN paymentmethod pm ON pm.sessionid = pay.paymentmethodsessionid
              WHERE pay.usersessionid = ? AND pay.status = \'A\'
           GROUP BY a.name, pm.sessionid, pm.name, pm.type
           ORDER BY a.name, pm.name',
            [$userID]
        )->getResultArray();
    }

    // ------------------------------------------------------------------
    // API helpers
    // ------------------------------------------------------------------

    /** Logged in user id for JSON endpoints (null when the session has expired). */
    protected function apiUser()
    {
        $userID = session()->get('user_id');

        return (session()->get('is_login') && $this->isSessionID($userID)) ? $userID : null;
    }

    protected function apiError(array $error, int $status = 422)
    {
        return $this->response->setStatusCode($status)->setJSON([
            'success'  => false,
            'code'     => $error['error_id'] ?? '0000',
            'response' => $error['error_category'] ?? 'Error',
            'message'  => $error['error_message'] ?? 'The request could not be completed.',
        ]);
    }

    protected function apiUnauthorised()
    {
        return $this->apiError(['error_id' => '0401', 'error_category' => 'Session', 'error_message' => 'Your session has expired, please log in again.'], 401);
    }

    protected function apiResult($result, string $successMessage)
    {
        if (! is_array($result) || isset($result['error_id'])) {
            return $this->apiError(is_array($result) ? $result : []);
        }

        return $this->response->setJSON([
            'success'  => true,
            'response' => $successMessage,
            'data'     => $result['data'] ?? $result,
        ]);
    }
}
