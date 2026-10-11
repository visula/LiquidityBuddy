<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= esc($StoredText['ScreenTitle']) ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= esc($StoredText['Header']) ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script>
        $(document).ready(function () {
            $('#refresh-rates-btn').on('click', function () {
                LB.post('js-request/bank-rates/sync', {}, function () { window.location.reload(); }, '#rates-error', $(this));
            });
        });
    </script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $pct = static function ($value) { return rtrim(rtrim(number_format((float) $value, 2), '0'), '.'); };

    // Best rate per product type, to highlight it
    $bestByType = [];
    foreach ($products as $product) {
        $type = $product['producttype'];
        if (! isset($bestByType[$type]) || (float) $product['interestrate'] > $bestByType[$type]) {
            $bestByType[$type] = (float) $product['interestrate'];
        }
    }

    $byBank = [];
    foreach ($products as $product) {
        $byBank[$product['bankname']][] = $product;
    }
    $usesSample = (bool) array_filter($providers, static fn ($p) => $p['sample']);
?>
    <div class="container">
        <?php if ($usesSample) { ?>
            <div class="alert alert-warning small">
                These rates come from the bundled <strong>sample feed</strong> and are illustrative, not live bank rates.
                Add a real provider in <code>app/Config/BankRates.php</code> to pull current rates.
            </div>
        <?php } ?>

        <div class="lb-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h2 class="lb-section-title mb-0">Rate providers</h2>
                <button type="button" class="btn btn-sm btn-outline-primary" id="refresh-rates-btn">Refresh rates</button>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Provider</th><th>Source</th><th>Last sync</th><th class="text-end">Products</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($providers as $provider) { ?>
                            <tr>
                                <td><?= esc($provider['name']) ?></td>
                                <td class="text-muted"><?= esc((string) $provider['source']) ?></td>
                                <td class="text-nowrap"><?= $provider['lastSync'] ? esc(date('d M Y H:i', $provider['lastSync'])) : '<span class="text-muted">never</span>' ?></td>
                                <td class="text-end"><?= (int) $provider['count'] ?></td>
                                <td>
                                    <?php if ($provider['status'] === 'S') { ?><span class="badge bg-success">OK</span>
                                    <?php } elseif ($provider['status'] === 'F') { ?><span class="badge bg-danger" title="<?= esc((string) $provider['message'], 'attr') ?>">Failed</span>
                                        <div class="small text-danger"><?= esc((string) $provider['message']) ?></div>
                                    <?php } else { ?><span class="badge bg-secondary">Not synced</span><?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <p class="error-message mb-0 mt-2" id="rates-error">&nbsp;</p>
        </div>

        <div class="lb-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="lb-section-title mb-0">Accounts and interest rates</h2>
                <div class="btn-group btn-group-sm" role="group" aria-label="Account type">
                    <a class="btn btn-outline-secondary<?= $selectedType === '' ? ' active' : '' ?>" href="<?= base_url('bank-rates') ?>">All</a>
                    <?php foreach ($types as $code => $label) { if ($code === 'OTHER') { continue; } ?>
                        <a class="btn btn-outline-secondary<?= $selectedType === $code ? ' active' : '' ?>" href="<?= base_url('bank-rates?type=' . $code) ?>"><?= esc($label) ?></a>
                    <?php } ?>
                </div>
            </div>

            <?php if ($products === []) { ?>
                <p class="text-muted mb-0">No products yet. Click <strong>Refresh rates</strong> to pull them from the providers.</p>
            <?php } ?>

            <?php foreach ($byBank as $bank => $bankProducts) { ?>
                <h3 class="lb-bank-name"><?= esc($bank) ?></h3>
                <div class="table-responsive mb-3">
                    <table class="table mb-0 lb-rate-table">
                        <colgroup><col style="width:30%"><col style="width:15%"><col style="width:17%"><col style="width:16%"><col style="width:12%"><col style="width:10%"></colgroup>
                        <thead>
                            <tr><th>Product</th><th>Type</th><th class="text-end">Rate p.a.</th><th class="text-end">Min. balance</th><th>Effective</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bankProducts as $product) {
                                $rate = (float) $product['interestrate'];
                                $isBest = $rate > 0 && $rate === ($bestByType[$product['producttype']] ?? null);
                                $linked = (int) ($linkedProducts[$product['sessionid']] ?? 0);
                            ?>
                                <tr>
                                    <td>
                                        <?= esc($product['productname']) ?>
                                        <?php $withdrawals = \App\Libraries\BankRateSync::feeSummary($productFees[$product['sessionid']] ?? []); ?>
                                        <?php if ($withdrawals !== '') { ?><div class="small text-muted">Withdrawals: <?= esc($withdrawals) ?></div><?php } ?>
                                    </td>
                                    <td><span class="badge lb-badge-type"><?= esc($types[$product['producttype']] ?? $product['producttype']) ?></span></td>
                                    <td class="text-end text-nowrap">
                                        <strong><?= $pct($rate) ?>%</strong>
                                        <?php if ($product['previousrate'] !== null && (float) $product['previousrate'] !== $rate) {
                                            $up = $rate > (float) $product['previousrate']; ?>
                                            <small class="<?= $up ? 'text-success' : 'text-danger' ?>"><?= $up ? '&uarr;' : '&darr;' ?> from <?= $pct($product['previousrate']) ?>%</small>
                                        <?php } ?>
                                        <?php if ($isBest) { ?><span class="badge bg-success ms-1">Best</span><?php } ?>
                                    </td>
                                    <td class="text-end"><?= $product['minbalance'] !== null ? number_format((float) $product['minbalance'], 2) : '-' ?> <small class="text-muted"><?= esc($product['currency']) ?></small></td>
                                    <td class="text-nowrap text-muted"><?= $product['effectivedate'] ? esc(date('d M Y', strtotime($product['effectivedate']))) : '-' ?></td>
                                    <td class="text-end text-nowrap"><?php if ($linked) { ?><span class="badge bg-secondary"><?= $linked ?> of your accounts</span><?php } ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
            <p class="small text-muted mb-0">Link an account to a product from its account page (Account List &rarr; the account) to see its rate there, post interest and get better-rate hints.</p>
        </div>
    </div>
<?= $this->endSection() ?>
