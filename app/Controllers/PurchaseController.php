<?php

namespace App\Controllers;

/**
 * External payments, purchases, incomes and due settlement.
 * Every endpoint records a `payment` (with the account's payment method) and its
 * `transaction` rows atomically via PaymentService::recordPayment().
 */
class PurchaseController extends PurchaseService
{
    /** POST /js-request/payment/expense  (travel / charity / other) */
    public function expenseTransaction()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['amount', 'current_account', 'budget', 'external_pay_type']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $amount = $this->toAmount($request_data['amount']);

        if($amount === null || $amount <= 0){
            return $this->apiError($this->paymentError('0024', 'Payment Amount', 'The payment amount must be greater than zero.'));
        }

        $payType = in_array($request_data['external_pay_type'], ['T', 'C', 'O'], true) ? $request_data['external_pay_type'] : 'O';
        $category = ['T' => 'travel', 'C' => 'charity', 'O' => 'expense'][$payType];
        $description = trim((string) ($request_data['description'] ?? ''));
        $description = ($description !== '') ? $description : ucfirst($category);
        $schedule = $this->normaliseSchedule($request_data['scheduled_info'] ?? '');
        $external = $request_data['external_data_list'] ?? [];

        $result = $this->runAtomic(function () use ($userID, $amount, $payType, $category, $description, $schedule, $external, $request_data) {
            $expense = $this->externalPaymentInitProccess($userID, $amount, $payType, $external);

            if(isset($expense['error_id'])){
                return $expense;
            }

            return $this->payOrSchedule($userID, [
                'direction'   => 'OUT',
                'account'     => $request_data['current_account'],
                'method'      => $request_data['payment_method'] ?? '',
                'budget'      => $request_data['budget'],
                'amount'      => $amount,
                'category'    => $category,
                'description' => $description,
                'reference'   => $request_data['reference'] ?? '',
                'date'        => $request_data['payment_date'] ?? '',
                'link_type'   => ($payType === 'T') ? 'travel' : 'expense',
                'link_id'     => $expense['data']['ExpenseSessionID'],
                'plan_link'   => 'expense',
                'allow_over_budget' => $request_data['allow_over_budget'] ?? false,
            ], $schedule);
        });

