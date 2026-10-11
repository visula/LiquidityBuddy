<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?> 
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/list.js') ?>"></script>
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
                <div class="col-lg-5 col-12">
                    <div class="lb-card">
                        <h2 class="lb-section-title">Accounts</h2>
                        <?= $account_list_content ?>
                    </div>
                </div>
                <div class="col-lg-7 col-12">
                    <div class="lb-card">
                        <?= $transaction_proccess_container ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="lb-card">
                        <?= $transaction_details_container ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>