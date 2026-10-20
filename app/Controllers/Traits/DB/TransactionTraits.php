<?php

namespace App\Controllers\Traits\DB;

use App\Models\Database\Common;

trait TransactionTraits{
    // the traits structure is in a format that even though the feild names change we access these traits from the frontend the same way so we would never have to make changes for frontend when we change table feild names or any data accessing method we use data changes the data type
       
    # 'FrontEndValues'[Not changed unless UI structures change] => 'BackEndFeilds'[Could change when table feild names or any data accessing method changes the data type]

    # Account Group Table Structure assigned
    public function accountgroupStructure()
    {
        $this->table = 'accountgroup';
        
        $this->primaryKeys = [
            'AccountGroupID'        => 'id',
            'AccountGroupSessionID' => 'sessionid'
        ];
        
        $this->allKeys = [
            'AccountID'                 => 'id',
            'AccountGroupSessionID'     => 'sessionid',
            'AccountGroupName'          => 'name',
            'AccountGroupType'          => 'grouptype',
            'AccountGroupStatus'        => 'status',
            'UserSessionID'             => 'usersessionid'
        ];
        
        // TODO: Update with with user information
        $this->foreignKeys = [
            'UserSessionID' => 'user'
        ];
    }

    # Account Table Structure assigned
    public function accountStructure()
    {
        $this->table = 'account';
        
        $this->primaryKeys = [
            'AccountID'         => 'id',
            'AccountSessionID'  => 'sessionid'
        ];
        
        $this->allKeys = [
            'AccountID'                 => 'id',
            'UserSessionID'             => 'usersessionid',
            'AccountGroupSessionID'     => 'accountgroupid',
            'AccountSessionID'          => 'sessionid',
            'AccountName'               => 'name',
            'AccountCurrentBalance'     => 'currentbalance',
            'AccountNumber'             => 'accountnumber',
            'AccountCreatedDateTime'    => 'createddatetime',
            'AccountUpdatedDateTime'    => 'updateddatetime',
            'AccountStatus'             => 'status'
        ];
        
        // TODO: Update with with user information
        $this->foreignKeys = [
            'UserSessionID'         => 'user',
            'AccountGroupSessionID' => 'accountgroup',
        ];
    }

    # Transaction Table Structure assigned
    public function transactionStructure()
    {
        $this->table = 'transaction';
        
        $this->primaryKeys = [
            'TransactionID'         => 'id',
            'TransactionSessionID'  => 'sessionid'
        ];
        
        $this->allKeys = [
            'TransactionID'                 => 'id',
            'TransactionSessionID'          => 'sessionid',
            'AccountSessionID'              => 'accountsessionid',
            'BudgetSessionID'               => 'budgetsessionid',
            'TransactionDescription'        => 'description',
            'TransactionDate'               => 'date',
            'TransactionDateTime'           => 'datetime',
            'TransactionAmount'             => 'amount',
            'TransactionPayableType'        => 'paymenttype',
            'TransactionCreatedDateTime'    => 'createddatetime',
            'TransactionUpdatedDateTime'    => 'updateddatetime',
            'TransactionStatus'             => 'status',
            'PaymentSessionID'              => 'paymentsessionid',
            'PaymentMethodSessionID'        => 'paymentmethodsessionid',
            'TransactionBalanceAfter'       => 'balanceafter'
        ];
        
        $this->foreignKeys = [
            'AccountSessionID' => 'account',
            'BudgetSessionID'  => 'budget'
        ];
    }

    # Budget Table Structure assigned
    public function budgetStructure()
    {
        $this->table = 'budget';
        
        $this->primaryKeys = [
            'BudgetID'  => 'id',
            'BudgetSessionID' => 'sessionid'
        ];
        
        $this->allKeys = [
            'BudgetID'              => 'id',
            'BudgetSessionID'       => 'sessionid',
            'UserSessionID'         => 'usersessionid',
            'BudgetName'            => 'name',
            'BudgetPeriodic'        => 'periodic',
            'BudgetAmount'          => 'amount',
            'BudgetCreatedDateTime' => 'createddatetime',
            'BudgetUpdatedDateTime' => 'updateddatetime',
            'BudgetStatus'          => 'status'
        ];
        
        $this->foreignKeys = [
            'UserSessionID' => 'user'
        ];
    }
}