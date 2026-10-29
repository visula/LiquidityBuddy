<?php

namespace App\Controllers;

/**
 * Monthly Payment Plan (docs/plans/MONTHLY_PAYMENT_PLAN.md).
 *
 * A plan item is one line of the Planned Total Expenses sheet; a plan entry is
 * one item in one month (a grid cell). From the item's tracking start onward,
 * every DUE entry owns a payable on the item's paymentplan
 * (paymentplanlink = 'planitem'), so it is paid through the normal Dued /
 * settlePayables() flow. Entries before the tracking start are historical (H):
 * shown, never payable, never overdue.
 *
 * Money never moves here - only plan rows, payment plans and payables are
 * written. Service methods are protected; spark commands reach them through
 * App\Libraries\PlanRunner.
 */
class PlanService extends PaymentService
{
    protected $planDirections = ['IN', 'OUT', 'TRANSFER'];
    protected $planKinds = ['DUE', 'ENVELOPE'];
    protected $planAmountTypes = ['FIXED', 'ESTIMATE', 'VARIABLE'];

    /** How many months past the current one roll forward generates. */
    protected $planHorizonMonths = 12;

    // ------------------------------------------------------------------
    // Month helpers
    // ------------------------------------------------------------------

    /** 'YYYY-MM' or 'YYYY-MM-DD' -> 'YYYY-MM-01', or null. */
    protected function monthStart($value)
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $value, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-01';
    }

    protected function currentMonth(): string
    {
        return date('Y-m-01');
    }

    protected function addMonths(string $monthStart, int $n): string
    {
        return (new \DateTime($monthStart))->modify('first day of this month')->modify(($n >= 0 ? '+' : '') . $n . ' month')->format('Y-m-01');
    }

    /** Due date of an item in a month: due day clamped to the month end; NULL = last day. */
    protected function planDueDate(string $monthStart, $dueDay): string
    {
        $last = (int) date('t', strtotime($monthStart));
        $day = ($dueDay === null || $dueDay === '') ? $last : min(max(1, (int) $dueDay), $last);

        return substr($monthStart, 0, 8) . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /** Income due on a Saturday or Sunday moves to the previous (PREV) or next (NEXT) working day. */
    protected function planWeekendAdjusted(string $date, string $rule): string
    {
        $dow = (int) date('N', strtotime($date));

        if ($rule === 'NONE' || $dow < 6) {
            return $date;
        }

        $days = ($rule === 'PREV') ? (($dow === 6) ? -1 : -2) : (($dow === 6) ? 2 : 1);

        return date('Y-m-d', strtotime($date . ' ' . ($days > 0 ? '+' : '') . $days . ' day'));
    }

    protected function money($value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    // ------------------------------------------------------------------
    // Payment plan + payables of an item
    // ------------------------------------------------------------------

    /** The item's paymentplan (created on first use). */
    protected function planPaymentPlan(array $item): string
    {
        if (! empty($item['paymentplansessionid'])) {
            return $item['paymentplansessionid'];
        }

        $db = $this->db();
        $now = time();
        $today = date('Y-m-d');

        $db->table('paymentplan')->insert([
            'paymentplanlink'      => 'planitem',
            'paymentplansessionid' => $item['sessionid'],
            'paymentplan'          => 'M',
            'period'               => 0,
            'rate'                 => 0,
            'startdate'            => $today,
            'startdatetime'        => strtotime($today),
            'enddate'              => $today,
            'enddatetime'          => strtotime($today),
            'createddatetime'      => $now,
            'updateddatetime'      => $now,
            'usersessionid'        => $item['usersessionid'],
            'status'               => 'A',
            'description'          => mb_substr($item['name'], 0, 100),
            'amount'               => 0,
            'direction'            => $item['direction'],
        ]);

        $planID = $db->table('paymentplan')->select('sessionid')->where('id', $db->insertID())->get()->getRow()->sessionid;
        $db->table('planitem')->set(['paymentplansessionid' => $planID, 'updateddatetime' => $now])->where('sessionid', $item['sessionid'])->update();

        return $planID;
    }

    protected function createPlanPayable(string $planID, string $dueDate, float $amount): string
    {
        $db = $this->db();
        $now = time();

        $db->table('payable')->insert([
            'paymentplansessionid' => $planID,
            'duedate'              => $dueDate,
            'duedatetime'          => strtotime($dueDate),
            'dueamount'            => $this->money($amount),
            'paidamount'           => '0.00',
            'status'               => 'P',
            'createddatetime'      => $now,
            'updateddatetime'      => $now,
        ]);

        return $db->table('payable')->select('sessionid')->where('id', $db->insertID())->get()->getRow()->sessionid;
    }

    /**
     * Keep the item's paymentplan in line with its non-cancelled payables:
     * first / last due date, count, total, and open (A) while anything is unpaid.
     */
    protected function syncPlanPaymentPlan(string $planID): void
    {
        $db = $this->db();
        $row = $db->query(
            'SELECT MIN(duedate) AS firstdue, MAX(duedate) AS lastdue, COUNT(*) AS n, COALESCE(SUM(dueamount), 0) AS total,
                    SUM(status IN (\'P\', \'T\')) AS open
               FROM payable WHERE paymentplansessionid = ? AND status <> \'C\'',
            [$planID]
        )->getRowArray();

        $update = ['period' => (int) $row['n'], 'amount' => $this->money($row['total']), 'updateddatetime' => time()];

        if ((int) $row['n'] > 0) {
            $update += [
                'startdate'     => $row['firstdue'],
                'startdatetime' => strtotime($row['firstdue']),
                'enddate'       => $row['lastdue'],
                'enddatetime'   => strtotime($row['lastdue']),
                'status'        => ((int) $row['open'] > 0) ? 'A' : 'S',
            ];
        } else {
            $update['status'] = 'A';
        }

        $db->table('paymentplan')->set($update)->where('sessionid', $planID)->update();
    }

    /**
     * Create one entry (and its payable when it is a DUE entry from the
     * tracking start). Returns 'created-H', 'created-A', 'trimmed' or 'exists'.
     */
    protected function createPlanEntry(array &$item, string $month, float $amount, bool $overridden = false): string
    {
        $db = $this->db();
        $dueDate = $this->planWeekendAdjusted($this->planDueDate($month, $item['dueday']), $item['weekendrule'] ?? 'NONE');

        if (! empty($item['enddate']) && $dueDate > $item['enddate']) {
            return 'trimmed';
        }

        $exists = $db->table('planentry')->where(['planitemsessionid' => $item['sessionid'], 'month' => $month])->countAllResults();

        if ($exists > 0) {
            return 'exists';
        }

        $historical = $month < $item['trackfrom'];
        $payableID = null;

        if (! $historical && $item['kind'] === 'DUE') {
            $planID = $this->planPaymentPlan($item);
            $item['paymentplansessionid'] = $planID;
            $payableID = $this->createPlanPayable($planID, $dueDate, $amount);
            $this->syncPlanPaymentPlan($planID);
        }

        $now = time();
        $db->table('planentry')->insert([
            'usersessionid'     => $item['usersessionid'],
            'planitemsessionid' => $item['sessionid'],
            'month'             => $month,
            'plannedamount'     => $this->money($amount),
            'duedate'           => $dueDate,
            'payablesessionid'  => $payableID,
            'overridden'        => $overridden ? 1 : 0,
            'status'            => $historical ? 'H' : 'A',
            'createddatetime'   => $now,
            'updateddatetime'   => $now,
        ]);

        return $historical ? 'created-H' : 'created-A';
    }

    /**
     * Change an existing entry's amount (import --update). Only while its
     * payable is untouched (P) or there is none; settled money is never changed.
     */
    protected function updatePlanEntryAmount(array $item, array $entry, float $amount): bool
    {
        if (round((float) $entry['plannedamount'], 2) === round($amount, 2) || $entry['status'] === 'X') {
            return false;
        }

        $db = $this->db();

        if (! empty($entry['payablesessionid'])) {
            $payable = $db->query('SELECT status FROM payable WHERE sessionid = ? FOR UPDATE', [$entry['payablesessionid']])->getRowArray();

            if (empty($payable) || $payable['status'] !== 'P') {
                return false;
            }

            $db->table('payable')->set(['dueamount' => $this->money($amount), 'updateddatetime' => time()])->where('sessionid', $entry['payablesessionid'])->update();
            $this->syncPlanPaymentPlan($item['paymentplansessionid']);
        }

        $db->table('planentry')->set(['plannedamount' => $this->money($amount), 'updateddatetime' => time()])->where('sessionid', $entry['sessionid'])->update();

        return true;
    }

    // ------------------------------------------------------------------
    // Roll forward
    // ------------------------------------------------------------------

    /**
     * Extend recurring items month by month up to current month + horizon,
     * never past their end date. Amount = last entry's amount (else the
     * default); derived items = share ratio x the source's amount that month.
     * Returns the number of entries created.
     */
    protected function rollPlanForward(string $userID, int $horizonMonths = 0): int
    {
        $db = $this->db();
        $horizon = $this->addMonths($this->currentMonth(), $horizonMonths > 0 ? $horizonMonths : $this->planHorizonMonths);

        $items = $db->table('planitem')
            ->where(['usersessionid' => $userID, 'status' => 'A', 'recurring' => 1])
            ->orderBy('sourceplanitemsessionid IS NOT NULL', 'ASC', false) // sources before derived items
            ->get()->getResultArray();

        $created = 0;

        foreach ($items as $item) {
            $created += $this->rollPlanItem($item, $horizon);
        }

        $this->closeEndedPlanItems($userID);

        return $created;
    }

    /** Roll one recurring item forward to $horizon (a month start). Returns the entries created. */
    protected function rollPlanItem(array $item, string $horizon): int
    {
        if ((int) $item['recurring'] !== 1 || $item['status'] !== 'A') {
            return 0;
        }

        $db = $this->db();
        $last = $db->table('planentry')->select('month, plannedamount')
            ->where('planitemsessionid', $item['sessionid'])->orderBy('month', 'DESC')->limit(1)->get()->getRowArray();

        $month = $last ? $this->addMonths($last['month'], 1) : ($item['startmonth'] ?: null);

        if ($month === null) {
            return 0; // nothing planned yet: the items page adds the first month
        }

        $amount = $last ? (float) $last['plannedamount'] : (float) $item['defaultamount'];
        $created = 0;

        for (; $month <= $horizon; $month = $this->addMonths($month, 1)) {
            if (! empty($item['enddate']) && $this->planDueDate($month, $item['dueday']) > $item['enddate']) {
                break;
            }

            $value = $amount;

            if (! empty($item['sourceplanitemsessionid'])) {
                $source = $db->table('planentry')->select('plannedamount, status')
                    ->where(['planitemsessionid' => $item['sourceplanitemsessionid'], 'month' => $month])->get()->getRowArray();

                if (empty($source) || $source['status'] === 'X') {
                    continue; // nothing to share this month
                }

                $value = round((float) $source['plannedamount'] * (float) $item['shareratio'], 2);
            }

            $result = $this->createPlanEntry($item, $month, $value);
            $created += (strpos($result, 'created') === 0) ? 1 : 0;
        }

        return $created;
    }

    /** Items past their end date with nothing left open become ended (E). */
    protected function closeEndedPlanItems(string $userID): void
    {
        $this->db()->query(
            'UPDATE planitem i SET i.status = \'E\', i.updateddatetime = ?
              WHERE i.usersessionid = ? AND i.status = \'A\' AND i.enddate IS NOT NULL AND i.enddate < ?
                AND NOT EXISTS (SELECT 1 FROM planentry e JOIN payable p ON p.sessionid = e.payablesessionid
                                 WHERE e.planitemsessionid = i.sessionid AND e.status = \'A\' AND p.status IN (\'P\', \'T\'))',
            [time(), $userID, date('Y-m-d')]
        );
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    protected function planError(string $id, string $message): array
    {
        return $this->paymentError($id, 'Monthly Plan', $message);
    }

    /**
     * Import a liquiditybuddy.monthly-plan/v1 seed for one user. Idempotent:
     * items match on (user, itemkey), entries on (item, month).
     *
     * $opt: from (YYYY-MM, tracking start), map (hint => real name), update,
     *       keep_past_end, create_missing_accounts, card_limit, dry_run
     */
    protected function importPlan(string $userID, array $seed, array $opt = [])
    {
        if (($seed['format'] ?? '') !== 'liquiditybuddy.monthly-plan/v1' || ! is_array($seed['items'] ?? null)) {
            return $this->planError('0070', 'Not a liquiditybuddy.monthly-plan/v1 seed file.');
        }

        $from = $this->monthStart((string) ($opt['from'] ?? '')) ?? $this->currentMonth();
        $map = array_change_key_case(is_array($opt['map'] ?? null) ? $opt['map'] : [], CASE_LOWER);
        $update = ! empty($opt['update']);
        $keepPastEnd = ! empty($opt['keep_past_end']);
        $createAccounts = ! empty($opt['create_missing_accounts']);
        $cardLimit = $this->toAmount($opt['card_limit'] ?? 0) ?? 0.0;
        $dryRun = ! empty($opt['dry_run']);

        $report = [
            'user' => $userID, 'from' => $from, 'dry_run' => $dryRun,
            'categories' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
            'items' => ['created' => 0, 'updated' => 0, 'unchanged' => 0],
            'entries' => ['created' => 0, 'historical' => 0, 'updated' => 0, 'unchanged' => 0, 'rolled_forward' => 0],
            'payables_created' => 0,
            'accounts_created' => [], 'budgets_created' => [],
            'unresolved' => [], 'trimmed' => [], 'end_moved' => [], 'zero_limit_cards' => [],
            'warnings' => array_values($seed['warnings'] ?? []),
        ];

        $db = $this->db();
        $db->transBegin();

        try {
            $result = $this->importPlanRows($userID, $seed, $from, $map, $update, $keepPastEnd, $createAccounts, $cardLimit, $report);
        } catch (\Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Plan import failed: {message}', ['message' => $exception->getMessage()]);

            return $this->planError('0071', 'The import failed and nothing was saved: ' . $exception->getMessage());
        }

        if (isset($result['error_id']) || $dryRun) {
            $db->transRollback();
        } else {
            $db->transCommit();
        }

        return isset($result['error_id']) ? $result : $report;
    }

    protected function importPlanRows(string $userID, array $seed, string $from, array $map, bool $update, bool $keepPastEnd, bool $createAccounts, float $cardLimit, array &$report)
    {
        $db = $this->db();
        $now = time();
        $mapped = static function ($hint) use ($map) {
            if ($hint === null || $hint === '') {
                return null;
            }
            return $map[strtolower((string) $hint)] ?? (string) $hint;
        };

        // ---- categories ----
        $categoryIDs = [];
        $seedCategories = $seed['categories'] ?? [];

        foreach ($seed['items'] as $item) { // categories used by items but not listed
            $names = array_map('strtolower', array_column($seedCategories, 'name'));
            if (! empty($item['category']) && ! in_array(strtolower($item['category']), $names, true)) {
                $seedCategories[] = ['name' => $item['category'], 'direction' => $item['direction'] === 'IN' ? 'IN' : 'OUT', 'allocation_percent' => null];
            }
        }

        foreach (array_values($seedCategories) as $order => $category) {
            $name = mb_substr(trim((string) $category['name']), 0, 50);
            $direction = ($category['direction'] ?? 'OUT') === 'IN' ? 'IN' : 'OUT';
            $percent = isset($category['allocation_percent']) && is_numeric($category['allocation_percent']) ? round((float) $category['allocation_percent'], 2) : null;
            $existing = $db->table('plancategory')->where(['usersessionid' => $userID, 'name' => $name])->get()->getRowArray();

            if (empty($existing)) {
                $db->table('plancategory')->insert(['usersessionid' => $userID, 'name' => $name, 'direction' => $direction, 'allocationpercent' => $percent,
                    'sortorder' => $order, 'status' => 'A', 'createddatetime' => $now, 'updateddatetime' => $now]);
                $existing = $db->table('plancategory')->where('id', $db->insertID())->get()->getRowArray();
                $report['categories']['created']++;
            } elseif ($update && ($existing['direction'] !== $direction || $existing['allocationpercent'] != $percent)) {
                $db->table('plancategory')->set(['direction' => $direction, 'allocationpercent' => $percent, 'updateddatetime' => $now])->where('id', $existing['id'])->update();
                $report['categories']['updated']++;
            } else {
                $report['categories']['unchanged']++;
            }

            $categoryIDs[strtolower($name)] = $existing['sessionid'];
        }

        // ---- accounts (by name, case-insensitive), card accounts on request ----
        $accounts = [];
        foreach ($this->rowsOf($this->activeAccountListAccessModule($userID)) as $account) {
            $accounts[strtolower($account['AccountName'])] = $account['AccountSessionID'];
        }

        $cardHints = array_map('strtolower', array_column($seed['card_groups'] ?? [], 'account_hint'));
        $methodNames = [];
        foreach ($seed['items'] as $item) {
            if (! empty($item['account_hint']) && ! empty($item['method_hint'])) {
                $methodNames[strtolower($mapped($item['account_hint']))] = $item['method_hint'];
            }
        }

        $resolveAccount = function ($hint, string $itemKey, string $field) use (&$accounts, $mapped, $createAccounts, $cardHints, $cardLimit, $methodNames, $userID, &$report) {
            $name = $mapped($hint);

            if ($name === null) {
                return null;
            }

            $lower = strtolower($name);

            if (isset($accounts[$lower])) {
                return $accounts[$lower];
            }

            $isCard = in_array(strtolower((string) $hint), $cardHints, true) || stripos($name, 'credit card') !== false;

            if ($createAccounts && $isCard) {
                $created = $this->createPlanCardAccount($userID, $name, $methodNames[$lower] ?? 'Credit Card', $cardLimit);

                if (isset($created['error_id'])) {
                    throw new \RuntimeException('Could not create account "' . $name . '": ' . $created['error_message']);
                }

                $accounts[$lower] = $created['AccountSessionID'];
                $report['accounts_created'][] = $name . ' (credit card, limit ' . number_format($cardLimit, 2) . ')';

                return $accounts[$lower];
            }

            $report['unresolved'][] = $itemKey . ': ' . $field . ' "' . $name . '" not found';

            return null;
        };

        // one lookup per account, not per item (the import runs this for every seed item)
        $methodsByAccount = [];

        $resolveMethod = function ($accountID, $hint, string $itemKey) use ($userID, &$report, &$methodsByAccount) {
            if ($accountID === null) {
                return null;
            }

            if ($hint !== null && $hint !== '') {
                $methodsByAccount[$accountID] ??= $this->paymentMethodList($userID, $accountID);

                foreach ($methodsByAccount[$accountID] as $method) {
                    if (strcasecmp($method['PaymentMethodName'], (string) $hint) === 0) {
                        return $method['PaymentMethodSessionID'];
                    }
                }
            }

            $default = $this->getDefaultPaymentMethod($userID, $accountID);

            if (is_array($default) && ! isset($default['error_id'])) {
                return $default['PaymentMethodSessionID'];
            }

            $report['unresolved'][] = $itemKey . ': no payment method on its account';

            return null;
        };

        $resolveBudget = function ($hint, float $amount) use ($userID, $mapped, $now, &$report) {
            $name = $mapped($hint);

            if ($name === null) {
                return null;
            }

            $db = $this->db();
            $row = $db->table('budget')->select('sessionid')->where(['usersessionid' => $userID, 'status' => 'A', 'name' => $name])->get()->getRowArray(); // utf8mb4_general_ci: case-insensitive

            if (! empty($row)) {
                return $row['sessionid'];
            }

            $db->table('budget')->insert(['usersessionid' => $userID, 'name' => mb_substr($name, 0, 50), 'periodic' => 'M', 'amount' => $this->money($amount),
                'status' => 'A', 'createddatetime' => $now, 'updateddatetime' => $now]);
            $report['budgets_created'][] = $name . ' (monthly, ' . number_format($amount, 2) . ')';

            return $db->table('budget')->select('sessionid')->where('id', $db->insertID())->get()->getRow()->sessionid;
        };

        // ---- items ----
        $itemsByKey = [];

        foreach (array_values($seed['items']) as $order => $seedItem) {
            $key = mb_substr(strtolower(trim((string) ($seedItem['key'] ?? ''))), 0, 60);

            if ($key === '' || ! preg_match('/^[a-z0-9-]+$/', $key)) {
                return $this->planError('0072', 'Seed item ' . ($order + 1) . ' has no valid key.');
            }

            $direction = in_array($seedItem['direction'] ?? '', $this->planDirections, true) ? $seedItem['direction'] : 'OUT';
            $kind = in_array($seedItem['kind'] ?? '', $this->planKinds, true) ? $seedItem['kind'] : 'DUE';
            $months = is_array($seedItem['months'] ?? null) ? $seedItem['months'] : [];
            ksort($months);
            $seedMonths = array_keys($months);
            $endDate = $this->validDate((string) ($seedItem['end'] ?? ''));

            // Decision 11a: keep past the end date -> end moves to the 28th of the last seeded month
            if ($keepPastEnd && $endDate !== null && $seedMonths !== []) {
                $lastMonth = $this->monthStart(end($seedMonths));
                if ($lastMonth !== null && $this->planDueDate($lastMonth, $seedItem['due_day'] ?? null) > $endDate) {
                    $newEnd = substr($lastMonth, 0, 8) . '28';
                    $report['end_moved'][] = $key . ': end ' . $endDate . ' -> ' . $newEnd;
                    $endDate = $newEnd;
                }
            }

            $accountID = $resolveAccount($seedItem['account_hint'] ?? null, $key, 'account');
            $toAccountID = ($direction === 'TRANSFER') ? $resolveAccount($seedItem['to_account_hint'] ?? null, $key, 'to account') : null;

            $row = [
                'name'                   => mb_substr(trim((string) ($seedItem['name'] ?? $key)), 0, 100),
                'direction'              => $direction,
                'kind'                   => $kind,
                'plancategorysessionid'  => $categoryIDs[strtolower((string) ($seedItem['category'] ?? ''))] ?? null,
                'amounttype'             => in_array($seedItem['amount_type'] ?? '', $this->planAmountTypes, true) ? $seedItem['amount_type'] : 'FIXED',
                'defaultamount'          => $this->money($seedItem['default_amount'] ?? 0),
                'dueday'                 => isset($seedItem['due_day']) && is_numeric($seedItem['due_day']) ? max(1, min(31, (int) $seedItem['due_day'])) : null,
                'startmonth'             => $seedMonths ? $this->monthStart($seedMonths[0]) : $this->monthStart((string) ($seedItem['start'] ?? '')),
                'recurring'              => ! empty($seedItem['recurring']) ? 1 : 0,
                'enddate'                => $endDate,
                'accountsessionid'       => $accountID,
                'paymentmethodsessionid' => ($direction === 'OUT' || $direction === 'TRANSFER') ? $resolveMethod($accountID, $seedItem['method_hint'] ?? null, $key) : null,
                'toaccountsessionid'     => $toAccountID,
                'budgetsessionid'        => $resolveBudget($seedItem['budget_hint'] ?? null, (float) ($seedItem['default_amount'] ?? 0)),
                'stakeholder'            => isset($seedItem['stakeholder_hint']) ? mb_substr((string) $seedItem['stakeholder_hint'], 0, 60) : null,
                'notes'                  => isset($seedItem['notes']) ? mb_substr((string) $seedItem['notes'], 0, 255) : null,
                'sortorder'              => (int) ($seedItem['sheet_row'] ?? $order),
            ];

            if ($kind === 'ENVELOPE' && $row['budgetsessionid'] === null) {
                $report['unresolved'][] = $key . ': envelope without a budget';
            }

            $existing = $db->table('planitem')->where(['usersessionid' => $userID, 'itemkey' => $key])->get()->getRowArray();

            if (empty($existing)) {
                $db->table('planitem')->insert($row + ['usersessionid' => $userID, 'itemkey' => $key, 'trackfrom' => $from,
                    'status' => 'A', 'createddatetime' => $now, 'updateddatetime' => $now]);
                $existing = $db->table('planitem')->where('id', $db->insertID())->get()->getRowArray();
                $report['items']['created']++;
            } elseif ($update) {
                $db->table('planitem')->set($row + ['updateddatetime' => $now])->where('id', $existing['id'])->update();
                $existing = $db->table('planitem')->where('id', $existing['id'])->get()->getRowArray();
                $report['items']['updated']++;
            } else {
                $report['items']['unchanged']++;
            }

            $itemsByKey[$key] = ['row' => $existing, 'seed' => $seedItem, 'months' => $months];
        }

        // ---- derived items (second pass: sources exist now) ----
        foreach ($itemsByKey as $key => $data) {
            $derived = $data['seed']['derived'] ?? null;

            if (! is_array($derived) || empty($derived['from'])) {
                continue;
            }

            $source = $itemsByKey[strtolower((string) $derived['from'])]['row'] ?? null;

            if ($source === null) {
                $report['unresolved'][] = $key . ': derived from unknown item "' . $derived['from'] . '"';
                continue;
            }

            $ratio = round((float) ($derived['ratio'] ?? 0), 4);
            $db->table('planitem')->set(['sourceplanitemsessionid' => $source['sessionid'], 'shareratio' => $ratio])->where('id', $data['row']['id'])->update();
            $itemsByKey[$key]['row']['sourceplanitemsessionid'] = $source['sessionid'];
            $itemsByKey[$key]['row']['shareratio'] = $ratio;
        }

        // ---- entries for the seeded months ----
        foreach ($itemsByKey as $key => $data) {
            $item = $data['row'];

            foreach ($data['months'] as $ym => $amount) {
                $month = $this->monthStart((string) $ym);

                if ($month === null || ! is_numeric($amount)) {
                    $report['unresolved'][] = $key . ': bad month or amount "' . $ym . '"';
                    continue;
                }

                $amount = round((float) $amount, 2);
                $existing = $db->table('planentry')->where(['planitemsessionid' => $item['sessionid'], 'month' => $month])->get()->getRowArray();

                if (! empty($existing)) {
                    if ($update && $this->updatePlanEntryAmount($item, $existing, $amount)) {
                        $report['entries']['updated']++;
                    } else {
                        $report['entries']['unchanged']++;
                    }
                    continue;
                }

                $overridden = false;
                if (! empty($item['sourceplanitemsessionid'])) {
                    $sourceKey = strtolower((string) $data['seed']['derived']['from']);
                    $sourceAmount = $itemsByKey[$sourceKey]['months'][$ym] ?? null;
                    $overridden = $sourceAmount === null || abs(round((float) $sourceAmount * (float) $item['shareratio'], 2) - $amount) > 0.005;
                }

                $result = $this->createPlanEntry($item, $month, $amount, $overridden);

                if ($result === 'trimmed') {
                    $report['trimmed'][] = $key . ' ' . substr($month, 0, 7) . ' (' . number_format($amount, 2) . ', after end ' . $item['enddate'] . ')';
                } elseif ($result === 'created-H') {
                    $report['entries']['historical']++;
                } elseif ($result === 'created-A') {
                    $report['entries']['created']++;
                    $report['payables_created'] += ($item['kind'] === 'DUE') ? 1 : 0;
                }

                $itemsByKey[$key]['row'] = $item; // may now carry its paymentplan id
            }
        }

        // ---- roll forward recurring items up to the horizon ----
        $before = (int) $db->table('payable')->countAllResults();
        $report['entries']['rolled_forward'] = $this->rollPlanForward($userID);
        $report['payables_created'] += (int) $db->table('payable')->countAllResults() - $before;

        // ---- items whose end date is before tracking start, with nothing open: ended ----
        $db->query(
            'UPDATE planitem i SET i.status = \'E\', i.updateddatetime = ?
              WHERE i.usersessionid = ? AND i.status = \'A\' AND i.enddate IS NOT NULL AND i.enddate < i.trackfrom
                AND NOT EXISTS (SELECT 1 FROM planentry e WHERE e.planitemsessionid = i.sessionid AND e.status = \'A\')',
            [$now, $userID]
        );

        // ---- credit cards that can't pay anything yet ----
        foreach ($db->query(
            'SELECT a.name AS account, pm.name AS method FROM paymentmethod pm JOIN account a ON a.sessionid = pm.accountsessionid
              WHERE pm.usersessionid = ? AND pm.status = \'A\' AND a.status = \'A\' AND pm.type = \'CREDIT_CARD\' AND pm.creditlimit <= 0',
            [$userID]
        )->getResultArray() as $card) {
            $report['zero_limit_cards'][] = $card['account'] . ' / ' . $card['method'];
        }

        $report['unresolved'] = array_values(array_unique($report['unresolved']));

        return ['success' => true];
    }

    /** Zero-balance credit card account with a CREDIT_CARD default method (import --create-missing-accounts). */
    protected function createPlanCardAccount(string $userID, string $name, string $methodName, float $limit)
    {
        $group = $this->db()->table('accountgroup')->select('sessionid')->where(['usersessionid' => $userID, 'status' => 'A'])->orderBy('id', 'ASC')->get()->getRowArray();

        if (empty($group)) {
            return $this->planError('0073', 'The user has no account group to put the card account in.');
        }

        $account = $this->accountInitProccess($userID, mb_substr($name, 0, 50), $group['sessionid'], 0);

        if (isset($account['error_id'])) {
            return $account;
        }

        $method = $this->paymentMethodCreateProccess($userID, $account['AccountSessionID'], [
            'name' => $methodName, 'type' => 'CREDIT_CARD', 'credit_limit' => (string) $limit, 'is_default' => true,
        ]);

        if (isset($method['error_id'])) {
            return $method;
        }

        $bank = trim((string) strstr($name, ':', true));
        $this->saveAccountType($userID, $account['AccountSessionID'], 'CREDIT_CARD', $bank);

        return ['success' => true, 'AccountSessionID' => $account['AccountSessionID']];
    }

    // ------------------------------------------------------------------
    // Totals + verify
    // ------------------------------------------------------------------

    /** Planned income / outflow / net per month of a year (A and H entries; skipped X excluded). */
    protected function planTotalsByMonth(string $userID, int $year): array
    {
        $rows = $this->db()->query(
            'SELECT DATE_FORMAT(e.month, \'%Y-%m\') AS ym,
                    SUM(CASE WHEN i.direction = \'IN\' THEN e.plannedamount ELSE 0 END) AS income,
                    SUM(CASE WHEN i.direction <> \'IN\' THEN e.plannedamount ELSE 0 END) AS outflow
               FROM planentry e JOIN planitem i ON i.sessionid = e.planitemsessionid
              WHERE e.usersessionid = ? AND i.usersessionid = ? AND i.status <> \'D\' AND e.status IN (\'A\', \'H\')
                AND e.month BETWEEN ? AND ?
           GROUP BY ym',
            [$userID, $userID, $year . '-01-01', $year . '-12-01']
        )->getResultArray();

        $totals = [];
        for ($m = 1; $m <= 12; $m++) {
            $totals[sprintf('%04d-%02d', $year, $m)] = ['income' => 0.0, 'outflow' => 0.0, 'net' => 0.0];
        }

        foreach ($rows as $row) {
            $income = round((float) $row['income'], 2);
            $outflow = round((float) $row['outflow'], 2);
            $totals[$row['ym']] = ['income' => $income, 'outflow' => $outflow, 'net' => round($income - $outflow, 2)];
        }

        return $totals;
    }

    /**
     * Reconcile the plan with the seed's expected monthly nets and check the
     * plan/payable invariants. Returns ['ok' => bool, 'checks' => [[name, ok, detail]], 'months' => totals].
     */
    protected function verifyPlan(string $userID, int $year, array $seed, bool $keepPastEnd): array
    {
        $db = $this->db();
        $checks = [];
        $expected = $seed['checks'][$keepPastEnd ? 'sheet_total_row_42' : 'net_if_end_dates_are_respected'] ?? [];
        $toleranceCents = (int) round((float) ($seed['checks']['tolerance'] ?? 0.01) * 100);
        $totals = $this->planTotalsByMonth($userID, $year);

        foreach ($totals as $ym => $total) {
            if (! array_key_exists($ym, $expected)) {
                continue;
            }
            $diff = abs((int) round($total['net'] * 100) - (int) round((float) $expected[$ym] * 100));
            $checks[] = ['net ' . $ym, $diff <= $toleranceCents,
                'planned net ' . number_format($total['net'], 2) . ', expected ' . number_format((float) $expected[$ym], 2) . ($diff ? ' (off by ' . $diff . ' cent(s))' : '')];
        }

        $mismatch = $db->query(
            'SELECT i.itemkey, e.month, e.plannedamount, p.dueamount FROM planentry e JOIN planitem i ON i.sessionid = e.planitemsessionid
               JOIN payable p ON p.sessionid = e.payablesessionid
              WHERE e.usersessionid = ? AND e.status = \'A\' AND p.status <> \'C\' AND e.plannedamount <> p.dueamount',
            [$userID]
        )->getResultArray();
        $checks[] = ['entry amount = payable due amount', $mismatch === [], $mismatch === [] ? 'all DUE entries match' : count($mismatch) . ' mismatch(es), e.g. ' . $mismatch[0]['itemkey'] . ' ' . $mismatch[0]['month']];

        $missing = (int) $db->query(
            'SELECT COUNT(*) AS n FROM planentry e JOIN planitem i ON i.sessionid = e.planitemsessionid
              WHERE e.usersessionid = ? AND e.status = \'A\' AND i.kind = \'DUE\' AND e.payablesessionid IS NULL',
            [$userID]
        )->getRow()->n;
        $checks[] = ['every tracked DUE entry has a payable', $missing === 0, $missing . ' without one'];

        $historicalWithPayable = (int) $db->query(
            'SELECT COUNT(*) AS n FROM planentry WHERE usersessionid = ? AND status = \'H\' AND payablesessionid IS NOT NULL',
            [$userID]
        )->getRow()->n;
        $checks[] = ['historical entries have no payable', $historicalWithPayable === 0, $historicalWithPayable . ' with one'];

        $afterEnd = $db->query(
            'SELECT i.itemkey, e.month FROM planentry e JOIN planitem i ON i.sessionid = e.planitemsessionid
              WHERE e.usersessionid = ? AND i.enddate IS NOT NULL AND e.duedate > i.enddate AND e.status <> \'X\'',
            [$userID]
        )->getResultArray();
        $checks[] = ['no entries after end dates', $afterEnd === [], $afterEnd === [] ? 'none' : count($afterEnd) . ', e.g. ' . $afterEnd[0]['itemkey'] . ' ' . $afterEnd[0]['month']];

        $duplicates = (int) $db->query(
            'SELECT COUNT(*) AS n FROM (SELECT planitemsessionid, month FROM planentry WHERE usersessionid = ? GROUP BY planitemsessionid, month HAVING COUNT(*) > 1) d',
            [$userID]
        )->getRow()->n;
        $checks[] = ['no duplicate (item, month)', $duplicates === 0, $duplicates . ' duplicate(s)'];

        $orphans = (int) $db->query(
            'SELECT COUNT(*) AS n FROM payable p JOIN paymentplan pp ON pp.sessionid = p.paymentplansessionid
              WHERE pp.usersessionid = ? AND pp.paymentplanlink = \'planitem\'
                AND NOT EXISTS (SELECT 1 FROM planentry e WHERE e.payablesessionid = p.sessionid)',
            [$userID]
        )->getRow()->n;
        $checks[] = ['every plan payable has an entry', $orphans === 0, $orphans . ' orphan payable(s)'];

        $plans = $db->query(
            'SELECT pp.sessionid, pp.period, pp.amount,
                    (SELECT COUNT(*) FROM payable p WHERE p.paymentplansessionid = pp.sessionid AND p.status <> \'C\') AS n,
                    (SELECT COALESCE(SUM(p.dueamount), 0) FROM payable p WHERE p.paymentplansessionid = pp.sessionid AND p.status <> \'C\') AS total
               FROM paymentplan pp WHERE pp.usersessionid = ? AND pp.paymentplanlink = \'planitem\'',
            [$userID]
        )->getResultArray();
        $badPlans = array_filter($plans, static fn ($p) => (int) $p['period'] !== (int) $p['n'] || round((float) $p['amount'], 2) !== round((float) $p['total'], 2));
        $checks[] = ['payment plans match their payables', $badPlans === [], count($plans) . ' plan(s), ' . count($badPlans) . ' out of line'];

        return [
            'ok'     => ! in_array(false, array_column($checks, 1), true),
            'checks' => $checks,
            'months' => $totals,
        ];
    }

    // ------------------------------------------------------------------
    // Ownership lookups (every incoming id is checked before any SQL)
    // ------------------------------------------------------------------

    protected function ownedPlanItem(string $userID, $itemID, bool $forUpdate = false)
    {
        if (! $this->isSessionID($itemID)) {
            return $this->planError('0076', 'Please select a valid plan item.');
        }

        $row = $this->db()->query(
            'SELECT * FROM planitem WHERE sessionid = ? AND usersessionid = ? AND status <> \'D\'' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$itemID, $userID]
        )->getRowArray();

        return empty($row) ? $this->planError('0076', 'The plan item was not found.') : $row;
    }

    protected function ownedPlanEntry(string $userID, $entryID, bool $forUpdate = false)
    {
        if (! $this->isSessionID($entryID)) {
            return $this->planError('0077', 'Please select a valid month of the plan.');
        }

        $row = $this->db()->query(
            'SELECT e.* FROM planentry e JOIN planitem i ON i.sessionid = e.planitemsessionid
              WHERE e.sessionid = ? AND e.usersessionid = ? AND i.usersessionid = ? AND i.status <> \'D\'' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$entryID, $userID, $userID]
        )->getRowArray();

        return empty($row) ? $this->planError('0077', 'That month of the plan was not found.') : $row;
    }

    protected function ownedPlanCategory(string $userID, $categoryID)
    {
        if (! $this->isSessionID($categoryID)) {
            return $this->planError('0078', 'Please select a valid category.');
        }

        $row = $this->db()->table('plancategory')->where(['sessionid' => $categoryID, 'usersessionid' => $userID, 'status' => 'A'])->get()->getRowArray();

        return empty($row) ? $this->planError('0078', 'The category was not found.') : $row;
    }

    protected function ownedBudget(string $userID, $budgetID)
    {
        if (! $this->isSessionID($budgetID)) {
            return $this->planError('0085', 'Please select a valid budget.');
        }

        $row = $this->db()->table('budget')->select('sessionid, name')->where(['sessionid' => $budgetID, 'usersessionid' => $userID, 'status' => 'A'])->get()->getRowArray();

        return empty($row) ? $this->planError('0085', 'The budget was not found.') : $row;
    }

    // ------------------------------------------------------------------
    // Editing a month (grid cell) and skipping it
    // ------------------------------------------------------------------

    /**
     * Change one entry's amount, and its payable with it. Only while the payable
     * is untouched (P), or part paid (T) and the new amount is not below what is
     * already paid (equal = settled). Settled and skipped months never change.
     */
    protected function setPlanEntryAmount(array $item, array $entry, float $amount, bool $markOverridden = false)
    {
        if ($entry['status'] === 'X') {
            return $this->planError('0079', 'A skipped month can\'t be edited.');
        }

        $db = $this->db();
        $now = time();

        if (! empty($entry['payablesessionid'])) {
            $payable = $db->query('SELECT paymentplansessionid, dueamount, paidamount, status FROM payable WHERE sessionid = ? FOR UPDATE', [$entry['payablesessionid']])->getRowArray();

            if (empty($payable) || ! in_array($payable['status'], ['P', 'T'], true)) {
                return $this->planError('0079', substr($entry['month'], 0, 7) . ' is already settled and can\'t be changed.');
            }

            $paid = round((float) $payable['paidamount'], 2);

            if ($payable['status'] === 'P' && $amount <= 0) {
                return $this->planError('0080', 'Use "Skip this month" instead of a zero amount.');
            }

            if ($amount < $paid) {
                return $this->planError('0080', 'The amount can\'t be less than the ' . number_format($paid, 2) . ' already paid for ' . substr($entry['month'], 0, 7) . '.');
            }

            $update = ['dueamount' => $this->money($amount), 'updateddatetime' => $now];

            // edited down to what was paid: the month is settled
            if ($payable['status'] === 'T' && round($amount, 2) <= $paid) {
                $update += ['status' => 'S', 'paiddate' => date('Y-m-d')];
            }

            $db->table('payable')->set($update)->where('sessionid', $entry['payablesessionid'])->update();
            $this->syncPlanPaymentPlan($payable['paymentplansessionid']);
        }

        $set = ['plannedamount' => $this->money($amount), 'updateddatetime' => $now];

        if ($markOverridden && ! empty($item['sourceplanitemsessionid'])) {
            $set['overridden'] = 1;
        }

        $db->table('planentry')->set($set)->where('sessionid', $entry['sessionid'])->update();

        return true;
    }

    /** Skip one entry: the entry becomes X and its untouched payable is cancelled (C). */
    protected function skipPlanEntry(array $entry)
    {
        if ($entry['status'] === 'X') {
            return $this->planError('0081', substr($entry['month'], 0, 7) . ' is already skipped.');
        }

        $db = $this->db();
        $now = time();

        if (! empty($entry['payablesessionid'])) {
            $payable = $db->query('SELECT paymentplansessionid, paidamount, status FROM payable WHERE sessionid = ? FOR UPDATE', [$entry['payablesessionid']])->getRowArray();

            if (! empty($payable) && $payable['status'] === 'T') {
                return $this->planError('0081', substr($entry['month'], 0, 7) . ' is part paid (' . number_format((float) $payable['paidamount'], 2)
                    . '). Edit its amount down to what was paid instead of skipping it.');
            }

            if (! empty($payable) && $payable['status'] === 'S') {
                return $this->planError('0081', substr($entry['month'], 0, 7) . ' is already settled and can\'t be skipped.');
            }

            if (! empty($payable)) {
                $db->table('payable')->set(['status' => 'C', 'updateddatetime' => $now])->where('sessionid', $entry['payablesessionid'])->update();
                $this->syncPlanPaymentPlan($payable['paymentplansessionid']);
            }
        }

        $db->table('planentry')->set(['status' => 'X', 'updateddatetime' => $now])->where('sessionid', $entry['sessionid'])->update();

        return true;
    }

    /** Bring a skipped entry back: historical before the tracking start, else active with a new payable. */
    protected function restorePlanEntry(array $item, array $entry)
    {
        if ($entry['status'] !== 'X') {
            return $this->planError('0081', substr($entry['month'], 0, 7) . ' is not skipped.');
        }

        if (! empty($item['enddate']) && $entry['duedate'] > $item['enddate']) {
            return $this->planError('0088', substr($entry['month'], 0, 7) . ' is after the item\'s end date (' . $item['enddate'] . '). Extend the item first.');
        }

        $db = $this->db();
        $historical = $entry['month'] < $item['trackfrom'];
        $payableID = null;

        if (! $historical && $item['kind'] === 'DUE') {
            $planID = $this->planPaymentPlan($item);
            $cancelled = empty($entry['payablesessionid']) ? null
                : $db->query('SELECT sessionid FROM payable WHERE sessionid = ? AND paymentplansessionid = ? AND status = \'C\' FOR UPDATE',
                    [$entry['payablesessionid'], $planID])->getRowArray();

            if (! empty($cancelled)) {
                // reopen the month's own payable (it was untouched when skipped), so no orphan is left
                $db->table('payable')->set([
                    'status' => 'P', 'dueamount' => $this->money($entry['plannedamount']), 'paidamount' => '0.00', 'paiddate' => null,
                    'duedate' => $entry['duedate'], 'duedatetime' => strtotime($entry['duedate']), 'updateddatetime' => time(),
                ])->where('sessionid', $cancelled['sessionid'])->update();
                $payableID = $cancelled['sessionid'];
            } else {
                $payableID = $this->createPlanPayable($planID, $entry['duedate'], (float) $entry['plannedamount']);
            }

            $this->syncPlanPaymentPlan($planID);
        }

        $db->table('planentry')->set(['status' => $historical ? 'H' : 'A', 'payablesessionid' => $payableID, 'updateddatetime' => time()])
            ->where('sessionid', $entry['sessionid'])->update();

        if ($item['status'] === 'E') {
            $db->table('planitem')->set(['status' => 'A', 'updateddatetime' => time()])->where('sessionid', $item['sessionid'])->update();
        }

        return true;
    }

    /** Derived items (share of a source) follow the source's months, unless the user overrode them. */
    protected function recalcDerivedEntries(string $userID, string $sourceItemID, array $months): void
    {
        if ($months === []) {
            return;
        }

        $db = $this->db();
        $derivedItems = $db->table('planitem')->where(['usersessionid' => $userID, 'sourceplanitemsessionid' => $sourceItemID])->whereIn('status', ['A', 'E'])->get()->getResultArray();

        foreach ($derivedItems as $derived) {
            foreach ($months as $month) {
                $source = $db->table('planentry')->select('plannedamount, status')->where(['planitemsessionid' => $sourceItemID, 'month' => $month])->get()->getRowArray();
                $entry = $db->table('planentry')->where(['planitemsessionid' => $derived['sessionid'], 'month' => $month])->get()->getRowArray();

                if (empty($source) || empty($entry) || (int) $entry['overridden'] === 1 || $entry['status'] === 'X') {
                    continue;
                }

                if ($source['status'] === 'X') {
                    $this->skipPlanEntry($entry); // a part-paid share stays as it is
                    continue;
                }

                $this->setPlanEntryAmount($derived, $entry, round((float) $source['plannedamount'] * (float) $derived['shareratio'], 2));
            }
        }
    }

    /**
     * POST /js-request/plan/entry/update - change a month, optionally every
     * following month of the item that can still change.
     */
    protected function planEntryUpdate(string $userID, $entryID, $amountInput, bool $applyForward)
    {
        $amount = $this->toAmount($amountInput);

        if ($amount === null || $amount < 0 || $amount > 9999999999999) {
            return $this->planError('0080', 'Please enter an amount of zero or more.');
        }

        if (! $this->isSessionID($entryID)) {
            return $this->planError('0077', 'Please select a valid month of the plan.');
        }

        return $this->runAtomic(function () use ($userID, $entryID, $amount, $applyForward) {
            $entry = $this->ownedPlanEntry($userID, $entryID, true);

            if (isset($entry['error_id'])) {
                return $entry;
            }

            $item = $this->ownedPlanItem($userID, $entry['planitemsessionid'], true);

            if (isset($item['error_id'])) {
                return $item;
            }

            $result = $this->setPlanEntryAmount($item, $entry, $amount, true);

            if ($result !== true) {
                return $result;
            }

            $months = [$entry['month']];
            $notChanged = [];

            if ($applyForward) {
                $later = $this->db()->query(
                    'SELECT * FROM planentry WHERE planitemsessionid = ? AND month > ? AND status IN (\'A\', \'H\') ORDER BY month FOR UPDATE',
                    [$item['sessionid'], $entry['month']]
                )->getResultArray();

                foreach ($later as $next) {
                    if (round((float) $next['plannedamount'], 2) === round($amount, 2)) {
                        continue;
                    }

                    if ($this->setPlanEntryAmount($item, $next, $amount, true) === true) {
                        $months[] = $next['month'];
                    } else {
                        $notChanged[] = substr($next['month'], 0, 7);
                    }
                }

                // roll forward continues from the last entry's amount
                $this->db()->table('planitem')->set(['defaultamount' => $this->money($amount), 'updateddatetime' => time()])->where('sessionid', $item['sessionid'])->update();
            }

            $this->recalcDerivedEntries($userID, $item['sessionid'], $months);

            return ['success' => true, 'data' => ['updated' => count($months), 'not_changed' => $notChanged]];
        });
    }

    /** POST /js-request/plan/entry/skip */
    protected function planEntrySkip(string $userID, $entryID)
    {
        if (! $this->isSessionID($entryID)) {
            return $this->planError('0077', 'Please select a valid month of the plan.');
        }

        return $this->runAtomic(function () use ($userID, $entryID) {
            $entry = $this->ownedPlanEntry($userID, $entryID, true);

            if (isset($entry['error_id'])) {
                return $entry;
            }

            $result = $this->skipPlanEntry($entry);

            if ($result !== true) {
                return $result;
            }

            $this->recalcDerivedEntries($userID, $entry['planitemsessionid'], [$entry['month']]);

            return ['success' => true, 'data' => ['skipped' => substr($entry['month'], 0, 7)]];
        });
    }

    /** POST /js-request/plan/entry/restore - undo a skip */
    protected function planEntryRestore(string $userID, $entryID)
    {
        if (! $this->isSessionID($entryID)) {
            return $this->planError('0077', 'Please select a valid month of the plan.');
        }

        return $this->runAtomic(function () use ($userID, $entryID) {
            $entry = $this->ownedPlanEntry($userID, $entryID, true);

            if (isset($entry['error_id'])) {
                return $entry;
            }

            $item = $this->ownedPlanItem($userID, $entry['planitemsessionid'], true);

            if (isset($item['error_id'])) {
                return $item;
            }

            $result = $this->restorePlanEntry($item, $entry);

            if ($result !== true) {
                return $result;
            }

            return ['success' => true, 'data' => ['restored' => substr($entry['month'], 0, 7)]];
        });
    }

    /**
     * Salary advance: the employer pays $amount in $month and the next month's salary is
     * reduced by the same amount. The advance is a one-off income due, so it settles through
     * the payment ledger like any other receivable.
     */
    protected function planSalaryAdvance(string $userID, $itemID, $monthInput, $amountInput)
    {
        $amount = $this->toAmount($amountInput);

        if ($amount === null || $amount <= 0 || $amount > 9999999999) {
            return $this->planError('0090', 'Please enter an advance amount greater than zero.');
        }

        $month = $this->monthStart((string) $monthInput);

        if ($month === null || $month < $this->currentMonth()) {
            return $this->planError('0090', 'Choose the current month or a later month for the advance.');
        }

        if (! $this->isSessionID($itemID)) {
            return $this->planError('0076', 'Please select a valid salary item.');
        }

        return $this->runAtomic(function () use ($userID, $itemID, $month, $amount) {
            $item = $this->ownedPlanItem($userID, $itemID, true);

            if (isset($item['error_id'])) {
                return $item;
            }

            if ($item['direction'] !== 'IN' || $item['kind'] !== 'DUE' || ! empty($item['sourceplanitemsessionid'])) {
                return $this->planError('0090', 'A salary advance can only come from an income item that isn\'t a share.');
            }

            $nextMonth = $this->addMonths($month, 1);
            $next = $this->db()->query(
                'SELECT * FROM planentry WHERE planitemsessionid = ? AND month = ? FOR UPDATE',
                [$item['sessionid'], $nextMonth]
            )->getRowArray();

            if (empty($next) || $next['status'] === 'X') {
                return $this->planError('0090', 'No ' . $item['name'] . ' is planned for ' . substr($nextMonth, 0, 7) . ', so the advance can\'t be taken back from it.');
            }

            $nextAmount = round((float) $next['plannedamount'] - $amount, 2);

            if ($nextAmount < 0) {
                return $this->planError('0090', 'The advance is more than the ' . number_format((float) $next['plannedamount'], 2) . ' planned for ' . substr($nextMonth, 0, 7) . '.');
            }

            $result = $this->setPlanEntryAmount($item, $next, $nextAmount);

            if ($result !== true) {
                return $result;
            }

            $advance = $this->planItemCreate($userID, [
                'name'           => 'Salary advance ' . substr($month, 0, 7),
                'direction'      => 'IN',
                'kind'           => 'DUE',
                'category_id'    => (string) ($item['plancategorysessionid'] ?? ''),
                'amount_type'    => 'FIXED',
                'default_amount' => $amount,
                'due_day'        => $item['dueday'] ?? '',
                'start_month'    => substr($month, 0, 7),
                'recurring'      => false,
                'account_id'     => $item['accountsessionid'],
            ]);

            if (isset($advance['error_id'])) {
                return $advance;
            }

            return ['success' => true, 'data' => [
                'advance_item_id'   => $advance['data']['item_id'],
                'next_month_amount' => $nextAmount,
            ]];
        });
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    protected function planCategories(string $userID): array
    {
        return $this->db()->table('plancategory')->where(['usersessionid' => $userID, 'status' => 'A'])
            ->orderBy('direction', 'ASC')->orderBy('sortorder', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
    }

    protected function planCategoryInput(array $in, bool $isUpdate)
    {
        $clean = [];

        if (! $isUpdate || array_key_exists('name', $in)) {
            $name = trim((string) ($in['name'] ?? ''));

            if ($name === '' || mb_strlen($name) > 50) {
                return $this->planError('0089', 'Category name is required (max 50 characters).');
            }
            $clean['name'] = $name;
        }

        if (array_key_exists('allocation_percent', $in)) {
            $raw = trim((string) $in['allocation_percent']);
            $percent = ($raw === '') ? null : $this->toAmount($raw);

            if ($raw !== '' && ($percent === null || $percent < 0 || $percent > 100)) {
                return $this->planError('0089', 'Allocation must be between 0 and 100 %, or empty for no target.');
            }
            $clean['allocationpercent'] = $percent;
        }

        if (! $isUpdate) {
            $clean['direction'] = (($in['direction'] ?? 'OUT') === 'IN') ? 'IN' : 'OUT';
        }

        return $clean;
    }

    protected function planCategoryCreate(string $userID, array $in)
    {
        $clean = $this->planCategoryInput($in, false);

        if (isset($clean['error_id'])) {
            return $clean;
        }

        $db = $this->db();

        if ($db->table('plancategory')->where(['usersessionid' => $userID, 'name' => $clean['name']])->countAllResults() > 0) {
            return $this->planError('0089', 'A category named "' . $clean['name'] . '" already exists.');
        }

        $order = (int) ($db->query('SELECT COALESCE(MAX(sortorder), 0) + 1 AS n FROM plancategory WHERE usersessionid = ?', [$userID])->getRow()->n);
        $now = time();
        $db->table('plancategory')->insert($clean + ['usersessionid' => $userID, 'sortorder' => $order, 'status' => 'A', 'createddatetime' => $now, 'updateddatetime' => $now]);

        return ['success' => true, 'data' => ['category_id' => $db->table('plancategory')->select('sessionid')->where('id', $db->insertID())->get()->getRow()->sessionid]];
    }

    /** POST /js-request/plan/category/update - name and / or allocation % */
    protected function planCategoryUpdate(string $userID, $categoryID, array $in)
    {
        $category = $this->ownedPlanCategory($userID, $categoryID);

        if (isset($category['error_id'])) {
            return $category;
        }

        $clean = $this->planCategoryInput($in, true);

        if (isset($clean['error_id'])) {
            return $clean;
        }

        if ($clean === []) {
            return $this->planError('0089', 'Nothing to change.');
        }

        $db = $this->db();

        if (isset($clean['name']) && $db->table('plancategory')->where(['usersessionid' => $userID, 'name' => $clean['name']])->where('id <>', $category['id'])->countAllResults() > 0) {
            return $this->planError('0089', 'A category named "' . $clean['name'] . '" already exists.');
        }

        $db->table('plancategory')->set($clean + ['updateddatetime' => time()])->where('id', $category['id'])->update();

        return ['success' => true, 'data' => ['category_id' => $category['sessionid']]];
    }

    // ------------------------------------------------------------------
    // Items: create / update / end / delete
    // ------------------------------------------------------------------

    /** Items with their category, account, method, budget and source names. */
    protected function planItemList(string $userID): array
    {
        return $this->db()->query(
            'SELECT i.*, c.name AS categoryname, c.direction AS categorydirection, c.allocationpercent AS categorypercent, a.name AS accountname, ta.name AS toaccountname,
                    pm.name AS methodname, pm.type AS methodtype, pm.reference AS methodreference, b.name AS budgetname, s.name AS sourcename,
                    (SELECT MAX(e.month) FROM planentry e WHERE e.planitemsessionid = i.sessionid AND e.status <> \'X\') AS lastmonth,
                    (SELECT COUNT(*) FROM planentry e JOIN payable p ON p.sessionid = e.payablesessionid
                      WHERE e.planitemsessionid = i.sessionid AND p.status IN (\'P\', \'T\')) AS opendues,
                    (SELECT COUNT(*) FROM planentry e JOIN payable p ON p.sessionid = e.payablesessionid
                      WHERE e.planitemsessionid = i.sessionid AND (p.paidamount > 0 OR p.status = \'S\')) AS paiddues
               FROM planitem i
          LEFT JOIN plancategory c ON c.sessionid = i.plancategorysessionid AND c.usersessionid = i.usersessionid
          LEFT JOIN account a ON a.sessionid = i.accountsessionid AND a.usersessionid = i.usersessionid
          LEFT JOIN account ta ON ta.sessionid = i.toaccountsessionid AND ta.usersessionid = i.usersessionid
          LEFT JOIN paymentmethod pm ON pm.sessionid = i.paymentmethodsessionid AND pm.usersessionid = i.usersessionid
          LEFT JOIN budget b ON b.sessionid = i.budgetsessionid AND b.usersessionid = i.usersessionid
          LEFT JOIN planitem s ON s.sessionid = i.sourceplanitemsessionid AND s.usersessionid = i.usersessionid
              WHERE i.usersessionid = ? AND i.status <> \'D\'
           ORDER BY (i.direction <> \'IN\'), c.sortorder IS NULL, c.sortorder, i.sortorder, i.name',
            [$userID]
        )->getResultArray();
    }

    /**
     * Validate item form input. $existing is the item being updated (direction,
     * kind and start month are fixed once it exists). Returns the column values.
     */
    protected function planItemInput(string $userID, array $in, ?array $existing)
    {
        $field = static function (string $key) use ($in) {
            return trim((string) ($in[$key] ?? ''));
        };
        $has = static function (string $key) use ($in, $existing) {
            return $existing === null || array_key_exists($key, $in);
        };
        $row = [];

        if ($has('name')) {
            if ($field('name') === '' || mb_strlen($field('name')) > 100) {
                return $this->planError('0082', 'Item name is required (max 100 characters).');
            }
            $row['name'] = $field('name');
        }

        $direction = $existing['direction'] ?? strtoupper($field('direction'));
        $kind = $existing['kind'] ?? strtoupper($field('kind') ?: 'DUE');

        if ($existing !== null && ((array_key_exists('direction', $in) && strtoupper($field('direction')) !== $existing['direction'])
                || (array_key_exists('kind', $in) && strtoupper($field('kind')) !== $existing['kind']))) {
            return $this->planError('0082', 'Direction and kind can\'t change once an item exists. End it and add a new item instead.');
        }

        if (! in_array($direction, $this->planDirections, true) || ! in_array($kind, $this->planKinds, true)) {
            return $this->planError('0082', 'Please choose whether the item is income, a payment or a transfer.');
        }

        if ($kind === 'ENVELOPE' && $direction !== 'OUT') {
            return $this->planError('0082', 'Only payments can be spending envelopes.');
        }

        if ($existing === null) {
            $row['direction'] = $direction;
            $row['kind'] = $kind;
            $start = $this->monthStart($field('start_month'));

            if ($start === null) {
                return $this->planError('0082', 'Please choose the first month of the item.');
            }
            $row['startmonth'] = $start;
        }

        if ($has('weekend_rule')) {
            $rule = strtoupper($field('weekend_rule')) ?: 'NONE';
            $ruleDirection = $existing['direction'] ?? strtoupper($field('direction'));

            if (! in_array($rule, ['NONE', 'PREV', 'NEXT'], true)) {
                return $this->planError('0082', 'The weekend rule must be none, the previous working day or the next working day.');
            }

            if ($rule !== 'NONE' && $ruleDirection !== 'IN') {
                return $this->planError('0082', 'Only income can move a due that falls on a weekend.');
            }

            $row['weekendrule'] = $rule;
        }

        if ($has('category_id')) {
            $row['plancategorysessionid'] = null;
            if ($field('category_id') !== '') {
                $category = $this->ownedPlanCategory($userID, $field('category_id'));
                if (isset($category['error_id'])) {
                    return $category;
                }
                $row['plancategorysessionid'] = $category['sessionid'];
            }
        }

        if ($has('amount_type')) {
            $row['amounttype'] = in_array(strtoupper($field('amount_type')), $this->planAmountTypes, true) ? strtoupper($field('amount_type')) : 'FIXED';
        }

        if ($has('default_amount')) {
            $amount = $this->toAmount($field('default_amount'));
            if ($amount === null || $amount < 0 || $amount > 9999999999999) {
                return $this->planError('0082', 'The monthly amount must be zero or more.');
            }
            $row['defaultamount'] = $this->money($amount);
        }

        if ($has('due_day')) {
            $row['dueday'] = null;
            if ($field('due_day') !== '') {
                if (! ctype_digit($field('due_day')) || (int) $field('due_day') < 1 || (int) $field('due_day') > 31) {
                    return $this->planError('0082', 'The due day must be between 1 and 31 (empty = last day of the month).');
                }
                $row['dueday'] = (int) $field('due_day');
            }
        }

        if ($has('end_date')) {
            $row['enddate'] = null;
            if ($field('end_date') !== '') {
                $end = $this->validDate($field('end_date'));
                $start = $row['startmonth'] ?? $existing['startmonth'] ?? null;
                if ($end === null || ($start !== null && $end < $start)) {
                    return $this->planError('0088', 'The end date must be a valid date on or after the first month.');
                }
                $row['enddate'] = $end;
            }
        }

        if ($has('recurring')) {
            $row['recurring'] = filter_var($in['recurring'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        foreach (['stakeholder' => 60, 'notes' => 255] as $key => $max) {
            if ($has($key)) {
                $row[$key] = ($field($key) === '') ? null : mb_substr($field($key), 0, $max);
            }
        }

        // ---- account, method, to-account, budget ----
        $accountID = $has('account_id') ? $field('account_id') : (string) ($existing['accountsessionid'] ?? '');

        if ($accountID !== '') {
            $account = $this->getOwnedAccount($userID, $accountID);
            if (isset($account['error_id'])) {
                return $account;
            }
        }
        $row['accountsessionid'] = ($accountID === '') ? null : $accountID;

        $methodID = $has('payment_method') ? $field('payment_method') : (string) ($existing['paymentmethodsessionid'] ?? '');

        if ($methodID !== '') {
            if ($accountID === '') {
                return $this->planError('0083', 'Choose the account before its payment method.');
            }
            $method = $this->resolvePaymentMethod($userID, $accountID, $methodID);
            if (isset($method['error_id'])) {
                return $this->planError('0083', $method['error_message']);
            }
        }
        $row['paymentmethodsessionid'] = ($methodID === '') ? null : $methodID;

        $toAccountID = ($direction === 'TRANSFER') ? ($has('to_account_id') ? $field('to_account_id') : (string) ($existing['toaccountsessionid'] ?? '')) : '';

        if ($direction === 'TRANSFER') {
            if ($accountID === '' || $toAccountID === '') {
                return $this->planError('0084', 'A transfer needs both the account it comes from and the account it goes to.');
            }
            if ($toAccountID === $accountID) {
                return $this->planError('0084', 'The transfer must go to a different account.');
            }
            $toAccount = $this->getOwnedAccount($userID, $toAccountID);
            if (isset($toAccount['error_id'])) {
                return $toAccount;
            }
        }
        $row['toaccountsessionid'] = ($toAccountID === '') ? null : $toAccountID;

        $budgetID = $has('budget_id') ? $field('budget_id') : (string) ($existing['budgetsessionid'] ?? '');

        if ($budgetID !== '') {
            $budget = $this->ownedBudget($userID, $budgetID);
            if (isset($budget['error_id'])) {
                return $budget;
            }
        } elseif ($kind === 'ENVELOPE') {
            return $this->planError('0085', 'A spending envelope needs a budget: its spending is the expenses tagged with that budget.');
        }
        $row['budgetsessionid'] = ($budgetID === '') ? null : $budgetID;

        // ---- derived share of another item ----
        if ($has('source_item_id')) {
            $row['sourceplanitemsessionid'] = null;
            $row['shareratio'] = null;

            if ($field('source_item_id') !== '') {
                $source = $this->ownedPlanItem($userID, $field('source_item_id'));
                if (isset($source['error_id'])) {
                    return $source;
                }
                if ($existing !== null && $source['sessionid'] === $existing['sessionid']) {
                    return $this->planError('0087', 'An item can\'t be a share of itself.');
                }
                if (! empty($source['sourceplanitemsessionid'])) {
                    return $this->planError('0087', '"' . $source['name'] . '" is itself a share of another item; pick the original item.');
                }
                if ($existing !== null && $this->db()->table('planitem')->where(['sourceplanitemsessionid' => $existing['sessionid']])->where('status <>', 'D')->countAllResults() > 0) {
                    return $this->planError('0087', 'Other items are a share of this one, so it can\'t be a share itself.');
                }
                $ratio = ($field('share_ratio') === '') ? null : round((float) str_replace(',', '', $field('share_ratio')), 4);
                if ($ratio === null || $ratio <= 0 || $ratio > 10) {
                    return $this->planError('0087', 'The share must be a ratio above 0 (0.5 = half).');
                }
                $row['sourceplanitemsessionid'] = $source['sessionid'];
                $row['shareratio'] = $ratio;
            }
        }

        return $row;
    }

    /** Unique item key (slug) for the user. */
    protected function newPlanItemKey(string $userID, string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base === '' ? 'item' : $base, 0, 50);
        $key = $base;

        for ($n = 2; $this->db()->table('planitem')->where(['usersessionid' => $userID, 'itemkey' => $key])->countAllResults() > 0; $n++) {
            $key = $base . '-' . $n;
        }

        return $key;
    }

    /** First months of a new item: its start month, and up to the horizon when it repeats. */
    protected function generatePlanItemEntries(array &$item): int
    {
        $db = $this->db();
        $horizon = $this->addMonths($this->currentMonth(), $this->planHorizonMonths);
        $last = ((int) $item['recurring'] === 1) ? max($horizon, $item['startmonth']) : $item['startmonth'];
        $created = 0;

        for ($month = $item['startmonth']; $month <= $last; $month = $this->addMonths($month, 1)) {
            $amount = (float) $item['defaultamount'];

            if (! empty($item['sourceplanitemsessionid'])) {
                $source = $db->table('planentry')->select('plannedamount, status')
                    ->where(['planitemsessionid' => $item['sourceplanitemsessionid'], 'month' => $month])->get()->getRowArray();

                if (empty($source) || $source['status'] === 'X') {
                    continue;
                }
                $amount = round((float) $source['plannedamount'] * (float) $item['shareratio'], 2);
            }

            $result = $this->createPlanEntry($item, $month, $amount);

            if ($result === 'trimmed') {
                break;
            }
            $created += (strpos($result, 'created') === 0) ? 1 : 0;
        }

        return $created;
    }

    /** POST /js-request/plan/item/create */
    protected function planItemCreate(string $userID, array $in)
    {
        $row = $this->planItemInput($userID, $in, null);

        if (isset($row['error_id'])) {
            return $row;
        }

        return $this->runAtomic(function () use ($userID, $row) {
            $db = $this->db();
            $now = time();

            $row += [
                'usersessionid' => $userID,
                'itemkey'       => $this->newPlanItemKey($userID, $row['name']),
                'trackfrom'     => max($row['startmonth'], $this->currentMonth()),
                'sortorder'     => (int) $db->query('SELECT COALESCE(MAX(sortorder), 0) + 1 AS n FROM planitem WHERE usersessionid = ?', [$userID])->getRow()->n,
                'status'        => 'A',
                'createddatetime' => $now,
                'updateddatetime' => $now,
            ];

            $db->table('planitem')->insert($row);
            $item = $db->table('planitem')->where('id', $db->insertID())->get()->getRowArray();
            $created = $this->generatePlanItemEntries($item);

            return ['success' => true, 'data' => ['item_id' => $item['sessionid'], 'entries_created' => $created]];
        });
    }

    /** POST /js-request/plan/item/update */
    protected function planItemUpdate(string $userID, $itemID, array $in)
    {
        if (! $this->isSessionID($itemID)) {
            return $this->planError('0076', 'Please select a valid plan item.');
        }

        return $this->runAtomic(function () use ($userID, $itemID, $in) {
            $item = $this->ownedPlanItem($userID, $itemID, true);

            if (isset($item['error_id'])) {
                return $item;
            }

            $row = $this->planItemInput($userID, $in, $item);

            if (isset($row['error_id'])) {
                return $row;
            }

            $db = $this->db();
            $now = time();
            $newEnd = array_key_exists('enddate', $row) ? $row['enddate'] : $item['enddate'];
            unset($row['enddate']); // end date changes go through endPlanItem() / extension below

            $db->table('planitem')->set($row + ['updateddatetime' => $now])->where('sessionid', $item['sessionid'])->update();

            if (! empty($item['paymentplansessionid']) && isset($row['name']) && $row['name'] !== $item['name']) {
                $db->table('paymentplan')->set(['description' => mb_substr($row['name'], 0, 100), 'updateddatetime' => $now])->where('sessionid', $item['paymentplansessionid'])->update();
            }

            // due day or weekend rule moved: open months move with it
            $newDay = array_key_exists('dueday', $row) ? $row['dueday'] : $item['dueday'];
            $newRule = $row['weekendrule'] ?? ($item['weekendrule'] ?? 'NONE');

            if ((string) $newDay !== (string) $item['dueday'] || $newRule !== ($item['weekendrule'] ?? 'NONE')) {
                $entries = $db->query('SELECT e.sessionid, e.month, e.payablesessionid, p.status AS payablestatus FROM planentry e
                                    LEFT JOIN payable p ON p.sessionid = e.payablesessionid
                                        WHERE e.planitemsessionid = ? AND e.status IN (\'A\', \'H\') FOR UPDATE', [$item['sessionid']])->getResultArray();

                foreach ($entries as $entry) {
                    if ($entry['payablesessionid'] !== null && ! in_array($entry['payablestatus'], ['P', 'T'], true)) {
                        continue;
                    }
                    $due = $this->planWeekendAdjusted($this->planDueDate($entry['month'], $newDay), $newRule);
                    $db->table('planentry')->set(['duedate' => $due, 'updateddatetime' => $now])->where('sessionid', $entry['sessionid'])->update();
                    if ($entry['payablesessionid'] !== null) {
                        $db->table('payable')->set(['duedate' => $due, 'duedatetime' => strtotime($due), 'updateddatetime' => $now])->where('sessionid', $entry['payablesessionid'])->update();
                    }
                }

                if (! empty($item['paymentplansessionid'])) {
                    $this->syncPlanPaymentPlan($item['paymentplansessionid']);
                }
            }

            $item = $db->table('planitem')->where('sessionid', $item['sessionid'])->get()->getRowArray();

            // share changed: open months of this derived item follow the source again
            if (array_key_exists('shareratio', $row) && ! empty($item['sourceplanitemsessionid'])) {
                $months = array_column($db->table('planentry')->select('month')->where('planitemsessionid', $item['sessionid'])
                    ->where('month >=', $this->currentMonth())->get()->getResultArray(), 'month');
                $db->table('planentry')->set('overridden', 0)->where('planitemsessionid', $item['sessionid'])->where('month >=', $this->currentMonth())->update();
                $this->recalcDerivedEntries($userID, $item['sourceplanitemsessionid'], $months);
            }

            $report = ['skipped' => [], 'blocked' => [], 'created' => 0];

            if ($newEnd !== $item['enddate']) {
                if ($newEnd !== null && ($item['enddate'] === null || $newEnd < $item['enddate'])) {
                    $ended = $this->endPlanItem($userID, $item, $newEnd);
                    if (isset($ended['error_id'])) {
                        return $ended;
                    }
                    $report = $ended + $report;
                } else {
                    // extended or end removed: active again, new months generated
                    $db->table('planitem')->set(['enddate' => $newEnd, 'status' => 'A', 'updateddatetime' => $now])->where('sessionid', $item['sessionid'])->update();
                    $oldEnd = $item['enddate'];
                    $item['enddate'] = $newEnd;
                    $item['status'] = 'A';

                    // months the old end skipped come back
                    if ($oldEnd !== null) {
                        $reopen = $db->query('SELECT * FROM planentry WHERE planitemsessionid = ? AND status = \'X\' AND duedate > ?' . ($newEnd !== null ? ' AND duedate <= ?' : '') . ' ORDER BY month FOR UPDATE',
                            $newEnd !== null ? [$item['sessionid'], $oldEnd, $newEnd] : [$item['sessionid'], $oldEnd])->getResultArray();

                        foreach ($reopen as $entry) {
                            $this->restorePlanEntry($item, $entry);
                            $item = $db->table('planitem')->where('sessionid', $item['sessionid'])->get()->getRowArray(); // may now own a paymentplan
                        }
                    }
                }
            }

            $item = $db->table('planitem')->where('sessionid', $item['sessionid'])->get()->getRowArray();
            $report['created'] = $this->rollPlanItem($item, $this->addMonths($this->currentMonth(), $this->planHorizonMonths));
            $this->closeEndedPlanItems($userID);

            return ['success' => true, 'data' => ['item_id' => $item['sessionid']] + $report];
        });
    }

    /**
     * Set (or shorten) an item's end: months after it are skipped. A part-paid
     * month can't be skipped and is reported in 'blocked'.
     */
    protected function endPlanItem(string $userID, array $item, string $endDate): array
    {
        $db = $this->db();

        // a settled month can't fall after the end
        $settled = $db->query(
            'SELECT MAX(e.duedate) AS lastpaid FROM planentry e JOIN payable p ON p.sessionid = e.payablesessionid
              WHERE e.planitemsessionid = ? AND e.status = \'A\' AND p.status = \'S\' AND e.duedate > ?',
            [$item['sessionid'], $endDate]
        )->getRow()->lastpaid;

        if ($settled !== null) {
            return $this->planError('0088', 'The month due ' . $settled . ' is already settled. End "' . $item['name'] . '" on or after that date.');
        }

        $db->table('planitem')->set(['enddate' => $endDate, 'updateddatetime' => time()])->where('sessionid', $item['sessionid'])->update();

        $skipped = [];
        $blocked = [];
        $later = $db->query('SELECT * FROM planentry WHERE planitemsessionid = ? AND duedate > ? AND status IN (\'A\', \'H\') ORDER BY month FOR UPDATE',
            [$item['sessionid'], $endDate])->getResultArray();

        foreach ($later as $entry) {
            $result = $this->skipPlanEntry($entry);

            if ($result === true) {
                $skipped[] = substr($entry['month'], 0, 7);
                $this->recalcDerivedEntries($userID, $item['sessionid'], [$entry['month']]);
            } else {
                $blocked[] = $result['error_message'];
            }
        }

        $this->closeEndedPlanItems($userID);

        return ['skipped' => $skipped, 'blocked' => $blocked];
    }

    /** POST /js-request/plan/item/end */
    protected function planItemEnd(string $userID, $itemID, $endDate)
    {
        $endDate = $this->validDate((string) $endDate);

        if ($endDate === null) {
            return $this->planError('0088', 'Please choose the last date of the item.');
        }

        if (! $this->isSessionID($itemID)) {
            return $this->planError('0076', 'Please select a valid plan item.');
        }

        return $this->runAtomic(function () use ($userID, $itemID, $endDate) {
            $item = $this->ownedPlanItem($userID, $itemID, true);

            if (isset($item['error_id'])) {
                return $item;
            }

            if ($endDate < (string) $item['startmonth']) {
                return $this->planError('0088', 'The end date can\'t be before the item\'s first month (' . substr((string) $item['startmonth'], 0, 7) . '). Delete the item instead.');
            }

            $ended = $this->endPlanItem($userID, $item, $endDate);

            return isset($ended['error_id']) ? $ended : ['success' => true, 'data' => ['item_id' => $item['sessionid']] + $ended];
        });
    }

    /** POST /js-request/plan/item/delete - only when nothing on it was ever paid. */
    protected function planItemDelete(string $userID, $itemID)
    {
        if (! $this->isSessionID($itemID)) {
            return $this->planError('0076', 'Please select a valid plan item.');
        }

        return $this->runAtomic(function () use ($userID, $itemID) {
            $item = $this->ownedPlanItem($userID, $itemID, true);

            if (isset($item['error_id'])) {
                return $item;
            }

            $db = $this->db();

            $derived = $db->table('planitem')->select('name')->where(['usersessionid' => $userID, 'sourceplanitemsessionid' => $item['sessionid']])->where('status <>', 'D')->get()->getResultArray();
            if ($derived !== []) {
                return $this->planError('0087', '"' . $derived[0]['name'] . '" is a share of this item. Delete or change it first.');
            }

            if (! empty($item['paymentplansessionid'])) {
                $paid = (int) $db->query(
                    'SELECT COUNT(*) AS n FROM payable p WHERE p.paymentplansessionid = ? AND (p.paidamount > 0 OR p.status IN (\'S\', \'T\')
                        OR EXISTS (SELECT 1 FROM duepaid d WHERE d.payablesessionid = p.sessionid))',
                    [$item['paymentplansessionid']]
                )->getRow()->n;

                if ($paid > 0) {
                    return $this->planError('0086', 'Payments were already made on "' . $item['name'] . '", so it can\'t be deleted. End it instead.');
                }

                $db->table('payable')->where('paymentplansessionid', $item['paymentplansessionid'])->delete();
                $db->table('paymentplan')->where(['sessionid' => $item['paymentplansessionid'], 'usersessionid' => $userID, 'paymentplanlink' => 'planitem'])->delete();
            }

            $db->table('planentry')->where(['planitemsessionid' => $item['sessionid'], 'usersessionid' => $userID])->delete();
            // the key is freed so the item can be added (or imported) again
            $db->table('planitem')->set([
                'status' => 'D', 'paymentplansessionid' => null, 'updateddatetime' => time(),
                'itemkey' => substr($item['itemkey'], 0, 40) . '-deleted-' . $item['id'],
            ])->where('sessionid', $item['sessionid'])->update();

            return ['success' => true, 'data' => ['item_id' => $item['sessionid']]];
        });
    }

    // ------------------------------------------------------------------
    // Year grid, month view, card projection, allocation
    // ------------------------------------------------------------------

    /** Grid cell state: settled, partial, overdue, upcoming, historical, skipped, envelope. */
    protected function planCellState(array $entry, string $today): string
    {
        if ($entry['status'] === 'H') {
            return 'historical';
        }
        if ($entry['status'] === 'X') {
            return 'skipped';
        }
        if (empty($entry['payablesessionid'])) {
            return 'envelope';
        }

        switch ($entry['payablestatus']) {
            case 'S':
                return 'settled';
            case 'T':
                return ($entry['duedate'] < $today) ? 'overdue' : 'partial';
            default:
                return ($entry['duedate'] < $today) ? 'overdue' : 'upcoming';
        }
    }

    /** Can the cell's amount still change (edit / skip)? */
    protected function planCellEditable(array $entry): bool
    {
        return $entry['status'] !== 'X' && (empty($entry['payablesessionid']) || in_array($entry['payablestatus'], ['P', 'T'], true));
    }

    /** Entries of the user between two month starts, with payable and item fields. */
    protected function planEntriesBetween(string $userID, string $from, string $to): array
    {
        return $this->db()->query(
            'SELECT e.sessionid, e.planitemsessionid, e.month, e.plannedamount, e.duedate, e.status, e.overridden,
                    e.paymentmethodsessionid AS entrymethod, e.payablesessionid,
                    p.status AS payablestatus, p.dueamount, COALESCE(p.paidamount, 0) AS paidamount, p.paiddate,
                    i.direction, i.kind, i.budgetsessionid, i.accountsessionid, i.toaccountsessionid,
                    i.paymentmethodsessionid AS itemmethod
               FROM planentry e
               JOIN planitem i ON i.sessionid = e.planitemsessionid AND i.usersessionid = e.usersessionid
          LEFT JOIN payable p ON p.sessionid = e.payablesessionid
              WHERE e.usersessionid = ? AND i.status <> \'D\' AND e.month BETWEEN ? AND ?
           ORDER BY e.month',
            [$userID, $from, $to]
        )->getResultArray();
    }

    /**
     * Envelope spending: expenses tagged with the budget, by month. Transfer
     * legs and due settlements are left out (they are counted as dues).
     * Returns [budget id][YYYY-MM] => spent.
     */
    protected function planEnvelopeActuals(string $userID, array $budgetIDs, string $from, string $to): array
    {
        $budgetIDs = array_values(array_unique(array_filter($budgetIDs, function ($id) {
            return $this->isSessionID($id);
        })));

        if ($budgetIDs === []) {
            return [];
        }

        $rows = $this->db()->query(
            'SELECT t.budgetsessionid AS budget, DATE_FORMAT(t.date, \'%Y-%m\') AS ym, SUM(t.amount) AS spent
               FROM transaction t
               JOIN account a ON a.sessionid = t.accountsessionid AND a.usersessionid = ?
          LEFT JOIN payment pay ON pay.sessionid = t.paymentsessionid
              WHERE t.status = \'A\' AND t.paymenttype = \'expense\'
                AND t.budgetsessionid IN (' . implode(',', array_fill(0, count($budgetIDs), '?')) . ')
                AND t.date >= ? AND t.date < ?
                AND (pay.sessionid IS NULL OR (pay.direction <> \'TRANSFER\' AND pay.category NOT IN (\'settlement\', \'transfer\')))
           GROUP BY t.budgetsessionid, ym',
            array_merge([$userID], $budgetIDs, [$from, $this->addMonths($to, 1)])
        )->getResultArray();

        $actuals = [];
        foreach ($rows as $row) {
            $actuals[$row['budget']][$row['ym']] = round((float) $row['spent'], 2);
        }

        return $actuals;
    }

    /** Planned / settled / actual totals per month for a list of entries (A and H; X excluded). */
    protected function planMonthTotals(array $entries, array $envelopeActuals, array $months): array
    {
        $totals = [];
        foreach ($months as $ym) {
            $totals[$ym] = ['income' => 0.0, 'outflow' => 0.0, 'settled_in' => 0.0, 'settled_out' => 0.0, 'envelope_actual' => 0.0, 'tracked' => false];
        }

        $envelopeSeen = [];

        foreach ($entries as $entry) {
            $ym = substr($entry['month'], 0, 7);

            if (! isset($totals[$ym]) || $entry['status'] === 'X') {
                continue;
            }

            $amount = (float) $entry['plannedamount'];
            $paid = (float) $entry['paidamount'];

            if ($entry['direction'] === 'IN') {
                $totals[$ym]['income'] += $amount;
                $totals[$ym]['settled_in'] += $paid;
            } else {
                $totals[$ym]['outflow'] += $amount;
                $totals[$ym]['settled_out'] += $paid;
            }

            if ($entry['status'] === 'A') {
                $totals[$ym]['tracked'] = true;

                // an envelope's spending counts once per budget and month
                if ($entry['kind'] === 'ENVELOPE' && ! empty($entry['budgetsessionid']) && ! isset($envelopeSeen[$entry['budgetsessionid']][$ym])) {
                    $envelopeSeen[$entry['budgetsessionid']][$ym] = true;
                    $totals[$ym]['envelope_actual'] += $envelopeActuals[$entry['budgetsessionid']][$ym] ?? 0.0;
                }
            }
        }

        $current = date('Y-m');
        $cumPlanned = 0.0;
        $cumActual = 0.0;

        foreach ($totals as $ym => $t) {
            $t = array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $t);
            $t['net'] = round($t['income'] - $t['outflow'], 2);
            // actuals exist for tracked months up to now
            $t['has_actual'] = $t['tracked'] && $ym <= $current;
            $t['actual_net'] = $t['has_actual'] ? round($t['settled_in'] - $t['settled_out'] - $t['envelope_actual'], 2) : null;
            $cumPlanned = round($cumPlanned + $t['net'], 2);
            $t['cum_planned'] = $cumPlanned;
            if ($t['has_actual']) {
                $cumActual = round($cumActual + $t['actual_net'], 2);
            }
            $t['cum_actual'] = $t['has_actual'] ? $cumActual : null;
            $totals[$ym] = $t;
        }

        return $totals;
    }

    /** Lazy roll forward when a page of the plan is opened. */
    protected function planRollOnOpen(string $userID): void
    {
        $this->runAtomic(function () use ($userID) {
            return ['success' => true, 'created' => $this->rollPlanForward($userID)];
        });
    }

    /** Year grid: rows grouped Income -> each category, cells per month, footer totals. */
    protected function planYearGrid(string $userID, int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = sprintf('%04d-%02d', $year, $m);
        }

        $today = date('Y-m-d');
        $items = $this->planItemList($userID);
        $entries = $this->planEntriesBetween($userID, $year . '-01-01', $year . '-12-01');
        $budgets = array_column(array_filter($items, static fn ($i) => $i['kind'] === 'ENVELOPE'), 'budgetsessionid');
        $actuals = $this->planEnvelopeActuals($userID, $budgets, $year . '-01-01', $year . '-12-01');

        $byItem = [];
        foreach ($entries as $entry) {
            $entry['state'] = $this->planCellState($entry, $today);
            $entry['editable'] = $this->planCellEditable($entry);
            $entry['outstanding'] = empty($entry['payablesessionid']) || $entry['payablestatus'] === 'C'
                ? 0.0 : round((float) $entry['dueamount'] - (float) $entry['paidamount'], 2);
            if ($entry['kind'] === 'ENVELOPE') {
                $entry['spent'] = $actuals[$entry['budgetsessionid']][substr($entry['month'], 0, 7)] ?? 0.0;
            }
            $byItem[$entry['planitemsessionid']][substr($entry['month'], 0, 7)] = $entry;
        }

        $groups = [];
        foreach ($items as $item) {
            $cells = $byItem[$item['sessionid']] ?? [];

            // items with nothing in this year and nothing ahead are left out
            if ($cells === [] && ($item['status'] !== 'A' || ($item['lastmonth'] !== null && $item['lastmonth'] < $year . '-01-01')
                    || ($item['startmonth'] !== null && $item['startmonth'] > $year . '-12-01'))) {
                continue;
            }

            $key = ($item['direction'] === 'IN') ? 'income' : ($item['plancategorysessionid'] ?: 'none');

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'label' => ($item['direction'] === 'IN') ? 'Income' : ($item['categoryname'] ?: 'Uncategorised'),
                    'direction' => ($item['direction'] === 'IN') ? 'IN' : 'OUT',
                    'items' => [],
                ];
            }

            $yearTotal = 0.0;
            foreach ($cells as $cell) {
                $yearTotal += ($cell['status'] === 'X') ? 0 : (float) $cell['plannedamount'];
            }

            $groups[$key]['items'][] = ['item' => $item, 'cells' => $cells, 'year_total' => round($yearTotal, 2)];
        }

        // Income first, then the categories in their order, uncategorised last
        uksort($groups, static function ($a, $b) {
            $rank = static fn ($k) => $k === 'income' ? 0 : ($k === 'none' ? 2 : 1);
            return $rank($a) <=> $rank($b);
        });

        return [
            'year'   => $year,
            'months' => $months,
            'groups' => $groups,
            'totals' => $this->planMonthTotals($entries, $actuals, $months),
            'cards'  => $this->planCardProjection($userID, $entries, $months),
        ];
    }

    /** Default method of every account: same order as getDefaultPaymentMethod() (enum 'Y' sorts before 'N'). */
    protected function planDefaultMethods(string $userID): array
    {
        $defaults = [];
        $rows = $this->db()->query('SELECT accountsessionid, sessionid FROM paymentmethod WHERE usersessionid = ? AND status = \'A\' ORDER BY isdefault ASC, id ASC', [$userID])->getResultArray();

        foreach ($rows as $row) {
            $defaults[$row['accountsessionid']] = $defaults[$row['accountsessionid']] ?? $row['sessionid'];
        }

        return $defaults;
    }

    /**
     * Card settlement projection (sheet rows 44-47): per credit card method and
     * month, planned outflow whose effective method (entry override, else item
     * method, else the item account's default) is the card, plus transfers into
     * the card's account (arrears pay-down).
     */
    protected function planCardProjection(string $userID, array $entries, array $months): array
    {
        $db = $this->db();
        $cards = $db->query(
            'SELECT pm.sessionid, pm.name, pm.reference, pm.creditlimit, pm.isdefault, a.sessionid AS accountid, a.name AS accountname
               FROM paymentmethod pm JOIN account a ON a.sessionid = pm.accountsessionid AND a.usersessionid = pm.usersessionid
              WHERE pm.usersessionid = ? AND pm.type = \'CREDIT_CARD\' AND pm.status = \'A\' AND a.status = \'A\'
           ORDER BY a.name, pm.isdefault, pm.name',
            [$userID]
        )->getResultArray();

        if ($cards === []) {
            return [];
        }

        $defaults = $this->planDefaultMethods($userID);

        $projection = [];
        $cardOfAccount = [];
        foreach ($cards as $card) {
            $projection[$card['sessionid']] = [
                'label'   => $card['accountname'] . ' / ' . $card['name'] . ($card['reference'] ? ' ••' . $card['reference'] : ''),
                'account' => $card['accountid'],
                'limit'   => round((float) $card['creditlimit'], 2),
                'months'  => array_fill_keys($months, 0.0),
                'total'   => 0.0,
            ];
            // transfers into a card account pay down its default card (else its first card)
            if (! isset($cardOfAccount[$card['accountid']]) || ($defaults[$card['accountid']] ?? null) === $card['sessionid']) {
                $cardOfAccount[$card['accountid']] = $card['sessionid'];
            }
        }

        foreach ($entries as $entry) {
            $ym = substr($entry['month'], 0, 7);

            if ($entry['status'] === 'X' || $entry['direction'] === 'IN' || ! in_array($ym, $months, true)) {
                continue;
            }

            $amount = (float) $entry['plannedamount'];
            $method = $entry['entrymethod'] ?: ($entry['itemmethod'] ?: ($defaults[$entry['accountsessionid']] ?? null));

            if ($method !== null && isset($projection[$method])) {
                $projection[$method]['months'][$ym] += $amount;
            }

            if ($entry['direction'] === 'TRANSFER' && ! empty($entry['toaccountsessionid']) && isset($cardOfAccount[$entry['toaccountsessionid']])) {
                $projection[$cardOfAccount[$entry['toaccountsessionid']]]['months'][$ym] += $amount;
            }
        }

        foreach ($projection as $id => $card) {
            $projection[$id]['months'] = array_map(static fn ($v) => round($v, 2), $card['months']);
            $projection[$id]['total'] = round(array_sum($projection[$id]['months']), 2);
        }

        return $projection;
    }

    /**
     * Allocation (Planning sheet): each payment category's planned outflow
     * against its % of the month's planned income (decision 11f: each month's
     * planned income is the base).
     */
    protected function planAllocation(array $grid, string $ym): array
    {
        $income = (float) ($grid['totals'][$ym]['income'] ?? 0);
        $yearIncome = array_sum(array_column($grid['totals'], 'income'));
        $categories = [];
        $percentTotal = 0.0;

        foreach ($grid['groups'] as $key => $group) {
            // Income categories take a share of the month's planned income too (income mix)
            $planned = 0.0;
            $yearPlanned = 0.0;
            foreach ($group['items'] as $row) {
                foreach ($row['cells'] as $cellYm => $cell) {
                    if ($cell['status'] === 'X') {
                        continue;
                    }
                    $yearPlanned += (float) $cell['plannedamount'];
                    $planned += ($cellYm === $ym) ? (float) $cell['plannedamount'] : 0.0;
                }
            }

            $percent = $group['items'][0]['item']['categorypercent'];
            $percent = ($key === 'none' || $percent === null) ? null : (float) $percent;
            $target = ($percent === null) ? null : round($income * $percent / 100, 2);
            $yearTarget = ($percent === null) ? null : round($yearIncome * $percent / 100, 2);

            $categories[] = [
                'category_id' => ($key === 'none') ? null : $key,
                'label'       => $group['label'],
                'direction'   => $group['direction'],
                'percent'     => $percent,
                'planned'     => round($planned, 2),
                'target'      => $target,
                'difference'  => ($target === null) ? null : round($target - $planned, 2),
                'year_planned' => round($yearPlanned, 2),
                'year_target' => $yearTarget,
            ];
        }

        return ['month' => $ym, 'income' => round($income, 2), 'categories' => $categories];
    }

    /** Sum of the active categories' allocation % for one direction (banner when it isn't 100). */
    protected function planAllocationPercentTotal(string $userID, string $direction = 'OUT'): float
    {
        return round((float) $this->db()->query(
            'SELECT COALESCE(SUM(allocationpercent), 0) AS total FROM plancategory WHERE usersessionid = ? AND status = \'A\' AND direction = ?',
            [$userID, $direction]
        )->getRow()->total, 2);
    }

    /**
     * Month view: the month's dues (with what to settle them from), envelopes
     * with spent / remaining, and what is left after commitments.
     */
    protected function planMonthView(string $userID, int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $ym = substr($monthStart, 0, 7);
        $today = date('Y-m-d');
        $db = $this->db();

        $rows = $db->query(
            'SELECT e.sessionid, e.planitemsessionid, e.month, e.plannedamount, e.duedate, e.status, e.overridden,
                    e.paymentmethodsessionid AS entrymethod, e.payablesessionid,
                    p.status AS payablestatus, p.dueamount, COALESCE(p.paidamount, 0) AS paidamount, p.paiddate,
                    i.name, i.direction, i.kind, i.amounttype, i.budgetsessionid, i.accountsessionid, i.toaccountsessionid,
                    i.paymentmethodsessionid AS itemmethod, i.stakeholder, i.notes, i.enddate,
                    c.name AS categoryname, a.name AS accountname, ta.name AS toaccountname, b.name AS budgetname
               FROM planentry e
               JOIN planitem i ON i.sessionid = e.planitemsessionid AND i.usersessionid = e.usersessionid
          LEFT JOIN payable p ON p.sessionid = e.payablesessionid
          LEFT JOIN plancategory c ON c.sessionid = i.plancategorysessionid
          LEFT JOIN account a ON a.sessionid = i.accountsessionid AND a.usersessionid = i.usersessionid
          LEFT JOIN account ta ON ta.sessionid = i.toaccountsessionid AND ta.usersessionid = i.usersessionid
          LEFT JOIN budget b ON b.sessionid = i.budgetsessionid AND b.usersessionid = i.usersessionid
              WHERE e.usersessionid = ? AND i.status <> \'D\' AND e.month = ?
           ORDER BY (i.direction <> \'IN\'), e.duedate, c.sortorder, i.sortorder, i.name',
            [$userID, $monthStart]
        )->getResultArray();

        $methods = [];
        foreach ($this->paymentMethodList($userID) as $method) {
            $methods[$method['PaymentMethodSessionID']] = $method;
        }
        $defaults = $this->planDefaultMethods($userID);

        $budgets = array_column(array_filter($rows, static fn ($r) => $r['kind'] === 'ENVELOPE'), 'budgetsessionid');
        $actuals = $this->planEnvelopeActuals($userID, $budgets, $monthStart, $monthStart);

        $dues = [];
        $envelopes = [];

        foreach ($rows as $row) {
            $row['state'] = $this->planCellState($row, $today);
            $row['editable'] = $this->planCellEditable($row);

            if ($row['kind'] === 'ENVELOPE') {
                $row['spent'] = $actuals[$row['budgetsessionid']][$ym] ?? 0.0;
                $row['remaining'] = round((float) $row['plannedamount'] - $row['spent'], 2);
                $envelopes[] = $row;
                continue;
            }

            // effective method; an override from another account pays from that account
            $methodID = $row['entrymethod'] ?: ($row['itemmethod'] ?: ($defaults[$row['accountsessionid']] ?? null));
            $method = ($methodID !== null) ? ($methods[$methodID] ?? null) : null;
            $row['payaccount'] = $method['AccountSessionID'] ?? $row['accountsessionid'];
            $row['payaccountname'] = $method['AccountName'] ?? $row['accountname'];
            $row['paymethod'] = $method['PaymentMethodSessionID'] ?? null;
            $row['paymethodlabel'] = ! $method ? null : $method['PaymentMethodLabel']
                . (strcasecmp($method['PaymentMethodName'], $method['PaymentMethodTypeLabel']) === 0 ? '' : ' (' . $method['PaymentMethodTypeLabel'] . ')');
            $row['outstanding'] = (empty($row['payablesessionid']) || in_array($row['payablestatus'], ['S', 'C'], true))
                ? 0.0 : round((float) $row['dueamount'] - (float) $row['paidamount'], 2);
            $dues[] = $row;
        }

        $totals = $this->planMonthTotals($rows, $actuals, [$ym])[$ym];

        return ['year' => $year, 'month' => $month, 'ym' => $ym, 'dues' => $dues, 'envelopes' => $envelopes, 'totals' => $totals];
    }
}
