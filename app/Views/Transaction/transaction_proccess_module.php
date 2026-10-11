<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/proccess.js') ?>"></script>
<div class="mb-3" id="transaction-type-list">
    <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link active select-transction-type" href="#External" data-transaction-type="external">External</a></li>
        <li class="nav-item"><a class="nav-link select-transction-type" href="#Internal" data-transaction-type="internal">Internal</a></li>
        <li class="nav-item"><a class="nav-link select-transction-type" href="#Income" data-transaction-type="income">Income</a></li>
        <li class="nav-item"><a class="nav-link select-transction-type" href="#Purchase" data-transaction-type="purchase">Purchases</a></li>
        <li class="nav-item"><a class="nav-link select-transction-type" href="#Dued" data-transaction-type="dued">Dues</a></li>
    </ul>
</div>
<?= $external_trans_content ?>
<?= $internal_trans_content ?>
<?= $purchase_content ?>
<?= $income_trans_content ?>
<?= $dued_trans_content ?>
<p class="error d-none" id="common-error">* Error</p>
<?= $other_trans_content ?>