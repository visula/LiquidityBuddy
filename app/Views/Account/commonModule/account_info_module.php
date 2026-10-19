<div class="table-responsive">
<table class="table table-striped mb-3">
    <thead>
    <tr>
        <th>Account</th>
        <th class="text-end">Balance</th>
        <th class="text-center">Group</th>
        <?php if(!empty($edit_mode)){ ?><th></th><?php } ?>
    </tr>
    </thead>
    <tbody>
        <?php foreach($accounts as $key => $account){ 
            $currentPage = ceil(($key + 1) / $page_limit);
            ?>
            <tr class="t-row-account-action <?php if(($key + 1) > $page_limit){echo 'd-none';}?>" data-page-no="account-page-no-<?= $currentPage?>" id="<?= $account['AccountSessionID']?>" data-account-number="<?= esc((string) ($account['AccountNumber'] ?? ''), 'attr') ?>">
                <td class="account-name"><?= $account['AccountName'] ?></td>
                <td class="text-end account-balance"><?= number_format($account['AccountCurrentBalance'],2) ?></td>
                <td class="text-center account-group"  data-account-group-id="<?= $account['AccountGroupSessionID'] ?>"><?= $account['AccountGroupName'] ?></td>
                <?php if($edit_mode){ ?>
                    <td class="text-end text-nowrap"><a data-account-id="<?= $account['AccountSessionID'] ?>" class="btn btn-sm btn-outline-primary update-account-btn">Update</a> <a data-account-id="<?= $account['AccountSessionID'] ?>"  class="btn btn-sm btn-outline-danger delete-account-btn">Remove</a></td>
                <?php } ?>
            </tr>
        <?php } ?>                        
    </tbody>
</table>
</div>
<?php if($page_count > 1){ ?>
    <ul class="pagination account" pagination-type="account">
        <li class="page-item disabled" disabled=true><a class="page-link previous-page" href="#">Previous</a></li>
            <?php for ($page=1; $page < ($page_count + 1); $page++) {  ?>
                <li class="page-item <?php if($page == 1){echo 'active first';}elseif($page == ($page_count)){echo 'last';}?>"><a class="page-link page-list" id="account-page-no-<?= $page ?>"><?= $page ?></a></li>
            <?php } ?>
        <li class="page-item"><a class="page-link next-page" href="#">Next</a></li>
    </ul>
<?php } ?>