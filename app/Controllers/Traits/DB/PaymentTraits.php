<?php

namespace App\Controllers\Traits\DB;

use App\Models\Database\Common;

trait PaymentTraits{
    // the traits structure is in a format that even though the feild names change we access these traits from the frontend the same way so we would never have to make changes for frontend when we change table feild names or any data accessing method we use data changes the data type
       
    # 'FrontEndValues'[Not changed unless UI structures change] => 'BackEndFeilds'[Could change when table feild names or any data accessing method changes the data type]

    # Purchase Plan Table Structure assigned
    public function paymentplanStructure()
    {
        $this->table = 'paymentplan';
        
        $this->primaryKeys = [
            'PaymentPlanID'         => 'id',
            'PaymentPlanSessionID'  => 'sessionid'
        ];
        
        $this->allKeys = [
            'PaymentPlanID'                 => 'id',
            'PaymentPlanSessionID'          => 'sessionid',
            'PaymentPlanLink'               => 'paymentplanlink',
            'PaymentPlanLinkSessionID'      => 'paymentplansessionid',
            'UserSessionID'                 => 'usersessionid',
            'PaymentPlan'                   => 'paymentplan',
            'PaymentPlanPeriod'             => 'period',
            'PaymentPlanRate'               => 'rate',
            'PaymentPlanStartDate'          => 'startdate',
            'PaymentPlanEndDate'            => 'enddate',
            'PaymentPlanStartDateTime'      => 'startdatetime',
            'PaymentPlanEndDateTime'        => 'enddatetime',
            'PaymentPlanCreatedDateTime'    => 'createddatetime',
            'PaymentPlanUpdatedDateTime'    => 'updateddatetime',
            'PaymentPlanStatus'             => 'status',
            'PaymentPlanDescription'        => 'description',
            'PaymentPlanAmount'             => 'amount'
        ];
        
        $this->foreignKeys = [
        ];
    }

    # Payable Table Structure assigned
    public function payableStructure()
    {
        $this->table = 'payable';
        
        $this->primaryKeys = [
            'PayableID'         => 'id',
            'PayableSessionID'  => 'sessionid'
        ];
        
        $this->allKeys = [
            'PayableID'                 => 'id',
            'PayableSessionID'          => 'sessionid',
            'PaymentPlanSessionID'      => 'paymentplansessionid',
            'PayableDueDate'            => 'duedate',
            'PayableDueDateTime'        => 'duedatetime',
            'PayablePaidDate'           => 'paiddate',
            'PayableDueAmount'          => 'dueamount',
            'PayablePaidAmount'         => 'paidamount',
            'PayableStatus'             => 'status',
            'PayableCreatedDateTime'    => 'createddatetime',
            'PayableUpdatedDateTime'    => 'updateddatetime'
        ];
        
        $this->foreignKeys = [
            'PaymentPlanSessionID' => 'paymentplan',
        ];
    }

    # Payment Method Table Structure assigned (payment methods owned by an account)
    public function paymentmethodStructure()
    {
        $this->table = 'paymentmethod';

        $this->primaryKeys = [
            'PaymentMethodID'           => 'id',
            'PaymentMethodSessionID'    => 'sessionid'
        ];

        $this->allKeys = [
            'PaymentMethodID'               => 'id',
            'PaymentMethodSessionID'        => 'sessionid',
            'AccountSessionID'              => 'accountsessionid',
            'UserSessionID'                 => 'usersessionid',
            'PaymentMethodName'             => 'name',
            'PaymentMethodType'             => 'type',
            'PaymentMethodReference'        => 'reference',
            'PaymentMethodCreditLimit'      => 'creditlimit',
            'PaymentMethodIsDefault'        => 'isdefault',
            'PaymentMethodStatus'           => 'status',
            'PaymentMethodCreatedDateTime'  => 'createddatetime',
            'PaymentMethodUpdatedDateTime'  => 'updateddatetime'
        ];

        $this->foreignKeys = [
            'AccountSessionID'  => 'account',
            'UserSessionID'     => 'user'
        ];
    }

    # Payment Table Structure assigned (one row per payment / receipt / transfer)
    public function paymentStructure()
    {
        $this->table = 'payment';

        $this->primaryKeys = [
            'PaymentID'         => 'id',
            'PaymentSessionID'  => 'sessionid'
        ];

        $this->allKeys = [
            'PaymentID'                 => 'id',
            'PaymentSessionID'          => 'sessionid',
            'UserSessionID'             => 'usersessionid',
            'AccountSessionID'          => 'accountsessionid',
            'PaymentMethodSessionID'    => 'paymentmethodsessionid',
            'ToAccountSessionID'        => 'toaccountsessionid',
            'BudgetSessionID'           => 'budgetsessionid',
            'PaymentDirection'          => 'direction',
            'PaymentCategory'           => 'category',
            'PaymentSettlementType'     => 'settlementtype',
            'PaymentLinkType'           => 'linktype',
            'PaymentLinkSessionID'      => 'linksessionid',
            'PaymentAmount'             => 'amount',
            'PaymentReference'          => 'reference',
            'PaymentDescription'        => 'description',
            'PaymentDate'               => 'date',
            'PaymentDateTime'           => 'datetime',
            'PaymentStatus'             => 'status',
            'PaymentCreatedDateTime'    => 'createddatetime',
            'PaymentUpdatedDateTime'    => 'updateddatetime'
        ];

        $this->foreignKeys = [
            'UserSessionID'     => 'user',
            'AccountSessionID'  => 'account'
        ];
    }

    # Due Paid Table Structure assigned (allocation of a payment against a payable)
    public function duepaidStructure()
    {
        $this->table = 'duepaid';

        $this->primaryKeys = [
            'DuePaidID'         => 'id',
            'DuePaidSessionID'  => 'sessionid'
        ];

        $this->allKeys = [
            'DuePaidID'                 => 'id',
            'DuePaidSessionID'          => 'sessionid',
            'PayableSessionID'          => 'payablesessionid',
            'PaymentSessionID'          => 'paymentsessionid',
            'DuePaidSettledPortion'     => 'settledportion',
            'DuePaidStatus'             => 'status',
            'DuePaidCreatedDateTime'    => 'createddatetime',
            'DuePaidUpdatedDateTime'    => 'updateddatetime'
        ];

        $this->foreignKeys = [
            'PayableSessionID'  => 'payable',
            'PaymentSessionID'  => 'payment'
        ];
    }

    # Claim Table Structure assigned
    public function claimStructure()
    {
        $this->table = 'claim';
        
        $this->primaryKeys = [
            'ClaimID'  => 'id'
        ];
        
        $this->allKeys = [
            'ClaimID'               => 'id',
            'StackholderSessionID'  => 'stackholdersessionid',
            'PaymentPlanSessionID'  => 'paymentplansessionid',
            'ClaimRate'             => 'rate',
            'ClaimStartedDate'      => 'starteddate',
            'ClaimEndDate'          => 'endate',
            'ClaimAmount'           => 'amount',
            'ClaimCreatedDateTime'  => 'createddatetime',
            'ClaimUpdatedDateTime'  => 'updateddatetime'
        ];
        
        $this->foreignKeys = [
            'StackholderSessionID'  => 'stackholdersessionid',
            'PaymentPlanSessionID'  => 'paymentplansessionid'
        ];
    }
}