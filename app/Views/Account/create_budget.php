<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?> 
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/account/create_budget.js') ?>"></script>
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
                <div class="col-md-7 col-12">
                    <div class="lb-card">
                        <h2 class="lb-section-title">Budgets</h2>
                        <?= $budget_details_container ?>
                    </div>
                </div>
                <div class="col-md-5 col-12">
                    <div class="lb-card">
                        <!-- <h3>New Budget Creation</h3> -->
                        <div class="form">
                            <div class="mb-3 d-flex row">
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the budget information" name="budget-name" id="budget-name">
                                        <label for="budget-name">Enter Budget Type</label>
                                        
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="form-floating">
                                        <select class="form-select budget-plan" name="budget-plan" id="budget-plan">
                                            <?php foreach($budgetInfo['payment_plan'] as $key => $periodic){ ?>
                                                <?php if($key != 'C' && $key != 'I'){ ?>
                                                    <option class="" value="<?= $key?>" <?php if($key == 'M'){echo 'selected';} ?>><?= $periodic ?></option>
                                                <?php } ?>
                                            <?php } ?>
                                        </select>
                                        <label for="from-budget-list" class="form-label">Budget Plan:</label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" placeholder="Enter the current budget-amount" name="budget-amount" id="budget-amount">
                                        <label for="budget-amount">Budget Amount</label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="form-floating">
                                        <a></a>
                                    </div>
                                </div>
                            </div>
                            <?php if (isset($unplannedIncome)) { ?>
                            <div class="row">
                                <small class="text-muted">Unplanned monthly income: <strong><?= number_format(max(0, $unplannedIncome['unplanned']), 2) ?></strong>
                                    (income since <?= esc($unplannedIncome['from']) ?> of <?= number_format($unplannedIncome['income'], 2) ?>, less <?= number_format($unplannedIncome['planned'], 2) ?> already budgeted).
                                    A new budget can't be larger than this.</small>
                            </div>
                            <?php } ?>
                            <div class="row">
                                <p class="error-message">&nbsp;</p>
                            </div>
                        </div>
                        <div class="mb-3 justify-content-center ">
                            <a id="create-budget-btn" class="btn btn-primary">Create Budget</a>
                            <a id="update-budget-btn" data-session-id="" class="btn btn-primary d-none">Update Budget</a>
                            <a id="cancel-budget-btn" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?= $this->endSection() ?>