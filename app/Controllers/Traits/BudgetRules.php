<?php

namespace App\Controllers\Traits;

/**
 * Budget rules from the design diagrams.
 *
 *  - Use case "Budget plan overboard for expense handle" (extends Make payment):
 *    a payment that takes a budget over its amount for the current period is
 *    stopped with error 0046 unless the user confirms (allow_over_budget).
 *  - Flowchart "Set Up Budget": a budget is only created when the unplanned
 *    income of its period is at least the budget amount (error 0047).
 *
 * The "Default" budget is the unplanned pool and is never limited.
 * Used by PaymentService (needs db(), paymentError(), budgetList()).
 */
trait BudgetRules
{
    /** Average days per budget period, used to compare budgets of different periods. */
    protected $budgetPeriodDays = ['D' => 1, 'W' => 7, 'M' => 30.44, 'Q' => 91.31, 'H' => 182.62, 'A' => 365.25, 'Y' => 365.25];

    protected $budgetPeriodLabels = ['D' => 'daily', 'W' => 'weekly', 'M' => 'monthly', 'Q' => 'quarterly', 'H' => 'half-yearly', 'A' => 'annual', 'Y' => 'annual'];

    protected function isDefaultBudget(array $budget): bool
    {
        return strcasecmp(trim((string) ($budget['BudgetName'] ?? '')), 'Default') === 0;
    }

    /** One active budget of the user as an assoc array, or null. */
    protected function budgetRow(string $userID, $budgetID)
    {
        if (! $this->isSessionID($budgetID)) {
            return null;
        }

        $row = $this->db()->table('budget')
            ->select('sessionid AS BudgetSessionID, name AS BudgetName, periodic AS BudgetPeriodic, amount AS BudgetAmount', false)
            ->where(['sessionid' => $budgetID, 'usersessionid' => $userID, 'status' => 'A'])
            ->get()->getRowArray();

        return empty($row) ? null : $row;
    }

    /** [start, end] (Y-m-d) of the calendar period of $periodic that contains $date. */
    protected function budgetPeriodRange(string $periodic, string $date): array
    {
        $d = new \DateTime($date);
        $year = (int) $d->format('Y');
        $month = (int) $d->format('n');

        switch ($periodic) {
            case 'D':
                return [$date, $date];

            case 'W':
                $start = (clone $d)->modify('monday this week');
                return [$start->format('Y-m-d'), (clone $start)->modify('+6 day')->format('Y-m-d')];

            case 'Q':
            case 'H':
                $span = ($periodic === 'Q') ? 3 : 6;
                $firstMonth = intdiv($month - 1, $span) * $span + 1;
                $start = (new \DateTime())->setDate($year, $firstMonth, 1);
                return [$start->format('Y-m-d'), (clone $start)->modify('+' . $span . ' month')->modify('-1 day')->format('Y-m-d')];

            case 'A':
            case 'Y':
                return [$year . '-01-01', $year . '-12-31'];

            case 'M':
            default:
                return [$d->format('Y-m-01'), $d->format('Y-m-t')];
        }
    }

