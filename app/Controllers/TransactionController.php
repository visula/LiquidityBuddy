<?php

namespace App\Controllers;

class TransactionController extends PaymentService
{

    // Account Page initiation function
    public function createTransaction()
    {
        $is_login = session()->get('is_login');

        if(!isset($is_login) || !$is_login){
            return redirect()->to(base_url('/login'));
            die;
        }

        // Post recurring transactions (salary, rent ...) that fell due since the last visit
        $this->postDueRecurring($this->userAccess());
        $this->postDueInterest($this->userAccess());
        
        $data = [];

        $data = [
            'StoredText' => [
                'Header' => 'Transactions',
                'ScreenTitle' => 'Transactions',
                'ErrorStatus' => 'Error Status: ',
            ]
        ];

        $data['Head'] = $this->commonHead();
        $data['CurrentID'] = 'transaction-proccesss';

        $accountDetails = $this->activeAccountListAccessModule($this->userAccess());

        // TODO:: Error Handling Method
        if(isset($accountDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            return $this->errorHandleLogAndPageRedirection($accountDetails, '/account/list');
        }

        if(!isset($accountDetails[0])){
            $accounts[] = $accountDetails;
        }else{
            $accounts = $accountDetails;
        }


        // TODO:: link user default user
        $budgetDetails = $this->budgetList($this->userAccess());

        // TODO:: Error Handling Method
        if(isset($budgetDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            return $this->errorHandleLogAndPageRedirection($budgetDetails, '/account/list');
        }

        if(!isset($budgetDetails[0])){
            $budgets[] = $budgetDetails;
        }else{
            $budgets = $budgetDetails;
        }

        // TODO:: link user default user
        $this->limit = 5;
        $shopDetails = $this->userShopList($this->userAccess());
        $shops = [];

        // TODO:: Error Handling Method
        if(!isset($shopDetails['error_id'])){

            // TODO:: Change the route the accout create URL

            if(!isset($shopDetails[0])){
                $shops[] = $shopDetails;
            }else{
                $shops = $shopDetails;
            }

            if(count($shops) < $this->limit){
                $shopIds = array_column($shops, 'ShopSessionID');
                // TODO:: link user default user
                $this->limit = ($this->limit - count($shops));
                $otherShopDetails = $this->otherShopList($shopIds);
                
                $this->limit = 0;

                // TODO:: Error Handling Method
                if(!isset($otherShopDetails['error_id'])){

                    // TODO:: Change the route the accout create URL

                    if(!isset($otherShopDetails[0])){
                        $shops[] = $otherShopDetails;
                    }else{
                        $shops = array_merge($shops, $otherShopDetails);
                    }
                }
            }
        }else{
            $otherShopDetails = $this->otherShopList();
                
            $this->limit = 0;

            // TODO:: Error Handling Method
            if(!isset($otherShopDetails['error_id'])){

                // TODO:: Change the route the accout create URL

                if(!isset($otherShopDetails[0])){
                    $shops[] = $otherShopDetails;
                }else{
                    $shops = array_merge($shops, $otherShopDetails);
                }
            }
        }

        // TODO:: link user default user
        $this->limit = 10;
        $stackholderDetails = $this->stackholderList($this->userAccess());
        $this->limit = 0;

        // TODO:: Error Handling Method
        if(isset($stackholderDetails['error_id'])){

            // TODO:: Change the route the accout create URL
            $stackholderDetails = [];
        }

        if(!isset($stackholderDetails[0])){
            $stackholders[] = $stackholderDetails;
        }else{
            $stackholders = $stackholderDetails;
        }

        $data['budgetInfo'] = $budgets;
        $data['stackholderInfo'] = $stackholders;
        $data['shopInfo'] = $shops;
        // $data['transactionInfo']['$transactions'] = $transactions;

        $data['accountInfo'] = [
            'page_limit' => 5,
            'count' => count($accounts),
            'page_count' => ceil(count($accounts) / 5),
            'accounts' => $accounts,
            'allow_all_accounts' => true,
            'edit_mode' => true
        ];
        $data['outstandingDues'] = $this->duedTabPayables($this->userAccess());
        $data['paymentPlan'] = $this->periodic;
        $data['paymentMethodInfo'] = $this->paymentMethodList($this->userAccess());
        $data['accountMeta'] = $this->accountTypeMap($this->userAccess());

        $data['external_trans_content'] = view('Transaction/external_trans_module', $data);
        if(isset($accounts[1])){
            $data['internal_trans_content'] = view('Transaction/internal_trans_module', $data);
        }else{
            $data['internal_trans_content'] = "<div class='transaction-type-list d-none' id='internal'><h3> Need more than one account to make internal transaction </h3></div>";
        }
        $data['income_trans_content'] = view('Transaction/income_trans_module', $data);
        $data['dued_trans_content'] = view('Transaction/dued_trans_module', $data);
        $data['other_trans_content'] = view('Transaction/other_trans_module', $data);
        $data['purchase_content'] = view('Transaction/purchase_module', $data);
        $data['transaction_proccess_container'] = view('Transaction/transaction_proccess_module', $data);
        return view('Transaction/create', $data);
    }
}
