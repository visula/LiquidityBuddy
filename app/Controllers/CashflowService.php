<?php

namespace App\Controllers;

/**
 * Cash flow: money in and out per month (from the transaction ledger), planned dues
 * against expected income, and what to do about each open due.
 */
class CashflowService extends PaymentService
{
    /**
     * $monthsBack  = past months charted, including the current month
     * $monthsAhead = future months charted (planned dues and expected income)
     */
    protected function cashflowProccess(string $userID, int $monthsBack = 6, int $monthsAhead = 6): array
    {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $first = date('Y-m-01', strtotime($monthStart . ' -' . $monthsBack . ' months'));
        $last = date('Y-m-t', strtotime($monthStart . ' +' . $monthsAhead . ' months'));

        $series = [];

        for ($month = $first; $month <= $last; $month = date('Y-m-01', strtotime($month . ' +1 month'))) {
            $ym = substr($month, 0, 7);
            $series[$ym] = ['ym' => $ym, 'inflow' => 0.0, 'outflow' => 0.0, 'dues' => 0.0, 'expected' => 0.0];
        }

        // Actual money in/out. Transfers between the user's own accounts are not income or spending:
        // a ledger row linked to a TRANSFER payment, or an older unlinked row described as a transfer.
        $flows = $this->db()->table('transaction t')
            ->select("DATE_FORMAT(t.date, '%Y-%m') AS ym, t.paymenttype AS PaymentType, SUM(t.amount) AS Amount", false)
            ->join('account a', 'a.sessionid = t.accountsessionid')
            ->join('payment pay', 'pay.sessionid = t.paymentsessionid', 'left')
            ->where('a.usersessionid', $userID)
            ->where('a.status', 'A')
            ->where('t.status', 'A')
            ->where('t.date >=', $first)
            ->where('t.date <=', $last)
            ->where("NOT (pay.direction = 'TRANSFER' OR (t.paymentsessionid IS NULL AND t.description LIKE 'Transfer%'))", null, false)
            ->groupBy("DATE_FORMAT(t.date, '%Y-%m'), t.paymenttype", false)
            ->get()->getResultArray();

        foreach ($flows as $flow) {
            if (! isset($series[$flow['ym']])) {
                continue;
            }

            $key = ($flow['PaymentType'] === 'income') ? 'inflow' : 'outflow';
            $series[$flow['ym']][$key] += (float) $flow['Amount'];
        }

        // Planned dues by month from the payment plans: OUT is what the user must pay, IN is income expected
        $dues = $this->db()->table('payable p')
            ->select("DATE_FORMAT(p.duedate, '%Y-%m') AS ym, COALESCE(pp.direction, IF(pp.paymentplanlink = 'income', 'IN', 'OUT')) AS Direction, SUM(p.dueamount) AS Planned", false)
            ->join('paymentplan pp', 'pp.sessionid = p.paymentplansessionid')
            ->where('pp.usersessionid', $userID)
            ->where('pp.status', 'A')
            ->whereIn('p.status', ['P', 'T', 'S'])
            ->where('p.duedate >=', $first)
            ->where('p.duedate <=', $last)
            ->groupBy("DATE_FORMAT(p.duedate, '%Y-%m'), COALESCE(pp.direction, IF(pp.paymentplanlink = 'income', 'IN', 'OUT'))", false)
            ->get()->getResultArray();

        foreach ($dues as $due) {
            if (! isset($series[$due['ym']])) {
                continue;
            }

            if ($due['Direction'] === 'OUT') {
                $series[$due['ym']]['dues'] += (float) $due['Planned'];
            } elseif ($due['Direction'] === 'IN') {
                $series[$due['ym']]['expected'] += (float) $due['Planned'];
            }
        }

        $totals = ['inflow' => 0.0, 'outflow' => 0.0, 'dues' => 0.0, 'expected' => 0.0];
        $rows = [];

        foreach ($series as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] += $row[$key];
            }

