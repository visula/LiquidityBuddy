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
        $dueDate = $this->planDueDate($month, $item['dueday']);

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
            $last = $db->table('planentry')->select('month, plannedamount')
                ->where('planitemsessionid', $item['sessionid'])->orderBy('month', 'DESC')->limit(1)->get()->getRowArray();

            $month = $last ? $this->addMonths($last['month'], 1) : ($item['startmonth'] ?: null);

            if ($month === null) {
                continue; // nothing planned yet: the items page adds the first month
            }

            $amount = $last ? (float) $last['plannedamount'] : (float) $item['defaultamount'];

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
        }

        return $created;
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

        $resolveMethod = function ($accountID, $hint, string $itemKey) use ($userID, &$report) {
            if ($accountID === null) {
                return null;
            }

            if ($hint !== null && $hint !== '') {
                foreach ($this->paymentMethodList($userID, $accountID) as $method) {
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
}