    /** Expenses charged to a budget between two dates. Internal transfers are moves, not spending. */
    protected function budgetSpent(string $userID, string $budgetID, string $from, string $to): float
    {
        $row = $this->db()->query(
            'SELECT COALESCE(SUM(t.amount), 0) AS spent
               FROM transaction t
               JOIN account a ON a.sessionid = t.accountsessionid
          LEFT JOIN payment p ON p.sessionid = t.paymentsessionid
              WHERE a.usersessionid = ? AND t.budgetsessionid = ? AND t.paymenttype = \'expense\' AND t.status = \'A\'
                AND t.date BETWEEN ? AND ? AND (p.category IS NULL OR p.category <> \'transfer\')',
            [$userID, $budgetID, $from, $to]
        )->getRowArray();

        return round((float) ($row['spent'] ?? 0), 2);
    }

    /** Spent / remaining of a budget for the period containing $date (null for the Default budget). */
    protected function budgetUsage(string $userID, array $budget, string $date): ?array
    {
        if ($this->isDefaultBudget($budget)) {
            return null;
        }

        $periodic = $budget['BudgetPeriodic'] ?: 'M';
        [$from, $to] = $this->budgetPeriodRange($periodic, $date);
        $spent = $this->budgetSpent($userID, $budget['BudgetSessionID'], $from, $to);
        $limit = round((float) $budget['BudgetAmount'], 2);

        return [
            'budget'    => $budget['BudgetName'],
            'period'    => $this->budgetPeriodLabels[$periodic] ?? 'monthly',
            'from'      => $from,
            'to'        => $to,
            'limit'     => $limit,
            'spent'     => $spent,
            'remaining' => round($limit - $spent, 2),
        ];
    }

    /**
     * Error 0046 when spending $amount on $budgetID (dated $date) goes over the
     * budget for that period; null when it fits or the budget is not limited.
     */
    protected function checkBudgetHeadroom(string $userID, $budgetID, float $amount, string $date)
    {
        $budget = $this->budgetRow($userID, $budgetID);

        if ($budget === null || (float) $budget['BudgetAmount'] <= 0) {
            return null;
        }

        $usage = $this->budgetUsage($userID, $budget, $date);

        if ($usage === null || $amount <= $usage['remaining']) {
            return null;
        }

        return $this->paymentError(
            '0046',
            'Budget',
            'This payment takes "' . $usage['budget'] . '" over its ' . $usage['period'] . ' budget: '
                . number_format($usage['spent'], 2) . ' of ' . number_format($usage['limit'], 2) . ' spent, '
                . number_format(max(0, $usage['remaining']), 2) . ' left, payment ' . number_format($amount, 2) . '.',
            $usage + ['over_budget' => true]
        );
    }

    /**
     * Unplanned income for a budget period: the income received over the last
     * month (rolling, ending today) less what the other active budgets already
     * plan for, both converted to the budget's period length.
     *
     * Income is always measured over one month, then scaled, so one large
     * income this week doesn't make a big weekly budget look affordable
     * (a 7 day window would), and an annual budget isn't judged on a year of
     * history the user may not have entered yet.
     */
    protected function unplannedIncome(string $userID, string $periodic, string $excludeBudgetID = ''): array
    {
        $today = new \DateTime('today');
        $from = (clone $today)->modify('-1 month +1 day')->format('Y-m-d');
        $to = $today->format('Y-m-d');

        $income = (float) $this->db()->query(
            'SELECT COALESCE(SUM(t.amount), 0) AS income
               FROM transaction t
               JOIN account a ON a.sessionid = t.accountsessionid
          LEFT JOIN payment p ON p.sessionid = t.paymentsessionid
              WHERE a.usersessionid = ? AND a.status = \'A\' AND t.paymenttype = \'income\' AND t.status = \'A\'
                AND t.date BETWEEN ? AND ? AND (p.category IS NULL OR p.category <> \'transfer\')',
            [$userID, $from, $to]
        )->getRowArray()['income'];

        $budgets = $this->db()->table('budget')
            ->select('sessionid, name, periodic, amount')
            ->where(['usersessionid' => $userID, 'status' => 'A'])
            ->get()->getResultArray();

        $targetDays = $this->budgetPeriodDays[$periodic] ?? $this->budgetPeriodDays['M'];
        $planned = 0.0;

        foreach ($budgets as $budget) {
            if ($budget['sessionid'] === $excludeBudgetID || strcasecmp(trim((string) $budget['name']), 'Default') === 0) {
                continue;
            }

            $days = $this->budgetPeriodDays[$budget['periodic'] ?: 'M'] ?? $this->budgetPeriodDays['M'];
            $planned += (float) $budget['amount'] * $targetDays / $days;
        }

        $monthlyIncome = round($income, 2);
        $income = round($income * $targetDays / $this->budgetPeriodDays['M'], 2);
        $planned = round($planned, 2);

        return [
            'from'          => $from,
            'to'            => $to,
            'monthlyIncome' => $monthlyIncome,
            'income'        => $income,
            'planned'       => $planned,
            'unplanned'     => round($income - $planned, 2),
        ];
    }

    /** Validate a budget create/update. Returns cleaned values or an error array. */
    protected function validateBudget(string $userID, $name, $periodic, $amount, string $excludeBudgetID = '')
    {
        $name = trim((string) $name);
        $periodic = strtoupper(trim((string) $periodic));
        $amount = $this->toAmount($amount);

        if ($name === '' || mb_strlen($name) > 50) {
            return $this->paymentError('0048', 'Budget', 'Budget name is required (max 50 characters).');
        }

        if (! isset($this->budgetPeriodLabels[$periodic])) {
            return $this->paymentError('0048', 'Budget', 'Please choose a valid budget period.');
        }

        if ($amount === null || $amount <= 0) {
            return $this->paymentError('0048', 'Budget', 'The budget amount must be greater than zero.');
        }

        if (strcasecmp($name, 'Default') !== 0) {
            $pool = $this->unplannedIncome($userID, $periodic, $excludeBudgetID);

            if ($amount > $pool['unplanned']) {
                return $this->paymentError(
                    '0047',
                    'Budget',
                    'Budget amount is greater than unplanned income. Unplanned ' . $this->budgetPeriodLabels[$periodic] . ' income is '
                        . number_format(max(0, $pool['unplanned']), 2) . ': income of ' . number_format($pool['monthlyIncome'], 2)
                        . ' since ' . $pool['from'] . ' is ' . number_format($pool['income'], 2) . ' ' . $this->budgetPeriodLabels[$periodic]
                        . ', less ' . number_format($pool['planned'], 2) . ' already budgeted.',
                    $pool
                );
            }
        }

        return ['name' => $name, 'periodic' => $periodic, 'amount' => $amount];
    }
}
