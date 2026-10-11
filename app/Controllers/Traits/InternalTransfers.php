<?php

namespace App\Controllers\Traits;

use App\Libraries\BankRateSync;

/**
 * Internal transactions between the user's own accounts.
 *
 * The account types decide what kind of movement it is, like a bank does:
 *   cash -> bank account   DEPOSIT     (channel: cash deposit machine, counter)
 *   bank account -> cash   WITHDRAWAL  (channel: own bank ATM, other bank ATM, counter)
 *   bank -> bank           TRANSFER    (channel: online same bank, online other bank / CEFTS, counter)
 *   anything else          MOVE        (e.g. paying off a credit card; no channel, no charge)
 *
 * The charge comes from the bank account's product (bankproductfee, pulled from
 * the rate providers). "First N a month free" rules count the account's earlier
 * transactions on the same channel this month. The user can override the
 * charge with what the bank actually took. The transfer and its charge are
 * posted in one DB transaction: both or neither.
 *
 * Used by PaymentService (needs accountWithProduct(), recordPayment(), ...).
 */
trait InternalTransfers
{
    protected $transferKindLabels = [
        'DEPOSIT'    => 'Cash deposit',
        'WITHDRAWAL' => 'Cash withdrawal',
        'TRANSFER'   => 'Bank transfer',
        'MOVE'       => 'Transfer',
    ];

    protected function isBankAccount(array $account): bool
    {
        return in_array($account['AccountType'] ?? 'OTHER', BankRateSync::BANK_ACCOUNT_TYPES, true);
    }

    protected function transferKind(array $from, array $to): string
    {
        $fromCash = ($from['AccountType'] ?? '') === 'CASH';
        $toCash = ($to['AccountType'] ?? '') === 'CASH';

        if ($fromCash && $this->isBankAccount($to)) {
            return 'DEPOSIT';
        }

        if ($this->isBankAccount($from) && $toCash) {
            return 'WITHDRAWAL';
        }

        if ($this->isBankAccount($from) && $this->isBankAccount($to)) {
            return 'TRANSFER';
        }

        return 'MOVE';
    }

    protected function accountBank(array $account): string
    {
        return trim((string) ($account['AccountBankName'] ?: ($account['BankName'] ?? '')));
    }

    /** Transfers on $channel touching the charged account in the month of $date. */
    protected function channelUsesThisMonth(string $userID, string $kind, string $accountID, string $channel, string $date): int
    {
        $first = date('Y-m-01', strtotime($date));
        $last = date('Y-m-t', strtotime($date));

        return (int) $this->db()->table('payment')
            ->where(['usersessionid' => $userID, 'status' => 'A', 'category' => 'transfer', 'channel' => $channel])
            ->where($kind === 'DEPOSIT' ? 'toaccountsessionid' : 'accountsessionid', $accountID)
            ->where('date >=', $first)->where('date <=', $last)
            ->countAllResults();
    }

