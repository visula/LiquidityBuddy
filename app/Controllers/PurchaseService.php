<?php

namespace App\Controllers;

use DateTime;

class PurchaseService extends PaymentService
{
    public function __construct() {
        helper('text'); // Load the text helper containing random_string()
    }

    /**
     * Creates the purchase (+ items). When items are given their total must match
     * the purchase amount; this is checked before anything is written.
     */
    protected function purchaseInitProcess($userID, $description, $shopID, $amount, $itemList)
    {
        if(!$this->isSessionID($shopID)){
            return $this->paymentError('0042', 'Purchase', 'Please select a valid shop.');
        }

        $amount = $this->toAmount($amount);
        $items = [];
        $TotalAmt = 0;
        $discountAmt = 0;
        $finalAmt = 0;

        if(is_array($itemList) && count($itemList) > 0){
            foreach ($itemList as $item) {
                $units = $this->toAmount($item['units'] ?? null);
                $unitPrice = $this->toAmount($item['unit_price'] ?? null);
                $discount = $this->toAmount($item['discount'] ?? 0) ?? 0;
                $name = trim((string) ($item['name'] ?? ''));

                if($name === '' || $units === null || $units <= 0 || $unitPrice === null || $unitPrice < 0 || $discount < 0 || $discount > $units * $unitPrice){
                    return $this->paymentError('0043', 'Purchase Items', 'Please check the item list: every item needs a name, units, unit price and a valid discount.');
                }

                $original = round($units * $unitPrice, 2);
                $TotalAmt += $original;
                $discountAmt += $discount;
                $finalAmt += $original - $discount;

                $items[] = [
                    'ItemName' => mb_substr($name, 0, 50),
                    'ItemDescription' => mb_substr('('.$name.' * '.$units.')', 0, 50),
                    'ItemOriginalPrice' => $original,
                    'ItemlDiscountAmount' => $discount,
                    'ItemFinalPrice' => round($original - $discount, 2),
                    'ItemUnits' => $units,
                    'ItemUnitPrice' => $unitPrice
                ];
            }

            if(abs(round($finalAmt, 2) - $amount) >= 0.01){
                return $this->paymentError('0026', 'Purchase Items', 'The item total ('.number_format($finalAmt, 2).') does not match the purchase amount ('.number_format($amount, 2).').');
            }
        }else{
            $TotalAmt = $amount;
            $finalAmt = $amount;
        }

        $now = time();
        $newPurchase = $this->insertDatatoDB('purchase', [
            'ShopSessionID' => $shopID,
            'PurchaseDescription' => mb_substr((string) $description, 0, 50),
            'PurchaseDate' => date('Y-m-d'),
            'UserSessionID' => $userID,
            'PurchaseDateTime' => $now,
            'PurchaseTotalAmount' => round($TotalAmt, 2),
            'PurchaseTotalDiscount' => round($discountAmt, 2),
            'PurchaseFinalAmount' => round($finalAmt, 2),
            'PurchaseCreatedDateTime' => $now,
            'PurchaseUpdatedDateTime' => $now,
        ]);

        if(isset($newPurchase['error_id'])){
            return $newPurchase;
        }

        $purchaseInfo = $this->getDatafromDB(
                        ['purchase'],
                        ['PurchaseID' => $newPurchase['id']],
                        ['PurchaseSessionID']
                    );

        if(isset($purchaseInfo['error_id'])){
            return $purchaseInfo;
        }

        foreach ($items as $item) {
            $newItem = $this->insertDatatoDB('item', ['PurchaseSessionID' => $purchaseInfo['PurchaseSessionID']] + $item);

            if(isset($newItem['error_id'])){
                return $newItem;
            }
        }

        // Most used shops first in the purchase form
        $db = \Config\Database::connect();
        $updated = $db->table('shopaccessorder')
            ->set('accesscount', 'accesscount + 1', false)
            ->where(['shopsessionid' => $shopID, 'usersessionid' => $userID])
            ->update();

        if($db->affectedRows() < 1){
            $newShopAccess = $this->insertDatatoDB('shopaccessorder', [
                'ShopSessionID' => $shopID,
                'UserSessionID' => $userID,
                'ShopAccessOrderAccessCount' => 1
            ]);

            if(isset($newShopAccess['error_id'])){
                return $newShopAccess;
            }
        }

        return ['success' => true, 'data' => $purchaseInfo];
    }

