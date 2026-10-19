<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/payment/methods.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
    <div class="container">
        <div class="row">
            <div class="col-md-7 col-12">
                <p class="text-muted">Each account holds its own payment methods. Payments made from an account use one of its methods; a credit card method lets that account go below zero up to its credit limit.</p>
                <?php foreach ($accounts as $account) {
                    $methods = $methodsByAccount[$account['AccountSessionID']] ?? [];
                    $balance = (float) $account['AccountCurrentBalance'];
                ?>
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="font-weight-600"><?= esc($account['AccountName']) ?></span>
                            <span class="<?= $balance < 0 ? 'text-danger' : '' ?>">Balance <?= number_format($balance, 2) ?></span>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th>Type</th>
                                        <th class="text-end">Credit limit</th>
                                        <th class="text-end">Available</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($methods === []) { ?>
                                        <tr><td colspan="5" class="text-muted text-center">No payment methods - payments use the account balance.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($methods as $method) { ?>
                                        <tr>
                                            <td>
                                                <?= esc($method['PaymentMethodLabel']) ?>
                                                <?php if ($method['PaymentMethodIsDefault'] === 'Y') { ?><span class="badge bg-secondary">Default</span><?php } ?>
                                            </td>
                                            <td><?= esc($method['PaymentMethodTypeLabel']) ?></td>
                                            <td class="text-end"><?= $method['PaymentMethodType'] === 'CREDIT_CARD' ? number_format($method['PaymentMethodCreditLimit'], 2) : '-' ?></td>
                                            <td class="text-end"><?= number_format($method['AvailableFunds'], 2) ?></td>
                                            <td class="text-end text-nowrap">
                                                <a class="btn btn-sm btn-outline-primary edit-method-btn"
                                                   data-id="<?= esc($method['PaymentMethodSessionID'], 'attr') ?>"
                                                   data-account="<?= esc($method['AccountSessionID'], 'attr') ?>"
                                                   data-name="<?= esc($method['PaymentMethodName'], 'attr') ?>"
                                                   data-type="<?= esc($method['PaymentMethodType'], 'attr') ?>"
                                                   data-reference="<?= esc((string) $method['PaymentMethodReference'], 'attr') ?>"
                                                   data-credit-limit="<?= esc($method['PaymentMethodCreditLimit'], 'attr') ?>"
                                                   data-show="<?= esc($method['PaymentMethodShowInDropdown'] ?? 'Y', 'attr') ?>"
                                                   data-default="<?= esc($method['PaymentMethodIsDefault'], 'attr') ?>">Edit</a>
                                                <a class="btn btn-sm btn-outline-danger delete-method-btn" data-id="<?= esc($method['PaymentMethodSessionID'], 'attr') ?>" data-name="<?= esc($method['PaymentMethodLabel'], 'attr') ?>">Remove</a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php } ?>
            </div>
            <div class="col-md-5 col-12">
                <div class="card">
                    <div class="card-header font-weight-600" id="method-form-title">Add payment method</div>
                    <div class="card-body">
                        <input type="hidden" id="method-id" value="">
                        <div class="form-floating mb-3">
                            <select class="form-select" id="method-account">
                                <?php foreach ($accounts as $account) { ?>
                                    <option value="<?= esc($account['AccountSessionID'], 'attr') ?>"><?= esc($account['AccountName']) ?></option>
                                <?php } ?>
                            </select>
                            <label for="method-account">Account</label>
                        </div>
                        <div class="form-floating mb-3">
                            <select class="form-select" id="method-type">
                                <?php foreach ($methodTypes as $code => $label) { ?>
                                    <option value="<?= esc($code, 'attr') ?>"><?= esc($label) ?></option>
                                <?php } ?>
                            </select>
                            <label for="method-type">Type</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="method-name" placeholder="Name" maxlength="50">
                            <label for="method-name">Name (e.g. Sampath Visa, Petty cash)</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="method-reference" placeholder="Last 4 digits" maxlength="4" inputmode="numeric">
                            <label for="method-reference">Card last 4 digits (optional)</label>
                        </div>
                        <div class="form-floating mb-3 d-none" id="method-credit-limit-group">
                            <input type="text" class="form-control" id="method-credit-limit" placeholder="Credit limit" value="0.00">
                            <label for="method-credit-limit">Credit limit</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="method-show" checked>
                            <label class="form-check-label" for="method-show">Show in payment and plan dropdowns (turn off if this account has no card)</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="method-default">
                            <label class="form-check-label" for="method-default">Default method for this account</label>
                        </div>
                        <p class="error-message" id="method-error">&nbsp;</p>
                        <a id="save-method-btn" class="btn btn-primary">Add Method</a>
                        <a id="cancel-method-btn" class="btn btn-secondary d-none">Cancel</a>
                    </div>
                </div>
                <p class="text-muted mt-3"><small>Only the last 4 digits of a card are kept. Full card numbers are never stored.</small></p>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
