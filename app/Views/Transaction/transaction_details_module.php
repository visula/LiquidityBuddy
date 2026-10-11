<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/common_modules/pagination.js') ?>"></script>
<h2 class="lb-section-title">Transactions</h2>
<div class="table-responsive">
<table class="table table-striped mb-3" id="transaction-table">
    <thead>
    <tr>
        <th>Description</th>
        <th class="text-center">Date</th>
        <th class="text-end">Income</th>
        <th class="text-end">Expense</th>
        <th class="text-end">Balance</th>
        <?php if(isset($paymentMethodMap)){ ?>
        <th class="text-center">Method</th>
        <?php } ?>
        <?php if($allow_all_accounts){  ?>
        <th>Account</th>
        <?php } ?>
    </tr>
    </thead>
    <tbody>
        <?php
            $runningBalance = [];
            $currentBalance = 0;
            
            foreach($transactions as $key => $transaction){
                $currentPage = ceil(($key + 1) / $page_limit);

                $currentBalance = 0;
                if(!isset($runningBalance[$transaction['AccountSessionID']])){
                    $runningBalance[$transaction['AccountSessionID']] = $transaction['AccountCurrentBalance'];
                }
                $currentBalance = $runningBalance[$transaction['AccountSessionID']];
            ?>
                <tr class="t-row-transaction-action <?php if(($key + 1) > $page_limit){echo 'd-none';}?>" data-page-no="transaction-page-no-<?= $currentPage?>" account="<?= esc($transaction['AccountSessionID'], 'attr') ?>">
                    <td><?= esc($transaction['TransactionDescription']) ?></td>
                    <td class="text-center"><?= $transaction['TransactionDate'] ?></td>
                    <?php if($transaction['TransactionPayableType'] == 'income') { ?>
                        <td class="text-end mx-3"><?= number_format($transaction['TransactionAmount'],2) ?></td>
                        <td class="text-end mx-3"><?= number_format(0,2) ?></td>
                    <?php }elseif($transaction['TransactionPayableType'] == 'expense'){ ?>
                        <td class="text-end mx-3"><?= number_format(0,2) ?></td>
                        <td class="text-end mx-3"><?= number_format($transaction['TransactionAmount'],2) ?></td>
                    <?php } ?>
                    <td class="text-end mx-3"><?= number_format($currentBalance,2) ?></td>
                    <?php if(isset($paymentMethodMap)){ ?>
                        <td class="text-center"><small><?= esc($paymentMethodMap[$transaction['PaymentMethodSessionID'] ?? ''] ?? '-') ?></small></td>
                    <?php } ?>
                    <?php if($allow_all_accounts){  ?>
                        <td><?= esc($transaction['AccountName']) ?></td>
                    <?php } ?>
                </tr>
        <?php 
                $currentBalance = ($transaction['TransactionPayableType'] == 'expense') ? ($currentBalance + $transaction['TransactionAmount']) : ($currentBalance - $transaction['TransactionAmount']);
                $runningBalance[$transaction['AccountSessionID']] = $currentBalance;
            } 
            ?>
    </tbody>
</table>
</div>
<?php if($page_count > 1){ ?>
    <div class="d-flex justify-content-center">
        <ul class="pagination transaction" pagination-type="transaction">
            <li class="page-item disabled" disabled=true><a class="page-link previous-page">Previous</a></li>
                <?php for ($page=1; $page < ($page_count + 1); $page++) {  ?>
                    <li class="page-item <?php if($page == 1){echo 'active first';}elseif($page == ($page_count)){echo 'last';}?>"><a class="page-link page-list" id="transaction-page-no-<?= $page ?>"><?= $page ?></a></li>
                <?php } ?>
            <li class="page-item"><a class="page-link next-page">Next</a></li>
        </ul>
    </div>
<?php } ?>
