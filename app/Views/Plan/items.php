<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/plan/items.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
    <link rel="stylesheet" href="<?= base_url('assets/css/plan.css') ?>">
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $fmt = static function ($value) { return number_format((float) $value, 2); };
    $directionBadge = ['IN' => ['Income', 'bg-success'], 'OUT' => ['Payment', 'bg-secondary'], 'TRANSFER' => ['Transfer', 'bg-info text-dark']];
    $statusBadge = ['A' => ['Active', 'bg-success'], 'E' => ['Ended', 'bg-light text-dark']];
    $sources = array_filter($items, static function ($item) { return empty($item['sourceplanitemsessionid']); });
    $percent = static function ($value) { return rtrim(rtrim(number_format((float) $value, 2), '0'), '.'); };
?>
    <div class="container-fluid">
        <div class="plan-toolbar">
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/plan/' . date('Y')) ?>"><i class="bi bi-grid-3x3"></i> Year grid</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/plan/' . date('Y/n')) ?>"><i class="bi bi-calendar-check"></i> This month</a>
        </div>
        <div class="row">
            <div class="col-xl-8 col-12">
                <div id="plan-items-region">
                <div class="lb-card">
                    <h2 class="lb-section-title">Items</h2>
                    <p class="text-muted small">Each item plans one income, bill, installment or transfer per month. From its tracking month, every month becomes a due you settle in the month view or the Dued tab. Repeating items are kept 12 months ahead.</p>
                    <div class="table-responsive">
                        <table class="table table-sm plan-items-table" id="plan-items-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Type</th>
                                    <th class="text-end">Monthly</th>
                                    <th>Due day</th>
                                    <th>From → to</th>
                                    <th>Account</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($items === []) { ?>
                                    <tr><td colspan="8" class="text-center text-muted">No plan items yet. Add the first one with the form.</td></tr>
                                <?php } ?>
                                <?php $lastGroup = null; foreach ($items as $item) {
                                    $group = $item['direction'] === 'IN' ? 'Income' : ($item['categoryname'] ?: 'Uncategorised');
                                    [$dirLabel, $dirClass] = $directionBadge[$item['direction']];
                                    [$statusLabel, $statusClass] = $statusBadge[$item['status']] ?? [$item['status'], 'bg-secondary'];
                                    $edit = [
                                        'id' => $item['sessionid'], 'name' => $item['name'], 'direction' => $item['direction'], 'kind' => $item['kind'],
                                        'category_id' => $item['plancategorysessionid'], 'amount_type' => $item['amounttype'], 'default_amount' => $item['defaultamount'],
                                        'due_day' => $item['dueday'], 'weekend_rule' => $item['weekendrule'], 'start_month' => $item['startmonth'] ? substr($item['startmonth'], 0, 7) : '',
                                        'end_date' => $item['enddate'], 'recurring' => (int) $item['recurring'], 'account_id' => $item['accountsessionid'],
                                        'payment_method' => $item['paymentmethodsessionid'], 'to_account_id' => $item['toaccountsessionid'],
                                        'budget_id' => $item['budgetsessionid'], 'source_item_id' => $item['sourceplanitemsessionid'],
                                        'share_ratio' => $item['shareratio'], 'stakeholder' => $item['stakeholder'], 'notes' => $item['notes'],
                                    ];
                                ?>
                                    <?php if ($group !== $lastGroup) { $lastGroup = $group; ?>
                                        <tr class="table-light"><td colspan="8" class="fw-semibold small text-uppercase text-muted"><?= esc($group) ?></td></tr>
                                    <?php } ?>
                                    <tr class="<?= $item['status'] === 'E' ? 'plan-item-ended' : '' ?>" data-item-row="<?= esc($item['sessionid'], 'attr') ?>">
                                        <td>
                                            <?= esc($item['name']) ?>
                                            <small class="d-block text-muted">
                                                <?php if ($item['sourcename']) { ?><?= esc($percent($item['shareratio'])) ?> × <?= esc($item['sourcename']) ?> · <?php } ?>
                                                <?= esc(ucfirst(strtolower($item['amounttype']))) ?><?= $item['recurring'] ? ' · repeats' : ' · one-off' ?>
                                                <?php if ($item['stakeholder']) { ?> · <?= esc($item['stakeholder']) ?><?php } ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?= $dirClass ?>"><?= esc($dirLabel) ?></span>
                                            <?php if ($item['kind'] === 'ENVELOPE') { ?><span class="badge bg-light text-dark">Envelope</span><?php } ?>
                                        </td>
                                        <td class="text-end"><?= $fmt($item['defaultamount']) ?></td>
                                        <td><?= $item['dueday'] ? (int) $item['dueday'] : 'last' ?></td>
                                        <td class="text-nowrap small">
                                            <?= $item['startmonth'] ? esc(date('M Y', strtotime($item['startmonth']))) : '-' ?>
                                            → <?= $item['enddate'] ? esc(date('d M Y', strtotime($item['enddate']))) : 'open' ?>
                                            <small class="d-block text-muted">tracked from <?= esc(date('M Y', strtotime($item['trackfrom']))) ?></small>
                                        </td>
                                        <td class="small">
                                            <?= esc($item['accountname'] ?? '-') ?>
                                            <?php if ($item['direction'] === 'TRANSFER') { ?><small class="d-block text-muted">→ <?= esc($item['toaccountname'] ?? '-') ?></small><?php } ?>
                                            <?php if ($item['methodname']) { ?><small class="d-block text-muted"><?= esc($item['methodname']) ?><?= $item['methodreference'] ? ' ••' . esc($item['methodreference']) : '' ?></small><?php } ?>
                                            <?php if ($item['budgetname']) { ?><small class="d-block text-muted">Budget: <?= esc($item['budgetname']) ?></small><?php } ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $statusClass ?>"><?= esc($statusLabel) ?></span>
                                            <?php if ((int) $item['opendues'] > 0) { ?><small class="d-block text-muted"><?= (int) $item['opendues'] ?> open</small><?php } ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a class="btn btn-sm btn-outline-primary plan-item-edit" data-item="<?= esc(json_encode($edit), 'attr') ?>">Edit</a>
                                            <?php if ($item['status'] === 'A') { ?>
                                                <a class="btn btn-sm btn-outline-secondary plan-item-end" data-id="<?= esc($item['sessionid'], 'attr') ?>" data-name="<?= esc($item['name'], 'attr') ?>">End</a>
                                            <?php } ?>
                                            <?php if ((int) $item['paiddues'] === 0) { ?>
                                                <a class="btn btn-sm btn-outline-danger plan-item-delete" data-id="<?= esc($item['sessionid'], 'attr') ?>" data-name="<?= esc($item['name'], 'attr') ?>">Delete</a>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <p id="plan-items-error" class="error-message">&nbsp;</p>
                </div>

                <?php $salaryItems = array_filter($sources, static function ($item) { return $item['direction'] === 'IN' && $item['kind'] === 'DUE' && $item['status'] === 'A'; }); ?>
                <div class="lb-card" id="plan-salary-advance">
                    <h2 class="lb-section-title">Salary advance</h2>
                    <p class="text-muted small mb-2">The advance is received in the month you pick. The next month's salary is reduced by the same amount.</p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <div class="form-floating">
                                <select class="form-select" id="advance-item">
                                    <?php foreach ($salaryItems as $item) { ?>
                                        <option value="<?= esc($item['sessionid'], 'attr') ?>"><?= esc($item['name']) ?></option>
                                    <?php } ?>
                                </select>
                                <label for="advance-item">Income item</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="month" class="form-control" id="advance-month" value="<?= date('Y-m') ?>">
                                <label for="advance-month">Month received</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="advance-amount" placeholder="Amount" inputmode="decimal">
                                <label for="advance-amount">Advance amount</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <a id="advance-save" class="btn btn-primary w-100">Take advance</a>
                        </div>
                    </div>
                    <p class="error-message mb-0" id="advance-error">&nbsp;</p>
                </div>
                <div class="lb-card" id="plan-categories">
                    <h2 class="lb-section-title">Categories and allocation</h2>
                    <?php if (abs($allocationPercentTotal - 100) > 0.001) { ?>
                        <div class="alert alert-warning py-2" id="plan-allocation-banner">
                            Payment categories add up to <strong><?= esc($percent($allocationPercentTotal)) ?>%</strong> of planned income, not 100%.
                        </div>
                    <?php } ?>
                    <p class="text-muted small">Allocation = the share of each month's planned income a payment category should take. Leave it empty for no target.</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Category</th><th>Kind</th><th>Allocation %</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($categories as $category) { ?>
                                    <tr data-category="<?= esc($category['sessionid'], 'attr') ?>">
                                        <td><input type="text" class="form-control form-control-sm plan-category-name" maxlength="50" value="<?= esc($category['name'], 'attr') ?>" aria-label="Category name"></td>
                                        <td class="text-muted small"><?= $category['direction'] === 'IN' ? 'Income' : 'Payments' ?></td>
                                        <td><input type="text" class="form-control form-control-sm plan-percent-input plan-category-percent" inputmode="decimal" value="<?= $category['allocationpercent'] === null ? '' : esc($percent($category['allocationpercent']), 'attr') ?>" aria-label="Allocation percent"></td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-primary plan-category-save">Save</a></td>
                                    </tr>
                                <?php } ?>
                                <tr id="plan-category-new">
                                    <td><input type="text" class="form-control form-control-sm" id="plan-category-new-name" maxlength="50" placeholder="New category"></td>
                                    <td>
                                        <select class="form-select form-select-sm" id="plan-category-new-direction" aria-label="Category kind">
                                            <option value="OUT">Payments</option>
                                            <option value="IN">Income</option>
                                        </select>
                                    </td>
                                    <td><input type="text" class="form-control form-control-sm plan-percent-input" id="plan-category-new-percent" inputmode="decimal" placeholder="%"></td>
                                    <td class="text-end"><a class="btn btn-sm btn-primary" id="plan-category-add">Add</a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p id="plan-category-error" class="error-message">&nbsp;</p>
                </div>
                </div>
            </div>

            <div class="col-xl-4 col-12">
                <div class="card transaction-type-list" id="plan-item-form">
                    <div class="card-header font-weight-600" id="plan-item-form-title">Add plan item</div>
                    <div class="card-body">
                        <input type="hidden" id="plan-item-id" value="">
                        <div class="d-flex flex-wrap gap-3 mb-3 plan-item-new-only">
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="plan-item-out" name="plan-item-direction" value="OUT" checked>
                                <label class="form-check-label" for="plan-item-out">I pay</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="plan-item-in" name="plan-item-direction" value="IN">
                                <label class="form-check-label" for="plan-item-in">I receive</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="plan-item-transfer" name="plan-item-direction" value="TRANSFER">
                                <label class="form-check-label" for="plan-item-transfer">I move money</label>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="plan-item-name" placeholder="Name" maxlength="100">
                                    <label for="plan-item-name">Name (e.g. House rent, Salary)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 plan-item-new-only plan-item-out-only">
                                <div class="form-floating">
                                    <select class="form-select" id="plan-item-kind">
                                        <option value="DUE">A due each month</option>
                                        <option value="ENVELOPE">Spending envelope</option>
                                    </select>
                                    <label for="plan-item-kind">Kind</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <select class="form-select" id="plan-item-category">
                                        <option value="">No category</option>
                                        <?php foreach ($categories as $category) { ?>
                                            <option value="<?= esc($category['sessionid'], 'attr') ?>" data-direction="<?= esc($category['direction'], 'attr') ?>"><?= esc($category['name']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="plan-item-category">Category</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="plan-item-amount" placeholder="Amount" inputmode="decimal">
                                    <label for="plan-item-amount">Monthly amount</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <select class="form-select" id="plan-item-amount-type">
                                        <?php foreach ($amountTypes as $type) { ?>
                                            <option value="<?= esc($type, 'attr') ?>"><?= esc(ucfirst(strtolower($type))) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="plan-item-amount-type">Amount is</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 plan-item-new-only">
                                <div class="form-floating">
                                    <input type="month" class="form-control" id="plan-item-start" value="<?= date('Y-m') ?>">
                                    <label for="plan-item-start">First month</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <input type="number" class="form-control" id="plan-item-due-day" min="1" max="31" placeholder="Due day">
                                    <label for="plan-item-due-day">Due day (empty = last)</label>
                                </div>
                            </div>
                            <div class="col-12 mb-3 plan-item-income-only d-none">
                                <div class="small text-muted mb-1">If the due date falls on a weekend (income only)</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input type="radio" class="form-check-input" id="plan-item-weekend-none" name="plan-item-weekend" value="NONE" checked>
                                        <label class="form-check-label" for="plan-item-weekend-none">No change</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" class="form-check-input" id="plan-item-weekend-prev" name="plan-item-weekend" value="PREV">
                                        <label class="form-check-label" for="plan-item-weekend-prev">Previous working day</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" class="form-check-input" id="plan-item-weekend-next" name="plan-item-weekend" value="NEXT">
                                        <label class="form-check-label" for="plan-item-weekend-next">Next working day</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <input type="date" class="form-control" id="plan-item-end">
                                    <label for="plan-item-end">Last date (optional)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 d-flex align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="plan-item-recurring" checked>
                                    <label class="form-check-label" for="plan-item-recurring">Repeats every month</label>
                                </div>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="form-floating">
                                    <select class="form-select current-account-list" id="current-account-list">
                                        <option value="">No account yet</option>
                                        <?php foreach ($accounts as $account) { ?>
                                            <option value="<?= esc($account['AccountSessionID'], 'attr') ?>"><?= esc($account['AccountName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="current-account-list" id="plan-item-account-label">Pay from account</label>
                                </div>
                            </div>
                        </div>
                        <div class="row plan-item-pay-only">
                            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo, 'label' => 'Payment method', 'showReference' => false]) ?>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <select class="form-select" id="plan-item-budget">
                                        <option value="">No budget</option>
                                        <?php foreach ($budgetInfo as $budget) { ?>
                                            <option value="<?= esc($budget['BudgetSessionID'], 'attr') ?>"><?= esc($budget['BudgetName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="plan-item-budget">Budget</label>
                                </div>
                            </div>
                        </div>
                        <div class="row plan-item-transfer-only d-none">
                            <div class="col-12 mb-3">
                                <div class="form-floating">
                                    <select class="form-select" id="plan-item-to-account">
                                        <option value="">Choose the account</option>
                                        <?php foreach ($accounts as $account) { ?>
                                            <option value="<?= esc($account['AccountSessionID'], 'attr') ?>"><?= esc($account['AccountName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="plan-item-to-account">Move to account</label>
                                </div>
                            </div>
                        </div>
                        <details class="mb-3" id="plan-item-more">
                            <summary class="text-muted small mb-2">Share of another item, notes</summary>
                            <div class="row">
                                <div class="col-8 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="plan-item-source">
                                            <option value="">Not a share</option>
                                            <?php foreach ($sources as $source) { ?>
                                                <option value="<?= esc($source['sessionid'], 'attr') ?>"><?= esc($source['name']) ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="plan-item-source">Share of</label>
                                    </div>
                                </div>
                                <div class="col-4 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="plan-item-ratio" placeholder="Ratio" inputmode="decimal">
                                        <label for="plan-item-ratio">Ratio</label>
                                    </div>
                                </div>
                                <div class="col-12 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="plan-item-stakeholder" placeholder="Who" maxlength="60">
                                        <label for="plan-item-stakeholder">Who shares / owes (optional)</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="plan-item-notes" placeholder="Notes" maxlength="255">
                                        <label for="plan-item-notes">Notes (optional)</label>
                                    </div>
                                </div>
                            </div>
                        </details>
                        <p class="error-message" id="plan-item-error">&nbsp;</p>
                        <a id="plan-item-save" class="btn btn-primary">Add Item</a>
                        <a id="plan-item-cancel" class="btn btn-secondary d-none">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
