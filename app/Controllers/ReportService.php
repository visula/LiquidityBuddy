<?php

namespace App\Controllers;

class ReportService extends PaymentService
{
    public function reportProccess($userID, $accountID, $budgetID, $startDate, $endDate, $transactionType = 'income')
    {
        $searchData = [];

        if($accountID != 0){
            $searchData['AccountSessionID'] = $accountID;
        }
        
        if($budgetID != 0){
            $searchData['BudgetSessionID'] = $budgetID;
        }

        if($transactionType == 'income' || $transactionType == 'expense'){
            $searchData['TransactionPayableType'] = $transactionType;
        }

        $searchData['sqldecrypt'] = 'datetime <= '.$endDate.' AND datetime >= '.$startDate;

        $transactionHistory = $this->getDatafromDB(
                        ['transaction','account','budget'], 
                         $searchData,
                        []
                    );

        if(isset($transactionHistory['error_id'])){
            return $transactionHistory;
        }

        return $transactionHistory;
    }

    public function purchaseReportProccess($userID, $startDate, $endDate)
    {
        $searchData = [];

        $searchData['UserSessionID'] = $userID;
        $searchData['sqldecrypt'] = 'datetime <= '.$endDate.' AND datetime >= '.$startDate;

        $purchaseHistory = $this->getDatafromDB(
                        ['purchase','shop'], 
                         $searchData,
                        []
                    );

        if(isset($purchaseHistory['error_id'])){
            return $purchaseHistory;
        }

        // a single purchase may come back as one row rather than a list of rows
        if(!isset($purchaseHistory[0])){
            $purchaseHistory = [$purchaseHistory];
        }

        foreach ($purchaseHistory as $key => $purchase) {
            # code...
            $purchaseHistory[$key]['PurchaseDateTime'] = date('Y-m-d h:i:s',$purchase['PurchaseDateTime']);
            $purchaseHistory[$key]['PurchaseTotalAmount'] = ($purchase['PurchaseTotalAmount']) ? $purchase['PurchaseTotalAmount'] : 0;
            $purchaseHistory[$key]['PurchaseTotalDiscount'] = ($purchase['PurchaseTotalDiscount']) ? $purchase['PurchaseTotalDiscount'] : 0;
            $purchaseHistory[$key]['PurchaseFinalAmount'] = ($purchase['PurchaseFinalAmount']) ? $purchase['PurchaseFinalAmount'] : 0;;
            

            $purchaseHistory[$key]['Ledger'] = $this->purchaseLedger($userID, $purchase['PurchaseSessionID']);

            $itemInfo = $this->getDatafromDB(
                        ['item'], 
                        ['PurchaseSessionID' => $purchase['PurchaseSessionID']],
                        []
                    );

            if(isset($itemInfo['error_id'])){
                continue;
            }

            $purchaseHistory[$key]['Items'] = $itemInfo;
        }

        return $purchaseHistory;
    }

    /**
     * How a purchase has been paid: its payments (paid in full, or through its installment
     * plan), its installments and a status. Purchases recorded before the payment ledger
     * have no payments and report UNLINKED.
     */
    protected function purchaseLedger(string $userID, string $purchaseID): array
    {
        $db = $this->db();

        $planIDs = array_column($db->table('paymentplan')
            ->select('sessionid')
            ->where(['usersessionid' => $userID, 'paymentplanlink' => 'purchase', 'paymentplansessionid' => $purchaseID])
            ->get()->getResultArray(), 'sessionid');

        $installments = $planIDs === [] ? [] : $db->table('payable')
            ->select('sessionid AS PayableSessionID, duedate AS DueDate, dueamount AS DueAmount, paidamount AS PaidAmount, status AS PayableStatus')
            ->whereIn('paymentplansessionid', $planIDs)
            ->orderBy('duedate', 'ASC')
            ->get()->getResultArray();

        $payableIDs = array_column($installments, 'PayableSessionID');
        $settledIDs = $payableIDs === [] ? [] : array_column($db->table('duepaid')
            ->select('paymentsessionid')
            ->whereIn('payablesessionid', $payableIDs)
            ->get()->getResultArray(), 'paymentsessionid');

        $paymentQuery = $db->table('payment pay')
            ->select('pay.sessionid AS PaymentSessionID, pay.date AS PaymentDate, pay.amount AS PaymentAmount, pay.direction AS PaymentDirection, pay.settlementtype AS PaymentSettlementType, a.name AS AccountName, pm.name AS PaymentMethodName, pm.reference AS PaymentMethodReference')
            ->join('account a', 'a.sessionid = pay.accountsessionid')
            ->join('paymentmethod pm', 'pm.sessionid = pay.paymentmethodsessionid', 'left')
            ->where('pay.usersessionid', $userID)
            ->where('pay.status', 'A')
            ->groupStart()
                ->where('pay.linktype', 'purchase')
                ->where('pay.linksessionid', $purchaseID)
            ->groupEnd();

        if($settledIDs !== []){
            $paymentQuery->orGroupStart()->whereIn('pay.sessionid', $settledIDs)->groupEnd();
        }

        $payments = $paymentQuery->orderBy('pay.date', 'ASC')->get()->getResultArray();

        $paid = 0.0;

        foreach ($payments as $key => $payment) {
            $payments[$key]['PaymentMethodLabel'] = $payment['PaymentMethodName']
                ? $payment['PaymentMethodName'] . ($payment['PaymentMethodReference'] ? ' ••' . $payment['PaymentMethodReference'] : '')
                : '-';

            if($payment['PaymentDirection'] === 'OUT'){
                $paid += (float) $payment['PaymentAmount'];
            }
        }

        $outstanding = 0.0;

        foreach ($installments as $installment) {
            if(in_array($installment['PayableStatus'], ['P', 'T'], true)){
                $outstanding += max(0, (float) $installment['DueAmount'] - (float) $installment['PaidAmount']);
            }
        }

        if($payments === [] && $installments === []){
            $status = 'UNLINKED';
        }elseif($outstanding > 0.004){
            $status = ($installments !== []) ? 'SCHEDULED' : 'PART_PAID';
        }else{
            $status = 'PAID';
        }

        return [
            'Status' => $status,
            'Payments' => $payments,
            'Installments' => $installments,
            'PaidAmount' => round($paid, 2),
            'OutstandingAmount' => round($outstanding, 2)
        ];
    }
}