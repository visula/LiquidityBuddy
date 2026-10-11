<div class="table-responsive">
<table class="table table-striped mb-3">
    <thead>
    <tr>
        <th>Account Group</th>
        <?php if(!empty($edit_mode)){ ?><th></th><?php } ?>
    </tr>
    </thead>
    <tbody>
        <?php foreach($accountGroups as $key => $accountGroup){
            $currentPage = ceil(($key + 1) / $page_limit);
            ?>
            <tr class="t-row-account-group-action <?php if(($key + 1) > $page_limit){echo 'd-none';}?>" data-page-no="account-group-page-no-<?= $currentPage?>" id="<?= $accountGroup['AccountGroupSessionID']?>">
                <td class="account-group-name"><?= $accountGroup['AccountGroupName'] ?></td>
                <?php if($edit_mode){ ?>
                    <td class="text-end text-nowrap"><a data-account-group-id="<?= $accountGroup['AccountGroupSessionID'] ?>" class="btn btn-sm btn-outline-primary update-account-group-btn">Update</a> <a data-account-group-id="<?= $accountGroup['AccountGroupSessionID'] ?>"  class="btn btn-sm btn-outline-danger delete-account-group-btn">Remove</a></td>
                <?php } ?>
            </tr>
        <?php } ?>
    </tbody>
</table>
</div>
<?php if($page_count > 1){ ?>
    <ul class="pagination account-group" pagination-type="account-group">
        <li class="page-item disabled" disabled=true><a class="page-link previous-page" href="#">Previous</a></li>
            <?php for ($page=1; $page < ($page_count + 1); $page++) {  ?>
                <li class="page-item <?php if($page == 1){echo 'active first';}elseif($page == ($page_count)){echo 'last';}?>"><a class="page-link page-list" id="account-group-page-no-<?= $page ?>"><?= $page ?></a></li>
            <?php } ?>
        <li class="page-item"><a class="page-link next-page" href="#">Next</a></li>
    </ul>
<?php } ?>