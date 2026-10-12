<?php

namespace App\Controllers\Traits\DB;

trait PlanTraits{
    // the traits structure is in a format that even though the feild names change we access these traits from the frontend the same way so we would never have to make changes for frontend when we change table feild names or any data accessing method we use data changes the data type

    # 'FrontEndValues'[Not changed unless UI structures change] => 'BackEndFeilds'[Could change when table feild names or any data accessing method changes the data type]

    # Plan Category Table Structure assigned (Planning sheet allocation %)
    public function plancategoryStructure()
    {
        $this->table = 'plancategory';

        $this->primaryKeys = [
            'PlanCategoryID'        => 'id',
            'PlanCategorySessionID' => 'sessionid'
        ];

        $this->allKeys = [
            'PlanCategoryID'                => 'id',
            'PlanCategorySessionID'         => 'sessionid',
            'UserSessionID'                 => 'usersessionid',
            'PlanCategoryName'              => 'name',
            'PlanCategoryDirection'         => 'direction',
            'PlanCategoryAllocationPercent' => 'allocationpercent',
            'PlanCategorySortOrder'         => 'sortorder',
            'PlanCategoryStatus'            => 'status',
            'PlanCategoryCreatedDateTime'   => 'createddatetime',
            'PlanCategoryUpdatedDateTime'   => 'updateddatetime'
        ];

        $this->foreignKeys = [
            'UserSessionID' => 'user'
        ];
    }

    # Plan Item Table Structure assigned (one line of the monthly plan)
    public function planitemStructure()
    {
        $this->table = 'planitem';

        $this->primaryKeys = [
            'PlanItemID'        => 'id',
            'PlanItemSessionID' => 'sessionid'
        ];

        $this->allKeys = [
            'PlanItemID'                    => 'id',
            'PlanItemSessionID'             => 'sessionid',
            'UserSessionID'                 => 'usersessionid',
            'PlanItemKey'                   => 'itemkey',
            'PlanItemName'                  => 'name',
            'PlanItemDirection'             => 'direction',
            'PlanItemKind'                  => 'kind',
            'PlanCategorySessionID'         => 'plancategorysessionid',
            'PlanItemAmountType'            => 'amounttype',
            'PlanItemDefaultAmount'         => 'defaultamount',
            'PlanItemDueDay'                => 'dueday',
            'PlanItemStartMonth'            => 'startmonth',
            'PlanItemTrackFrom'             => 'trackfrom',
            'PlanItemRecurring'             => 'recurring',
            'PlanItemEndDate'               => 'enddate',
            'AccountSessionID'              => 'accountsessionid',
            'PaymentMethodSessionID'        => 'paymentmethodsessionid',
            'ToAccountSessionID'            => 'toaccountsessionid',
            'BudgetSessionID'               => 'budgetsessionid',
            'SourcePlanItemSessionID'       => 'sourceplanitemsessionid',
            'PlanItemShareRatio'            => 'shareratio',
            'PlanItemStakeholder'           => 'stakeholder',
            'PaymentPlanSessionID'          => 'paymentplansessionid',
            'PlanItemNotes'                 => 'notes',
            'PlanItemSortOrder'             => 'sortorder',
            'PlanItemStatus'                => 'status',
            'PlanItemCreatedDateTime'       => 'createddatetime',
            'PlanItemUpdatedDateTime'       => 'updateddatetime'
        ];

        $this->foreignKeys = [
            'UserSessionID' => 'user'
        ];
    }

    # Plan Entry Table Structure assigned (one item, one month: a grid cell)
    public function planentryStructure()
    {
        $this->table = 'planentry';

        $this->primaryKeys = [
            'PlanEntryID'        => 'id',
            'PlanEntrySessionID' => 'sessionid'
        ];

        $this->allKeys = [
            'PlanEntryID'                => 'id',
            'PlanEntrySessionID'         => 'sessionid',
            'UserSessionID'              => 'usersessionid',
            'PlanItemSessionID'          => 'planitemsessionid',
            'PlanEntryMonth'             => 'month',
            'PlanEntryPlannedAmount'     => 'plannedamount',
            'PlanEntryDueDate'           => 'duedate',
            'PaymentMethodSessionID'     => 'paymentmethodsessionid',
            'PayableSessionID'           => 'payablesessionid',
            'PlanEntryOverridden'        => 'overridden',
            'PlanEntryStatus'            => 'status',
            'PlanEntryCreatedDateTime'   => 'createddatetime',
            'PlanEntryUpdatedDateTime'   => 'updateddatetime'
        ];

        $this->foreignKeys = [
            'UserSessionID' => 'user'
        ];
    }
}
