<?php

namespace App\Controllers\Traits;

use App\Libraries\BankRateSync;

/**
 * Bank products on accounts, and interest posting.
 *
 * Interest uses the daily balance method: for each day, the account's closing
 * balance (negative balances count as 0) x annual rate / 365. It is credited
 * for completed months, like banks do, starting the day after the account was
 * linked to its product (`interestpostedthrough` marks what is already posted).
 * The quoted amount can be overridden with what the bank actually credited.
 *
 * Used by PaymentService (needs db(), paymentError(), recordPayment(), ...).
 */
trait InterestPosting
{
    protected function bankRates(): BankRateSync
    {
        return new BankRateSync();
    }

    /** Account (owned, active) with its linked product, or an error array. */
    protected function accountWithProduct(string $userID, $accountID, bool $forUpdate = false)
    {
        if (! $this->isSessionID($accountID)) {
            return $this->paymentError('0021', 'Account', 'Please select a valid account.');
        }

        $row = $this->db()->query(
            'SELECT a.sessionid AS AccountSessionID, a.name AS AccountName, a.currentbalance AS AccountCurrentBalance,
                    a.bankproductsessionid AS BankProductSessionID, a.interestpostedthrough AS InterestPostedThrough,
                    a.autointerest AS AutoInterest, a.accounttype AS AccountType, a.bankname AS AccountBankName,
                    bp.bankname AS BankName, bp.productname AS ProductName, bp.producttype AS ProductType,
                    bp.interestrate AS InterestRate, bp.previousrate AS PreviousRate, bp.minbalance AS MinBalance,
                    bp.currency AS Currency, bp.effectivedate AS EffectiveDate, bp.status AS ProductStatus
               FROM account a
          LEFT JOIN bankproduct bp ON bp.sessionid = a.bankproductsessionid
              WHERE a.sessionid = ? AND a.usersessionid = ? AND a.status = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$accountID, $userID, 'A']
        )->getRowArray();

