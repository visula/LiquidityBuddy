<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?> 
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/create_account_group.js') ?>"></script>
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
                <div class="col-md-5 col-12">
                    <div class="lb-card">
                        <h2 class="lb-section-title">Account groups</h2>
                        <?= $account_group_details_container ?>
                    </div>
                </div>
                <div class="col-md-7 col-12">
                    <div class="lb-card">
                        <div class="form">
                            <div class="mb-3 d-flex row">
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the Account Group information" name="account-group-name" id="account-group-name">
                                        <label for="account-group-name">Enter Account Group</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="account-group-type">
                                            <option value="OTHER">Other</option>
                                            <option value="BANK">Bank (one debit card for all its accounts)</option>
                                        </select>
                                        <label for="account-group-type">Group type</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <p class="error-message">&nbsp;</p>
                            </div>
                        </div>
                        <div class="mb-3 justify-content-center ">
                            <a id="create-account-group-btn" class="btn btn-primary">Create Account Group</a>
                            <a id="update-account-group-btn" data-session-id="" class="btn btn-primary d-none">Update Account Group</a>
                            <a id="cancel-account-group-btn" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>