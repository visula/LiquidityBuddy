<?php

namespace App\Controllers\Traits;

/**
 * Continuous (recurring) transactions - ERD Salary_Bill -> Scheduled.
 *
 * A `recurring` rule says "pay / receive AMOUNT from ACCOUNT every PERIOD from
 * START (until END)". Each occurrence is posted through recordPayment() as an
 * ordinary payment (linktype 'recurring'), so balances, payment methods,
 * history and budgets all see it.
 *
 * There is no cron: postDueRecurring() catches up every occurrence due up to
 * today and is called when the user opens the dashboard, the transaction page,
 * an account page or the Recurring page. The rule row is locked while an
 * occurrence posts, so two open tabs can never post the same occurrence twice.
 * An occurrence that fails (e.g. insufficient funds) is retried on the next
 * visit and its reason is shown on the Recurring page.
 *
 * Used by PaymentService.
 */
trait RecurringPayments
{
    protected $recurringPeriods = ['D' => 'Daily', 'W' => 'Weekly', 'M' => 'Monthly', 'A' => 'Annually'];

    /** Safety cap on occurrences posted for one rule in one catch-up run. */
    protected $recurringCatchUpLimit = 62;

    protected function recurringRow(string $userID, $recurringID, bool $forUpdate = false)
    {
        if (! $this->isSessionID($recurringID)) {
            return null;
        }

        $row = $this->db()->query(
            'SELECT * FROM recurring WHERE sessionid = ? AND usersessionid = ? AND status <> \'D\'' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$recurringID, $userID]
        )->getRowArray();

