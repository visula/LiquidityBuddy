<?php

namespace App\Controllers;
use DateTime;

class AccountService extends MainService
{
    public function getAccountSessionID($ID)
    {

        $condtionList = ['AccountID' => $ID, 'AccountStatus' => 'A'];
        $feildList = ['AccountSessionID'];

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['account'], 
                        $condtionList,
                        $feildList
                    );

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function getAccountName($userID, $accountID)
    {
        $getAccountDetails = $this->activeAccountListAccessModule($userID, $accountID);

        if(isset($getAccountDetails['error_id']))
        {
            return $getAccountDetails;
        }

        return $getAccountDetails['AccountName'];
    }

    // Active Account List Access of the User ID
    public function activeAccountGroupsListAccessModule($userID, $accountgroupID = '')
    {
        $condtionList = ['UserSessionID' => $userID, 'AccountGroupStatus' => 'A'];
        $feildList = ['AccountGroupSessionID','AccountGroupName'];
        $organizerList = [];

        if($accountgroupID != ''){
            $condtionList['AccountGroupSessionID'] = $accountgroupID;
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['accountgroup'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );

        return $response;
    }

    // Active Account List Access of the User ID
    public function activeAccountListAccessModule($userID, $accountID = '')
    {
        $condtionList = ['UserSessionID' => $userID, 'AccountStatus' => 'A'];
        $feildList = ['AccountSessionID','AccountName','AccountGroupSessionID','AccountGroupName','AccountCurrentBalance'];
        $organizerList = [];

        if($accountID != ''){
            $condtionList['AccountSessionID'] = $accountID;
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['account', 'accountgroup'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );

        return $response;
    }

    public function getAccountCurrentBalance($userID, $accountID)
    {
        $getAccountDetails = $this->activeAccountListAccessModule($userID, $accountID);

        if(isset($getAccountDetails['error_id']))
        {
            return $getAccountDetails;
        }

        return $getAccountDetails['AccountCurrentBalance'];
    }

    public function accountInitProccess($userID, $accountName, $groupID, $amount)
    {
        $data = [
            'UserSessionID' => $userID,
            'AccountName' => $accountName,
            'AccountGroupSessionID' => $groupID,
            'AccountCurrentBalance' => 0
        ];

        // TODO:: Account Creation Process
        $response = $this->insertDatatoDB('account', $data);

        if(isset($response['error_id'])){
            return $response;
        }
        
        $response = $this->getAccountSessionID($response['id']);

        if(isset($response['error_id']))
        {
            return $response;
        }

        $accountID = $response['AccountSessionID'];
        
        $response = $this->getDefaultBudgetID($userID);

        if(isset($response['error_id']))
        {
            return $response;
        }

        $budgetID = $response['BudgetSessionID'];

        // Opening balance: a zero balance (e.g. a new credit card account) needs no transaction
        if((float) $amount > 0){
            $response = $this->transactionInitProccess($userID, $accountName, $amount, 'income', $accountID, $budgetID);

            if(isset($response['error_id']))
            {
                return $response;
            }

            $response = $this->budgetPriceChange($userID, $budgetID, $amount);

            if(isset($response['error_id']))
            {
                return $response;
            }
        }

        return ['success' => true, 'AccountSessionID' => $accountID];
    }

    // Active Transaction List Access of the User ID
    public function activeTransactionListAccess($userID, $accountID = '')
    {
        $condtionList = ['UserSessionID' => $userID, 'AccountStatus' => 'A', 'TransactionStatus' => 'A'];
        $feildList = ['AccountSessionID','AccountName','TransactionDescription','TransactionDate','TransactionAmount','TransactionPayableType','AccountCurrentBalance','PaymentMethodSessionID','TransactionBalanceAfter'];
        $organizerList = ['orderBy' => ['TransactionCreatedDateTime' => 'DESC', 'TransactionID' => 'DESC']];

        if($accountID != ''){
            $condtionList['AccountSessionID'] = $accountID;
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['account','transaction'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );

        return $response;
    }

    public function getBudgetDetails($userID, $budgetID = '')
    {
        $condtionList = ['UserSessionID' => $userID, 'BudgetStatus' => 'A'];
        $feildList = ['BudgetName','BudgetCreatedDateTime','BudgetUpdatedDateTime','BudgetPeriodic','BudgetAmount'];
        $organizerList = ['orderBy' => ['BudgetCreatedDateTime' => 'DESC', 'BudgetID' => 'DESC']];

        if($budgetID != ''){
            $condtionList['BudgetSessionID'] = $budgetID;
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['budget'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );
        
        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function getDefaultBudgetID($userID)
    {

        $condtionList = ['UserSessionID' => $userID, 'BudgetName' => 'Default'];
        $feildList = ['BudgetSessionID'];
        $organizerList = ['orderBy' => ['BudgetCreatedDateTime' => 'DESC', 'BudgetID' => 'DESC']];

        $response = $this->getDatafromDB(
                        ['budget'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );
        
        if(isset($response['error_id']))
        {
            return $response;
        }
            
        return $response;
    }

    /**
     * Post a single income/expense transaction against an account.
     * Validates before anything is written (the old version inserted the row first
     * and silently returned when funds were short, leaving a transaction with no
     * balance change). Plain balance check only - payments that may use a credit
     * card limit go through PaymentService::recordPayment().
     */
    protected function transactionInitProccess($userID, $description, $amount, $type, $accountID, $budgetID, $extra = [])
    {
        $amount = $this->toAmount($amount);

        if(!in_array($type, ['income', 'expense'], true)){
            return [
                'error_id' => '0028',
                'category' => 'Development_Issue',
                'error_category' => 'Transaction Type',
                'error_message'  => 'Transaction type must be income or expense'
            ];
        }

        if($amount === null || $amount <= 0){
            return [
                'error_id' => '0024',
                'category' => 'Format_Issue',
                'error_category' => 'Transaction Amount',
                'error_message'  => 'The transaction amount must be greater than zero'
            ];
        }

        $currentAccountBalance = $this->getAccountCurrentBalance($userID, $accountID);

        if(isset($currentAccountBalance['error_id'])){
            return $currentAccountBalance;
        }

        if($type == 'expense' && (float) $currentAccountBalance < $amount){
            return [
                'error_id' => '0019',
                'category' => 'Data_Issue',
                'error_category' => 'Account Balance',
                'error_message'  => 'The amount entered is larger than the amount in account. Plesae check and rectify.'
            ];
        }

        return $this->runAtomic(function () use ($userID, $description, $amount, $type, $accountID, $budgetID, $extra) {
            return $this->postTransaction($userID, $accountID, $type, $amount, $description, $budgetID, $extra);
        });
    }

    /**
     * Low level ledger write: moves the account balance atomically in SQL and
     * writes the transaction row with the resulting balance. No validation here -
     * callers (transactionInitProccess / recordPayment) validate first and wrap
     * the call in runAtomic().
     *
     * $extra: PaymentSessionID, PaymentMethodSessionID, date (Y-m-d)
     */
    protected function postTransaction($userID, $accountID, $type, $amount, $description, $budgetID, array $extra = [])
    {
        $db = \Config\Database::connect();
        $now = time();
        $amountSql = number_format((float) $amount, 2, '.', '');
        $date = $this->validDate($extra['date'] ?? '') ?? date('Y-m-d');

        $db->table('account')
            ->set('currentbalance', 'currentbalance ' . ($type === 'expense' ? '-' : '+') . ' ' . $amountSql, false)
            ->set('updateddatetime', $now)
            ->where('sessionid', $accountID)
            ->where('usersessionid', $userID)
            ->where('status', 'A')
            ->update();

        if($db->affectedRows() < 1){
            return [
                'error_id' => '0022',
                'category' => 'Data_Issue',
                'error_category' => 'Account Balance',
                'error_message'  => 'The account balance could not be updated, please refresh and retry.'
            ];
        }

        $balanceAfter = (float) $db->table('account')->select('currentbalance')->where('sessionid', $accountID)->get()->getRow()->currentbalance;

        $createTransactionData = [
            'TransactionDescription'     => mb_substr((string) $description, 0, 50),
            'TransactionAmount'          => $amountSql,
            'TransactionPayableType'     => $type,
            'AccountSessionID'           => $accountID,
            'TransactionDate'            => $date,
            'TransactionDateTime'        => ($date === date('Y-m-d')) ? $now : strtotime($date . ' ' . date('H:i:s')),
            'TransactionCreatedDateTime' => $now,
            'TransactionUpdatedDateTime' => $now,
            'TransactionBalanceAfter'    => number_format($balanceAfter, 2, '.', '')
        ];

        if(!empty($budgetID)){
            $createTransactionData['BudgetSessionID'] = $budgetID;
        }

        foreach (['PaymentSessionID', 'PaymentMethodSessionID'] as $link) {
            if(!empty($extra[$link])){
                $createTransactionData[$link] = $extra[$link];
            }
        }

        $newTransation = $this->insertDatatoDB('transaction', $createTransactionData);

        if(isset($newTransation['error_id'])){
            return $newTransation;
        }

        return [
            'createTransactionData' => $createTransactionData,
            'currentAccountBalance' => $balanceAfter,
            'TransactionID'         => $newTransation['id']
        ];
    }
    // ... (Inside your controller)

    public function budgetInitProccess($userID, $budgetName, $budgetPlan, $budgetAmount)
    {
        $data = [
            'UserSessionID' => $userID,
            'BudgetName' => $budgetName,
            'BudgetPeriodic' => $budgetPlan,
            'BudgetAmount' => $budgetAmount,
            'BudgetCreatedDateTime' => strtotime(date_format(new DateTime(),'Y-m-d h:i:s')),
            'BudgetUpdatedDateTime' => strtotime(date_format(new DateTime(),'Y-m-d h:i:s'))
        ];

        $newBudget = $this->insertDatatoDB('budget', $data);

        if(isset($newBudget['error_id'])){
            return $newBudget;
        }

        return $newBudget;
    }

    public function accountUpdateProccess($userID, $accountID, $name = '', $groupID = '')
    {
        $response = $this->activeAccountListAccessModule($userID, $accountID);
        $updateList = [];

        if(isset($response['error_id']))
        {
            return $response;
        }

        if($name != '' && $name != $response['AccountName'])
        {
            $updateList['AccountName'] = $name;
        }

        if($groupID != '' && $groupID != $response['AccountGroupSessionID'])
        {
            $updateList['AccountGroupSessionID'] = $groupID;
        }

        if(count($updateList) == 0)
        {
            return $response;
        }

        $response = $this->updateDBData('account', ['UserSessionID' => $userID, 'AccountSessionID' => $accountID], $updateList);

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function accountDeleteProccess($userID, $accountID)
    {
        $response = $this->activeAccountListAccessModule($userID, $accountID);

        if(isset($response['error_id']))
        {
            return $response;
        }

        if($response['AccountCurrentBalance'] != 0){
            return $error = [
                    'error_id' => '0020', // LATEST Error Code
                    'category' => 'Data_Issue',
                    'error_category' => 'Account Balance',
                    'error_message'  => 'Cannot Delete Account. Please remove transactions the account balance first.',
                    'data' => $response
                ];
        }

        $response = $this->updateDBData('account', ['UserSessionID' => $userID, 'AccountSessionID' => $accountID], ['AccountStatus' => "D"]);

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function budgetPriceChange($userID, $budgetID, $amount)
    {
        $response = $this->getBudgetDetails($userID, $budgetID);

        if(isset($response['error_id']))
        {
            return $response;
        }

        $response = $this->updateDBData('budget', ['UserSessionID' => $userID, 'BudgetSessionID' => $budgetID], ['BudgetAmount' => $response['BudgetAmount'] + $amount]);

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function budgetUpdateProccess($userID, $budgetID, $name = '', $plan = 'M', $amount = 0)
    {
        $response = $this->getBudgetDetails($userID, $budgetID);
        $updateList = [];

        if(isset($response['error_id']))
        {
            return $response;
        }

        if($name != '' && $name != $response['BudgetName'])
        {
            $updateList['BudgetName'] = $name;
        }

        if($plan != '' && $plan != $response['BudgetPeriodic'])
        {
            $updateList['BudgetPeriodic'] = $plan;
        }

        if($amount != 0 && $amount != $response['BudgetAmount'])
        {
            $updateList['BudgetAmount'] = $amount;
        }

        if(!isset($updateList) || $updateList == [])
        {
            return ['success' => false, 'response' => 'No update required'];
        }

        $response = $this->updateDBData('budget', ['UserSessionID' => $userID, 'BudgetSessionID' => $budgetID], $updateList);

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function budgetDeleteProccess($userID, $budgetID)
    {
        $response = $this->getBudgetDetails($userID, $budgetID);

        if(isset($response['error_id']))
        {
            return $response;
        }

        $response = $this->updateDBData('budget', ['UserSessionID' => $userID, 'BudgetSessionID' => $budgetID], ['BudgetStatus' => 'D']);

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }

    public function accountGroupCreateProccess($userID, $accountGroupName)
    {
        $data = [
            'UserSessionID' => $userID,
            'AccountGroupName' => $accountGroupName,
        ];

        $newAccountGroup = $this->insertDatatoDB('accountgroup', $data);

        if(isset($newAccountGroup['error_id']))
        {
            return $newAccountGroup;
        }

        return $newAccountGroup;
    }

    public function accountGroupUpdateProccess($userID, $accountGroupID, $accountGroupName)
    {
        $data = [
            'AccountGroupName' => $accountGroupName,
        ];

        $updateAccountGroup = $this->updateDBData('accountgroup', ['UserSessionID' => $userID, 'AccountGroupSessionID' => $accountGroupID], $data);

        if(isset($updateAccountGroup['error_id']))
        {
            return $updateAccountGroup;
        }

        return $updateAccountGroup;
    }

    public function accountGroupDeleteProccess($userID, $accountGroupID)
    {
        $response = $this->updateDBData('accountgroup', ['UserSessionID' => $userID, 'AccountGroupSessionID' => $accountGroupID], ['AccountGroupStatus' => 'D']);

        if(isset($response['error_id']))
        {
            return $response;
        }

        return $response;
    }
}
