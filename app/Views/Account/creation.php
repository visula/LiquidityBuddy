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
<?php
    $pct = static function ($value) { return rtrim(rtrim(number_format((float) $value, 2), '0'), '.'); };

    // Bank products, grouped by bank
    $productsByBank = [];
    foreach (($bankProducts ?? []) as $product) { $productsByBank[$product['bankname']][] = $product; }

    // The types a bank account can be (its product type)
    $bankTypes = array_intersect_key($accountTypes, array_flip(['SAVINGS', 'CURRENT', 'FIXED_DEPOSIT', 'OTHER']));
?>
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

                            <!-- 1. The basics: group, name and opening amount -->
                            <div class="mb-3 d-flex row" id="account-basics-section">
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select account-group-list" name="account-group-list" id="account-group-list" data-default-value="<?= $accountGroupDetails[0]['AccountGroupSessionID'] ?>">
                                            <?php foreach($accountGroupDetails as $accountGroup){ ?>
                                                <option class="<?= $accountGroup['AccountGroupSessionID']?>" value="<?= $accountGroup['AccountGroupSessionID']?>"><?= $accountGroup['AccountGroupName'] ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="account-group-list" class="form-label">Account group</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Account name" name="account-name" id="account-name">
                                        <label for="account-name">Account name</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Current amount" name="amount" id="amount">
                                        <label for="amount">Account amount</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3 d-none" id="account-number-col">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Account number" id="account-number" maxlength="40">
                                        <label for="account-number">Account number</label>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. What kind of account: bank, cash or other. Bank opens the bank details. -->
                            <div class="mb-3 row" id="account-type-section">
                                <div class="col-12 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="account-kind">
                                            <option value="BANK">Bank account</option>
                                            <option value="CASH" selected>Cash in hand</option>
                                            <option value="OTHER">Other (wallet, loan, etc.)</option>
                                        </select>
                                        <label for="account-kind">Is this a bank account?</label>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Bank details (bank accounts only) -->
                            <div class="mb-3 d-flex row bank-only" id="bank-section">
                                <div class="col-12"><h6 class="mb-2">Bank details</h6></div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="bank-name" placeholder="Bank" maxlength="100" list="bank-name-list">
                                        <label for="bank-name">Bank name</label>
                                        <datalist id="bank-name-list">
                                            <?php foreach (array_keys($productsByBank) as $bankName) { ?><option value="<?= esc($bankName, 'attr') ?>"><?php } ?>
                                        </datalist>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="account-type">
                                            <?php foreach ($bankTypes as $code => $label) { ?>
                                                <option value="<?= esc($code, 'attr') ?>"><?= esc($label) ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="account-type">Bank product type</label>
                                    </div>
                                </div>
                                <div class="col-12 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="bank-product">
                                            <option value="">Not listed / choose later</option>
                                            <?php foreach ($productsByBank as $bankName => $products) { ?>
                                                <optgroup label="<?= esc($bankName, 'attr') ?>">
                                                    <?php foreach ($products as $product) { ?>
                                                        <option value="<?= esc($product['sessionid'], 'attr') ?>"
                                                                data-type="<?= esc($product['producttype'], 'attr') ?>"
                                                                data-rate="<?= esc($pct($product['interestrate']), 'attr') ?>"
                                                                data-fees="<?= esc(\App\Libraries\BankRateSync::feeSummary($productFees[$product['sessionid']] ?? []), 'attr') ?>"><?= esc($product['productname']) ?> · <?= $pct($product['interestrate']) ?>%</option>
                                                    <?php } ?>
                                                </optgroup>
                                            <?php } ?>
                                        </select>
                                        <label for="bank-product">Bank product (optional)</label>
                                    </div>
                                    <small class="text-muted" id="bank-product-hint">Pick the bank product to get its interest rate and its withdrawal and transfer charges from <a href="<?= base_url('bank-rates') ?>">Bank Rates</a>.</small>
                                </div>
                                <div class="col-12 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="parent-account">
                                            <option value="">No, this is a main account</option>
                                            <?php foreach (($mainAccounts ?? []) as $main) { ?>
                                                <option value="<?= esc($main['sessionid'], 'attr') ?>"><?= esc($main['name']) ?><?= $main['bankname'] ? ' · ' . esc($main['bankname']) : '' ?><?= $main['groupname'] ? ' (' . esc($main['groupname']) . ')' : '' ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="parent-account">Sub-account of a main account?</label>
                                    </div>
                                    <small class="text-muted">For example, Vishwa sub-accounts under the main Vishwa account.</small>
                                    <small class="text-danger d-none" id="parent-note"></small>
                                </div>
                                <div class="col-12 mb-2 d-none" id="auto-interest-row">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="new-auto-interest">
                                        <label class="form-check-label small" for="new-auto-interest">Post interest automatically each month</label>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. Card (bank accounts only, and only when there is a choice) -->
                            <div class="mb-3 row bank-only" id="card-section">
                                <div class="col-12"><h6 class="mb-2">Card</h6></div>
                                <div class="col-12 mb-2 d-none" id="card-current"><small class="text-muted"></small></div>
                                <div class="col-12 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="card-mode" id="card-none" value="none" checked>
                                        <label class="form-check-label" for="card-none">No card for this account</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="card-mode" id="card-new" value="new">
                                        <label class="form-check-label" for="card-new">Add a new card</label>
                                    </div>
                                </div>

                                <div class="col-12 d-none" id="card-new-col">
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <div class="form-floating">
                                                <select class="form-select" id="method-type">
                                                    <option value="DEBIT_CARD">Debit card</option>
                                                    <option value="CREDIT_CARD">Credit card</option>
                                                </select>
                                                <label for="method-type">Card type</label>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" placeholder="Card name" id="method-name" maxlength="50">
                                                <label for="method-name">Card name (e.g. Sampath Visa)</label>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" placeholder="Last 4 digits" id="method-reference" maxlength="4" inputmode="numeric">
                                                <label for="method-reference">Last 4 digits (optional)</label>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3 d-none" id="credit-limit-input">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" placeholder="Credit limit" id="credit-limit" value="0.00">
                                                <label for="credit-limit">Credit limit</label>
                                            </div>
                                        </div>
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
