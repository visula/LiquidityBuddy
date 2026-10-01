
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/purchase.js') ?>"></script>
<div class="mb-3 transaction-type-list d-none" id="purchase">
    <h3>Purchase Transaction</h3>
    <div class="form">
        <div class="mb-3 d-flex row">
            <div class="col-12 mb-3">
                <input type="text" class="form-control" placeholder="Enter the transaction information" name="description" id="description">
            </div>
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Enter the amount" name="amount" id="amount">
                    <label for="amount">Enter an Amount</label>
                </div>
            </div>
            <div class="col-6">
                <div class="form-floating">
                    <select class="form-select budget-list" name="budget-list" id="budget-list">
                        <?php foreach($budgetInfo as $key => $budget){ ?>
                            <option class="<?= $budget['BudgetSessionID']?>" value="<?= $budget['BudgetSessionID']?>"><?= $budget['BudgetName'] ?></option>
                        <?php } ?>
                    </select>
                    <label for="from-account-list" class="form-label">Budget Handle:</label>
                </div>
            </div>
            <div class="col-12 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="additem" name="additem" value="item">
                    <label class="form-check-label">Add Items</label>
                </div>
                <div id="item-list" class="d-none">
                    <table class="table table-striped mb-3" id="dataTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Units</th>
                                <th>Unit Price</th>
                                <th>Discount Per Unit</th>
                                <th>Discount</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Rows will be added here dynamically -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5"><button id="addRowBtn" class="btn btn-primary">Add Row</button></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="col-6 mb-3">
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
            <div class="col-6 mb-3">
                <div class="form-floating">
                    <select class="form-select shop-list" name="shop-list" id="shop-list">
                        <?php foreach($shopInfo as $key => $shop){ ?>
                            <option class="<?= $shop['ShopSessionID']?>" value="<?= $shop['ShopSessionID']?>" <?php if($key == 0){echo 'selected';} ?>><?= $shop['ShopName'] ?></option>
                        <?php } ?>
                    </select>
                    <label for="from-account-list" class="form-label">Shops:</label>
                </div>
            </div>
            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo ?? [], 'label' => 'Pay with', 'showReference' => true]) ?>
        </div>
    </div>
    <div class="mb-3 justify-content-center">
        <a id="purchase-btn" class="btn btn-primary">Purchase</a>
    </div>
</div>