        return empty($row) ? null : $row;
    }

    /** Rules of the user (not deleted) with account / method / budget names. */
    protected function recurringList(string $userID): array
    {
        $rows = $this->db()->table('recurring r')
            ->select('r.*, a.name AS accountname, pm.name AS methodname, pm.reference AS methodreference, b.name AS budgetname', false)
            ->join('account a', 'a.sessionid = r.accountsessionid')
            ->join('paymentmethod pm', 'pm.sessionid = r.paymentmethodsessionid', 'left')
            ->join('budget b', 'b.sessionid = r.budgetsessionid', 'left')
            ->where('r.usersessionid', $userID)
            ->where('r.status <>', 'D')
            ->orderBy('r.status', 'ASC')->orderBy('r.nextduedate', 'ASC')
            ->get()->getResultArray();

        foreach ($rows as $key => $row) {
            $rows[$key]['periodlabel'] = $this->recurringPeriods[$row['periodic']] ?? $row['periodic'];
            $rows[$key]['methodlabel'] = $row['methodname'] ? $row['methodname'] . ($row['methodreference'] ? ' ••' . $row['methodreference'] : '') : '-';
        }

        return $rows;
    }

    /**
     * Validate rule input. $input: direction (IN/OUT), account, method, budget,
     * amount, description, periodic, start_date, end_date.
     */
    protected function normaliseRecurringInput(string $userID, array $input)
    {
        $direction = strtoupper((string) ($input['direction'] ?? 'OUT'));

        if (! in_array($direction, ['IN', 'OUT'], true)) {
            return $this->paymentError('0050', 'Recurring', 'Choose whether this is money paid or received.');
        }

        $amount = $this->toAmount($input['amount'] ?? null);

        if ($amount === null || $amount <= 0) {
            return $this->paymentError('0024', 'Payment Amount', 'The amount must be greater than zero.');
        }

        $description = trim((string) ($input['description'] ?? ''));

        if ($description === '' || mb_strlen($description) > 100) {
            return $this->paymentError('0050', 'Recurring', 'A description is required (max 100 characters), e.g. Salary or House rent.');
        }

        $periodic = strtoupper((string) ($input['periodic'] ?? 'M'));

        if (! isset($this->recurringPeriods[$periodic])) {
            return $this->paymentError('0050', 'Recurring', 'Please choose how often it repeats.');
        }

        $start = $this->validDate($input['start_date'] ?? '') ?? date('Y-m-d');
        $end = $this->validDate($input['end_date'] ?? '');

        if (($input['end_date'] ?? '') !== '' && $end === null) {
            return $this->paymentError('0050', 'Recurring', 'The end date is not a valid date.');
        }

        if ($end !== null && $end < $start) {
            return $this->paymentError('0050', 'Recurring', 'The end date cannot be before the start date.');
        }

        $accountID = (string) ($input['account'] ?? '');
        $account = $this->getOwnedAccount($userID, $accountID);

        if (isset($account['error_id'])) {
            return $account;
        }

        $method = $this->resolvePaymentMethod($userID, $accountID, $input['method'] ?? '');

        if (isset($method['error_id'])) {
            return $method;
        }

        $budgetID = $this->resolveBudget($userID, $input['budget'] ?? '');

        if (is_array($budgetID)) {
            return $budgetID;
        }

        return [
            'direction'              => $direction,
            'category'               => ($direction === 'IN') ? 'income' : 'expense',
            'accountsessionid'       => $accountID,
            'paymentmethodsessionid' => is_array($method) ? $method['PaymentMethodSessionID'] : null,
            'budgetsessionid'        => $budgetID,
            'amount'                 => number_format($amount, 2, '.', ''),
            'description'            => $description,
            'periodic'               => $periodic,
            'startdate'              => $start,
            'enddate'                => $end,
        ];
    }

    /** Create a rule and post whatever is already due (e.g. a salary starting today). */
    protected function recurringCreateProccess(string $userID, array $input)
    {
        $clean = $this->normaliseRecurringInput($userID, $input);

        if (isset($clean['error_id'])) {
            return $clean;
        }

        $now = time();
        $db = $this->db();
        $db->table('recurring')->insert($clean + [
            'usersessionid'   => $userID,
            'nextduedate'     => $clean['startdate'],
            'occurrences'     => 0,
            'status'          => 'A',
            'createddatetime' => $now,
            'updateddatetime' => $now,
        ]);

        $row = $db->table('recurring')->select('sessionid')->where('id', $db->insertID())->get()->getRowArray();

        if (empty($row)) {
            return $this->paymentError('0005', 'Recurring', 'The recurring transaction could not be saved.');
        }

        $posted = $this->postDueRecurring($userID, $row['sessionid']);

        return ['success' => true, 'data' => ['RecurringSessionID' => $row['sessionid'], 'Posted' => $posted]];
    }

    /** Pause, resume, delete, or change amount / description / end date. */
    protected function recurringUpdateProccess(string $userID, $recurringID, array $input)
    {
        $rule = $this->recurringRow($userID, $recurringID);

        if ($rule === null) {
            return $this->paymentError('0051', 'Recurring', 'The recurring transaction was not found.');
        }

        $update = [];
        $action = strtolower((string) ($input['action'] ?? 'edit'));

        if ($action === 'pause') {
            if ($rule['status'] !== 'A') {
                return $this->paymentError('0051', 'Recurring', 'Only an active recurring transaction can be paused.');
            }
            $update['status'] = 'P';
        } elseif ($action === 'resume') {
            if ($rule['status'] !== 'P') {
                return $this->paymentError('0051', 'Recurring', 'Only a paused recurring transaction can be resumed.');
            }
            // Resuming does not back-post the paused periods: continue from the next date on or after today
            $next = $rule['nextduedate'];
            $n = (int) $rule['occurrences'];
            while ($next < date('Y-m-d')) {
                $n++;
                $next = $this->addPeriod($rule['startdate'], $rule['periodic'], $n);
            }
            $update += ['status' => 'A', 'nextduedate' => $next, 'occurrences' => $n, 'lasterror' => null];
        } elseif ($action === 'delete') {
            $update['status'] = 'D';
        } else {
            if (array_key_exists('amount', $input)) {
                $amount = $this->toAmount($input['amount']);
                if ($amount === null || $amount <= 0) {
                    return $this->paymentError('0024', 'Payment Amount', 'The amount must be greater than zero.');
                }
                $update['amount'] = number_format($amount, 2, '.', '');
            }

            if (array_key_exists('description', $input)) {
                $description = trim((string) $input['description']);
                if ($description === '' || mb_strlen($description) > 100) {
                    return $this->paymentError('0050', 'Recurring', 'A description is required (max 100 characters).');
                }
                $update['description'] = $description;
            }

            if (array_key_exists('end_date', $input)) {
                $end = ($input['end_date'] === '') ? null : $this->validDate($input['end_date']);
                if ($input['end_date'] !== '' && ($end === null || $end < $rule['startdate'])) {
                    return $this->paymentError('0050', 'Recurring', 'The end date must be a valid date on or after the start date.');
                }
                $update['enddate'] = $end;
                if ($rule['status'] === 'E' && ($end === null || $end >= $rule['nextduedate'])) {
                    $update['status'] = 'A';
                }
            }
        }

        if ($update === []) {
            return ['success' => true, 'data' => ['RecurringSessionID' => $rule['sessionid']]];
        }

        $update['updateddatetime'] = time();
        $this->db()->table('recurring')->set($update)->where('sessionid', $rule['sessionid'])->where('usersessionid', $userID)->update();

        $posted = (($update['status'] ?? '') === 'A') ? $this->postDueRecurring($userID, $rule['sessionid']) : 0;

        return ['success' => true, 'data' => ['RecurringSessionID' => $rule['sessionid'], 'Posted' => $posted]];
    }

    /**
     * Post every occurrence due up to today (all active rules, or one rule).
     * Returns the number of occurrences posted. Never throws: a failing
     * occurrence stops that rule and records the reason in `lasterror`.
     */
    protected function postDueRecurring(string $userID, string $onlyRecurringID = ''): int
    {
        $today = date('Y-m-d');

        try {
            $builder = $this->db()->table('recurring')
                ->select('sessionid')
                ->where(['usersessionid' => $userID, 'status' => 'A'])
                ->where('nextduedate <=', $today);

            if ($onlyRecurringID !== '') {
                $builder->where('sessionid', $onlyRecurringID);
            }

            $due = $builder->get()->getResultArray();
        } catch (\Throwable $exception) {
            // Table missing (migration not run yet) must not break the pages calling this
            log_message('error', 'Recurring catch-up skipped: {message}', ['message' => $exception->getMessage()]);
            return 0;
        }

        $posted = 0;

        foreach ($due as $rule) {
            for ($i = 0; $i < $this->recurringCatchUpLimit; $i++) {
                $result = $this->postNextOccurrence($userID, $rule['sessionid'], $today);

                if ($result !== true) {
                    break;
                }

                $posted++;
            }
        }

        return $posted;
    }

    /** true when one occurrence was posted, false when nothing is due or it failed. */
    protected function postNextOccurrence(string $userID, string $recurringID, string $today): bool
    {
        $error = null;

        $result = $this->runAtomic(function () use ($userID, $recurringID, $today, &$error) {
            $rule = $this->recurringRow($userID, $recurringID, true);

            if ($rule === null || $rule['status'] !== 'A' || $rule['nextduedate'] > $today) {
                return ['success' => true, 'posted' => false];
            }

            if ($rule['enddate'] !== null && $rule['nextduedate'] > $rule['enddate']) {
                $this->db()->table('recurring')->set(['status' => 'E', 'updateddatetime' => time()])->where('sessionid', $recurringID)->update();
                return ['success' => true, 'posted' => false];
            }

            $paid = $this->recordPayment($userID, [
                'direction'         => $rule['direction'],
                'account'           => $rule['accountsessionid'],
                'method'            => $rule['paymentmethodsessionid'] ?? '',
                'budget'            => $rule['budgetsessionid'] ?? '',
                'amount'            => $rule['amount'],
                'category'          => $rule['category'],
                'settlement'        => 'FULL',
                'link_type'         => 'recurring',
                'link_id'           => $recurringID,
                'description'       => $rule['description'],
                'date'              => $rule['nextduedate'],
                'allow_over_budget' => true,
            ]);

            if (isset($paid['error_id'])) {
                $error = $paid;
                return $paid;
            }

            // Calendar-correct: always step from the start date (31 Jan -> 28 Feb -> 31 Mar)
            $occurrences = (int) $rule['occurrences'] + 1;
            $next = $this->addPeriod($rule['startdate'], $rule['periodic'], $occurrences);
            $ended = ($rule['enddate'] !== null && $next > $rule['enddate']);

            $this->db()->table('recurring')->set([
                'occurrences'     => $occurrences,
                'lastposteddate'  => $rule['nextduedate'],
                'nextduedate'     => $next,
                'status'          => $ended ? 'E' : 'A',
                'lasterror'       => null,
                'updateddatetime' => time(),
            ])->where('sessionid', $recurringID)->update();

            return ['success' => true, 'posted' => true];
        });

        if ($error !== null || isset($result['error_id'])) {
            // The posting was rolled back; remember why so the user can fix it
            $reason = $error['error_message'] ?? ($result['error_message'] ?? 'Could not be posted.');
            $this->db()->table('recurring')
                ->set(['lasterror' => mb_substr($reason, 0, 200), 'updateddatetime' => time()])
                ->where(['sessionid' => $recurringID, 'usersessionid' => $userID])
                ->update();

            return false;
        }

        return ! empty($result['posted']);
    }
}
