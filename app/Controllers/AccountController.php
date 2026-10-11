<?php

namespace App\Controllers;

class AccountController extends PaymentService
{

    // Account Page initation function
    public function accountList()
    {
        // Login Check        
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        // Post recurring transactions (salary, rent ...) that fell due since the last visit
        $this->postDueRecurring($this->userAccess());
        $this->postDueInterest($this->userAccess());

        $data = [
            'StoredText' => [
                'Header' => 'Dashboard',
                'ScreenTitle' => 'Dashboard',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];
        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'main-menu';

        // TODO:: link user default user
        // $this->limit = 5;
        $accountDetails = $this->activeAccountListAccessModule($this->userAccess());
        // $this->limit = 0;

        // TODO:: Error Handling Method
        // No accounts yet (e.g. a new user): start on Add Account. Sign-up already
        // created a Default account group; Add Account sends users without one to Account Groups.
        if(isset($accountDetails['error_id'])){
            return redirect()->to(base_url('/account/add'))->with('msg', $accountDetails['error_message']);
        }

        if(!isset($accountDetails[0])){
            $accounts[] = $accountDetails;
        }else{
            $accounts = $accountDetails;
        }

        // TODO:: link user default user
        $this->limit = 20;
        $transactionDetails = $this->activeTransactionListAccess($this->userAccess());
        $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($transactionDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            return $this->errorHandleLogAndPageRedirection($transactionDetails, $this->init_trigger_url);
            // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);
        }

        if(!isset($transactionDetails[0])){
            $transactions[] = $transactionDetails;
        }else{
            $transactions = $transactionDetails;
        }

        // TODO:: link user default user
        // $this->limit = 5;
        $budgetDetails = $this->budgetList($this->userAccess());
        // $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($budgetDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            return $this->errorHandleLogAndPageRedirection($budgetDetails, $this->init_trigger_url);
            // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);
        }

        if(!isset($budgetDetails[0])){
            $budgets[] = $budgetDetails;
        }else{
            $budgets = $budgetDetails;
        }

        // TODO:: link user default user
        $this->limit = 5;
        $shopDetails = $this->userShopList($this->userAccess());
        // $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($shopDetails['error_id'])){
            $shopDetails = $this->otherShopList();

            if(isset($shopDetails['error_id'])){
                return $this->errorHandleLogAndPageRedirection($shopDetails, $this->init_trigger_url);
            }
        }

        // TODO:: Change the route the accout create URL
        // return $this->errorHandleLogAndPageRedirection($shopDetails, '/account/list');
        // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);

        if(!isset($shopDetails[0])){
            $shops[] = $shopDetails;
        }else{
            $shops = $shopDetails;
        }

        if(count($shops) < $this->limit){
            $shopIds = array_column($shops, 'ShopSessionID');
            // TODO:: link user default user
            $this->limit = ($this->limit - count($shops));
            // var_dump($shopIds);
            // die;
            $otherShopDetails = $this->otherShopList($shopIds);
            
            $this->limit = 0;

            // TODO:: Error Handling Method
            if(!isset($otherShopDetails['error_id'])){

                // TODO:: Change the route the accout create URL
                // return $this->errorHandleLogAndPageRedirection($shopDetails, '/account/list');
                // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);

                if(!isset($otherShopDetails[0])){
                    $shops[] = $otherShopDetails;
                }else{
                    $shops = array_merge($shops, $otherShopDetails);
                }
            }
        }

        $data['shopInfo'] = $shops;

        // TODO:: link user default user
        $this->limit = 10;
        $stackholderDetails = $this->stackholderList($this->userAccess());
        $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($stackholderDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            $stackholderDetails = [];
            // return $this->errorHandleLogAndPageRedirection($stackholderDetails, '/account/list');
            // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);
        }

        if(!isset($stackholderDetails[0])){
            $stackholders[] = $stackholderDetails;
        }else{
            $stackholders = $stackholderDetails;
        }