    /** Normalise the scheduled_info posted by the forms; null when the payment is not scheduled. */
    protected function normaliseSchedule($raw)
    {
        if(!is_array($raw) || $raw === []){
            return null;
        }

        $type = strtoupper((string) ($raw['schedule_type'] ?? 'M'));

        if(!isset($this->periodic[$type]) && $type !== 'C'){
            $type = 'M';
        }

        return [
            'schedule_type' => $type,
            'period' => ($type === 'C') ? 0 : max(1, (int) ($raw['period'] ?? 1)),
            'start_date' => $this->validDate($raw['start_date'] ?? '') ?? date('Y-m-d'),
            'make_initial_payment' => filter_var($raw['make_initial_payment'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];
    }

    /**
     * Creates a payment plan and its installments (all unpaid - the first one is
     * paid through recordPayment() so it gets a payment + transaction like any
     * other payment). Installments are split so they add up exactly to $amount.
     */
    protected function paymentPlanInitProccess($userID, $link, $linkID, $scheduled_info, $amount, $description = '')
    {
        $schedule = $this->normaliseSchedule($scheduled_info);
        $amount = $this->toAmount($amount);

        if($schedule === null || $amount === null || $amount <= 0){
            return $this->paymentError('0044', 'Payment Plan', 'Invalid payment schedule.');
        }

        $type = $schedule['schedule_type'];
        $period = $schedule['period'];
        $start_date = $schedule['start_date'];
        $end_date = ($period > 0) ? $this->addPeriod($start_date, $type, $period - 1) : $start_date;
        $now = time();

        $newPaymentPlan = $this->insertDatatoDB('paymentplan', [
            'PaymentPlanLink' => $link,
            'PaymentPlanLinkSessionID' => mb_substr((string) $linkID, 0, 32),
            'PaymentPlan' => $type,
            'PaymentPlanPeriod' => $period,
            'PaymentPlanRate' => 0,
            'PaymentPlanStartDate' => $start_date,
            'PaymentPlanStartDateTime' => strtotime($start_date),
            'PaymentPlanEndDate' => $end_date,
            'PaymentPlanEndDateTime' => strtotime($end_date),
            'PaymentPlanCreatedDateTime' => $now,
            'PaymentPlanUpdatedDateTime' => $now,
            'PaymentPlanStatus' => 'A',
            'PaymentPlanDescription' => mb_substr((string) $description, 0, 100),
            'PaymentPlanAmount' => $amount,
            'UserSessionID' => $userID
        ]);

        if(isset($newPaymentPlan['error_id'])){
            return $newPaymentPlan;
        }

        $planInfo = $this->getDatafromDB(
                        ['paymentplan'],
                        ['PaymentPlanID' => $newPaymentPlan['id']],
                        ['PaymentPlanSessionID']
                    );

        if(isset($planInfo['error_id'])){
            return $planInfo;
        }

        $planInfo['Payables'] = [];

        if($period > 0){
            $installment = floor($amount / $period * 100) / 100;

            for ($no = 0; $no < $period; $no++) {
                $due_date = $this->addPeriod($start_date, $type, $no);
                // last installment absorbs the rounding remainder
                $dueAmount = ($no === $period - 1) ? round($amount - $installment * ($period - 1), 2) : $installment;

                $newPayable = $this->insertDatatoDB('payable', [
                    'PaymentPlanSessionID' => $planInfo['PaymentPlanSessionID'],
                    'PayableDueDate' => $due_date,
                    'PayableDueDateTime' => strtotime($due_date),
                    'PayableDueAmount' => $dueAmount,
                    'PayablePaidAmount' => 0,
                    'PayableStatus' => 'P',
                    'PayableCreatedDateTime' => $now,
                    'PayableUpdatedDateTime' => $now
                ]);

                if(isset($newPayable['error_id'])){
                    return $newPayable;
                }

                $payable = $this->getDatafromDB(['payable'], ['PayableID' => $newPayable['id']], ['PayableSessionID']);

                if(isset($payable['error_id'])){
                    return $payable;
                }

                $planInfo['Payables'][] = ['PayableSessionID' => $payable['PayableSessionID'], 'PayableDueDate' => $due_date, 'PayableDueAmount' => $dueAmount];
            }

            $planInfo['PaidAmount'] = $planInfo['Payables'][0]['PayableDueAmount'];
        }else{
            $planInfo['PaidAmount'] = $amount;
        }

        return ['success' => true, 'data' => $planInfo];
    }

    /**
     * Pay in full now, or create an installment plan and (optionally) pay the
     * first installment now. All payments go through recordPayment().
     *
     * $p: direction, account, method, budget, amount, category, description, reference,
     *     link_type, link_id, plan_link ('purchase' | 'expense' | 'income')
     */
    protected function payOrSchedule(string $userID, array $p, $schedule)
    {
        if($schedule === null){
            return $this->recordPayment($userID, $p + ['settlement' => 'FULL']);
        }

        $plan = $this->paymentPlanInitProccess($userID, $p['plan_link'], $p['link_id'] ?? '', $schedule, $p['amount'], $p['description'] ?? '');

        if(isset($plan['error_id'])){
            return $plan;
        }

        $result = ['PaymentPlan' => $plan['data'], 'Payment' => null];

        if($schedule['make_initial_payment']){
            $payment = $p;
            $payment['link_type'] = 'paymentplan';
            $payment['link_id'] = $plan['data']['PaymentPlanSessionID'];

            if(!empty($plan['data']['Payables'])){
                $first = $plan['data']['Payables'][0];
                $payment['amount'] = $first['PayableDueAmount'];
                $payment['settlement'] = (count($plan['data']['Payables']) > 1) ? 'INSTALLMENT' : 'FULL';
                $payment['allocations'] = [['payable' => $first['PayableSessionID'], 'amount' => $first['PayableDueAmount']]];
                $payment['date'] = min($first['PayableDueDate'], date('Y-m-d'));
            }else{
                $payment['settlement'] = 'FULL';
            }

            $paid = $this->recordPayment($userID, $payment);

            if(isset($paid['error_id'])){
                return $paid;
            }

            $result['Payment'] = $paid['data'];
        }

        return ['success' => true, 'data' => $result];
    }

    public function externalPaymentInitProccess($userID, $amount, $external_pay_type, $external_data_list)
    {
        if($external_pay_type == 'T'){
            $external_data_list = is_array($external_data_list) ? $external_data_list : [];
            $now = time();

            $newTravel = $this->insertDatatoDB('travel', [
                'UserSessionID' => $userID,
                'TravelDate' => date('Y-m-d'),
                'TravelCost' => $amount,
                'TravelTransportType' => mb_substr(trim((string) ($external_data_list['travel_mode'] ?? '')), 0, 50),
                'TravelFromLocation' => mb_substr(trim((string) ($external_data_list['from_location'] ?? '')), 0, 50),
                'TravelToLocation' => mb_substr(trim((string) ($external_data_list['to_location'] ?? '')), 0, 50),
                'TravelCreatedDateTime' => $now,
                'TravelUpdatedDateTime' => $now
            ]);

            if(isset($newTravel['error_id'])){
                return $newTravel;
            }

            return ['success' => true, 'data' => ['ExpenseSessionID' => (string) $newTravel['id']]];
        }

        return ['success' => true, 'data' => ['ExpenseSessionID' => random_string('alpha', 32)]];
    }
}
