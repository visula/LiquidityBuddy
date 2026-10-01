
<div class="mb-3 transaction-type-list d-none" id="internal">
    <h3>Internal Transaction</h3>
    <div class="form">
        <div class="mb-3 d-flex row">
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter the amount" name="amount" id="amount">
                    <label for="amount">Enter an Amount</label>
                </div>
            </div>
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
            <div class="col-6">
                <div class="form-floating">
                    <select class="form-select current-account-list" name="current-account-list" id="current-account-list">
                        <?php foreach($accountInfo['accounts'] as $key => $account){ ?>
                            <option class="<?= $account['AccountSessionID']?> <?php if($key == 1){echo 'd-none';} ?>" value="<?= $account['AccountSessionID']?>" data-running-balance="<?= number_format($account['AccountCurrentBalance'],2)?>"><?= $account['AccountName'] ?></option>
                        <?php } ?>
                    </select>
                    <label for="to-account-list" class="form-label">Current Account:</label>
                </div>
                <input type="text" class="form-control" name="current-running-balance" id="current-running-balance" value="<?= number_format($accountInfo['accounts'][0]['AccountCurrentBalance'],2)?>" readonly>
            </div>
            <div class="col-6">
                <div class="form-floating">
                    <select class="form-select to-account-list" name="to-account-list" id="to-account-list">
                        <?php foreach($accountInfo['accounts'] as $key => $account){ ?>
                            <option class="<?= $account['AccountSessionID']?> <?php if($key == 0){echo 'd-none';} ?>" value="<?= $account['AccountSessionID']?>" data-running-balance="<?= number_format($account['AccountCurrentBalance'],2)?>" <?php if($key == 1){echo 'selected';} ?>><?= $account['AccountName'] ?></option>
                        <?php } ?>
                    </select>
                    <label for="from-account-list" class="form-label">Transfer to Account:</label>
                </div>
                <input type="text" class="form-control" name="to-running-balance" id="to-running-balance" value="<?= number_format($accountInfo['accounts'][1]['AccountCurrentBalance'],2)?>" readonly>
            </div>
            <div class="col-12 mb-3 mt-3">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Description" name="description" id="description" maxlength="100">
                    <label for="description">Description (optional, e.g. Credit card settlement)</label>
                </div>
            </div>
            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo ?? [], 'label' => 'Transfer from method', 'showReference' => true]) ?>
        </div>
    </div>
    <div class="mb-3 justify-content-center">
        <a id="transferred-btn" class="btn btn-primary">Transferred</a>
    </div>
</div>