        // $data['accountInfo'] = $accounts;
        $data['budgetInfo'] = $budgets;
        $data['stackholderInfo'] = $stackholders;
        // $data['transactionInfo']['$transactions'] = $transactions;

        $data['accountInfo'] = [
            'page_limit' => 5,
            'count' => count($accounts),
            'page_count' => ceil(count($accounts) / 5),
            'accounts' => $accounts,
            'allow_all_accounts' => true,
            'edit_mode' => false
        ];

        $data['transactionInfo'] = [
            'page_limit' => 10,
            'count' => count($transactions),
            'page_count' => ceil(count($transactions) / 10),
            'transactions' => $transactions,
            'allow_all_accounts' => true
        ];

        $data['paymentPlan'] = $this->periodic;
        $data['paymentMethodInfo'] = $this->paymentMethodList($this->userAccess());
        $data['accountMeta'] = $this->accountTypeMap($this->userAccess());
        $data['transactionInfo']['paymentMethodMap'] = $this->paymentMethodMap($this->userAccess());

        $data['external_trans_content'] = view('Transaction/external_trans_module', $data);
        if(isset($data['accountInfo']['accounts'][1])){
            $data['internal_trans_content'] = view('Transaction/internal_trans_module', $data);
        }else{
            $data['internal_trans_content'] = "<div class='transaction-type-list d-none' id='internal'><h3> Need more than one account to make internal transaction </h3></div>";
        }

        $data['outstandingDues'] = $this->outstandingPayables($this->userAccess());

