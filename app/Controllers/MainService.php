<?php

namespace App\Controllers;
use App\Controllers\AccountService;

class MainService extends BlueprintService
{

    // TODO: remove the user id
    protected $user_id = '63974bc49c63510dd159e9d4585aa3f8';
    protected $init_trigger_url = "/account/group";

    protected $limit = 0;

    protected $periodic = [
            'I' => 'Instant',
            'D' =>'Daily',
            'W' => 'Weekly',
            'M' => 'Monthly',
            'A' => 'Anually'
            // 'C' => 'Continously'
        ];

    public function userAccess()
    {
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        return session()->get('user_id');
    }


    public function commonHead()
    {
        $this->user_id = session()->get('user_id');

        $accountService = new AccountService();
        $response = $accountService->activeAccountListAccessModule($this->userAccess());
        $subMenu = [];

        if(isset($response['error_id']))
        {
            if($response['error_id'] == '0004')
            {
                $subMenu = [];
            }else{
                return redirect()->to(base_url('/account/list'))->with('msg', $response['error_message']);
            }
        }else{

            if(!isset($response[0])){
                $accountDetails[] = $response;
            }else{
                $accountDetails = $response;
            }

            foreach ($accountDetails as $key => $value) {
                # code...

                $subMenu[$key]['Title'] = $value['AccountName'];
                $subMenu[$key]['ID'] = $value['AccountSessionID'];
                $subMenu[$key]['Link'] = '/account/info/'.$value['AccountSessionID'];
            }

        }

        // Top menu, grouped. 'ID' must match the page's $data['CurrentID'] so the
        // current page (and its dropdown) is highlighted. Icons: Bootstrap Icons names.
        $firstName = trim((string) session()->get('user_fname'));
        $lastName = trim((string) session()->get('user_lname'));
        // Actions first, then the (scrolling) list of accounts
        $accountItems = $subMenu === [] ? [] : array_merge([['Divider' => true], ['Header' => 'Your accounts']], $subMenu);

        $head = [
            'Title' => 'Liquidity Buddy',
            'Link' => '/account/list',
            'MainList' => [
                'Home' => [
                    'Title' => 'Dashboard',
                    'Icon' => 'speedometer2',
                    'ID' => 'main-menu',
                    'Link' => '/account/list',
                ],
                'AccountList' => [
                    'Title' => 'Accounts',
                    'Icon' => 'wallet2',
                    'ID' => 'account-list',
                    'Link' => '',
                    'Scroll' => true,
                    'SubMenu' => array_merge([
                        ['ID' => 'add-new-account', 'Title' => 'Add account', 'Icon' => 'plus-circle', 'Link' => '/account/add'],
                        ['ID' => 'account-group', 'Title' => 'Account groups', 'Icon' => 'collection', 'Link' => '/account/group'],
                        ['ID' => 'payment-methods', 'Title' => 'Payment methods', 'Icon' => 'credit-card', 'Link' => '/payment-method/list'],
                    ], $accountItems),
                ],
                'Transactions' => [
                    'Title' => 'Transactions',
                    'Icon' => 'arrow-left-right',
                    'ID' => 'transactions-menu',
                    'Link' => '',
                    'SubMenu' => [
                        ['ID' => 'transaction-proccesss', 'Title' => 'New transaction', 'Icon' => 'plus-circle', 'Link' => '/transaction/add'],
                        ['ID' => 'recurring-list', 'Title' => 'Recurring', 'Icon' => 'arrow-repeat', 'Link' => '/recurring/list'],
                        ['ID' => 'payment-history', 'Title' => 'Payment history', 'Icon' => 'clock-history', 'Link' => '/payment/history'],
                    ],
                ],
                'Budget' => [
                    'Title' => 'Budgets',
                    'Icon' => 'pie-chart',
                    'ID' => 'budget-proccesss',
                    'Link' => '/budget/add',
                ],
                'BankRates' => [
                    'Title' => 'Bank Rates',
                    'Icon' => 'bank',
                    'ID' => 'bank-rates',
                    'Link' => '/bank-rates',
                ],
                'Reports' => [
                    'Title' => 'Reports',
                    'Icon' => 'bar-chart-line',
                    'ID' => 'report-list',
                    'Link' => '',
                    'SubMenu' => [
                        ['ID' => 'income-report', 'Title' => 'Income', 'Icon' => 'graph-up-arrow', 'Link' => '/report/transactions/income'],
                        ['ID' => 'expense-report', 'Title' => 'Expenses', 'Icon' => 'graph-down-arrow', 'Link' => '/report/transactions/expense'],
                        ['ID' => 'budget-report', 'Title' => 'Budgets', 'Icon' => 'pie-chart', 'Link' => '/report/transactions/budget'],
                        ['ID' => 'purchcase-report', 'Title' => 'Purchases', 'Icon' => 'bag', 'Link' => '/report/transactions/purchase'],
                    ],
                ],
            ],
            'Profile' => [
                'Title' => ($firstName !== '') ? $firstName : 'Profile',
                'FullName' => trim($firstName . ' ' . $lastName),
                'Initials' => strtoupper(mb_substr($firstName !== '' ? $firstName : 'U', 0, 1) . mb_substr($lastName, 0, 1)),
                'Link' => '',
                'SubMenu' => [
                    'SignOut' => [
                        'Title' => 'Sign out',
                        'Icon' => 'box-arrow-right',
                        'Link' => '/user/logout',
                    ]
                ]
            ]
        ];

        return $head;


    }

