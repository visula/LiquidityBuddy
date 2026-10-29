<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/payment/cashflow.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
    <style>
        .cashflow-chart { position: relative; min-height: 320px; }
    </style>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $totals = $cashflow['totals'];
    $handling = $cashflow['handling'];
    $levelBadge = ['danger' => 'bg-danger', 'warning' => 'bg-warning text-dark', 'success' => 'bg-success', 'secondary' => 'bg-secondary'];
    $dueGroups = ['overdue' => 'Overdue', 'this_month' => 'Due this month', 'next_month' => 'Due next month'];
    $duesRate = $totals['expected'] > 0 ? round($totals['dues'] / $totals['expected'] * 100, 1) : null;
?>
    <div class="container">
        <div class="row mb-3">
            <div class="col-md-3 col-6 mb-2">
                <div class="card"><div class="card-body">
                    <div class="text-muted small">Money in</div>
                    <div class="head-type-4 text-success">Rs. <?= number_format($totals['inflow'], 2) ?></div>
                </div></div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card"><div class="card-body">
                    <div class="text-muted small">Money out</div>
                    <div class="head-type-4 text-danger">Rs. <?= number_format($totals['outflow'], 2) ?></div>
                </div></div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card"><div class="card-body">
                    <div class="text-muted small">Planned dues</div>
                    <div class="head-type-4">Rs. <?= number_format($totals['dues'], 2) ?></div>
                </div></div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card"><div class="card-body">
                    <div class="text-muted small" title="Planned dues as a share of expected income">Dues rate</div>
                    <div class="head-type-4 <?= $duesRate !== null && $duesRate > 100 ? 'text-danger' : '' ?>"><?= $duesRate === null ? '–' : number_format($duesRate, 1) . '%' ?></div>
                </div></div>
            </div>
        </div>

        <div class="lb-card">
            <h2 class="lb-section-title">Money in and out <small class="text-muted fw-normal">(past and upcoming months)</small></h2>
            <div class="cashflow-chart"><canvas id="cashflow-chart"></canvas></div>
            <script type="application/json" id="cashflow-data"><?= json_encode($cashflow['chart'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        </div>

        <div class="lb-card">
            <h2 class="lb-section-title">Month by month</h2>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Money in</th>
                            <th class="text-end">Money out</th>
                            <th class="text-end">Planned dues</th>
                            <th class="text-end">Expected income</th>
                            <th class="text-end" title="Planned dues as a share of expected income">Dues rate</th>
                            <th class="text-end" title="Money out as a share of money in">Out / in</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashflow['months'] as $row) { ?>
                            <tr>
                                <td class="text-nowrap"><?= esc($row['label']) ?> <?php if ($row['future']) { ?><span class="badge bg-light text-dark border">Planned</span><?php } ?></td>
                                <td class="text-end"><?= number_format($row['inflow'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['outflow'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['dues'], 2) ?></td>
                                <td class="text-end"><?= number_format($row['expected'], 2) ?></td>
                                <td class="text-end <?= $row['dues_rate'] !== null && $row['dues_rate'] > 100 ? 'text-danger fw-bold' : '' ?>"><?= $row['dues_rate'] === null ? '–' : number_format($row['dues_rate'], 1) . '%' ?></td>
                                <td class="text-end <?= $row['outflow_rate'] !== null && $row['outflow_rate'] > 100 ? 'text-danger fw-bold' : '' ?>"><?= $row['outflow_rate'] === null ? '–' : number_format($row['outflow_rate'], 1) . '%' ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lb-card">
            <h2 class="lb-section-title">What to do about dues</h2>
            <p class="text-muted small mb-2">Balances are the accounts' current balances. Settle dues from the Dued tab on <a href="<?= base_url('transaction/add') ?>">New transaction</a>.</p>

            <?php foreach ($dueGroups as $groupKey => $title) { $rows = $handling[$groupKey]; ?>
                <h3 class="h6 mt-3">
                    <?= $title ?> <span class="badge bg-secondary"><?= count($rows) ?></span>
                </h3>
                <?php if ($rows === []) { ?>
                    <p class="text-muted small">Nothing here.</p>
                <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Due</th><th>Item</th><th class="text-end">Outstanding</th><th>Account (balance)</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $due) { ?>
                                    <tr>
                                        <td class="text-nowrap"><?= esc($due['DueDate']) ?></td>
                                        <td><?= esc($due['Description']) ?></td>
                                        <td class="text-end"><?= number_format($due['Outstanding'], 2) ?></td>
                                        <td><?= $due['AccountName'] === null ? '<span class="text-muted">Any account</span>' : esc($due['AccountName']) . ' <small class="text-muted">(' . number_format($due['Balance'], 2) . ')</small>' ?></td>
                                        <td><span class="badge <?= $levelBadge[$due['Level']] ?? 'bg-secondary' ?>"><?= esc($due['Action']) ?></span></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            <?php } ?>

            <p class="small mt-3 mb-0">
                <?= $handling['later']['count'] ?> later due(s) totalling Rs. <?= number_format($handling['later']['amount'], 2) ?>.
            </p>
        </div>
    </div>
<?= $this->endSection() ?>
