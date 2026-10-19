<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/plan/entry.js') ?>"></script>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/plan/month.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
    <link rel="stylesheet" href="<?= base_url('assets/css/plan.css') ?>">
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $fmt = static function ($value) { return number_format((float) $value, 2); };
    $t = $view['totals'];
    $prev = mktime(0, 0, 0, $view['month'] - 1, 1, $view['year']);
    $next = mktime(0, 0, 0, $view['month'] + 1, 1, $view['year']);
    $stateLabels = ['settled' => 'Settled', 'partial' => 'Part paid', 'overdue' => 'Overdue', 'upcoming' => 'Upcoming',
        'historical' => 'History', 'skipped' => 'Skipped', 'envelope' => 'Envelope'];
    $directionLabels = ['OUT' => 'Payables', 'IN' => 'Receivables', 'TRANSFER' => 'Transfers'];
    $counts = ['OUT' => 0, 'IN' => 0, 'TRANSFER' => 0];
    foreach ($view['dues'] as $due) {
        if ($due['outstanding'] > 0) { $counts[$due['direction']]++; }
    }
    $firstDirection = 'OUT';
    foreach (['OUT', 'IN', 'TRANSFER'] as $direction) {
        if ($counts[$direction] > 0) { $firstDirection = $direction; break; }
    }
    $envelopeSpent = array_sum(array_column($view['envelopes'], 'spent'));
