<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?> 
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/create.js') ?>"></script>
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/common_modules/pagination.js') ?>"></script>

<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
    <div class="container">
        <div class="justify-content-center">
            <div class="row">
                <?php
                    if(session()->getFlashdata('status'))
                    {
                ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <strong class="me-auto"><?= $StoredText['ErrorStatus'] ?></strong>
                        <p><?= session()->getFlashdata('status') ?></p>
                    </div>
                <?php
                    }
                ?>
            </div>
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="lb-card">
                        <h2 class="lb-section-title">Your accounts</h2>
                        <?= $account_list_content ?>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="lb-card">
                        <div class="form">
                            <div class="mb-3 d-flex row">
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select account-group-list" name="account-group-list" id="account-group-list" data-default-value="<?= $accountGroupDetails[0]['AccountGroupSessionID'] ?>">
                                            <?php foreach($accountGroupDetails as $key => $accountGroup){ ?>
                                                <option class="<?= $accountGroup['AccountGroupSessionID']?>" value="<?= $accountGroup['AccountGroupSessionID']?>"><?= $accountGroup['AccountGroupName'] ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="account-group-list" class="form-label">Account Group:</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the transaction information" name="account-name" id="account-name">
                                        <label for="account-name">Enter Account Name</label>
                                        
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the current amount" name="amount" id="amount">
                                        <label for="amount">Current Amount</label>
                                    </div>
                                </div>
                                <div class="col-6" id="account-number-col">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Account number" id="account-number" maxlength="40">
                                        <label for="account-number">Account number (optional, bank accounts)</label>
                                    </div>
                                </div>
                            </div>
                            <?php
                                $pct = static function ($value) { return rtrim(rtrim(number_format((float) $value, 2), '0'), '.'); };
                                $productsByBank = [];
                                foreach ($bankProducts as $product) { $productsByBank[$product['bankname']][] = $product; }
                            ?>
                            <div class="mb-3 d-flex row" id="account-type-section">
                                <div class="col-12"><h6 class="mb-2">Account type and bank</h6></div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="account-type">
                                            <?php foreach ($accountTypes as $code => $label) { ?>
                                                <option value="<?= esc($code, 'attr') ?>" <?= $code === 'SAVINGS' ? 'selected' : '' ?>><?= esc($label) ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="account-type">Account type</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3 bank-only">
                                    <div class="form-floating">
                                        <select class="form-select" id="bank-product">
                                            <option value="">Not listed / choose later</option>
                                            <?php foreach ($productsByBank as $bankName => $products) { ?>
                                                <optgroup label="<?= esc($bankName, 'attr') ?>">
                                                    <?php foreach ($products as $product) { ?>
                                                        <option value="<?= esc($product['sessionid'], 'attr') ?>"
                                                                data-type="<?= esc($product['producttype'], 'attr') ?>"
                                                                data-rate="<?= esc($pct($product['interestrate']), 'attr') ?>"
                                                                data-fees="<?= esc(\App\Libraries\BankRateSync::feeSummary($productFees[$product['sessionid']] ?? []), 'attr') ?>"><?= esc($bankName) ?> · <?= esc($product['productname']) ?> · <?= $pct($product['interestrate']) ?>%</option>
                                                    <?php } ?>
                                                </optgroup>
                                            <?php } ?>
                                        </select>
                                        <label for="bank-product">Bank product</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3 bank-only" id="bank-name-col">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="bank-name" placeholder="Bank" maxlength="100" list="bank-name-list">
                                        <label for="bank-name">Bank (if the product isn't listed)</label>
                                        <datalist id="bank-name-list">
                                            <?php foreach (array_keys($productsByBank) as $bankName) { ?><option value="<?= esc($bankName, 'attr') ?>"><?php } ?>
                                        </datalist>
                                    </div>
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="link-card-check">
                                        <label class="form-check-label" for="link-card-check">This account uses a debit card from another account</label>
                                    </div>
                                </div>
                                <div class="col-12 mb-2 bank-only">
                                    <small class="text-muted" id="bank-product-hint">Pick the bank product to get its interest rate and its withdrawal and transfer charges from <a href="<?= base_url('bank-rates') ?>">Bank Rates</a>.</small>
                                </div>
                                
                                <div class="col-12 mb-2 d-none" id="auto-interest-row">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="new-auto-interest">
                                        <label class="form-check-label small" for="new-auto-interest">Post interest automatically each month</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3 d-flex row" id="default-method-section">
                                <div class="col-12"><h6 class="mb-2">Default payment method</h6></div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="method-type">
                                            <?php foreach ($methodTypes as $code => $label) { ?>
                                                <option value="<?= esc($code, 'attr') ?>" <?= $code === 'BANK_TRANSFER' ? 'selected' : '' ?>><?= esc($label) ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="method-type">Type</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Name" id="method-name" maxlength="50">
                                        <label for="method-name">Name (e.g. Sampath Visa)</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Last 4 digits" id="method-reference" maxlength="4" inputmode="numeric">
                                        <label for="method-reference">Card last 4 digits (optional)</label>
                                    </div>
                                </div>
                                <div class="col-12 mb-3 d-none" id="link-card-select-col">
                                    <div class="form-floating">
                                        <select class="form-select" id="linked-card-list">
                                            <option value="">Choose a card</option>
                                            <?php foreach (($paymentMethodInfo ?? []) as $method) {
                                                if (! in_array($method['PaymentMethodType'] ?? '', ['DEBIT_CARD', 'CREDIT_CARD'], true) || !empty($method['PaymentMethodLinkedFrom']) || ($method['PaymentMethodShowInDropdown'] ?? 'Y') === 'N') { continue; } ?>
                                                <option value="<?= esc($method['PaymentMethodSessionID'], 'attr') ?>"><?= esc($method['PaymentMethodLabel']) ?> (<?= esc($method['AccountName']) ?>)</option>
                                            <?php } ?>
                                        </select>
                                        <label for="linked-card-list">Debit card of another account</label>
                                    </div>
                                    <small class="text-muted">The account pays from its own balance, as a debit. The other account's card limit and debt are not used.</small>
                                </div>
                                <div class="col-6 mb-3 d-none" id="credit-limit-input">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Credit limit" id="credit-limit" value="0.00">
                                        <label for="credit-limit">Credit limit</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <p class="error-message">&nbsp;</p>
                            </div>
                        </div>
                        <div class="mb-3 justify-content-center ">
                            <a id="create-account-btn" class="btn btn-primary">Create Account</a>
                            <a id="update-account-btn" class="btn btn-primary d-none">Update Account</a>
                            <a id="cancel-account-btn" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>