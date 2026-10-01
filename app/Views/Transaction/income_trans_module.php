
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/income.js') ?>"></script>
<div class="mb-3 transaction-type-list d-none" id="income">
    <!-- <h3 class="mb-5">Income Transaction</h3> -->
    <div class="form">
        <div class="mb-3 d-flex row">
            <div class="col-12 my-3 d-flex">
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="one-time" name="income-type" value="one-time" checked>
                    <label class="form-check-label" for="one-time">One Time</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="monthly" name="income-type" value="monthly">
                    <label class="form-check-label" for="monthly">Monthly</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="till" name="income-type" value="till">
                    <label class="form-check-label" for="till">Until</label>
                </div>
            </div>
            <div class="col-3 d-none" id="initate-date">
                <div class="form-floating mb-3 mt-3">
                    <input type="date" class="form-control" id="start-date" placeholder="Starting Date">
                    <label for="start-date">Starting Date</label>
                </div>
            </div>
            <div class="col-3 d-none" id="until-date">
                <div class="form-floating mb-3 mt-3">
                    <select class="form-select until-plan-type" name="until-plan-type" id="until-plan-type">
                        <?php foreach($paymentPlan as $key => $periodic){ ?>
                            <?php if($key != 'C' && $key != 'I'){ ?>
                                <option class="" value="<?= $key?>" <?php if($key == 'M'){echo 'selected';} ?>><?= $periodic ?></option>
                            <?php } ?>
                        <?php } ?>
                    </select>
                    <label for="from-account-list" class="form-label">Payment Plan:</label>
                </div>
            </div>
            <div class="col-3 d-none" id="until-period">
                <div class="form-floating mb-3 mt-3">
                    <input type="number" class="form-control" placeholder="Period" id="until-payment-period" value="3">
                    <label for="until-payment-period">Period</label>
                </div>
            </div>
            <div class="col-12 mb-3" id="description-input">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter the transaction information" name="description" id="description">
                    <label for="description">Description</label>
                </div>
            </div>
            <div class="col-6">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter the amount" name="amount" id="amount">
                    <label for="amount">Enter an Amount</label>
                </div>
            </div>
            <div class="col-6">
                <div class="form-floating">
                    <select class="form-select current-account-list" name="current-account-list" id="current-account-list">
                        <?php foreach($accountInfo['accounts'] as $key => $account){ ?>
                            <option class="<?= $account['AccountSessionID']?>" value="<?= $account['AccountSessionID']?>" data-running-balance="<?= number_format($account['AccountCurrentBalance'],2)?>"><?= esc($account['AccountName']) ?></option>
                        <?php } ?>
                    </select>
                    <label for="to-account-list" class="form-label">Current Account:</label>
                </div>
                <input type="text" class="form-control" name="current-running-balance" id="current-running-balance" value="<?= number_format($accountInfo['accounts'][0]['AccountCurrentBalance'],2)?>" readonly>
            </div>
            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo ?? [], 'label' => 'Received via', 'showReference' => true]) ?>
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <select class="form-select budget-list" name="budget-list" id="budget-list">
                        <?php foreach($budgetInfo as $key => $budget){ ?>
                            <option class="<?= $budget['BudgetSessionID']?>" value="<?= $budget['BudgetSessionID']?>"><?= $budget['BudgetName'] ?></option>
                        <?php } ?>
                    </select>
                    <label for="from-account-list" class="form-label">Budget Handle:</label>
                </div>
            </div>
            <div class="col-12 mb-3 d-none" id="first-payment-section">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="first-payment" name="first-payment" value="make-init-payment">
                    <label class="form-check-label">Make the initate payment</label>
                </div>
            </div>
        </div>
    </div>
    <div class="mb-3 justify-content-center">
        <a id="payment-btn" class="btn btn-primary">Make Payment</a>
    </div>
</div>