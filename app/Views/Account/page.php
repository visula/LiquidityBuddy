<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= esc($StoredText['ScreenTitle']) ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= esc($StoredText['Header']) ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/create.js') ?>"></script>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/quick.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $balance = (float) $account['AccountCurrentBalance'];
    $hasProduct = ! empty($account['BankProductSessionID']);
    $rate = (float) ($account['InterestRate'] ?? 0);
    $minBalance = $account['MinBalance'] !== null ? (float) $account['MinBalance'] : null;
    $pct = static function ($value) { return rtrim(rtrim(number_format((float) $value, 2), '0'), '.'); };
    $defaultBudget = '';
    foreach ($budgetInfo as $budget) {
        if (strcasecmp($budget['BudgetName'], 'Default') === 0) { $defaultBudget = $budget['BudgetSessionID']; }
    }
    $productsByType = [];
    foreach ($bankProducts as $product) {
        $productsByType[$productTypes[$product['producttype']] ?? $product['producttype']][] = $product;
    }
?>
    <div class="container" id="account-page" data-account="<?= esc($account['AccountSessionID'], 'attr') ?>" data-default-budget="<?= esc($defaultBudget, 'attr') ?>">
        <?php if (session()->getFlashdata('status')) { ?>
            <div class="alert alert-info"><?= esc(session()->getFlashdata('status')) ?></div>
        <?php } ?>
        <div class="row">
            <div class="col-lg-7 col-12">
                <div class="lb-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="text-muted small">Balance</div>
                            <div class="lb-balance <?= $balance < 0 ? 'text-danger' : '' ?>"><?= number_format($balance, 2) ?></div>
                            <?php if ($hasProduct && $minBalance !== null && $balance < $minBalance) { ?>
                                <div class="small text-danger">Below the minimum balance of <?= number_format($minBalance, 2) ?></div>
                            <?php } ?>
                        </div>
                        <?php if ($hasProduct) { ?>
                            <div class="text-end">
                                <div class="small text-muted"><?= esc($account['BankName']) ?></div>
                                <div class="fw-semibold"><?= esc($account['ProductName']) ?> <span class="badge lb-badge-type"><?= esc($productTypes[$account['ProductType']] ?? $account['ProductType']) ?></span></div>
                                <div class="lb-rate"><?= $pct($rate) ?>% <span class="small text-muted fw-normal">p.a.</span></div>
                                <?php if ($account['PreviousRate'] !== null && (float) $account['PreviousRate'] !== $rate) { ?>
                                    <div class="small text-muted">was <?= $pct($account['PreviousRate']) ?>%</div>
                                <?php } ?>
                            </div>
                        <?php } else { ?>
                            <div class="text-end">
                                <?php if (! empty($account['AccountBankName'])) { ?><div class="small text-muted"><?= esc($account['AccountBankName']) ?></div><?php } ?>
                                <span class="badge lb-badge-type"><?= esc($accountTypes[$account['AccountType'] ?? 'OTHER'] ?? 'Other') ?></span>
                            </div>
                        <?php } ?>
                    </div>

                    <?php if ($accountFees !== []) { ?>
                        <div class="lb-fee-list small mt-3">
                            <div class="text-muted mb-1">Bank charges</div>
                            <?php foreach (['WITHDRAWAL' => 'Withdrawals', 'DEPOSIT' => 'Deposits', 'TRANSFER' => 'Transfers'] as $txnType => $txnLabel) {
                                $summary = \App\Libraries\BankRateSync::feeSummary($accountFees, $txnType);
                                if ($summary === '') { continue; } ?>
                                <div><strong><?= esc($txnLabel) ?>:</strong> <?= esc($summary) ?></div>
                            <?php } ?>
                        </div>
                    <?php } ?>

                    <?php if ($hasProduct && $account['ProductStatus'] !== 'A') { ?>
                        <div class="alert alert-warning py-2 mt-3 mb-0 small">This product is no longer in the bank's rate feed. The last known rate is used for interest.</div>
                    <?php } ?>
                    <?php if ($betterProduct) { ?>
                        <div class="alert alert-info py-2 mt-3 mb-0 small">
                            Better rate available: <strong><?= esc($betterProduct['bankname']) ?> <?= esc($betterProduct['productname']) ?></strong>
                            at <?= $pct($betterProduct['interestrate']) ?>%<?php if ($betterProduct['minbalance'] !== null) { ?> (min. balance <?= number_format((float) $betterProduct['minbalance'], 2) ?>)<?php } ?>.
                            <a href="<?= base_url('bank-rates?type=' . urlencode($betterProduct['producttype'])) ?>">Compare rates</a>
                        </div>
                    <?php } ?>

                    <hr class="my-3">

                    <div class="row g-2 mb-2">
                        <div class="col-md-6 col-12">
                            <div class="form-floating">
                                <select class="form-select" id="account-type-edit">
                                    <?php foreach ($accountTypes as $code => $label) { ?>
                                        <option value="<?= esc($code, 'attr') ?>" <?= ($account['AccountType'] ?? 'OTHER') === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
                                    <?php } ?>
                                </select>
                                <label for="account-type-edit">Account type</label>
                            </div>
                        </div>
                        <div class="col-md-6 col-12" id="account-bank-col">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="account-bank-edit" placeholder="Bank" maxlength="100" value="<?= esc((string) ($account['AccountBankName'] ?? ''), 'attr') ?>" <?= $hasProduct ? 'readonly' : '' ?>>
                                <label for="account-bank-edit">Bank<?= $hasProduct ? ' (from the bank product)' : '' ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-8 col-12">
                            <div class="form-floating">
                                <select class="form-select" id="bank-product">
                                    <option value="">Not linked to a bank product</option>
                                    <?php foreach ($productsByType as $typeLabel => $products) { ?>
                                        <optgroup label="<?= esc($typeLabel, 'attr') ?>">
                                            <?php foreach ($products as $product) { ?>
                                                <option value="<?= esc($product['sessionid'], 'attr') ?>" data-type="<?= esc($product['producttype'], 'attr') ?>" data-bank="<?= esc($product['bankname'], 'attr') ?>" <?= $product['sessionid'] === $account['BankProductSessionID'] ? 'selected' : '' ?>><?= esc($product['bankname']) ?> · <?= esc($product['productname']) ?> · <?= $pct($product['interestrate']) ?>%</option>
                                            <?php } ?>
                                        </optgroup>
                                    <?php } ?>
                                    <?php if ($hasProduct && $account['ProductStatus'] !== 'A') { ?>
                                        <option value="<?= esc($account['BankProductSessionID'], 'attr') ?>" selected><?= esc($account['BankName'] . ' · ' . $account['ProductName']) ?> (no longer offered)</option>
                                    <?php } ?>
                                </select>
                                <label for="bank-product">Bank product (rate and charges)</label>
                            </div>
                        </div>
                        <div class="col-md-4 col-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="auto-interest" <?= ($account['AutoInterest'] ?? 'N') === 'Y' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="auto-interest">Post interest automatically each month</label>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm w-100" id="save-product-btn">Save</button>
                        </div>
                    </div>
                    <p class="error-message mb-0" id="product-error">&nbsp;</p>

                    <?php if ($hasProduct && $interestDue && $rate > 0) { ?>
                        <div class="lb-interest small text-muted">
                            Interest earned since <?= esc(date('d M Y', strtotime($interestDue['From']))) ?>, not posted yet:
                            <strong class="text-success"><?= number_format($interestDue['Amount'], 2) ?></strong>
                            (average balance <?= number_format($interestDue['AverageBalance'], 2) ?> over <?= (int) $interestDue['Days'] ?> days)
                        </div>
                    <?php } ?>
                </div>
            </div>
            <div class="col-lg-5 col-12">
                <div class="lb-card">
                    <h2 class="lb-section-title">Quick add</h2>
                    <div class="lb-quick-grid">
                        <button type="button" class="btn lb-quick-btn" data-action="deposit"><span class="lb-quick-icon">+</span>Deposit</button>
                        <button type="button" class="btn lb-quick-btn" data-action="withdraw" <?= $otherAccounts ? '' : 'disabled' ?>><span class="lb-quick-icon">&darr;</span>Withdraw</button>
                        <button type="button" class="btn lb-quick-btn" data-action="transfer" <?= $otherAccounts ? '' : 'disabled' ?>><span class="lb-quick-icon">&#8644;</span>Transfer</button>
                        <button type="button" class="btn lb-quick-btn" data-action="pay"><span class="lb-quick-icon">&minus;</span>Pay</button>
                        <button type="button" class="btn lb-quick-btn" data-action="interest" <?= ($hasProduct && $rate > 0) ? '' : 'disabled title="Link a bank product with an interest rate first"' ?>><span class="lb-quick-icon">%</span>Interest</button>
                        <a class="btn lb-quick-btn" href="<?= base_url('payment/history?account=' . urlencode($accountID)) ?>"><span class="lb-quick-icon">&equiv;</span>History</a>
                    </div>
                    <p class="small text-muted mt-3 mb-0">Withdraw moves money to another account (cash first). Pay records an expense from this account.</p>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
              <div class="lb-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="lb-section-title mb-0">Payment methods</h2>
                    <span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= base_url('payment-method/list') ?>">Manage</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('payment/history?account=' . urlencode($accountID ?? '')) ?>">Payment history</a>
                    </span>
                </div>
                <?php if (empty($accountPaymentMethods)) { ?>
                    <p class="text-muted mb-0">No payment methods on this account - payments use the account balance.</p>
                <?php } else { ?>
                    <div class="d-flex flex-wrap">
                        <?php foreach ($accountPaymentMethods as $method) { ?>
                            <div class="card me-2 mb-2">
                                <div class="card-body py-2 px-3">
                                    <div class="font-weight-600">
                                        <?= esc($method['PaymentMethodLabel']) ?>
                                        <?php if ($method['PaymentMethodIsDefault'] === 'Y') { ?><span class="badge bg-secondary">Default</span><?php } ?>
                                    </div>
                                    <small class="text-muted"><?= esc($method['PaymentMethodTypeLabel']) ?> · available <?= number_format($method['AvailableFunds'], 2) ?></small>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
              </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
              <div class="lb-card">
                <?php if (empty($transactionInfo['transactions'])) { ?>
                    <h2 class="lb-section-title">Transactions</h2>
                    <p class="text-muted mb-0">No transactions yet. Use Quick add to record the first one.</p>
                <?php } else { ?>
                    <?= $transaction_details_container ?>
                <?php } ?>
              </div>
            </div>
        </div>
    </div>

    <!-- Quick add: one short form, fields shown per action -->
    <div class="modal fade" id="quick-modal" tabindex="-1" aria-labelledby="quick-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quick-title">Quick add</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12 quick-field" data-for="interest">
                            <div class="btn-group w-100 mb-1" role="group" aria-label="Interest period">
                                <input type="radio" class="btn-check" name="interest-through" id="through-month" value="month_end" checked>
                                <label class="btn btn-outline-secondary btn-sm" for="through-month">Up to last month end</label>
                                <input type="radio" class="btn-check" name="interest-through" id="through-today" value="today">
                                <label class="btn btn-outline-secondary btn-sm" for="through-today">Up to today</label>
                            </div>
                            <p class="small text-muted mb-2" id="interest-quote">Calculating...</p>
                        </div>
                        <div class="col-12 quick-field" data-for="withdraw transfer">
                            <div class="form-floating">
                                <select class="form-select" id="quick-to-account">
                                    <?php foreach ($otherAccounts as $other) { ?>
                                        <option value="<?= esc($other['AccountSessionID'], 'attr') ?>" data-cash="<?= in_array($other['AccountSessionID'], $cashAccountIDs, true) ? '1' : '0' ?>"><?= esc($other['AccountName']) ?></option>
                                    <?php } ?>
                                </select>
                                <label for="quick-to-account">To account</label>
                            </div>
                        </div>
                        <div class="col-6 quick-field" data-for="withdraw transfer">
                            <div class="form-floating">
                                <select class="form-select" id="quick-channel"></select>
                                <label for="quick-channel">How</label>
                            </div>
                        </div>
                        <div class="col-6 quick-field" data-for="withdraw transfer">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="quick-fee" placeholder="Bank charge" inputmode="decimal" value="0.00">
                                <label for="quick-fee">Bank charge</label>
                            </div>
                        </div>
                        <div class="col-12 quick-field" data-for="withdraw transfer">
                            <small class="text-muted" id="quick-fee-rule">&nbsp;</small>
                        </div>
                        <div class="col-6">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="quick-amount" placeholder="Amount" inputmode="decimal" autocomplete="off">
                                <label for="quick-amount">Amount</label>
                            </div>
                        </div>
                        <div class="col-6 quick-field" data-for="deposit withdraw transfer pay">
                            <div class="form-floating">
                                <input type="date" class="form-control" id="quick-date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                                <label for="quick-date">Date</label>
                            </div>
                        </div>
                        <div class="col-12 quick-field" data-for="deposit withdraw transfer pay">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="quick-description" placeholder="Description" maxlength="50">
                                <label for="quick-description">Description</label>
                            </div>
                        </div>
                        <div class="col-6 quick-field" data-for="deposit withdraw transfer pay interest">
                            <div class="form-floating">
                                <select class="form-select" id="quick-method">
                                    <?php foreach ($accountPaymentMethods as $method) { ?>
                                        <option value="<?= esc($method['PaymentMethodSessionID'], 'attr') ?>" <?= $method['PaymentMethodIsDefault'] === 'Y' ? 'selected' : '' ?>><?= esc($method['PaymentMethodLabel']) ?></option>
                                    <?php } ?>
                                    <?php if (empty($accountPaymentMethods)) { ?><option value="">Account balance</option><?php } ?>
                                </select>
                                <label for="quick-method">Payment method</label>
                            </div>
                        </div>
                        <div class="col-6 quick-field" data-for="deposit withdraw transfer pay">
                            <div class="form-floating">
                                <select class="form-select" id="quick-budget">
                                    <?php foreach ($budgetInfo as $budget) { ?>
                                        <option value="<?= esc($budget['BudgetSessionID'], 'attr') ?>" <?= $budget['BudgetSessionID'] === $defaultBudget ? 'selected' : '' ?>><?= esc($budget['BudgetName']) ?></option>
                                    <?php } ?>
                                </select>
                                <label for="quick-budget">Budget</label>
                            </div>
                        </div>
                    </div>
                    <p class="error-message mt-2 mb-0" id="quick-error">&nbsp;</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="quick-submit">Save</button>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
