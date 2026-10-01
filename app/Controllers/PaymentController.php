<?php

namespace App\Controllers;

/**
 * Payment methods (per account) and the payment history page.
 */
class PaymentController extends PaymentService
{
    /** GET /payment-method/list */
    public function paymentMethodPage()
    {
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
        }

        $userID = $this->userAccess();

        $data = [
            'StoredText' => [
                'Header' => 'Payment Methods',
                'ScreenTitle' => 'Payment Methods',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];
        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'payment-methods';

        $accounts = $this->rowsOf($this->activeAccountListAccessModule($userID));

        if($accounts === []){
            return redirect()->to(base_url('/account/add'))->with('status', 'Create an account before adding payment methods.');
        }

        $methods = $this->paymentMethodList($userID);
        $byAccount = [];

        foreach ($methods as $method) {
            $byAccount[$method['AccountSessionID']][] = $method;
        }

        $data['accounts'] = $accounts;
        $data['methodsByAccount'] = $byAccount;
        $data['methodTypes'] = $this->paymentMethodTypes;

        return view('Payment/methods', $data);
    }

    /** GET /payment/history[?account=<id>] */
    public function paymentHistoryPage()
    {
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
        }

        $userID = $this->userAccess();
        $accountID = (string) $this->request->getGet('account');
        $accountID = $this->isSessionID($accountID) ? $accountID : '';

        $data = [
            'StoredText' => [
                'Header' => 'Payment History',
                'ScreenTitle' => 'Payment History',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];
        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'report-list';
        $data['accounts'] = $this->rowsOf($this->activeAccountListAccessModule($userID));
        $data['selectedAccount'] = $accountID;
        $data['payments'] = $this->paymentHistory($userID, $accountID);
        $data['summary'] = $this->paymentMethodSummary($userID);
        $data['outstanding'] = $this->outstandingPayables($userID);

        return view('Payment/history', $data);
    }

    /** POST /js-request/payment-method/create */
    public function createPaymentMethod()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_id', 'name', 'type']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $result = $this->paymentMethodCreateProccess($userID, $request_data['account_id'], $this->paymentMethodInput($request_data));

        return $this->apiResult($result, 'Payment method added');
    }

    /** POST /js-request/payment-method/update */
    public function updatePaymentMethod()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['payment_method_id']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $result = $this->paymentMethodUpdateProccess($userID, $request_data['payment_method_id'], $this->paymentMethodInput($request_data));

        return $this->apiResult($result, 'Payment method updated');
    }

    /** POST /js-request/payment-method/delete */
    public function deletePaymentMethod()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['payment_method_id']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $result = $this->paymentMethodDeleteProccess($userID, $request_data['payment_method_id']);

        return $this->apiResult($result, 'Payment method removed');
    }

    /** Only the editable fields, so unknown POST keys never reach the DB layer. */
    protected function paymentMethodInput(array $request_data): array
    {
        $input = [];

        foreach (['name', 'type', 'reference', 'credit_limit', 'is_default'] as $field) {
            if(array_key_exists($field, $request_data)){
                $input[$field] = $request_data[$field];
            }
        }

        return $input;
    }
}