            $rows[] = $row + [
                'label' => date('M Y', strtotime($row['ym'] . '-01')),
                'future' => $row['ym'] > substr($today, 0, 7),
                // planned dues as a share of the income expected that month
                'dues_rate' => $row['expected'] > 0 ? round($row['dues'] / $row['expected'] * 100, 1) : null,
                // money out as a share of money in that month
                'outflow_rate' => $row['inflow'] > 0 ? round($row['outflow'] / $row['inflow'] * 100, 1) : null,
            ];
        }

        return [
            'months' => $rows,
            'totals' => array_map(static fn ($value) => round($value, 2), $totals),
            'handling' => $this->dueHandling($userID),
            'chart' => [
                'labels' => array_column($rows, 'label'),
                'inflow' => array_map(static fn ($row) => round($row['inflow'], 2), $rows),
                'outflow' => array_map(static fn ($row) => round($row['outflow'], 2), $rows),
                'dues' => array_map(static fn ($row) => round($row['dues'], 2), $rows),
                'expected' => array_map(static fn ($row) => round($row['expected'], 2), $rows),
            ],
        ];
    }

    /**
     * Open outgoing dues grouped by when they fall due, each with the account's current
     * balance and a suggested action. Later dues are only counted.
     */
    protected function dueHandling(string $userID): array
    {
        $accounts = [];

        foreach ($this->db()->table('account')->select('sessionid, name, currentbalance')->where(['usersessionid' => $userID, 'status' => 'A'])->get()->getResultArray() as $account) {
            $accounts[$account['sessionid']] = $account;
        }

        $today = date('Y-m-d');
        $thisMonth = substr($today, 0, 7);
        $nextMonth = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));

        $handling = ['overdue' => [], 'this_month' => [], 'next_month' => [], 'later' => ['count' => 0, 'amount' => 0.0]];

        foreach ($this->outstandingPayables($userID) as $due) {
            if ($due['Direction'] !== 'OUT') {
                continue;
            }

            $account = $accounts[$due['PlanItemAccountSessionID'] ?? ''] ?? null;
            $overdue = $due['PayableDueDate'] < $today;

            $row = [
                'PayableSessionID' => $due['PayableSessionID'],
                'Description' => $due['PaymentPlanDescription'],
                'DueDate' => $due['PayableDueDate'],
                'Outstanding' => round((float) $due['PayableOutstanding'], 2),
                'AccountName' => $account['name'] ?? null,
                'Balance' => $account !== null ? round((float) $account['currentbalance'], 2) : null,
            ];
            [$row['Action'], $row['Level']] = $this->dueAction($row, $overdue);

            $ym = substr($due['PayableDueDate'], 0, 7);

            if ($overdue) {
                $handling['overdue'][] = $row;
            } elseif ($ym === $thisMonth) {
                $handling['this_month'][] = $row;
            } elseif ($ym === $nextMonth) {
                $handling['next_month'][] = $row;
            } else {
                $handling['later']['count']++;
                $handling['later']['amount'] += $row['Outstanding'];
            }
        }

        $handling['later']['amount'] = round($handling['later']['amount'], 2);

        return $handling;
    }

    /** Suggested action and badge colour for one due, from the balance of the account it is paid from. */
    protected function dueAction(array $due, bool $overdue): array
    {
        if ($due['AccountName'] === null) {
            return ['Pay from any account on the Dued tab', $overdue ? 'danger' : 'secondary'];
        }

        if ($due['Balance'] < $due['Outstanding']) {
            $short = number_format($due['Outstanding'] - $due['Balance'], 2);

            return ['Short by ' . $short . ' on ' . $due['AccountName'] . ': move funds in or pay part on the Dued tab', $overdue ? 'danger' : 'warning'];
        }

        return ['Covered by the ' . $due['AccountName'] . ' balance: settle on the Dued tab', $overdue ? 'danger' : 'success'];
    }
}
