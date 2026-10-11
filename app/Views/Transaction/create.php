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
        <div class="row">
            <div class="col-12">
              <div class="lb-card">
                <?= $transaction_proccess_container ?>
              </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>