    /**
     * What an internal transaction from $fromID to $toID is, which channels it
     * can use and what the bank charges. $channel '' = the usual one.
     */
    protected function transferQuote(string $userID, $fromID, $toID, $amount, string $channel = '', string $date = '')
    {
        $from = $this->accountWithProduct($userID, $fromID);

        if (isset($from['error_id'])) {
            return $from;
        }

        $to = $this->accountWithProduct($userID, $toID);

        if (isset($to['error_id'])) {
            return $to;
        }

        if ($from['AccountSessionID'] === $to['AccountSessionID']) {
            return $this->paymentError('0025', 'Internal Transfer', 'Please choose two different accounts.');
        }

        $amount = max(0.0, (float) ($this->toAmount($amount) ?? 0));
        $date = $this->validDate($date) ?? date('Y-m-d');
        $kind = $this->transferKind($from, $to);
        $channels = BankRateSync::TXN_CHANNELS[$kind] ?? [];

        if (! in_array($channel, $channels, true)) {
            $sameBank = $this->accountBank($from) !== '' && strcasecmp($this->accountBank($from), $this->accountBank($to)) === 0;
            $channel = match ($kind) {
                'WITHDRAWAL' => 'OWN_ATM',
                'DEPOSIT'    => 'COUNTER',
                'TRANSFER'   => $sameBank ? 'ONLINE_OWN' : 'ONLINE_OTHER',
                default      => '',
            };
        }

        // The bank side pays the charge: the account the cash goes into, or comes out of
        $feeAccount = ($kind === 'DEPOSIT') ? $to : $from;
        $fee = ['fee' => 0.0, 'rule' => '', 'known' => false];
        $warnings = [];

        if ($kind !== 'MOVE') {
            if (empty($feeAccount['BankProductSessionID'])) {
                $fee['rule'] = 'No bank product linked, so no charge is known';
                $warnings[] = 'Link ' . $feeAccount['AccountName'] . ' to its bank product (on its account page) to apply the bank\'s charges automatically.';
            } else {
                $txnType = ($kind === 'TRANSFER') ? 'TRANSFER' : $kind;
                $rule = $this->db()->table('bankproductfee')
                    ->where(['productsessionid' => $feeAccount['BankProductSessionID'], 'txntype' => $txnType, 'channel' => $channel])
                    ->get()->getRowArray();
                $used = $this->channelUsesThisMonth($userID, $kind, $feeAccount['AccountSessionID'], $channel, $date);
                $fee = BankRateSync::calculateFee($rule ?: null, $amount, $used);

                if (! empty($rule['note'])) {
                    $warnings[] = $rule['note'];
                }
            }
        }

        if (($from['AccountType'] ?? '') === 'FIXED_DEPOSIT') {
            $warnings[] = 'Taking money out of a fixed deposit before it matures usually reduces the interest the bank pays.';
        }

        $describe = static function (array $account) {
            return [
                'id'   => $account['AccountSessionID'],
                'name' => $account['AccountName'],
                'type' => BankRateSync::ACCOUNT_TYPES[$account['AccountType'] ?? 'OTHER'] ?? 'Other',
                'bank' => trim((string) ($account['AccountBankName'] ?: ($account['BankName'] ?? ''))),
            ];
        };

        return [
            'Kind'                => $kind,
            'KindLabel'           => $this->transferKindLabels[$kind],
            'From'                => $describe($from),
            'To'                  => $describe($to),
            'Channels'            => array_map(static fn ($code) => ['code' => $code, 'label' => BankRateSync::CHANNELS[$code]], $channels),
            'Channel'             => $channel,
            'ChannelLabel'        => BankRateSync::CHANNELS[$channel] ?? '',
            'FeeAccountSessionID' => $feeAccount['AccountSessionID'],
            'FeeAccountName'      => $feeAccount['AccountName'],
            'Fee'                 => $fee['fee'],
            'FeeRule'             => $fee['rule'],
            'FeeKnown'            => $fee['known'],
            'Warnings'            => $warnings,
            'Description'         => match ($kind) {
                'DEPOSIT'    => 'Cash deposit to ' . $to['AccountName'],
                'WITHDRAWAL' => 'Cash withdrawal from ' . $from['AccountName'],
                'TRANSFER'   => 'Transfer to ' . $to['AccountName'],
                default      => '',
            },
        ];
    }