        return empty($row) ? $this->paymentError('0021', 'Account', 'The selected account was not found or is no longer active.') : $row;
    }

    /** Last day of the month before $date (interest is credited for completed months). */
    protected function lastCompletedMonthEnd(?string $date = null): string
    {
        return (new \DateTime($date ?? 'today'))->modify('first day of this month')->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Interest due on an account from the day after `interestpostedthrough`
     * up to $through ('month_end' = last completed month, 'today', or Y-m-d).
     */
    protected function interestQuote(string $userID, $accountID, string $through = 'month_end', $account = null)
    {
        $account = $account ?? $this->accountWithProduct($userID, $accountID);

        if (isset($account['error_id'])) {
            return $account;
        }

        if (empty($account['BankProductSessionID'])) {
            return $this->paymentError('0060', 'Interest', 'Link this account to a bank product first.');
        }

        $today = date('Y-m-d');
        $to = ($through === 'today') ? $today : (($through === 'month_end') ? $this->lastCompletedMonthEnd() : $this->validDate($through));

        if ($to === null || $to > $today) {
            return $this->paymentError('0061', 'Interest', 'Interest can only be calculated up to today.');
        }

        $from = (new \DateTime($account['InterestPostedThrough'] ?? $today))->modify('+1 day')->format('Y-m-d');
        $rate = (float) $account['InterestRate'];
        $quote = [
            'AccountSessionID' => $account['AccountSessionID'],
            'AccountName'      => $account['AccountName'],
            'Product'          => trim(($account['BankName'] ?? '') . ' ' . ($account['ProductName'] ?? '')),
            'Rate'             => $rate,
            'From'             => $from,
            'To'               => $to,
            'Days'             => 0,
            'AverageBalance'   => 0.0,
            'Amount'           => 0.0,
        ];

        if ($from > $to) {
            return $quote; // nothing due yet
        }

        // Closing balance of each day, walking back from the current balance
        $effects = $this->db()->query(
            'SELECT date, SUM(CASE WHEN paymenttype = \'income\' THEN amount ELSE -amount END) AS net
               FROM transaction WHERE accountsessionid = ? AND status = \'A\' AND date > ?
           GROUP BY date',
            [$account['AccountSessionID'], $from]
        )->getResultArray();
        $netByDate = array_column($effects, 'net', 'date');
        krsort($netByDate);

        $balance = (float) $account['AccountCurrentBalance'];
        foreach ($netByDate as $date => $net) {
            if ($date > $to) {
                $balance -= (float) $net; // undo everything after the period
            }
        }

        $total = 0.0;
        $balanceSum = 0.0;
        $days = 0;
        $day = new \DateTime($to);
        $start = new \DateTime($from);

        while ($day >= $start) {
            $key = $day->format('Y-m-d');
            $positive = max(0.0, $balance);
            $total += $positive * $rate / 100 / 365;
            $balanceSum += $positive;
            $days++;
            $balance -= (float) ($netByDate[$key] ?? 0); // balance at the end of the previous day
            $day->modify('-1 day');
        }

        $quote['Days'] = $days;
        $quote['AverageBalance'] = round($balanceSum / max(1, $days), 2);
        $quote['Amount'] = round($total, 2);

        return $quote;
    }

    /**
     * Link (or unlink with '') an account to a bank product. Interest starts
     * accruing from today. When unposted interest exists, $confirmReset must be
     * true (the UI asks first) because it is not carried over.
     */
    protected function linkAccountProduct(string $userID, $accountID, $productID, bool $autoInterest, bool $confirmReset = false)
    {
        $account = $this->accountWithProduct($userID, $accountID);

        if (isset($account['error_id'])) {
            return $account;
        }

        $product = null;

        if ($productID !== '' && $productID !== null) {
            $product = $this->isSessionID($productID) ? $this->bankRates()->product($productID) : null;

            if ($product === null || $product['status'] !== 'A') {
                return $this->paymentError('0063', 'Bank Product', 'That bank product is not available any more. Refresh the rates and pick another.');
            }
        }

        $changing = ($product['sessionid'] ?? null) !== $account['BankProductSessionID'];

        if ($changing && ! empty($account['BankProductSessionID']) && ! $confirmReset) {
            $pending = $this->interestQuote($userID, $accountID, 'today', $account);

            if (! isset($pending['error_id']) && $pending['Amount'] > 0) {
                return $this->paymentError('0062', 'Interest', 'This account has ' . number_format($pending['Amount'], 2) . ' interest not posted yet. Post it first, or confirm to change the product without it.', ['pending' => $pending['Amount']]);
            }
        }

        $update = [
            'autointerest'    => ($product !== null && $autoInterest) ? 'Y' : 'N',
            'updateddatetime' => time(),
        ];

        if ($changing) {
            $update['bankproductsessionid'] = $product['sessionid'] ?? null;
            $update['interestpostedthrough'] = ($product !== null) ? date('Y-m-d', strtotime('-1 day')) : null;
        }

        $this->db()->table('account')->set($update)->where(['sessionid' => $account['AccountSessionID'], 'usersessionid' => $userID])->update();

        return ['success' => true, 'data' => ['AccountSessionID' => $account['AccountSessionID'], 'BankProductSessionID' => $product['sessionid'] ?? null]];
    }

    /**
     * Post interest up to $through as an income on the account.
     * $amount null = the calculated amount; otherwise what the bank credited.
     * A zero amount (e.g. a 0% current account) only moves the posted-through date.
     */
    protected function postInterest(string $userID, $accountID, string $through = 'month_end', $amount = null, $methodID = '')
    {
        if ($amount !== null && $amount !== '') {
            $amount = $this->toAmount($amount);

            if ($amount === null || $amount < 0) {
                return $this->paymentError('0024', 'Payment Amount', 'The interest amount must be zero or more.');
            }
        } else {
            $amount = null;
        }

        return $this->runAtomic(function () use ($userID, $accountID, $through, $amount, $methodID) {
            // Lock the account so two tabs can't post the same period twice
            $account = $this->accountWithProduct($userID, $accountID, true);

            if (isset($account['error_id'])) {
                return $account;
            }

            $quote = $this->interestQuote($userID, $accountID, $through, $account);

            if (isset($quote['error_id'])) {
                return $quote;
            }

            if ($quote['Days'] === 0) {
                return $this->paymentError('0064', 'Interest', 'Interest is already posted up to ' . $account['InterestPostedThrough'] . '.');
            }

            $credit = $amount ?? $quote['Amount'];
            $payment = null;

            if ($credit > 0) {
                $payment = $this->recordPayment($userID, [
                    'direction'   => 'IN',
                    'account'     => $quote['AccountSessionID'],
                    'method'      => $methodID,
                    'budget'      => '',
                    'amount'      => $credit,
                    'category'    => 'income',
                    'settlement'  => 'FULL',
                    'link_type'   => 'interest',
                    'link_id'     => $account['BankProductSessionID'],
                    'description' => 'Interest ' . date('d M', strtotime($quote['From'])) . ' - ' . date('d M Y', strtotime($quote['To'])),
                    'date'        => $quote['To'],
                ]);

                if (isset($payment['error_id'])) {
                    return $payment;
                }
            }

            $this->db()->table('account')
                ->set(['interestpostedthrough' => $quote['To'], 'updateddatetime' => time()])
                ->where('sessionid', $quote['AccountSessionID'])->update();

            return ['success' => true, 'data' => $quote + ['Posted' => $credit, 'Payment' => $payment['data'] ?? null]];
        });
    }

    /** Auto-post interest for completed months on accounts that opted in. Never throws. */
    protected function postDueInterest(string $userID): int
    {
        try {
            $due = $this->db()->table('account a')
                ->select('a.sessionid')
                ->join('bankproduct bp', 'bp.sessionid = a.bankproductsessionid')
                ->where(['a.usersessionid' => $userID, 'a.status' => 'A', 'a.autointerest' => 'Y'])
                ->where('a.interestpostedthrough <', $this->lastCompletedMonthEnd())
                ->get()->getResultArray();
        } catch (\Throwable $exception) {
            log_message('error', 'Interest auto posting skipped: {message}', ['message' => $exception->getMessage()]);
            return 0;
        }

        $posted = 0;

        foreach ($due as $row) {
            $result = $this->postInterest($userID, $row['sessionid'], 'month_end');

            if (isset($result['error_id'])) {
                log_message('error', 'Auto interest for {account} failed: {message}', ['account' => $row['sessionid'], 'message' => $result['error_message']]);
                continue;
            }

            $posted++;
        }

        return $posted;
    }
}