        $data['income_trans_content'] = view('Transaction/income_trans_module', $data);
        $data['dued_trans_content'] = view('Transaction/dued_trans_module', $data);
        $data['other_trans_content'] = view('Transaction/other_trans_module', $data);
        $data['purchase_content'] = view('Transaction/purchase_module', $data);
        $data['account_list_content'] = view('Account/commonModule/account_info_module', $data['accountInfo']);
        $data['transaction_proccess_container'] = view('Transaction/transaction_proccess_module', $data);
        $data['transaction_details_container'] = view('Transaction/transaction_details_module', $data['transactionInfo']);
        return view('Account/list', $data);
    }

    // Transaction List Page initation function
    public function transactionList()
    {
        // Login Check        
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        $data = [
            'StoredText'=>[
                'Header' => 'Transactions',
                'ScreenTitle' => 'Transactions',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];

        $transactionDetails = $this->activeTransactionListAccess($this->userAccess());

        // TODO:: Error Handling Method
        if(isset($transactionDetails['error_id'])){
            
            // Redirect to account page
            return $this->errorHandleLogAndPageRedirection($transactionDetails, $this->init_trigger_url);
            // return redirect()->to(base_url('/account/list'))->with('msg', $transactionDetails['error_message']);
        }

        // TODO:: link user default user

        return view('Transaction/list', $data);
    }

    /**
     * POST /js-request/account/transferred - deposit (cash -> bank), withdrawal
     * (bank -> cash) or transfer between two of the user's accounts, plus the
     * bank's charge for the channel used (channel, fee = override or '' for the listed charge).
     */
    public function internalTransaction()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();

        // passing parameters --> (amount [required], from_account [required], to_account [required], budget [required])
        $requiredParameters = $this->handleRequiredParameters($request_data, ['amount', 'from_account', 'to_account','budget']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $result = $this->internalTransfer($userID, $request_data);

        if(isset($result['error_id'])){
            return $this->apiError($result);
        }

        $label = ['DEPOSIT' => 'Deposit recorded', 'WITHDRAWAL' => 'Withdrawal recorded'][$result['data']['Kind']] ?? 'Transfer recorded';

        return $this->apiResult($result, $label . ($result['data']['Fee'] > 0 ? ' with a bank charge of ' . number_format($result['data']['Fee'], 2) : ''));
    }

    /** POST /js-request/account/transfer/quote  (from_account, to_account, amount, channel, payment_date) */
    public function internalTransferQuote()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $quote = $this->transferQuote(
            $userID,
            $request_data['from_account'] ?? '',
            $request_data['to_account'] ?? '',
            $request_data['amount'] ?? 0,
            (string) ($request_data['channel'] ?? ''),
            (string) ($request_data['payment_date'] ?? '')
        );

        return $this->apiResult(isset($quote['error_id']) ? $quote : ['success' => true, 'data' => $quote], 'Quote');
    }

    public function addAccount()
    {
        // Login Check        
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        $data = [];

        $data = [
            'StoredText' => [
                'Header' => 'Accounts',
                'ScreenTitle' => 'Accounts',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];
        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'add-new-account';

        // TODO:: link user default user
        // $this->limit = 5;
        $accountGroupDetails = $this->activeAccountGroupsListAccessModule($this->userAccess());
        // $this->limit = 0;

        // An account needs a group: without any, create one first
        if(isset($accountGroupDetails['error_id'])){
            return redirect()->to(base_url('/account/group'))->with('status', 'Create an account group before adding accounts.');
        }

        if(!isset($accountGroupDetails[0])){
            $accountGroupsInfo[] = $accountGroupDetails;
        }else{
            $accountGroupsInfo = $accountGroupDetails;
        }
        $data['accountGroupDetails'] = $accountGroupsInfo;
        $data['methodTypes'] = $this->paymentMethodTypes;

        // Account type + bank product (with its charges) for the new account
        $bank = new \App\Libraries\BankRateSync();
        $bank->syncIfStale();
        $data['accountTypes'] = \App\Libraries\BankRateSync::ACCOUNT_TYPES;
        $data['bankProducts'] = $bank->products();
        $data['productFees'] = $bank->fees(array_column($data['bankProducts'], 'sessionid'));

        // TODO:: link user default user
        // $this->limit = 0;
        $accountDetails = $this->activeAccountListAccessModule($this->userAccess());
        // $this->limit = 0;

        // TODO:: Error Handling Method
        if(!isset($accountDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            // return $this->errorHandleLogAndPageRedirection($accountDetails, '/account/list');
            // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);

            if(!isset($accountDetails[0])){
                $accounts[] = $accountDetails;
            }else{
                $accounts = $accountDetails;
            }
            
            $data['accountInfo'] = [
                'page_limit' => 5,
                'count' => count($accounts),
                'page_count' => ceil(count($accounts) / 5),
                'accounts' => $accounts,
                'allow_all_accounts' => true,
                'edit_mode' => true
            ];

            $data['account_list_content'] = view('Account/commonModule/account_info_module', $data['accountInfo']);
        }else{
            $data['account_list_content'] = '';
        }   

        return view('Account/creation', $data);
    }

    public function createAccount()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();

        // passing parameters --> (accountName [required], groupID [required], amount [required])
        $requiredParameters = $this->handleRequiredParameters($request_data, ['accountName', 'groupID', 'amount']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $accountName = trim((string) $request_data['accountName']);
        $groupID = $request_data['groupID'];
        $amount = $this->toAmount($request_data['amount']);

        if($accountName === '' || $amount === null || $amount < 0 || !$this->isSessionID($groupID)){
            return $this->apiError($this->paymentError('0045', 'Account', 'Account name, group and a zero or positive opening amount are required.'));
        }

        // Every account gets a default payment method (cash, card, bank transfer ...)
        $methodType = strtoupper((string) ($request_data['method_type'] ?? 'CASH'));
        $methodType = isset($this->paymentMethodTypes[$methodType]) ? $methodType : 'CASH';
        $methodInput = [
            'name'         => trim((string) ($request_data['method_name'] ?? '')) ?: $this->paymentMethodTypes[$methodType],
            'type'         => $methodType,
            'reference'    => $request_data['method_reference'] ?? '',
            'credit_limit' => $request_data['credit_limit'] ?? '0',
            'is_default'   => true,
        ];

        // What kind of account it is, and the bank product it is (savings, current ...)
        $accountType = strtoupper((string) ($request_data['account_type'] ?? 'OTHER'));
        $accountType = isset(\App\Libraries\BankRateSync::ACCOUNT_TYPES[$accountType]) ? $accountType : 'OTHER';
        $productID = ($accountType === 'CASH') ? '' : trim((string) ($request_data['bank_product_id'] ?? ''));
        $bankName = (string) ($request_data['bank_name'] ?? '');
        $autoInterest = filter_var($request_data['auto_interest'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if($productID !== ''){
            $product = $this->isSessionID($productID) ? (new \App\Libraries\BankRateSync())->product($productID) : null;

            if($product === null || $product['status'] !== 'A'){
                return $this->apiError($this->paymentError('0063', 'Bank Product', 'That bank product is not available any more. Refresh the rates and pick another.'));
            }

            if($product['producttype'] !== $accountType && in_array($accountType, \App\Libraries\BankRateSync::BANK_ACCOUNT_TYPES, true)){
                return $this->apiError($this->paymentError('0067', 'Bank Product', 'The bank product is a ' . strtolower(\App\Libraries\BankRateSync::TYPES[$product['producttype']] ?? 'different') . ' product; pick one that matches the account type.'));
            }
        }

        $result = $this->runAtomic(function () use ($userID, $accountName, $groupID, $amount, $methodInput, $accountType, $productID, $bankName, $autoInterest) {
            $accountCreation = $this->accountInitProccess($userID, $accountName, $groupID, $amount);

            if(isset($accountCreation['error_id'])){
                return $accountCreation;
            }

            $accountID = $accountCreation['AccountSessionID'];
            $method = $this->paymentMethodCreateProccess($userID, $accountID, $methodInput);

            if(isset($method['error_id'])){
                return $method;
            }

            if($productID !== ''){
                $link = $this->linkAccountProduct($userID, $accountID, $productID, $autoInterest);

                if(isset($link['error_id'])){
                    return $link;
                }
            }

            $this->saveAccountType($userID, $accountID, $accountType, $bankName);

            return ['success' => true, 'data' => ['accountCreation' => $accountCreation, 'paymentMethod' => $method]];
        });

        return $this->apiResult($result, 'Successfully created account');
    }

    public function accountPage($account_id)
    {
        // Login Check        
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        // Post recurring transactions (salary, rent ...) that fell due since the last visit
        $this->postDueRecurring($this->userAccess());
        $this->postDueInterest($this->userAccess());

        $userID = $this->userAccess();

        // The account must be the user's own; an account without transactions still opens
        $account = $this->accountWithProduct($userID, $account_id);

        if(isset($account['error_id'])){
            return redirect()->to(base_url('/account/list'))->with('status', $account['error_message']);
        }

        $data = [
            'StoredText' => [
                'Header' => $account['AccountName'],
                'ScreenTitle' => $account['AccountName'],
                'ErrorStatus' => 'Error Status: ',
            ]
        ];

        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = $account_id; // highlights this account under Account List

        $response = $this->activeTransactionListAccess($userID, $account_id);
        $transaction = $this->rowsOf($response);

        $data['transactionInfo'] = [
            'page_limit' => 10,
            'count' => count($transaction),
            'page_count' => ceil(count($transaction) / 10),
            'transactions' => $transaction,
            'allow_all_accounts' => true,
            'paymentMethodMap' => $this->paymentMethodMap($this->userAccess())
        ];

        $data['transaction_details_container'] = view('Transaction/transaction_details_module', $data['transactionInfo']);
        $data['accountPaymentMethods'] = $this->paymentMethodList($userID, $account_id);
        $data['accountID'] = $account_id;
        $data['account'] = $account;

        // Bank product, rate, a better offer of the same type, interest due
        $bank = new \App\Libraries\BankRateSync();
        $bank->syncIfStale();
        $linked = $account['BankProductSessionID'] ? $bank->product($account['BankProductSessionID']) : null;
        $data['bankProducts'] = $bank->products();
        $data['productTypes'] = \App\Libraries\BankRateSync::TYPES;
        $data['accountTypes'] = \App\Libraries\BankRateSync::ACCOUNT_TYPES;
        $data['accountFees'] = $linked ? ($bank->fees([$linked['sessionid']])[$linked['sessionid']] ?? []) : [];
        $data['betterProduct'] = $linked ? $bank->betterThan($linked) : null;
        $quote = $linked ? $this->interestQuote($userID, $account_id, 'today', $account) : null;
        $data['interestDue'] = (is_array($quote) && ! isset($quote['error_id'])) ? $quote : null;

        // Quick actions: other accounts (transfer / withdraw), budgets
        $data['otherAccounts'] = array_values(array_filter($this->rowsOf($this->activeAccountListAccessModule($userID)), static function ($row) use ($account_id) {
            return $row['AccountSessionID'] !== $account_id;
        }));
        $data['cashAccountIDs'] = array_values(array_unique(array_column(array_filter($this->paymentMethodList($userID), static function ($method) {
            return $method['PaymentMethodType'] === 'CASH';
        }), 'AccountSessionID')));
        $data['budgetInfo'] = $this->rowsOf($this->budgetList($userID));

        return view('Account/page', $data);
    }

    public function updateAccount(){
        $request_data = $this->handlePOSTBodyDataList();

        // passing parameters --> (account_id [required], accountName [required], group_id [required], amount [required])
        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_id', 'account_name', 'account_group']);

        if(isset($requiredParameters['error_id']))
        {
            return $requiredParameters;
        }

        $accountID = $request_data['account_id'];
        $accountName = $request_data['account_name'];
        $groupID = $request_data['account_group'];

        // TODO:: link user default user
        $accountUpdate = $this->accountUpdateProccess($this->userAccess(), $accountID, $accountName, $groupID);

        if(isset($accountUpdate['error_id'])){            
            $response = $this->errorHandleforAPIResponses($accountUpdate);
            echo $response;
            exit;
            // return $accountUpdate;
        }

        $response = [
            'data' => [
                'success'  => true,
                'response' => 'Successfully updated account',
                'data' => [
                    'accountUpdate' => $accountUpdate
                ]
            ]
        ];

        echo json_encode($response['data']);
        exit;
    }

    public function deleteAccount(){
        $request_data = $this->handlePOSTBodyDataList();

        // passing parameters --> (account_id [required])
        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_id']);

        if(isset($requiredParameters['error_id']))
        {
            return $requiredParameters;
        }

        $accountID = $request_data['account_id'];

        // TODO:: link user default user
        $accountDelete = $this->accountDeleteProccess($this->userAccess(), $accountID);

        if(isset($accountDelete['error_id'])){            
            $response = $this->errorHandleforAPIResponses($accountDelete);
            echo $response;
            exit;
            // return $accountDelete;
        }

        $this->deactivateAccountPaymentMethods($this->userAccess(), $accountID);

        $response = [
            'data' => [
                'success'  => true,
                'response' => 'Successfully deleted account',
                'data' => [
                    'accountDelete' => $accountDelete
                ]
            ]
        ];

        echo json_encode($response['data']);
        exit;
    }

    public function accountGroupPage(){
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }
        
        $data = [];

        $data = [
            'StoredText' => [
                'Header' => 'Account Groups',
                'ScreenTitle' => 'Account Groups',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];

        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'account-group';

        // TODO:: link user default user
        // $this->limit = 10;
        $accountGroupDetails = $this->activeAccountGroupsListAccessModule($this->userAccess());
        // $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($accountGroupDetails['error_id'])){
            // TODO:: Change the route the accout create URL
            // return $this->errorHandleLogAndPageRedirection($accountGroupDetails, $this->init_trigger_url);
            if($accountGroupDetails['error_id'] == '0004'){
                # code...
                $data['account_group_details_container'] = '';
                return view('Account/create_account_group', $data);
            }
        }

        if(!isset($accountGroupDetails[0])){
            $accountGroups[] = $accountGroupDetails;
        }else{
            $accountGroups = $accountGroupDetails;
        }

        $data['accountGroupInfo'] = [
            'page_limit' => 10,
            'count' => count($accountGroups),
            'page_count' => ceil(count($accountGroups) / 10),
            'accountGroups' => $accountGroups,
            'edit_mode' => true
        ];

        $data['account_group_details_container'] = view('Account/commonModule/account_group_details_module', $data['accountGroupInfo']);

        return view('Account/create_account_group', $data);
    }

    public function createAccountGroup(){
        $request_data = $this->handlePOSTBodyDataList();

        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_group_name']);

        if(isset($requiredParameters['error_id'])){
            return $requiredParameters;
        }

        $accountGroupName = $request_data['account_group_name'];

        // TODO:: link user default user
        $accountGroupCreate = $this->accountGroupCreateProccess($this->userAccess(), $accountGroupName);

        if(isset($accountGroupCreate['error_id'])){            
            $response = $this->errorHandleforAPIResponses($accountGroupCreate);
            echo $response;
            exit;
            // return $accountGroupCreate;
        }

        $response = [
            'data' => [
                'success'  => true,
                'response' => 'Successfully created account group',
            ]
        ];

        echo json_encode($response['data']);
        exit;
    }

    public function updateAccountGroup(){
        $request_data = $this->handlePOSTBodyDataList();

        // passing parameters --> (account_group_id [required], account_group_name [required])
        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_group_id', 'account_group_name']);

        if(isset($requiredParameters['error_id']))
        {
            return $requiredParameters;
        }

        $accountGroupID = $request_data['account_group_id'];
        $accountGroupName = $request_data['account_group_name'];

        // TODO:: link user default user
        $accountGroupUpdate = $this->accountGroupUpdateProccess($this->userAccess(), $accountGroupID, $accountGroupName);

        if(isset($accountGroupUpdate['error_id'])){            
            $response = $this->errorHandleforAPIResponses($accountGroupUpdate);
            echo $response;
            exit;
        }

        $response = [
            'data' => [
                'success'  => true,
                'response' => 'Successfully updated account group',
            ]
        ];
    }

    public function deleteAccountGroup(){
        $request_data = $this->handlePOSTBodyDataList();    

        $requiredParameters = $this->handleRequiredParameters($request_data, ['account_group_id']);

        if(isset($requiredParameters['error_id']))
        {
            return $requiredParameters;
        }

        $accountGroupID = $request_data['account_group_id'];

        // TODO:: link user default user
        $accountGroupDelete = $this->accountGroupDeleteProccess($this->userAccess(), $accountGroupID);

        if(isset($accountGroupDelete['error_id'])){            
            $response = $this->errorHandleforAPIResponses($accountGroupDelete);
            echo $response;
            exit;
        }

        $response = [
            'data' => [
                'success'  => true,
                'response' => 'Successfully deleted account group',
            ]
        ];
    }

    public function addBudget(){
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        $data = [];
        
        $data['StoredText'] = [
            'Header' => 'Budgets',
            'ScreenTitle' => 'Budgets',
            'ErrorStatus' => 'Error Status: ',
        ];

        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'budget-proccesss';

        $this->limit = 10;
        $response = $this->budgetList($this->userAccess());
        $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($response['error_id'])){
            if($response['error_id'] != '0004'){
                // TODO:: Change the route the accout create URL
                return $this->errorHandleLogAndPageRedirection($response, $this->init_trigger_url);
            }else{
                $data['budget_details_container'] = '';
                $data['budgetInfo'] = [
                    'payment_plan' => $this->periodic,
                ];
            }
        }else{
            if(!isset($response[0])){
                $budgets[] = $response;
            }else{
                $budgets = $response;
            }

            // Spent / left in the current period (the Default budget is the unplanned pool, not limited)
            foreach ($budgets as $key => $budget) {
                $budgets[$key]['Usage'] = $this->budgetUsage($this->userAccess(), $budget, date('Y-m-d'));
            }

            $data['budgetInfo'] = [
                'page_limit' => 10,
                'count' => count($budgets),
                'page_count' => ceil(count($budgets) / 10),
                'budgets' => $budgets,
                'payment_plan' => $this->periodic,
                'edit_mode' => true
            ];

            $data['budget_details_container'] = view('Account/commonModule/budget_details_module', $data['budgetInfo']);
        }

        $data['unplannedIncome'] = $this->unplannedIncome($this->userAccess(), 'M');

        return view('Account/create_budget', $data);
    }

    /** POST /js-request/budget/create  (flowchart "Set Up Budget": amount must fit the unplanned income) */
    public function createBudget(){
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();

        if(strcasecmp(trim((string) ($request_data['budgetName'] ?? '')), 'Default') === 0){
            return $this->apiError($this->paymentError('0048', 'Budget', 'The name "Default" is reserved for the unplanned income budget.'));
        }

        $budget = $this->validateBudget($userID, $request_data['budgetName'] ?? '', $request_data['budgetPlan'] ?? '', $request_data['budgetAmount'] ?? '');

        if(isset($budget['error_id'])){
            return $this->apiError($budget);
        }

        $budgetCreation = $this->budgetInitProccess($userID, $budget['name'], $budget['periodic'], $budget['amount']);

        return $this->apiResult($budgetCreation, 'Successfully created budget');
    }

    /** POST /js-request/budget/update */
    public function updateBudget(){
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();

        $requiredParameters = $this->handleRequiredParameters($request_data, ['budget_id','budget_name','budget_plan','budget_amount']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $budgetID = $request_data['budget_id'];
        $current = $this->budgetRow($userID, $budgetID);

        if($current === null){
            return $this->apiError($this->paymentError('0035', 'Budget', 'The selected budget was not found.'));
        }

        $isDefault = $this->isDefaultBudget($current);
        $renamingToDefault = strcasecmp(trim((string) $request_data['budget_name']), 'Default') === 0;

        if($isDefault !== $renamingToDefault){
            return $this->apiError($this->paymentError('0048', 'Budget', 'The "Default" budget cannot be renamed, and no other budget can take that name.'));
        }

        $budget = $this->validateBudget($userID, $request_data['budget_name'], $request_data['budget_plan'], $request_data['budget_amount'], $budgetID);

        if(isset($budget['error_id'])){
            return $this->apiError($budget);
        }

        $budgetUpdate = $this->budgetUpdateProccess($userID, $budgetID, $budget['name'], $budget['periodic'], $budget['amount']);

        return $this->apiResult($budgetUpdate, 'Successfully updated budget');
    }

    public function deleteBudget(){
        $request_data = $this->handlePOSTBodyDataList();

        $requiredParameters = $this->handleRequiredParameters($request_data, ['budget_id']);

        if(isset($requiredParameters['error_id'])){
            return $requiredParameters;
        }

        $budgetID = $request_data['budget_id'];

        // TODO:: link user default user
        $budgetDelete = $this->budgetDeleteProccess($this->userAccess(), $budgetID);

        // TODO:: Error Handling Method
        if(isset($budgetDelete['error_id'])){
            return $this->errorHandleforAPIResponses($budgetDelete);
        }

        $response = [
            'data' => [
                'success'  => true,
                'response' => 'Successfully deleted budget',
                'data' => [
                    'budgetDelete' => $budgetDelete
                    ]
            ],
            'code' => 200
        ];

        echo json_encode($response['data']);
        exit;
    }
}
