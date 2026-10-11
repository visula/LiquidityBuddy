<?php

namespace App\Controllers;

use App\Libraries\BankRateSync;

/**
 * Bank rates catalog (from the providers in app/Config/BankRates.php),
 * account <-> bank product links, and interest posting.
 */
class BankController extends PaymentService
{
    /** GET /bank-rates[?type=SAVINGS] */
    public function ratesPage()
    {
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
        }

        $sync = new BankRateSync();
        $sync->syncIfStale();

        $type = strtoupper((string) $this->request->getGet('type'));
        $type = isset(BankRateSync::TYPES[$type]) ? $type : '';

        $data = [
            'StoredText' => [
                'Header' => 'Bank Rates',
                'ScreenTitle' => 'Bank Rates',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];
        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'bank-rates';
        $data['products'] = $sync->products($type);
        $data['productFees'] = $sync->fees(array_column($data['products'], 'sessionid'));
        $data['providers'] = $sync->providerStatus();
        $data['types'] = BankRateSync::TYPES;
        $data['selectedType'] = $type;
        $data['linkedProducts'] = $this->linkedProductCounts($this->userAccess());

        return view('Bank/rates', $data);
    }

    /** Product sessionid => how many of the user's accounts use it. */
    protected function linkedProductCounts(string $userID): array
    {
        $rows = $this->db()->table('account')
            ->select('bankproductsessionid, COUNT(*) AS n')
            ->where(['usersessionid' => $userID, 'status' => 'A'])
            ->where('bankproductsessionid IS NOT NULL', null, false)
            ->groupBy('bankproductsessionid')
            ->get()->getResultArray();

        return array_column($rows, 'n', 'bankproductsessionid');
    }

    /** POST /js-request/bank-rates/sync */
    public function syncRates()
    {
        if($this->apiUser() === null){
            return $this->apiUnauthorised();
        }

        $results = (new BankRateSync())->syncAll();
        $failed = array_filter($results, static fn ($r) => $r['status'] === 'failed');

        if(count($failed) === count($results)){
            return $this->apiError($this->paymentError('0065', 'Bank Rates', 'No provider could be synced: ' . implode(' ', array_column($failed, 'message'))));
        }

        return $this->apiResult(['success' => true, 'data' => $results], 'Rates refreshed');
    }

    /** POST /js-request/account/bank-product  (account_id, product_id ('' = unlink), auto_interest, confirm_reset) */
    public function linkProduct()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_id']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $result = $this->linkAccountProduct(
            $userID,
            $request_data['account_id'],
            (string) ($request_data['product_id'] ?? ''),
            filter_var($request_data['auto_interest'] ?? false, FILTER_VALIDATE_BOOLEAN),
            filter_var($request_data['confirm_reset'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );

        // Account type and bank: from the product when one is linked, else as chosen
        if(! isset($result['error_id']) && (array_key_exists('account_type', $request_data) || ! empty($result['data']['BankProductSessionID']))){
            $this->saveAccountType(
                $userID,
                $result['data']['AccountSessionID'],
                strtoupper((string) ($request_data['account_type'] ?? '')),
                (string) ($request_data['bank_name'] ?? '')
            );
        }

        return $this->apiResult($result, 'Bank product saved');
    }

    /** POST /js-request/account/interest/quote  (account_id, through: month_end | today) */
    public function interestQuoteRequest()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $through = in_array($request_data['through'] ?? '', ['month_end', 'today'], true) ? $request_data['through'] : 'month_end';
        $quote = $this->interestQuote($userID, $request_data['account_id'] ?? '', $through);

        return $this->apiResult(isset($quote['error_id']) ? $quote : ['success' => true, 'data' => $quote], 'Interest calculated');
    }

    /** POST /js-request/account/interest/post  (account_id, through, amount?, payment_method?) */
    public function interestPostRequest()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $through = in_array($request_data['through'] ?? '', ['month_end', 'today'], true) ? $request_data['through'] : 'month_end';

        $result = $this->postInterest($userID, $request_data['account_id'] ?? '', $through, $request_data['amount'] ?? null, $request_data['payment_method'] ?? '');

        return $this->apiResult($result, 'Interest posted');
    }
}