?>
    <div class="container-fluid">
        <div class="plan-toolbar">
            <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/plan/' . date('Y/n', $prev)) ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
            <span class="plan-toolbar-title"><?= esc(date('M Y', mktime(0, 0, 0, $view['month'], 1, $view['year']))) ?></span>
            <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/plan/' . date('Y/n', $next)) ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
            <span class="plan-toolbar-spacer"></span>
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/plan/' . $view['year']) ?>"><i class="bi bi-grid-3x3"></i> Year grid</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/plan/items') ?>"><i class="bi bi-list-check"></i> Plan items</a>
        </div>

        <div id="plan-month-region">
        <div class="row g-3 mb-4" id="plan-month-summary">
            <div class="col-6 col-lg-3">
                <div class="plan-stat">
                    <div class="plan-stat-label">Planned income</div>
                    <div class="plan-stat-value text-success" id="plan-month-income"><?= $fmt($t['income']) ?></div>
                    <div class="plan-stat-sub">received <?= $fmt($t['settled_in']) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="plan-stat">
                    <div class="plan-stat-label">Planned commitments</div>
                    <div class="plan-stat-value" id="plan-month-outflow"><?= $fmt($t['outflow']) ?></div>
                    <div class="plan-stat-sub">paid <?= $fmt($t['settled_out']) ?> · envelopes spent <?= $fmt($envelopeSpent) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="plan-stat">
                    <div class="plan-stat-label">Left after commitments</div>
                    <div class="plan-stat-value <?= $t['net'] < 0 ? 'plan-negative' : '' ?>" id="plan-month-net"><?= $fmt($t['net']) ?></div>
                    <div class="plan-stat-sub">planned income − planned commitments</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="plan-stat">
                    <div class="plan-stat-label">Actual so far</div>
                    <?php $actual = round($t['settled_in'] - $t['settled_out'] - $envelopeSpent, 2); ?>
                    <div class="plan-stat-value <?= $actual < 0 ? 'plan-negative' : '' ?>" id="plan-month-actual"><?= $fmt($actual) ?></div>
                    <div class="plan-stat-sub">received − paid − envelope spending</div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-9 col-lg-8 col-12">
                <div class="lb-card" id="plan-month-dues">
                    <div class="d-flex flex-wrap gap-3 mb-2">
                        <?php foreach ($directionLabels as $direction => $label) { ?>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="plan-direction-<?= strtolower($direction) ?>" name="plan-direction" value="<?= $direction ?>" <?= $direction === $firstDirection ? 'checked' : '' ?>>
                                <label class="form-check-label" for="plan-direction-<?= strtolower($direction) ?>"><?= $label ?> (<?= $counts[$direction] ?> open)</label>
                            </div>
                        <?php } ?>
                        <div class="form-check ms-auto">
                            <input type="checkbox" class="form-check-input" id="plan-show-closed">
                            <label class="form-check-label" for="plan-show-closed">Show settled and skipped</label>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm plan-dues-table" id="plan-dues-table">
                            <thead>
                                <tr>
                                    <th><input class="form-check-input" type="checkbox" id="plan-select-all" title="Select all"></th>
                                    <th>Due</th>
                                    <th>Item</th>
                                    <th>From / to</th>
                                    <th class="text-end">Planned</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Outstanding</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($view['dues'] === []) { ?>
                                    <tr><td colspan="8" class="text-center text-muted">Nothing planned for this month</td></tr>
                                <?php } ?>
                                <?php foreach ($view['dues'] as $due) {
                                    $open = $due['outstanding'] > 0;
                                ?>
                                    <tr class="plan-due-row<?= $open ? '' : ' plan-due-closed' ?>"
                                        data-direction="<?= esc($due['direction'], 'attr') ?>"
                                        data-account="<?= esc((string) $due['payaccount'], 'attr') ?>"
                                        data-method="<?= esc((string) $due['paymethod'], 'attr') ?>"
                                        data-budget="<?= esc((string) $due['budgetsessionid'], 'attr') ?>"
                                        data-to-account="<?= esc((string) $due['toaccountsessionid'], 'attr') ?>">
                                        <td>
                                            <?php if ($open) { ?>
                                                <input class="form-check-input plan-due-check" type="checkbox" value="<?= esc($due['payablesessionid'], 'attr') ?>" data-outstanding="<?= esc($due['outstanding'], 'attr') ?>">
                                            <?php } ?>
                                        </td>
                                        <td class="text-nowrap"><?= esc(date('d M', strtotime($due['duedate']))) ?></td>
                                        <td>
                                            <?= esc($due['name']) ?>
                                            <small class="d-block text-muted"><?= esc($due['categoryname'] ?? '') ?><?= $due['notes'] ? ' · ' . esc($due['notes']) : '' ?></small>
                                        </td>
                                        <td>
                                            <?= esc($due['payaccountname'] ?? 'No account set') ?>
                                            <?php if ($due['direction'] === 'TRANSFER') { ?>
                                                <small class="d-block text-muted">&rarr; <?= esc($due['toaccountname'] ?? 'no account set') ?></small>
                                            <?php } elseif ($due['paymethodlabel']) { ?>
                                                <small class="d-block text-muted"><?= esc($due['paymethodlabel']) ?></small>
                                            <?php } ?>
                                        </td>
                                        <td class="text-end"><?= $fmt($due['plannedamount']) ?></td>
                                        <td class="text-end"><?= (float) $due['paidamount'] > 0 ? $fmt($due['paidamount']) : '<span class="text-muted">&ndash;</span>' ?></td>
                                        <td class="text-end fw-semibold"><?= $open ? $fmt($due['outstanding']) : '<span class="text-muted">&ndash;</span>' ?></td>
                                        <td class="text-nowrap">
                                            <span class="badge plan-badge-state state-<?= esc($due['state'], 'attr') ?>"><?= esc($stateLabels[$due['state']] ?? $due['state']) ?></span>
                                            <a class="btn btn-sm btn-link p-0 ms-1 plan-entry-open"
                                               data-entry="<?= esc($due['sessionid'], 'attr') ?>"
                                               data-label="<?= esc($due['name'] . ' · ' . date('M Y', strtotime($due['month'])), 'attr') ?>"
                                               data-amount="<?= esc($due['plannedamount'], 'attr') ?>"
                                               data-paid="<?= esc($due['paidamount'], 'attr') ?>"
                                               data-state="<?= esc($due['state'], 'attr') ?>"
                                               data-editable="<?= $due['editable'] ? '1' : '0' ?>"><?= $due['state'] === 'skipped' ? 'Restore' : 'Edit' ?></a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="lb-card" id="plan-month-envelopes">
                    <h2 class="lb-section-title">Envelopes</h2>
                    <?php if ($view['envelopes'] === []) { ?>
                        <p class="text-muted mb-0">No spending envelopes this month. An envelope caps spending tagged with a budget (for example Groceries).</p>
                    <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr><th>Envelope</th><th>Budget</th><th class="text-end">Planned</th><th class="text-end">Spent</th><th class="text-end">Remaining</th><th></th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($view['envelopes'] as $envelope) {
                                        $planned = (float) $envelope['plannedamount'];
                                        $percent = $planned > 0 ? min(100, round($envelope['spent'] / $planned * 100)) : 100;
                                    ?>
                                        <tr data-envelope="<?= esc($envelope['sessionid'], 'attr') ?>">
                                            <td><?= esc($envelope['name']) ?></td>
                                            <td><?= esc($envelope['budgetname'] ?? '-') ?></td>
                                            <td class="text-end"><?= $fmt($planned) ?></td>
                                            <td class="text-end"><?= $fmt($envelope['spent']) ?></td>
                                            <td class="text-end <?= $envelope['remaining'] < 0 ? 'plan-negative' : '' ?>"><?= $fmt($envelope['remaining']) ?></td>
                                            <td><div class="plan-envelope-bar<?= $envelope['remaining'] < 0 ? ' is-over' : '' ?>"><span style="width: <?= (int) $percent ?>%"></span></div></td>
                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-secondary plan-entry-open"
                                                   data-entry="<?= esc($envelope['sessionid'], 'attr') ?>"
                                                   data-label="<?= esc($envelope['name'] . ' · ' . date('M Y', strtotime($envelope['month'])), 'attr') ?>"
                                                   data-amount="<?= esc($envelope['plannedamount'], 'attr') ?>"
                                                   data-paid="0"
                                                   data-state="<?= esc($envelope['state'], 'attr') ?>"
                                                   data-editable="<?= $envelope['editable'] ? '1' : '0' ?>"><?= $envelope['state'] === 'skipped' ? 'Restore' : 'Edit' ?></a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mt-2 mb-0">Spent = expenses tagged with the envelope's budget this month (transfers and due settlements are not counted).</p>
                    <?php } ?>
                </div>
            </div>

            <div class="col-xl-3 col-lg-4 col-12">
                <div class="card transaction-type-list" id="plan-settle">
                    <div class="card-header font-weight-600" id="plan-settle-title">Settle selected</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12 mb-3">
                                <div class="form-floating">
                                    <select class="form-select current-account-list" id="current-account-list">
                                        <?php foreach ($accounts as $account) { ?>
                                            <option value="<?= esc($account['AccountSessionID'], 'attr') ?>"><?= esc($account['AccountName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="current-account-list" id="plan-settle-account-label">Pay from account</label>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo, 'label' => 'Payment method', 'showReference' => false]) ?>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <select class="form-select" id="plan-settle-budget">
                                        <?php foreach ($budgetInfo as $budget) { ?>
                                            <option value="<?= esc($budget['BudgetSessionID'], 'attr') ?>" <?= strcasecmp($budget['BudgetName'], 'Default') === 0 ? 'selected' : '' ?>><?= esc($budget['BudgetName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="plan-settle-budget">Budget</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 mb-2">
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="plan-mode-full" name="plan-mode" value="full" checked>
                                <label class="form-check-label" for="plan-mode-full">Full</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="plan-mode-partial" name="plan-mode" value="partial">
                                <label class="form-check-label" for="plan-mode-partial">Part</label>
                            </div>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="plan-settle-amount" placeholder="Amount" inputmode="decimal" readonly value="0.00">
                            <label for="plan-settle-amount">Amount (oldest due first)</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="plan-settle-reference" placeholder="Reference" maxlength="50">
                            <label for="plan-settle-reference">Reference (optional)</label>
                        </div>
                        <p class="text-muted small" id="plan-settle-hint">Select dues on the left. Their account and method are filled in for you.</p>
                        <p class="error-message" id="plan-settle-error">&nbsp;</p>
                        <a id="plan-settle-btn" class="btn btn-primary w-100">Settle Selected</a>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    <?= view('Plan/entry_modal') ?>
<?= $this->endSection() ?>
