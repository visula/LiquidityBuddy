<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/dued.js') ?>"></script>
<?php
    $outstandingDues = $outstandingDues ?? [];
    $payables = array_values(array_filter($outstandingDues, function ($due) { return $due['Direction'] === 'OUT'; }));
    $receivables = array_values(array_filter($outstandingDues, function ($due) { return $due['Direction'] === 'IN'; }));
    $transfers = array_values(array_filter($outstandingDues, function ($due) { return $due['Direction'] === 'TRANSFER'; }));
?>
<div class="mb-3 transaction-type-list d-none" id="dued">
    <div class="form">
        <div class="mb-3 d-flex row">
            <div class="col-12 my-3 d-flex">
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="dued-direction-out" name="dued-direction" value="OUT" checked>
                    <label class="form-check-label" for="dued-direction-out">Payables (<?= count($payables) ?>)</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="dued-direction-in" name="dued-direction" value="IN">
                    <label class="form-check-label" for="dued-direction-in">Receivables (<?= count($receivables) ?>)</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="dued-direction-transfer" name="dued-direction" value="TRANSFER">
                    <label class="form-check-label" for="dued-direction-transfer">Transfers (<?= count($transfers) ?>)</label>
                </div>
            </div>
            <div class="col-12 mb-3">
                <table class="table table-striped table-sm mb-1" id="dued-table">
                    <thead>
                        <tr>
                            <th><input class="form-check-input" type="checkbox" id="dued-select-all" title="Select all"></th>
                            <th>Due Date</th>
                            <th>For</th>
                            <th class="text-center">Installment / month</th>
                            <th class="text-end">Due</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($outstandingDues === []) { ?>
                            <tr class="dued-empty"><td colspan="7" class="text-center text-muted">Nothing outstanding</td></tr>
                        <?php } ?>
                        <?php foreach ($outstandingDues as $due) { ?>
                            <tr class="dued-row <?= $due['Direction'] !== 'OUT' ? 'd-none' : '' ?>" data-direction="<?= esc($due['Direction'], 'attr') ?>"
                                data-account="<?= esc((string) ($due['PlanItemAccountSessionID'] ?? ''), 'attr') ?>"
                                data-method="<?= esc((string) ($due['PlanItemMethodSessionID'] ?? ''), 'attr') ?>"
                                data-budget="<?= esc((string) ($due['PlanItemBudgetSessionID'] ?? ''), 'attr') ?>">
                                <td>
                                    <input class="form-check-input payment-check" type="checkbox"
                                           value="<?= esc($due['PayableSessionID'], 'attr') ?>"
                                           data-outstanding="<?= esc($due['PayableOutstanding'], 'attr') ?>">
                                </td>
                                <td>
                                    <?= esc($due['PayableDueDate']) ?>
                                    <?php if ($due['DueState'] !== 'Upcoming') { ?>
                                        <span class="badge <?= $due['DueState'] === 'Overdue' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= esc($due['DueState']) ?></span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?= esc($due['PaymentPlanDescription']) ?>
                                    <small class="text-muted">(<?= esc($due['PaymentPlanLink'] === 'planitem' ? 'Monthly plan' : ucfirst($due['PaymentPlanLink'])) ?>)</small>
                                    <?php if ($due['Direction'] === 'TRANSFER') { ?>
                                        <br><small class="text-muted">&rarr; <?= esc($due['ToAccountName'] ?? 'no account set') ?></small>
                                    <?php } ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($due['PaymentPlanLink'] === 'planitem') { ?>
                                        <?= esc(date('M Y', strtotime($due['PayableDueDate']))) ?>
                                    <?php } else { ?>
                                        <?= (int) $due['InstallmentNo'] ?> / <?= (int) $due['PaymentPlanPeriod'] ?>
                                    <?php } ?>
                                </td>
                                <td class="text-end"><?= number_format($due['PayableDueAmount'], 2) ?></td>
                                <td class="text-end"><?= number_format($due['PayablePaidAmount'], 2) ?></td>
                                <td class="text-end font-weight-600"><?= number_format($due['PayableOutstanding'], 2) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <small class="text-muted">Partial amounts are applied to the oldest selected due first.
                    Monthly plan dues are listed up to the end of next month; later months are in the <a href="<?= base_url('/plan/' . date('Y')) ?>">Monthly Plan</a>.</small>
            </div>
            <div class="col-12 my-2 d-flex">
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="dued-mode-full" name="dued-mode" value="full" checked>
                    <label class="form-check-label" for="dued-mode-full">Pay in full</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="dued-mode-partial" name="dued-mode" value="partial">
                    <label class="form-check-label" for="dued-mode-partial">Partial payment</label>
                </div>
            </div>
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Selected total" id="dued-selected-total" value="0.00" readonly>
                    <label for="dued-selected-total">Selected outstanding</label>
                </div>
            </div>
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Amount to pay" name="amount" id="amount" value="0.00" readonly>
                    <label for="amount" id="dued-amount-label">Amount to pay</label>
                </div>
            </div>
            <div class="col-12 mb-3" id="description-input">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Description" name="description" id="description" maxlength="100">
                    <label for="description">Description (optional)</label>
                </div>
            </div>
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <select class="form-select current-account-list" name="current-account-list" id="current-account-list">
                        <?php foreach($accountInfo['accounts'] as $key => $account){ ?>
                            <option class="<?= $account['AccountSessionID']?>" value="<?= $account['AccountSessionID']?>" data-running-balance="<?= number_format($account['AccountCurrentBalance'],2)?>"><?= esc($account['AccountName']) ?></option>
                        <?php } ?>
                    </select>
                    <label for="current-account-list" class="form-label" id="dued-account-label">Pay from account:</label>
                </div>
                <input type="text" class="form-control" name="current-running-balance" id="current-running-balance" value="<?= number_format($accountInfo['accounts'][0]['AccountCurrentBalance'],2)?>" readonly>
            </div>
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <select class="form-select budget-list" name="budget-list" id="budget-list">
                        <?php foreach($budgetInfo as $key => $budget){ ?>
                            <option class="<?= $budget['BudgetSessionID']?>" value="<?= $budget['BudgetSessionID']?>"><?= esc($budget['BudgetName']) ?></option>
                        <?php } ?>
                    </select>
                    <label for="budget-list" class="form-label">Budget Handle:</label>
                </div>
            </div>
            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo ?? [], 'label' => 'Payment method', 'showReference' => true]) ?>
        </div>
    </div>
    <div class="mb-3 justify-content-center">
        <a id="dued-payment-btn" class="btn btn-primary">Settle Selected</a>
    </div>
</div>
