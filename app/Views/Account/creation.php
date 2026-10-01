<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?> 
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/create.js') ?>"></script>
<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/common_modules/pagination.js') ?>"></script>

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
                <div class="col-md-6 col-12">
                    <?= $account_list_content ?>
                </div>
                <div class="col-md-6 col-12">
                    <div class="mb-3">
                        <div class="form">
                            <div class="mb-3 d-flex row">
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select account-group-list" name="account-group-list" id="account-group-list" data-default-value="<?= $accountGroupDetails[0]['AccountGroupSessionID'] ?>">
                                            <?php foreach($accountGroupDetails as $key => $accountGroup){ ?>
                                                <option class="<?= $accountGroup['AccountGroupSessionID']?>" value="<?= $accountGroup['AccountGroupSessionID']?>"><?= $accountGroup['AccountGroupName'] ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="account-group-list" class="form-label">Account Group:</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the transaction information" name="account-name" id="account-name">
                                        <label for="account-name">Enter Account Name</label>
                                        
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the current amount" name="amount" id="amount">
                                        <label for="amount">Current Amount</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3 d-flex row" id="default-method-section">
                                <div class="col-12"><h6 class="mb-2">Default payment method</h6></div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select" id="method-type">
                                            <?php foreach ($methodTypes as $code => $label) { ?>
                                                <option value="<?= esc($code, 'attr') ?>" <?= $code === 'BANK_TRANSFER' ? 'selected' : '' ?>><?= esc($label) ?></option>
                                            <?php } ?>
                                        </select>
                                        <label for="method-type">Type</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Name" id="method-name" maxlength="50">
                                        <label for="method-name">Name (e.g. Sampath Visa)</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Last 4 digits" id="method-reference" maxlength="4" inputmode="numeric">
                                        <label for="method-reference">Card last 4 digits (optional)</label>
                                    </div>
                                </div>
                                <div class="col-6 mb-3 d-none" id="credit-limit-input">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Credit limit" id="credit-limit" value="0.00">
                                        <label for="credit-limit">Credit limit</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <p class="error-message">&nbsp;</p>
                            </div>
                        </div>
                        <div class="mb-3 justify-content-center ">
                            <a id="create-account-btn" class="btn btn-primary">Create Account</a>
                            <a id="update-account-btn" class="btn btn-primary d-none">Update Account</a>
                            <a id="cancel-account-btn" class="btn btn-secondary">Cancelled Account</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>