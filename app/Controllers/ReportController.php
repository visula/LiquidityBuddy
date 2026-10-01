<?php
    namespace App\Controllers;

    class ReportController extends ReportService
    {
        public function transactionReport($transactonType = null)
        {
            $is_login = session()->get('is_login');

            if(!isset($is_login) || !$is_login){
                return redirect()->to(base_url('/login'));
                die;
            }
            
            $data = [];

            $data = [
                'StoredText' => [
                    'Header' => 'Report Page',
                    'ScreenTitle' => 'Report',
                    'ErrorStatus' => 'Error Status: ',
                ]
            ];

            $data['Head'] = $this->commonHead();
            $data['CurrentID'] = 'transaction-page';

            $accountService = new AccountService();

            if($transactonType == 'income' || $transactonType == 'expense'){
                // $this->limit = 5;
                $accountDetails = $accountService->activeAccountListAccessModule($this->userAccess());
                // $this->limit = 0;

                // TODO:: Error Handling Method
                if(isset($accountDetails['error_id'])){

                    // TODO:: Change the route the accout create URL
                    return $this->errorHandleLogAndPageRedirection($accountDetails, '/account/list');
                    // return redirect()->to(base_url('/account/list'))->with('msg', $accountDetails['error_message']);
                }

                if(!isset($accountDetails[0])){
                    $accounts[] = $accountDetails;
                }else{
                    $accounts = $accountDetails;
                }

                $data['accountList'] = $accounts;
            }

            if($transactonType == 'budget'){
                // TODO:: link user default user
                // $this->limit = 5;
                $budgetDetails = $this->budgetList($this->userAccess());
                // $this->limit = 0;

                // TODO:: Error Handling Method
                if(isset($budgetDetails['error_id'])){
                    if($budgetDetails['error_id'] != '0004'){
                        // TODO:: Change the route the accout create URL
                        return $this->errorHandleLogAndPageRedirection($budgetDetails, '/account/list');
                    }else{
                        $data['budget_details_container'] = '';
                        $budgets = [];
                    }
                }else{
                    if(!isset($budgetDetails[0])){
                        $budgets[] = $budgetDetails;
                    }else{
                        $budgets = $budgetDetails;
                    }
                }

                $data['budgetList'] = $budgets;
            }

            $data['transactonType'] = $transactonType;
            $data['accountSearchActive'] = $transactonType == 'income' || $transactonType == 'expense' ? true : false;
            $data['budgetSearchActive'] = $transactonType == 'budget' ? true : false;
            

            return view('Report/page', $data);
        }

        public function reportIncomeExpense()
        {
            $request_data = $this->handlePOSTBodyDataList();
            // passing parameters --> (amount [required], transaction_type [required], from_account [required], to_account [required])
            $requiredParameters = $this->handleRequiredParameters($request_data, ['date_from', 'date_to', 'transacton_type']);

            if(isset($requiredParameters['error_id'])){
                return $requiredParameters;
            }

            $account_id = isset($request_data['account_id']) ? $request_data['account_id'] : 0;
            $budget_id = isset($request_data['budget_id']) ? $request_data['budget_id'] : 0;
            $transacton_type = $request_data['transacton_type'];
            $date_from = strtotime($request_data['date_from']);
            $date_to = strtotime(date('Y-m-d', strtotime($request_data['date_to'] . ' +1 day')));

            // TODO:: link user default user
            $response = $this->reportProccess($this->userAccess(), $account_id, $budget_id, $date_from, $date_to, $transacton_type);

            if(isset($response['error_id'])){
                 echo json_encode([
                    'success' => false,
                    'data' => $response,
                    'code' => 500]);
                exit;
            }

            $data['transactionInfo'] = [
                'page_limit' => 10,
                'count' => count($response),
                'page_count' => ceil(count($response) / 10),
                'transactions' => $response,
                'allow_all_accounts' => true
            ];

            if($transacton_type == 'income' || $transacton_type == 'expense'){
                $data['transactionSummary']['Info'] = [
                    'Title' => 'Account Summary',
                    'Key' => 'AccountSessionID',
                    'Name' => 'AccountName',
                    'Feild' => 'Account Name',
                    'TotalIncome' => 0,
                    'TotalExpense' => 0
                ];
            }elseif($transacton_type == 'budget'){
                $data['transactionSummary']['Info'] = [
                    'Title' => 'Budget Summary',
                    'Key' => 'BudgetSessionID',
                    'Name' => 'BudgetName',
                    'Feild' => 'Budget Name',
                    'TotalIncome' => 0,
                    'TotalExpense' => 0
                ];
            }

            foreach($response as $key => $transaction){
                if(isset($data['transactionSummary']['Data'][$transaction[$data['transactionSummary']['Info']['Key']]])){
                }else{
                    $data['transactionSummary']['Data'][$transaction[$data['transactionSummary']['Info']['Key']]] = [
                            'Name' => $transaction[$data['transactionSummary']['Info']['Name']],
                            'Income' => 0,
                            'Expense' => 0
                        ];
                }
                if($transaction['TransactionPayableType'] == 'expense'){
                    $data['transactionSummary']['Data'][$transaction[$data['transactionSummary']['Info']['Key']]]['Expense'] = $data['transactionSummary']['Data'][$transaction[$data['transactionSummary']['Info']['Key']]]['Expense'] + $transaction['TransactionAmount'];
                    $data['transactionSummary']['Info']['TotalExpense'] = $data['transactionSummary']['Info']['TotalExpense'] + $transaction['TransactionAmount'];
                }else{
                    $data['transactionSummary']['Data'][$transaction[$data['transactionSummary']['Info']['Key']]]['Income'] = $data['transactionSummary']['Data'][$transaction[$data['transactionSummary']['Info']['Key']]]['Income'] + $transaction['TransactionAmount'];
                    $data['transactionSummary']['Info']['TotalIncome'] = $data['transactionSummary']['Info']['TotalIncome'] + $transaction['TransactionAmount'];
                }
            }

            $data['transaction_summary_container'] = view('Transaction/transaction_summary_module', $data['transactionSummary']); 
            $data['transaction_details_container'] = view('Transaction/transaction_details_module', $data['transactionInfo']);

            echo json_encode([
                'success' => true,
                'data' => $data,
                'code' => 200]);
            exit;
        }

        public function reportPurchaseReport()
        {
            $request_data = $this->handlePOSTBodyDataList();
            // passing parameters --> (transaction_type [required], from_account [required], to_account [required])
            $requiredParameters = $this->handleRequiredParameters($request_data, ['date_from', 'date_to']);

            if(isset($requiredParameters['error_id'])){
                return $requiredParameters;
            }
            $date_from = strtotime($request_data['date_from']);
            $date_to = strtotime(date('Y-m-d', strtotime($request_data['date_to'] . ' +1 day')));

            $response = $this->purchaseReportProccess($this->userAccess(), $date_from, $date_to);

            if(isset($response['error_id'])){
                 echo json_encode([
                    'success' => false,
                    'data' => $response,
                    'code' => 500]);
                exit;
            }
            

            $data['purchaseInfo'] = [
                'page_limit' => 10,
                'count' => count($response),
                'page_count' => ceil(count($response) / 10),
                'purchases' => $response
            ];

            $data['purchase_container'] = view('Report/purchase_report_module', $data['purchaseInfo']); 

            echo json_encode([
                'success' => true,
                'data' => $data,
                'code' => 200]);
            exit;

        }
    }
?>