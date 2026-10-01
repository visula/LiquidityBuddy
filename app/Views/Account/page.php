<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/create.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>

    <div class="container">
        <div class="row mb-3">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Payment methods</h5>
                    <span>
                        <a class="btn btn-sm btn-outline-primary" href="<?= base_url('payment-method/list') ?>">Manage</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('payment/history?account=' . urlencode($accountID ?? '')) ?>">Payment history</a>
                    </span>
                </div>
                <?php if (empty($accountPaymentMethods)) { ?>
                    <p class="text-muted">No payment methods on this account - payments use the account balance.</p>
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
        <div class="row">
            <div class="col-12">
              <?= $transaction_details_container ?>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
