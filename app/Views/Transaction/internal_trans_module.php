<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/internal.js') ?>"></script>
<?php
    $accounts = $accountInfo['accounts'];
    $accountMeta = $accountMeta ?? [];
    $metaOf = static function ($id) use ($accountMeta) {
        return $accountMeta[$id] ?? ['type' => 'OTHER', 'typeLabel' => 'Other', 'bank' => '', 'product' => ''];
    };
    // Sensible first pair: cash -> first bank account (a deposit), else the first two accounts
    $fromIndex = 0;
    $toIndex = 1;
    foreach ($accounts as $key => $account) {
        if ($metaOf($account['AccountSessionID'])['type'] === 'CASH') { $fromIndex = $key; break; }
    }
    foreach ($accounts as $key => $account) {
        if ($key !== $fromIndex && in_array($metaOf($account['AccountSessionID'])['type'], \App\Libraries\BankRateSync::BANK_ACCOUNT_TYPES, true)) { $toIndex = $key; break; }
    }
    if ($toIndex === $fromIndex) { $toIndex = ($fromIndex === 0) ? 1 : 0; }
    $optionLabel = static function ($account, $meta) {
        return $account['AccountName'] . ' · ' . $meta['typeLabel'] . ($meta['bank'] !== '' && stripos($account['AccountName'], $meta['bank']) === false ? ' (' . $meta['bank'] . ')' : '');
    };
?>
<div class="mb-3 transaction-type-list d-none" id="internal">
    <div class="d-flex align-items-center gap-2 mb-3">
        <span class="badge lb-kind-badge" id="internal-kind">Transfer</span>
        <small class="text-muted" id="internal-kind-hint">Money moving between two of your accounts.</small>
    </div>
    <div class="form">
        <div class="mb-3 d-flex row g-2">
            <div class="col-md-6 col-12">
                <div class="form-floating">
                    <select class="form-select current-account-list" name="current-account-list" id="current-account-list">
                        <?php foreach($accounts as $key => $account){ $meta = $metaOf($account['AccountSessionID']); ?>
                            <option value="<?= esc($account['AccountSessionID'], 'attr') ?>" data-running-balance="<?= number_format($account['AccountCurrentBalance'],2)?>" data-type="<?= esc($meta['type'], 'attr') ?>" <?= $key === $fromIndex ? 'selected' : '' ?>><?= esc($optionLabel($account, $meta)) ?></option>
                        <?php } ?>
                    </select>
                    <label for="current-account-list" class="form-label">From account</label>
                </div>
                <input type="text" class="form-control form-control-sm mt-1" name="current-running-balance" id="current-running-balance" value="<?= number_format($accounts[$fromIndex]['AccountCurrentBalance'],2)?>" readonly aria-label="From account balance">
            </div>
            <div class="col-md-6 col-12">
                <div class="form-floating">
                    <select class="form-select to-account-list" name="to-account-list" id="to-account-list">
                        <?php foreach($accounts as $key => $account){ $meta = $metaOf($account['AccountSessionID']); ?>
                            <option class="<?= $key === $fromIndex ? 'd-none' : '' ?>" value="<?= esc($account['AccountSessionID'], 'attr') ?>" data-running-balance="<?= number_format($account['AccountCurrentBalance'],2)?>" data-type="<?= esc($meta['type'], 'attr') ?>" <?= $key === $toIndex ? 'selected' : '' ?>><?= esc($optionLabel($account, $meta)) ?></option>
                        <?php } ?>
                    </select>
                    <label for="to-account-list" class="form-label">To account</label>
                </div>
                <input type="text" class="form-control form-control-sm mt-1" name="to-running-balance" id="to-running-balance" value="<?= number_format($accounts[$toIndex]['AccountCurrentBalance'],2)?>" readonly aria-label="To account balance">
            </div>

            <div class="col-md-6 col-12">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Amount" name="amount" id="amount" inputmode="decimal" autocomplete="off">
                    <label for="amount">Amount</label>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="form-floating">
                    <input type="date" class="form-control" id="internal-date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    <label for="internal-date">Date</label>
                </div>
            </div>

            <div class="col-md-6 col-12" id="internal-channel-col">
                <div class="form-floating">
                    <select class="form-select" id="internal-channel"></select>
                    <label for="internal-channel" id="internal-channel-label">How</label>
                </div>
            </div>
            <div class="col-md-6 col-12" id="internal-fee-col">
                <div class="form-floating">
                    <input type="text" class="form-control" id="internal-fee" placeholder="Bank charge" inputmode="decimal" autocomplete="off" value="0.00">
                    <label for="internal-fee">Bank charge</label>
                </div>
                <small class="text-muted d-block" id="internal-fee-rule">&nbsp;</small>
            </div>
            <div class="col-12">
                <div class="alert alert-warning small py-2 mb-0 d-none" id="internal-warnings"></div>
            </div>

            <div class="col-md-6 col-12">
                <div class="form-floating">
                    <select class="form-select budget-list" name="budget-list" id="budget-list">
                        <?php foreach($budgetInfo as $key => $budget){ ?>
                            <option value="<?= esc($budget['BudgetSessionID'], 'attr') ?>"><?= esc($budget['BudgetName']) ?></option>
                        <?php } ?>
                    </select>
                    <label for="budget-list" class="form-label">Budget (for the bank charge)</label>
                </div>
            </div>
            <div class="col-md-6 col-12">
                <div class="form-floating">
                    <input type="text" class="form-control" placeholder="Description" name="description" id="description" maxlength="100">
                    <label for="description">Description (optional)</label>
                </div>
            </div>
            <?= view('Transaction/payment_method_select', ['paymentMethodInfo' => $paymentMethodInfo ?? [], 'label' => 'From account method', 'showReference' => true]) ?>
        </div>
        <div class="lb-internal-summary small" id="internal-summary">&nbsp;</div>
    </div>
    <div class="mb-3 justify-content-center">
        <button type="button" id="transferred-btn" class="btn btn-primary">Save</button>
    </div>
</div>
