<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script>
        $(document).ready(function () {
            $('#history-account').on('change', function () {
                var account = $(this).val();
                window.location.href = LB.url('payment/history' + (account ? '?account=' + encodeURIComponent(account) : ''));
            });
            $(document).on('click', '.payment-row', function () {
                $('#detail-' + $(this).data('payment')).toggleClass('d-none');
            });
        });
    </script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
    <style>
        .payment-row { cursor: pointer; }
        .payment-detail td { background: #f8f9fa; }
    </style>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $directionLabel = ['OUT' => 'Paid', 'IN' => 'Received', 'TRANSFER' => 'Transfer'];
    $settlementBadge = ['FULL' => 'bg-success', 'PARTIAL' => 'bg-warning text-dark', 'INSTALLMENT' => 'bg-info text-dark'];
    $outstandingOut = 0; $outstandingIn = 0;
    foreach ($outstanding as $due) {
        if ($due['Direction'] === 'IN') { $outstandingIn += $due['PayableOutstanding']; } else { $outstandingOut += $due['PayableOutstanding']; }
    }
?>
    <div class="container">
        <div class="row mb-3">
            <div class="col-md-4 col-12 mb-2">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Outstanding payables</div>
                    <div class="head-type-4 text-danger"><?= number_format($outstandingOut, 2) ?></div>
                </div></div>
            </div>
            <div class="col-md-4 col-12 mb-2">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Outstanding receivables</div>
                    <div class="head-type-4 text-success"><?= number_format($outstandingIn, 2) ?></div>
                </div></div>
            </div>
            <div class="col-md-4 col-12 mb-2">
                <div class="form-floating">
                    <select class="form-select" id="history-account">
                        <option value="">All accounts</option>
                        <?php foreach ($accounts as $account) { ?>
                            <option value="<?= esc($account['AccountSessionID'], 'attr') ?>" <?= $selectedAccount === $account['AccountSessionID'] ? 'selected' : '' ?>><?= esc($account['AccountName']) ?></option>
                        <?php } ?>
                    </select>
                    <label for="history-account">Account</label>
                </div>
            </div>
        </div>

        <h5>By account and payment method</h5>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr><th>Account</th><th>Payment method</th><th class="text-end">Paid out</th><th class="text-end">Received</th><th class="text-end">Payments</th></tr>
                </thead>
                <tbody>
                    <?php if ($summary === []) { ?>
                        <tr><td colspan="5" class="text-center text-muted">No payments recorded yet</td></tr>
                    <?php } ?>
                    <?php foreach ($summary as $row) { ?>
                        <tr>
                            <td><?= esc($row['AccountName']) ?></td>
                            <td><?= esc($row['PaymentMethodName']) ?></td>
                            <td class="text-end"><?= number_format($row['TotalOut'], 2) ?></td>
                            <td class="text-end"><?= number_format($row['TotalIn'], 2) ?></td>
                            <td class="text-end"><?= (int) $row['PaymentCount'] ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <h5>Payments <small class="text-muted">(click a row for its transactions and settled dues)</small></h5>
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Account</th>
                        <th>Method</th>
                        <th>Settlement</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($payments === []) { ?>
                        <tr><td colspan="7" class="text-center text-muted">No payments for this selection</td></tr>
                    <?php } ?>
                    <?php foreach ($payments as $payment) { ?>
                        <tr class="payment-row" data-payment="<?= esc($payment['PaymentSessionID'], 'attr') ?>">
                            <td class="text-nowrap"><?= esc($payment['PaymentDate']) ?></td>
                            <td>
                                <?= esc($payment['PaymentDescription']) ?>
                                <?php if ($payment['PaymentReference']) { ?><br><small class="text-muted">Ref: <?= esc($payment['PaymentReference']) ?></small><?php } ?>
                            </td>
                            <td><?= esc($directionLabel[$payment['PaymentDirection']] ?? $payment['PaymentDirection']) ?> <small class="text-muted"><?= esc($payment['PaymentCategory']) ?></small></td>
                            <td><?= esc($payment['AccountName']) ?><?php if ($payment['ToAccountName']) { ?> &rarr; <?= esc($payment['ToAccountName']) ?><?php } ?></td>
                            <td><?= esc($payment['PaymentMethodLabel']) ?></td>
                            <td><span class="badge <?= $settlementBadge[$payment['PaymentSettlementType']] ?? 'bg-secondary' ?>"><?= esc(ucfirst(strtolower($payment['PaymentSettlementType']))) ?></span></td>
                            <td class="text-end text-nowrap <?= $payment['PaymentDirection'] === 'IN' ? 'text-success' : ($payment['PaymentDirection'] === 'OUT' ? 'text-danger' : '') ?>">
                                <?= number_format($payment['PaymentAmount'], 2) ?>
                            </td>
                        </tr>
                        <tr class="payment-detail d-none" id="detail-<?= esc($payment['PaymentSessionID'], 'attr') ?>">
                            <td colspan="7">
                                <div class="row">
                                    <div class="col-md-7 col-12">
                                        <strong>Transactions</strong>
                                        <table class="table table-sm mb-2">
                                            <?php foreach ($payment['Transactions'] as $transaction) { ?>
                                                <tr>
                                                    <td><?= esc($transaction['accountname']) ?></td>
                                                    <td><?= esc($transaction['description']) ?></td>
                                                    <td class="text-end <?= $transaction['paymenttype'] === 'income' ? 'text-success' : 'text-danger' ?>"><?= $transaction['paymenttype'] === 'income' ? '+' : '-' ?><?= number_format($transaction['amount'], 2) ?></td>
                                                    <td class="text-end text-muted">bal <?= $transaction['balanceafter'] !== null ? number_format($transaction['balanceafter'], 2) : '-' ?></td>
                                                </tr>
                                            <?php } ?>
                                        </table>
                                    </div>
                                    <div class="col-md-5 col-12">
                                        <?php if ($payment['Allocations'] !== []) { ?>
                                            <strong>Dues settled</strong>
                                            <table class="table table-sm mb-2">
                                                <?php foreach ($payment['Allocations'] as $allocation) { ?>
                                                    <tr>
                                                        <td><?= esc($allocation['duedate']) ?></td>
                                                        <td class="text-end"><?= number_format($allocation['settledportion'], 2) ?> of <?= number_format($allocation['dueamount'], 2) ?></td>
                                                        <td><?= $allocation['status'] === 'S' ? '<span class="badge bg-success">Settled</span>' : '<span class="badge bg-warning text-dark">Part paid</span>' ?></td>
                                                    </tr>
                                                <?php } ?>
                                            </table>
                                        <?php } ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
<?= $this->endSection() ?>