    /**
     * Post an internal transaction and the bank's charge.
     * $r: from_account, to_account, amount, channel, fee ('' = calculated),
     *     budget, payment_method, description, reference, payment_date
     */
    protected function internalTransfer(string $userID, array $r)
    {
        $amount = $this->toAmount($r['amount'] ?? null);

        if ($amount === null || $amount <= 0) {
            return $this->paymentError('0024', 'Payment Amount', 'The transfer amount must be greater than zero.');
        }

        $date = $this->validDate($r['payment_date'] ?? '') ?? date('Y-m-d');
        $quote = $this->transferQuote($userID, $r['from_account'] ?? '', $r['to_account'] ?? '', $amount, (string) ($r['channel'] ?? ''), $date);

        if (isset($quote['error_id'])) {
            return $quote;
        }

        $fee = $quote['Fee'];

        if (isset($r['fee']) && $r['fee'] !== '' && $r['fee'] !== null) {
            $fee = $this->toAmount($r['fee']);

            if ($fee === null || $fee < 0 || $fee > 100000) {
                return $this->paymentError('0066', 'Bank Charge', 'The bank charge must be between 0 and 100,000.');
            }
        }

        if ($quote['Kind'] === 'MOVE') {
            $fee = 0.0;
        }

        $method = (string) ($r['payment_method'] ?? '');
        $chargedFromSource = $fee > 0 && $quote['FeeAccountSessionID'] === $quote['From']['id'];

        // Clear message when the amount fits but amount + charge does not
        if ($chargedFromSource) {
            $source = $this->getOwnedAccount($userID, $quote['From']['id']);
            $resolved = $this->resolvePaymentMethod($userID, $quote['From']['id'], $method);

            if (! isset($source['error_id']) && ! isset($resolved['error_id'])) {
                $available = $this->availableFunds($source, $resolved);

                if ($available >= $amount && $available < $amount + $fee) {
                    return $this->paymentError(
                        '0019',
                        'Account Balance',
                        'Insufficient funds in ' . $source['AccountName'] . ' for the amount plus the bank charge: available '
                            . number_format($available, 2) . ', required ' . number_format($amount + $fee, 2)
                            . ' (' . number_format($amount, 2) . ' + ' . number_format($fee, 2) . ' charge).',
                        ['available' => $available, 'required' => $amount + $fee]
                    );
                }
            }
        }

        $description = trim((string) ($r['description'] ?? '')) ?: $quote['Description'];

        return $this->runAtomic(function () use ($userID, $r, $quote, $amount, $fee, $date, $method, $description, $chargedFromSource) {
            $transfer = $this->recordPayment($userID, [
                'direction'   => 'TRANSFER',
                'account'     => $quote['From']['id'],
                'method'      => $method,
                'to_account'  => $quote['To']['id'],
                'budget'      => $r['budget'] ?? '',
                'amount'      => $amount,
                'category'    => 'transfer',
                'channel'     => $quote['Channel'],
                'description' => $description,
                'reference'   => $r['reference'] ?? '',
                'date'        => $date,
            ]);

            if (isset($transfer['error_id'])) {
                return $transfer;
            }

            $charge = null;

            if ($fee > 0) {
                $charge = $this->recordPayment($userID, [
                    'direction'         => 'OUT',
                    'account'           => $quote['FeeAccountSessionID'],
                    'method'            => $chargedFromSource ? $method : '',
                    'budget'            => $r['budget'] ?? '',
                    'amount'            => $fee,
                    'category'          => 'fee',
                    'channel'           => $quote['Channel'],
                    'link_type'         => 'transfer',
                    'link_id'           => $transfer['data']['PaymentSessionID'],
                    'description'       => $quote['KindLabel'] . ' charge (' . $quote['ChannelLabel'] . ')',
                    'date'              => $date,
                    'allow_over_budget' => true,
                ]);

                if (isset($charge['error_id'])) {
                    return $charge;
                }
            }

            return ['success' => true, 'data' => [
                'Kind'     => $quote['Kind'],
                'Channel'  => $quote['Channel'],
                'Fee'      => $fee,
                'Transfer' => $transfer['data'],
                'Charge'   => $charge['data'] ?? null,
            ]];
        });
    }

    /** sessionid => ['type', 'typeLabel', 'bank', 'product'] for the Internal transaction form. */
    protected function accountTypeMap(string $userID): array
    {
        $rows = $this->db()->table('account a')
            ->select('a.sessionid, a.accounttype, COALESCE(a.bankname, bp.bankname) AS bank, bp.productname', false)
            ->join('bankproduct bp', 'bp.sessionid = a.bankproductsessionid', 'left')
            ->where(['a.usersessionid' => $userID, 'a.status' => 'A'])
            ->get()->getResultArray();
        $map = [];

        foreach ($rows as $row) {
            $map[$row['sessionid']] = [
                'type'      => $row['accounttype'],
                'typeLabel' => BankRateSync::ACCOUNT_TYPES[$row['accounttype']] ?? 'Other',
                'bank'      => (string) $row['bank'],
                'product'   => (string) $row['productname'],
            ];
        }

        return $map;
    }

    /**
     * Account type and bank of an account. A linked bank product sets both;
     * without one, the type and the typed bank name are used. Cash accounts have no bank.
     */
    protected function saveAccountType(string $userID, string $accountID, string $type, string $bankName = ''): void
    {
        $account = $this->accountWithProduct($userID, $accountID);

        if (isset($account['error_id'])) {
            return;
        }

        if (! empty($account['BankProductSessionID'])) {
            $type = in_array($account['ProductType'], BankRateSync::BANK_ACCOUNT_TYPES, true) ? $account['ProductType'] : ($type ?: 'OTHER');
            $bankName = (string) $account['BankName'];
        }

        $type = isset(BankRateSync::ACCOUNT_TYPES[$type]) ? $type : 'OTHER';
        $bankName = ($type === 'CASH') ? '' : mb_substr(trim(strip_tags($bankName)), 0, 100);

        $this->db()->table('account')
            ->set(['accounttype' => $type, 'bankname' => $bankName !== '' ? $bankName : null, 'updateddatetime' => time()])
            ->where(['sessionid' => $accountID, 'usersessionid' => $userID])
            ->update();
    }
}