        return $this->apiResult($result, $schedule ? 'Payment plan created' : 'Payment recorded');
    }

    /** POST /js-request/payment/purchase */
    public function purchaseTransaction()
    {
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['amount', 'current_account', 'budget', 'shop']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $amount = $this->toAmount($request_data['amount']);

        if($amount === null || $amount <= 0){
            return $this->apiError($this->paymentError('0024', 'Payment Amount', 'Amount should be larger than 0.'));
        }

        $description = trim((string) ($request_data['description'] ?? ''));
        $description = ($description !== '') ? $description : 'Purchase';
        $schedule = $this->normaliseSchedule($request_data['scheduled_info'] ?? '');
        $items = is_array($request_data['item_list'] ?? null) ? $request_data['item_list'] : [];

        $result = $this->runAtomic(function () use ($userID, $amount, $description, $schedule, $items, $request_data) {
            $purchase = $this->purchaseInitProcess($userID, $description, $request_data['shop'], $amount, $items);

            if(isset($purchase['error_id'])){
                return $purchase;
            }

            $paid = $this->payOrSchedule($userID, [
                'direction'   => 'OUT',
                'account'     => $request_data['current_account'],
                'method'      => $request_data['payment_method'] ?? '',
                'budget'      => $request_data['budget'],
                'amount'      => $amount,
                'category'    => 'purchase',
                'description' => $description,
                'reference'   => $request_data['reference'] ?? '',
                'date'        => $request_data['payment_date'] ?? '',
                'link_type'   => 'purchase',
                'link_id'     => $purchase['data']['PurchaseSessionID'],
                'plan_link'   => 'purchase',
                'allow_over_budget' => $request_data['allow_over_budget'] ?? false,
            ], $schedule);

            if(isset($paid['error_id'])){
                return $paid;
            }

            return ['success' => true, 'data' => ['Purchase' => $purchase['data'], 'Payment' => $paid['data']]];
        });

        return $this->apiResult($result, $schedule ? 'Purchase recorded with a payment plan' : 'Purchase paid in full');
    }

    /** POST /js-request/payment/income  (type: one-time | monthly | till) */
    public function incomeTransaction(){
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $requiredParameters = $this->handleRequiredParameters($request_data, ['type', 'amount', 'account_id', 'budget_id']);

        if(isset($requiredParameters['error_id'])){
            return $this->apiError($requiredParameters);
        }

        $amount = $this->toAmount($request_data['amount']);

        if($amount === null || $amount <= 0){
            return $this->apiError($this->paymentError('0024', 'Payment Amount', 'The amount must be greater than zero.'));
        }

        $type = $request_data['type'];
        $description = trim((string) ($request_data['description'] ?? ''));
        $description = ($description !== '') ? $description : 'Payment Received';
        $plan = $request_data['collection_plan'] ?? [];
        $schedule = null;

        // Monthly income (salary) is a continuous transaction: a recurring rule that
        // posts the amount every month from the start date (see Recurring page).
        if($type === 'monthly'){
            $result = $this->recurringCreateProccess($userID, [
                'direction'   => 'IN',
                'account'     => $request_data['account_id'],
                'method'      => $request_data['payment_method'] ?? '',
                'budget'      => $request_data['budget_id'],
                'amount'      => $amount,
                'description' => $description,
                'periodic'    => 'M',
                'start_date'  => is_array($plan) ? ($plan['start_date'] ?? '') : '',
            ]);

            return $this->apiResult($result, 'Monthly income added');
        }

        if($type === 'till'){
            $schedule = $this->normaliseSchedule(is_array($plan) ? $plan : []);
        }

        $payment = [
            'direction'   => 'IN',
            'account'     => $request_data['account_id'],
            'method'      => $request_data['payment_method'] ?? '',
            'budget'      => $request_data['budget_id'],
            'amount'      => $amount,
            'category'    => 'income',
            'description' => $description,
            'reference'   => $request_data['reference'] ?? '',
            'date'        => $request_data['payment_date'] ?? '',
            'link_type'   => 'income',
            'plan_link'   => 'income',
        ];

        $result = $this->runAtomic(function () use ($userID, $payment, $schedule) {
            return $this->payOrSchedule($userID, $payment, $schedule);
        });

        return $this->apiResult($result, $schedule ? 'Income plan created' : 'Income recorded');
    }

    /**
     * POST /js-request/payment/dued  (kept for older front ends)
     * Same as /js-request/payment/settle; accepts the old payment_list payload.
     */
    public function duedTransaction(){
        $userID = $this->apiUser();

        if($userID === null){
            return $this->apiUnauthorised();
        }

        $request_data = $this->handlePOSTBodyDataList();
        $payableIDs = $request_data['payable_ids'] ?? [];

        if(empty($payableIDs) && is_array($request_data['payment_list'] ?? null)){
            $payableIDs = array_column($request_data['payment_list'], 'payment_id');
        }

        $result = $this->settlePayables($userID, [
            'payable_ids'     => is_array($payableIDs) ? $payableIDs : [],
            'payment_plan_id' => $request_data['payment_plan_id'] ?? '',
            'mode'            => $request_data['mode'] ?? 'full',
            'amount'          => $request_data['amount'] ?? null,
            'account'         => $request_data['account_id'] ?? '',
            'method'          => $request_data['payment_method'] ?? '',
            'budget'          => $request_data['budget_id'] ?? '',
            'description'     => $request_data['description'] ?? '',
            'reference'       => $request_data['reference'] ?? '',
            'date'            => $request_data['payment_date'] ?? '',
            'allow_over_budget' => $request_data['allow_over_budget'] ?? false,
        ]);

        return $this->apiResult($result, 'Dues settled');
    }
}
