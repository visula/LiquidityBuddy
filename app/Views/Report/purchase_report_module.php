<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/common_modules/pagination.js') ?>"></script>
<div class="mb-3 w-100 bg-shadow p-3">
    <h3 class="text-center font-color-3">Purchase Report</h3>
</div>
<div class="table-responsive">
    <table class="table table-striped table-bordered" id="purchase-table">
        <?php foreach ($purchases as $key => $purchase) { ?>
            <thead data-purchase-id="<?= $purchase['PurchaseSessionID'] ?>">
                <tr>
                    <th>Purchase Description</th>
                    <th colspan="3"><?= $purchase['PurchaseDescription'] ?></th>
                    <th class="text-end"><?= $purchase['PurchaseDateTime'] ?></th>
                </tr>
                <tr>
                    <th>Shop Description</th>
                    <th colspan="4"><?= $purchase['ShopName'].' - '.$purchase['ShopDescription'] ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if(isset($purchase['Items'])){?>
                    <?php foreach ($purchase['Items'] as $key => $item) { ?>
                        <tr>
                            <td></td>
                            <th>Item Description</th>
                            <th colspan="4"><?= $item['ItemName'].' - '.$item['ItemDescription'] ?></th>
                        </tr>
                        <tr>
                            <td colspan="2"></td>
                            <td>Unit Item</td>
                            <td class="text-end" colspan="3"><?= number_format($item['ItemUnits']) ?></td>
                        </tr>
                        <tr>
                            <td colspan="2"></td>
                            <td>Unit Item Price</td>
                            <td class="text-end" colspan="3">Rs. <?= number_format($item['ItemUnitPrice'],2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="2"></td>
                            <td>Original Item Price</td>
                            <td class="text-end" colspan="3">Rs. <?= number_format($item['ItemOriginalPrice'],2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="2"></td>
                            <td>Discount Item Price</td>
                            <td class="text-end" colspan="3">Rs. <?= number_format($item['ItemlDiscountAmount'],2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="2"></td>
                            <th>Final Item Price</th>
                            <th class="text-end" colspan="3">Rs. <?= number_format($item['ItemFinalPrice'],2) ?></th>
                        </tr>
                    <?php } ?>
                <?php } ?>
                <tr>
                    <th class=" head-type-4" colspan="2">Total</th>
                    <th class="text-type-2 text-center">(<?= 'Rs. '. number_format($purchase['PurchaseTotalAmount'],2).' - Rs. '.number_format($purchase['PurchaseTotalDiscount'],2) ?>)</th>
                    <th class="text-end head-type-4"  colspan="2">Rs. <?= number_format($purchase['PurchaseFinalAmount'],2) ?></th>
                </tr>
                <?php
                    $ledger = $purchase['Ledger'];
                    $ledgerLabel = [
                        'UNLINKED' => ['No payment recorded', 'bg-secondary'],
                        'SCHEDULED' => ['Scheduled', 'bg-info text-dark'],
                        'PART_PAID' => ['Part paid', 'bg-warning text-dark'],
                        'PAID' => ['Paid', 'bg-success'],
                    ][$ledger['Status']];
                    $installmentLabel = ['S' => ['Settled', 'bg-success'], 'T' => ['Part paid', 'bg-warning text-dark'], 'P' => ['Due', 'bg-secondary']];
                ?>
                <tr>
                    <th colspan="2">Payment status</th>
                    <td><span class="badge <?= $ledgerLabel[1] ?>"><?= $ledgerLabel[0] ?></span></td>
                    <td class="text-end" colspan="2">Paid Rs. <?= number_format($ledger['PaidAmount'],2) ?><?php if($ledger['OutstandingAmount'] > 0){ ?> &middot; Outstanding Rs. <?= number_format($ledger['OutstandingAmount'],2) ?><?php } ?></td>
                </tr>
                <?php foreach ($ledger['Payments'] as $payment) { ?>
                    <tr>
                        <td colspan="2"></td>
                        <td><?= esc($payment['PaymentDate']) ?> &middot; <?= esc($payment['AccountName']) ?></td>
                        <td colspan="2" class="text-end"><small><?= esc($payment['PaymentMethodLabel']) ?></small> Rs. <?= number_format($payment['PaymentAmount'],2) ?></td>
                    </tr>
                <?php } ?>
                <?php foreach ($ledger['Installments'] as $installment) { ?>
                    <tr>
                        <td colspan="2"></td>
                        <td>Installment due <?= esc($installment['DueDate']) ?></td>
                        <td>Rs. <?= number_format($installment['PaidAmount'],2) ?> of <?= number_format($installment['DueAmount'],2) ?></td>
                        <td class="text-end"><span class="badge <?= $installmentLabel[$installment['PayableStatus']][1] ?? 'bg-secondary' ?>"><?= $installmentLabel[$installment['PayableStatus']][0] ?? esc($installment['PayableStatus']) ?></span></td>
                    </tr>
                <?php } ?>
                <tr>
                    <td></td>
                </tr>
            </tbody>
        <?php } ?>
    </table>
</div>
