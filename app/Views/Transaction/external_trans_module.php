
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/external.js') ?>"></script>
<div class="mb-3 transaction-type-list" id="external">
    <!-- <h3 class="mb-5">External Transaction</h3> -->
    <div class="form">
        <div class="mb-3 d-flex row">
            <div class="col-12 my-3 d-flex">
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="travel" name="expense-type" value="travel" checked>
                    <label class="form-check-label" for="travel">Travel</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="charity" name="expense-type" value="charity">
                    <label class="form-check-label" for="charity">Charity</label>
                </div>
                <div class="form-check mr-3">
                    <input type="radio" class="form-check-input" id="other" name="expense-type" value="other">
                    <label class="form-check-label" for="other">Other</label>
                </div>
            </div>
            <div class="col-12 mb-3 d-none" id="description-input">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter the transaction information" name="description" id="description">
                    <label for="description">Description</label>
                </div>
            </div>
            <div class="col-12 mb-3" id="travel-mode-input">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Travel Mode" name="travel-mode" id="travel-mode">
                    <label for="travel-mode">Travel Mode</label>
                </div>
            </div>
            <div class="col-6 mb-3" id="from-location-input">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter from Location" name="from-location" id="from-location">
                    <label for="from-location">Enter from Location</label>
                </div>
            </div>
            <div class="col-6 mb-3" id="to-location-input">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter to Location" name="to-location" id="to-location">
                    <label for="to-location">Enter to Location</label>
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
            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo ?? [], 'label' => 'Pay with', 'showReference' => true]) ?>
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
        </div>
    </div>
    <div class="mb-3 justify-content-center">
        <a id="payment-btn" class="btn btn-primary">Make Payment</a>
    </div>
</div>