    public function encryptLogic($password, $encrypt = true)
    {
        if($encrypt){
            return MD5($password); 
        }
    }

    public function FormattedStatus($status)
    {
        // switch to handle the status
        switch ($status) {
            case 'A':
                # Active Status
                return 'Active';
            case 'D':
                # Deactive Status
                return 'Dective';
            case 'L':
                # Locked Status
                return 'Locked';
            case 'I':
                # Inactive Status
                return 'Inactive';
            case 'C':
                # Cancelled Status
                return 'Cancelled';
            default:
                # Default Status
                return 'Default';
        }
    }
    
    public function passwordFormatHandler($password)
    {
        if(strlen($password) < 8){
            return $error = [
                        'error_id' => '0014',
                        'category' => 'Format_Issue',
                        'error_category' => 'Password Length',
                        'error_message'  => 'Please enter a password length higher than 8'
                    ];
        }

        return $response = ['success' => true];

    }
 
    public function contactNoFormatHandler($contactNo)
    {
        if(strlen($contactNo) < 9){
            return $error = [
                        'error_id' => '0015',
                        'category' => 'Format_Issue',
                        'error_category' => 'Contact No Length',
                        'error_message'  => 'Contact no length should be higher than 9'
                    ];
        }

        if(strlen($contactNo) > 16){
            return $error = [
                        'error_id' => '0016',
                        'category' => 'Format_Issue',
                        'error_category' => 'Contact No Length',
                        'error_message'  => 'Contact no length should be lower than 16'
                    ];
        }

        if(!is_numeric($contactNo)){
                    return $error = [
                    'error_id' => '0017',
                    'category' => 'Format_Issue',
                    'error_category' => 'Contact No Format',
                    'error_message'  => 'Contact no cannot have string values'
                ];
        }

        return $response = ['success' => true];

    }

    public function handleRequiredParameters($parameters, $requiredFeilds)
    {
        foreach ($requiredFeilds as $feilds) {
            # code...
            if(!isset($parameters[$feilds])){
                return $error = [
                        'error_id' => '0018',
                        'category' => 'Format_Issue',
                        'error_category' => 'Required Parameters',
                        'error_message'  => 'Required parameter "'.$feilds.'" is missing, and the follow is blocked'
                    ];
            }
        }
    }

    public function imageFormat($imageFormat)
    {
        // return base64_decode($imageFormat);
        return $imageFormat;
    }
    
    public function periodicHandle($periodType)
    {
        return $this->periodic[$periodType];
    }

    /**
     * Date of the $n-th period after $date (n = 0 returns $date itself).
     * Calendar aware: monthly/annual steps keep the day of month and clamp to the
     * last day of shorter months (31 Jan -> 28/29 Feb -> 31 Mar), unlike the old
     * fixed 28/365 day offsets which drifted every installment.
     */
    protected function addPeriod(string $date, string $periodType, int $n): string
    {
        $dateTime = new \DateTime($date);

        switch ($periodType) {
            case 'D':
                $dateTime->modify('+' . $n . ' day');
                break;

            case 'W':
                $dateTime->modify('+' . (7 * $n) . ' day');
                break;

            case 'M':
            case 'A':
                $months = ($periodType === 'M') ? $n : 12 * $n;
                $day = (int) $dateTime->format('d');
                $dateTime->modify('first day of this month')->modify('+' . $months . ' month');
                $dateTime->setDate((int) $dateTime->format('Y'), (int) $dateTime->format('m'), min($day, (int) $dateTime->format('t')));
                break;

            default:
                // 'I' (instant) and 'C' (continuous) have no fixed step
                break;
        }

        return $dateTime->format('Y-m-d');
    }

