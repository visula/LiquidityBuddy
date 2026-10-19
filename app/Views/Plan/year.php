<?= $this->extend("layouts/default") ?>
<?= $this->section("title") ?> <?= $StoredText['ScreenTitle'] ?> <?= $this->endSection() ?>
<?= $this->section("header") ?> <?= $StoredText['Header'] ?> <?= $this->endSection() ?>
<?= $this->section("scripts") ?>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/plan/entry.js') ?>"></script>
<?= $this->endSection() ?>
<?= $this->section("styles") ?>
    <link rel="stylesheet" href="<?= base_url('assets/css/plan.css') ?>">
<?= $this->endSection() ?>
<?= $this->section("content") ?>
<?php
    $year = $grid['year'];
    $months = $grid['months'];
    $totals = $grid['totals'];
    $currentYm = date('Y-m');
    $fmt = static function ($value) { return number_format((float) $value, 2); };
    $signed = static function ($value) use ($fmt) {
        if ($value === null) { return '<span class="text-muted">&ndash;</span>'; }
        return '<span class="' . ($value < 0 ? 'plan-negative' : '') . '">' . $fmt($value) . '</span>';
    };
    $stateLabels = ['settled' => 'Settled', 'partial' => 'Part paid', 'overdue' => 'Overdue', 'upcoming' => 'Upcoming',
        'historical' => 'History (before tracking)', 'skipped' => 'Skipped', 'envelope' => 'Envelope'];
