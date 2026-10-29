<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="lb-card">
                    <h2 class="lb-section-title">All transactions</h2>
                    <?= $transaction_details_container ?>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>