    /**
     * Run $work inside a single DB transaction.
     * $work must return a success array, or an error array (with 'error_id').
     * Error arrays and exceptions roll everything back, so a failed payment never
     * leaves a half written ledger (payment without transaction, balance moved
     * without a row, ...). Nested calls are safe: CodeIgniter only commits or
     * rolls back the outermost level, and every level propagates the error.
     */
    protected function runAtomic(callable $work)
    {
        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $result = $work();
        } catch (\Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Atomic operation rolled back: {message}', ['message' => $exception->getMessage()]);

            return [
                'error_id' => '0030',
                'category' => 'Data_Issue',
                'error_category' => 'Transaction Failure',
                'error_message'  => 'The operation could not be completed and was rolled back. Please retry.'
            ];
        }

        if (! is_array($result) || isset($result['error_id'])) {
            $db->transRollback();

            return is_array($result) ? $result : [
                'error_id' => '0030',
                'category' => 'Data_Issue',
                'error_category' => 'Transaction Failure',
                'error_message'  => 'The operation could not be completed and was rolled back. Please retry.'
            ];
        }

        $db->transCommit();

        return $result;
    }

    /** 32 character session id (MD5 style). Guards the raw SQL built by getDatafromDB(). */
    protected function isSessionID($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{32}$/', $value) === 1;
    }

    /** Money input -> float rounded to cents, or null when not a finite number. */
    protected function toAmount($value)
    {
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /** Y-m-d string when valid, otherwise null. */
    protected function validDate($value)
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return ($date && $date->format('Y-m-d') === $value) ? $value : null;
    }

    /** getDatafromDB() returns one row as an assoc array and many rows as a list; always get a list. */
    protected function rowsOf($response): array
    {
        if (! is_array($response) || isset($response['error_id']) || $response === []) {
            return [];
        }

        return isset($response[0]) ? $response : [$response];
    }

    public function getDaysofPeriodType($periodType)
    {
        switch ($periodType) {
            case 'D':
                # code...
                return 1;
                break;
            
            case 'W':
                # code...
                return 7;
                break;
            
            case 'M':
                # code...
                return 28;
                break;
            
            case 'A':
                # code...
                return 365;
                break;
            
            default:
                # code...
                break;
        }

    }

    // Active Budget List Access of the User ID
    protected function budgetList($userID, $budgetID = '')
    {
        $condtionList = ['UserSessionID' => $userID, 'BudgetStatus' => 'A'];
        $feildList = ['BudgetSessionID','BudgetName','BudgetPeriodic','BudgetAmount'];
        $organizerList = [];

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

        return $response;
    }

    // Active Budget List Access of the User ID
    protected function stackholderList($userID, $stackholderID = '')
    {
        $condtionList = ['UserSessionID' => $userID];
        $feildList = ['StackholderSessionID','StackholderName','StackholderRelationship'];
        $organizerList = [];

        if($stackholderID != ''){
            $condtionList['StackholderSessionID'] = $stackholderID;
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['stackholder'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );

        return $response;
    }

    // Active User Shop List Access of the User ID
    protected function userShopList($userID, $shopID = '')
    {
        $condtionList = ['UserSessionID' => $userID];
        $feildList = ['ShopSessionID','ShopName','ShopDescription'];
        $organizerList = ['orderBy' => ['ShopAccessOrderAccessCount' => 'DESC']];
        // $organizerList = [];

        if($shopID != ''){
            $condtionList['ShopSessionID'] = $shopID;
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['shopaccessorder', 'shop'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );

        return $response;
    }

    // Active User Shop List Access of the User ID
    protected function otherShopList($expectionShop = [])
    {
        $condtionList = [];
        $feildList = ['ShopSessionID','ShopName','ShopDescription'];
        $organizerList = ['orderBy' => ['ShopID' => 'ASC']];

        if($expectionShop != ''){
            $condtionList = ['sqldecrypt' => "sessionid NOT IN ('".implode("','", $expectionShop)."')"];
        }

        if($this->limit != 0){
            $organizerList['limit'] = $this->limit;
        }

        // TODO:: User Session ID need to be handled from user login
        $response = $this->getDatafromDB(
                        ['shop'], 
                        $condtionList,
                        $feildList,
                        $organizerList
                    );

        return $response;
    }
}
