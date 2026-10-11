<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?> 
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/report/page.js') ?>"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
    
    <div class="container" id="report-container" data-transaction-module-type="<?= $transactonType ?>">
      <div class="lb-card">
        <div class="row justify-content-center">
            <?php if($accountSearchActive){ ?>
                <div class="col-3">
                    <div class="form-floating mb-3">
                        <select class="form-select" name="account" id="account">
                            <option value="0">All Accounts</option>
                        <?php foreach($accountList as $key => $account){ ?>
                            <option value="<?= $account['AccountSessionID'] ?>"><?= $account['AccountName'] ?></option>
                        <?php } ?>
                        </select>
                        <label for="account">Account Type</label>
                    </div>
                </div>
            <?php } ?>
            <?php if($budgetSearchActive){ ?>
                <div class="col-3">
                    <div class="form-floating mb-3">
                        <select class="form-select" name="budget" id="budget">
                            <option value="0">All Budgets</option>
                        <?php foreach($budgetList as $key => $budget){ ?>
                            <option value="<?= $budget['BudgetSessionID'] ?>"><?= $budget['BudgetName'] ?></option>
                        <?php } ?>
                        </select>
                        <label for="budget">Budget</label>
                    </div>
                </div>
            <?php } ?>
            <div class="col-3">
                <div class="form-floating mb-3">
                    <input type="date" class="form-control" name="date-from" id="date-from" placeholder="Date From">
                    <label for="date-from">Date From</label>
                </div>
            </div>
            <div class="col-3">
                <div class="form-floating mb-3">
                    <input type="date" class="form-control" name="date-to" id="date-to" placeholder="Date To">
                    <label for="date-to">Date To</label>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-center mb-3">
            <a id="report-generate" class="btn btn-primary">Generate Report</a>
            <button id="report-export" class="btn btn-secondary ms-2">Export Report</button>
        </div>
      </div>
        <div class="col-12 mb-5" id="report-container-summary">
        </div>
        <div class="col-12" id="report-container-data">
        </div>
    </div>
<?= $this->endSection() ?>