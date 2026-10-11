<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/payment/recurring.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $statusBadge = ['A' => ['Active', 'bg-success'], 'P' => ['Paused', 'bg-secondary'], 'E' => ['Ended', 'bg-light text-dark']];
?>
    <div class="container">
        <?php if ($justPosted > 0) { ?>
            <div class="alert alert-info"><?= (int) $justPosted ?> due recurring transaction<?= $justPosted > 1 ? 's were' : ' was' ?> posted just now.</div>
        <?php } ?>
        <div class="row">
            <div class="col-md-7 col-12">
              <div class="lb-card">
                <p class="text-muted">Salary, rent, subscriptions and other amounts that repeat. Each due date is posted as a normal payment from its account (shown in Payment History), when you next open the app.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Account / method</th>
                                <th>Repeats</th>
                                <th class="text-end">Amount</th>
                                <th>Next due</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recurringRules === []) { ?>
                                <tr><td colspan="7" class="text-center text-muted">No recurring transactions yet</td></tr>
                            <?php } ?>
                            <?php foreach ($recurringRules as $rule) {
                                [$statusLabel, $statusClass] = $statusBadge[$rule['status']] ?? [$rule['status'], 'bg-secondary'];
                            ?>
                                <tr>
                                    <td>
                                        <?= esc($rule['description']) ?>
                                        <?php if ($rule['budgetname']) { ?><br><small class="text-muted">Budget: <?= esc($rule['budgetname']) ?></small><?php } ?>
                                        <?php if ($rule['lasterror'] && $rule['status'] === 'A') { ?><br><small class="text-danger">Not posted: <?= esc($rule['lasterror']) ?></small><?php } ?>
                                    </td>
                                    <td><?= esc($rule['accountname']) ?><br><small class="text-muted"><?= esc($rule['methodlabel']) ?></small></td>
                                    <td>
                                        <?= esc($rule['periodlabel']) ?>
                                        <br><small class="text-muted">from <?= esc($rule['startdate']) ?><?= $rule['enddate'] ? ' to ' . esc($rule['enddate']) : '' ?></small>
                                    </td>
                                    <td class="text-end text-nowrap <?= $rule['direction'] === 'IN' ? 'text-success' : 'text-danger' ?>">
                                        <?= $rule['direction'] === 'IN' ? '+' : '-' ?><?= number_format($rule['amount'], 2) ?>
                                    </td>
                                    <td class="text-nowrap"><?= $rule['status'] === 'E' ? '-' : esc($rule['nextduedate']) ?><br><small class="text-muted"><?= (int) $rule['occurrences'] ?> posted</small></td>
                                    <td><span class="badge <?= $statusClass ?>"><?= esc($statusLabel) ?></span></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($rule['status'] === 'A') { ?>
                                            <a class="btn btn-sm btn-outline-secondary recurring-action-btn" data-action="pause" data-id="<?= esc($rule['sessionid'], 'attr') ?>">Pause</a>
                                        <?php } elseif ($rule['status'] === 'P') { ?>
                                            <a class="btn btn-sm btn-outline-primary recurring-action-btn" data-action="resume" data-id="<?= esc($rule['sessionid'], 'attr') ?>">Resume</a>
                                        <?php } ?>
                                        <a class="btn btn-sm btn-outline-primary recurring-edit-btn"
                                           data-id="<?= esc($rule['sessionid'], 'attr') ?>"
                                           data-description="<?= esc($rule['description'], 'attr') ?>"
                                           data-amount="<?= esc($rule['amount'], 'attr') ?>"
                                           data-end-date="<?= esc((string) $rule['enddate'], 'attr') ?>">Edit</a>
                                        <a class="btn btn-sm btn-outline-danger recurring-action-btn" data-action="delete" data-id="<?= esc($rule['sessionid'], 'attr') ?>" data-name="<?= esc($rule['description'], 'attr') ?>">Remove</a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <p id="recurring-list-error" class="error-message">&nbsp;</p>
              </div>
            </div>
            <div class="col-md-5 col-12">
                <div class="card transaction-type-list" id="recurring-form">
                    <div class="card-header font-weight-600" id="recurring-form-title">Add recurring transaction</div>
                    <div class="card-body">
                        <input type="hidden" id="recurring-id" value="">
                        <div class="d-flex mb-3 recurring-new-only">
                            <div class="form-check mr-3 me-3">
                                <input type="radio" class="form-check-input" id="recurring-out" name="recurring-direction" value="OUT" checked>
                                <label class="form-check-label" for="recurring-out">I pay (bill, rent)</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" id="recurring-in" name="recurring-direction" value="IN">
                                <label class="form-check-label" for="recurring-in">I receive (salary)</label>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="recurring-description" placeholder="Description" maxlength="100">
                                    <label for="recurring-description">Description (e.g. Salary, House rent)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="recurring-amount" placeholder="Amount" inputmode="decimal">
                                    <label for="recurring-amount">Amount</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 recurring-new-only">
                                <div class="form-floating">
                                    <select class="form-select" id="recurring-periodic">
                                        <?php foreach ($recurringPeriods as $code => $label) { ?>
                                            <option value="<?= esc($code, 'attr') ?>" <?= $code === 'M' ? 'selected' : '' ?>><?= esc($label) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="recurring-periodic">Repeats</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 recurring-new-only">
                                <div class="form-floating">
                                    <input type="date" class="form-control" id="recurring-start-date" value="<?= date('Y-m-d') ?>">
                                    <label for="recurring-start-date">First date</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="form-floating">
                                    <input type="date" class="form-control" id="recurring-end-date">
                                    <label for="recurring-end-date">End date (optional)</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 recurring-new-only">
                                <div class="form-floating">
                                    <select class="form-select current-account-list" id="current-account-list">
                                        <?php foreach ($accountInfo['accounts'] as $account) { ?>
                                            <option value="<?= esc($account['AccountSessionID'], 'attr') ?>"><?= esc($account['AccountName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="current-account-list" id="recurring-account-label">Pay from account</label>
                                </div>
                            </div>
                            <div class="col-6 mb-3 recurring-new-only">
                                <div class="form-floating">
                                    <select class="form-select" id="recurring-budget">
                                        <?php foreach ($budgetInfo as $budget) { ?>
                                            <option value="<?= esc($budget['BudgetSessionID'], 'attr') ?>" <?= strcasecmp($budget['BudgetName'], 'Default') === 0 ? 'selected' : '' ?>><?= esc($budget['BudgetName']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <label for="recurring-budget">Budget</label>
                                </div>
                            </div>
                        </div>
                        <div class="row recurring-new-only">
                            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo, 'label' => 'Payment method', 'showReference' => false]) ?>
                        </div>
                        <p class="error-message" id="recurring-error">&nbsp;</p>
                        <a id="save-recurring-btn" class="btn btn-primary">Add Recurring</a>
                        <a id="cancel-recurring-btn" class="btn btn-secondary d-none">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