?>
    <div class="container-fluid">
        <div class="plan-toolbar">
            <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/plan/' . ($year - 1)) ?>" aria-label="Previous year"><i class="bi bi-chevron-left"></i></a>
            <span class="plan-toolbar-title"><?= (int) $year ?></span>
            <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('/plan/' . ($year + 1)) ?>" aria-label="Next year"><i class="bi bi-chevron-right"></i></a>
            <span class="plan-toolbar-spacer"></span>
            <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/plan/' . date('Y/n')) ?>"><i class="bi bi-calendar-check"></i> This month</a>
            <a class="btn btn-sm btn-primary" href="<?= base_url('/plan/items') ?>"><i class="bi bi-list-check"></i> Plan items</a>
        </div>

        <?php if (! $hasItems) { ?>
            <div class="lb-card">
                <h2 class="lb-section-title">No plan yet</h2>
                <p class="mb-2">Add your monthly income, bills, installments and transfers on the <a href="<?= base_url('/plan/items') ?>">Plan items</a> page.
                    Each month of an item becomes a due you settle from the month view or the Dued tab.</p>
                <p class="text-muted small mb-0">An existing spreadsheet plan can be imported with
                    <code>php spark plan:import DBBackup/Seeds/planned_expenses_<?= (int) $year ?>.json --user=&lt;email&gt; --from=<?= date('Y-m') ?></code></p>
            </div>
        <?php } else { ?>

        <div id="plan-year-region">
        <div class="lb-card">
            <div class="plan-legend">
                <?php foreach (['settled', 'partial', 'overdue', 'upcoming', 'envelope', 'historical', 'skipped'] as $state) { ?>
                    <span class="lg-<?= $state ?>"><?= esc($stateLabels[$state]) ?></span>
                <?php } ?>
                <span class="ms-auto">Click an amount to change or skip that month.</span>
            </div>
            <div class="plan-grid-wrap">
                <table class="table table-sm plan-grid" id="plan-grid">
                    <thead>
                        <tr>
                            <th class="plan-sticky">Item</th>
                            <?php foreach ($months as $i => $ym) { ?>
                                <th class="plan-num plan-month-head<?= $ym === $currentYm ? ' is-current' : '' ?>">
                                    <a href="<?= base_url('/plan/' . $year . '/' . ($i + 1)) ?>" title="Open <?= esc(date('F Y', strtotime($ym . '-01')), 'attr') ?>"><?= esc(date('M', strtotime($ym . '-01'))) ?></a>
                                </th>
                            <?php } ?>
                            <th class="plan-num">Year</th>
                            <th>Ends</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grid['groups'] as $group) { ?>
                            <tr class="plan-group-row"><td class="plan-sticky"><?= esc($group['label']) ?></td><td colspan="<?= count($months) + 2 ?>"></td></tr>
                            <?php foreach ($group['items'] as $row) {
                                $item = $row['item'];
                                $meta = [];
                                if ($item['direction'] === 'TRANSFER') { $meta[] = 'Transfer → ' . ($item['toaccountname'] ?? 'no account'); }
                                if ($item['kind'] === 'ENVELOPE') { $meta[] = 'Envelope: ' . ($item['budgetname'] ?? 'no budget'); }
                                if ($item['sourcename']) { $meta[] = rtrim(rtrim(number_format((float) $item['shareratio'], 4), '0'), '.') . ' × ' . $item['sourcename']; }
                                if ($item['status'] === 'E') { $meta[] = 'Ended'; }
                            ?>
                                <tr data-item="<?= esc($item['sessionid'], 'attr') ?>">
                                    <td class="plan-sticky">
                                        <span class="plan-item-name"><?= esc($item['name']) ?></span>
                                        <?php if ($meta !== []) { ?><span class="plan-item-meta"><?= esc(implode(' · ', $meta)) ?></span><?php } ?>
                                    </td>
                                    <?php foreach ($months as $ym) {
                                        $cell = $row['cells'][$ym] ?? null;
                                        $isCurrent = $ym === $currentYm ? ' is-current' : '';
                                        if ($cell === null) { ?>
                                            <td class="plan-cell<?= $isCurrent ?>"></td>
                                        <?php continue; }
                                        $title = $stateLabels[$cell['state']] . ' · due ' . $cell['duedate'];
                                        if ((float) $cell['paidamount'] > 0) { $title .= ' · paid ' . $fmt($cell['paidamount']); }
                                        if ($cell['kind'] === 'ENVELOPE') { $title .= ' · spent ' . $fmt($cell['spent'] ?? 0); }
                                    ?>
                                        <td class="plan-cell plan-cell--<?= esc($cell['state'], 'attr') ?><?= $isCurrent ?> plan-entry-open<?= $cell['editable'] ? ' is-editable' : '' ?>"
                                            title="<?= esc($title, 'attr') ?>"
                                            data-entry="<?= esc($cell['sessionid'], 'attr') ?>"
                                            data-label="<?= esc($item['name'] . ' · ' . date('M Y', strtotime($ym . '-01')), 'attr') ?>"
                                            data-amount="<?= esc($cell['plannedamount'], 'attr') ?>"
                                            data-paid="<?= esc($cell['paidamount'], 'attr') ?>"
                                            data-state="<?= esc($cell['state'], 'attr') ?>"
                                            data-editable="<?= $cell['editable'] ? '1' : '0' ?>">
                                            <?= $fmt($cell['plannedamount']) ?>
                                            <?php if ($cell['kind'] === 'ENVELOPE' && $cell['status'] === 'A') { ?>
                                                <span class="plan-cell-sub">spent <?= $fmt($cell['spent'] ?? 0) ?></span>
                                            <?php } elseif ($cell['state'] === 'partial' || ($cell['state'] === 'overdue' && (float) $cell['paidamount'] > 0)) { ?>
                                                <span class="plan-cell-sub">paid <?= $fmt($cell['paidamount']) ?></span>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                    <td class="plan-num fw-semibold"><?= $fmt($row['year_total']) ?></td>
                                    <td class="text-muted"><?= $item['enddate'] ? esc(date('d M Y', strtotime($item['enddate']))) : '' ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                    <tfoot>
                        <?php
                            $yearSum = static function ($key) use ($totals) { return array_sum(array_column($totals, $key)); };
                            $footer = [
                                ['Planned income', 'income', false],
                                ['Planned outflow', 'outflow', false],
                                ['Planned net', 'net', true],
                                ['Actual net', 'actual_net', true],
                                ['Cumulative planned', 'cum_planned', true],
                                ['Cumulative actual', 'cum_actual', true],
                            ];
                        ?>
                        <?php foreach ($footer as [$label, $key, $isSigned]) { ?>
                            <tr class="<?= $key === 'net' ? 'plan-net-row' : '' ?>" id="plan-total-<?= esc($key, 'attr') ?>">
                                <th class="plan-sticky"><?= esc($label) ?></th>
                                <?php foreach ($months as $ym) { $value = $totals[$ym][$key]; ?>
                                    <td class="plan-num<?= $ym === $currentYm ? ' is-current' : '' ?>" data-month="<?= esc($ym, 'attr') ?>"><?= $isSigned ? $signed($value) : $fmt($value) ?></td>
                                <?php } ?>
                                <td class="plan-num">
                                    <?php if (in_array($key, ['income', 'outflow', 'net'], true)) { echo $signed($yearSum($key)); }
                                          elseif ($key === 'actual_net') { $actuals = array_filter(array_column($totals, 'actual_net'), static fn ($v) => $v !== null); echo $actuals === [] ? $signed(null) : $signed(array_sum($actuals)); } ?>
                                </td>
                                <td></td>
                            </tr>
                        <?php } ?>
                    </tfoot>
                </table>
            </div>
            <p class="text-muted small mt-2 mb-0">Actual net = received − paid on this plan's dues − envelope spending, for tracked months up to this month.</p>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="lb-card" id="plan-cards">
                    <h2 class="lb-section-title">Card settlement projection</h2>
                    <p class="text-muted small">What each credit card will need each month: items paid with the card (the month's method, else the item's, else the account's default) plus transfers into the card's account.</p>
                    <?php if ($grid['cards'] === []) { ?>
                        <p class="text-muted mb-0">No credit card payment methods yet. Add one on <a href="<?= base_url('/payment-method/list') ?>">Payment methods</a>.</p>
                    <?php } else { ?>
                        <div class="plan-grid-wrap">
                            <table class="table table-sm plan-grid mb-0">
                                <thead>
                                    <tr>
                                        <th class="plan-sticky">Card</th>
                                        <?php foreach ($months as $ym) { ?><th class="plan-num<?= $ym === $currentYm ? ' is-current' : '' ?>"><?= esc(date('M', strtotime($ym . '-01'))) ?></th><?php } ?>
                                        <th class="plan-num">Year</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grid['cards'] as $cardID => $card) { ?>
                                        <tr data-card="<?= esc($cardID, 'attr') ?>">
                                            <td class="plan-sticky">
                                                <?= esc($card['label']) ?>
                                                <span class="plan-item-meta">
                                                    <?php if ($card['limit'] <= 0) { ?><span class="text-danger">No credit limit set: payments from it will fail</span><?php } else { ?>Limit <?= $fmt($card['limit']) ?><?php } ?>
                                                </span>
                                            </td>
                                            <?php foreach ($months as $ym) { ?>
                                                <td class="plan-num<?= $ym === $currentYm ? ' is-current' : '' ?>" data-month="<?= esc($ym, 'attr') ?>"><?= $card['months'][$ym] > 0 ? $fmt($card['months'][$ym]) : '<span class="text-muted">&ndash;</span>' ?></td>
                                            <?php } ?>
                                            <td class="plan-num fw-semibold"><?= $fmt($card['total']) ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } ?>
                </div>
            </div>
            <div class="col-12">
                <div class="lb-card" id="plan-allocation">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <h2 class="lb-section-title mb-0 me-auto">Allocation</h2>
                        <label for="plan-allocation-month" class="text-muted small">Month</label>
                        <select class="form-select form-select-sm w-auto" id="plan-allocation-month" onchange="window.location.search = '?m=' + this.value;">
                            <?php foreach ($months as $ym) { ?>
                                <option value="<?= esc($ym, 'attr') ?>" <?= $ym === $allocation['month'] ? 'selected' : '' ?>><?= esc(date('F', strtotime($ym . '-01'))) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <?php if (abs($allocationPercentTotal - 100) > 0.001) { ?>
                        <div class="alert alert-warning py-2" id="plan-allocation-banner">
                            Your category allocations add up to <strong><?= rtrim(rtrim(number_format($allocationPercentTotal, 2), '0'), '.') ?>%</strong>, not 100%.
                            Adjust them on the <a href="<?= base_url('/plan/items') ?>">Plan items</a> page.
                        </div>
                    <?php } ?>
                    <p class="text-muted small">Target = the category's % of the month's planned income (<?= $fmt($allocation['income']) ?> in <?= esc(date('F', strtotime($allocation['month'] . '-01'))) ?>).</p>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">%</th>
                                    <th class="text-end">Target</th>
                                    <th class="text-end">Planned</th>
                                    <th class="text-end">Left / over</th>
                                    <th class="text-end">Year target</th>
                                    <th class="text-end">Year planned</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allocation['categories'] as $category) { ?>
                                    <tr>
                                        <td><?= esc($category['label']) ?></td>
                                        <td class="text-end"><?= $category['percent'] === null ? '<span class="text-muted">&ndash;</span>' : rtrim(rtrim(number_format($category['percent'], 2), '0'), '.') . '%' ?></td>
                                        <td class="text-end"><?= $category['target'] === null ? '<span class="text-muted">&ndash;</span>' : $fmt($category['target']) ?></td>
                                        <td class="text-end"><?= $fmt($category['planned']) ?></td>
                                        <td class="text-end"><?= $category['difference'] === null ? '<span class="text-muted">&ndash;</span>' : '<span class="' . ($category['difference'] < 0 ? 'plan-negative' : 'plan-positive') . '">' . $fmt($category['difference']) . '</span>' ?></td>
                                        <td class="text-end"><?= $category['year_target'] === null ? '<span class="text-muted">&ndash;</span>' : $fmt($category['year_target']) ?></td>
                                        <td class="text-end"><?= $fmt($category['year_planned']) ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php } ?>
    </div>
    <?= view('Plan/entry_modal') ?>
<?= $this->endSection() ?>
