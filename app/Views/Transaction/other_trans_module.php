<script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/transaction/other.js') ?>"></script>
<div class="col-12 mb-3" id="schedule-payment-section">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="schedule-payment-checkbox">
        <label class="form-check-label">Schedule this Payment</label>
    </div>
</div>
<div id="schedule-payment" class="d-none d-flex row">
    <div class="col-6 mb-3">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Partial amount" name="partialamount" id="partialamount" disabled>
            <label for="partialamount">Partial Amount</label>
        </div>
    </div>
    <div class="col-3">
        <div class="form-floating">
            <select class="form-select payment-plan-type" name="payment-plan-type" id="payment-plan-type">
                <?php foreach($paymentPlan as $key => $periodic){ ?>
                    <?php if($key != 'C' && $key != 'I'){ ?>
                        <option class="" value="<?= $key?>" <?php if($key == 'M'){echo 'selected';} ?>><?= $periodic ?></option>
                    <?php } ?>
                <?php } ?>
            </select>
            <label for="from-account-list" class="form-label">Payment Plan:</label>
        </div>
    </div>
    <div class="col-3" id="payment-plan-period">
        <div class="form-floating">
            <input type="number" class="form-control" placeholder="Period" id="scheduled-payment-period" value="3">
            <label for="scheduled-payment-period">Period</label>
        </div>
    </div>
    <div class="col-3">
        <div class="form-floating mb-3 mt-3">
            <input type="date" class="form-control" id="payment-plan-start-date" placeholder="Starting Date">
            <label for="payment-plan-start-date">Starting Date</label>
        </div>
    </div>
    <div class="col-12 mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="init-payment" name="init-payment" value="make-init-payment">
            <label class="form-check-label">Make the initial payment</label>
        </div>
    </div>
    <div class="col-12 mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="int-rate" name="int-rate" value="has-int-rate">
            <label class="form-check-label">This payment has an interest rate</label>
        </div>
    </div>
    <div class="d-none d-flex row mb-3" id="interest-rate">
        <div class="col-3">
            <div class="form-floating">
                <input type="number" class="form-control" placeholder="Interest rate" name="rate" id="rate">
                <label for="rate">Rate</label>
            </div>
        </div>
        <div class="col-3">
            <div class="form-floating">
                <select class="form-select rate-period" name="rate-period" id="rate-period">
                    <?php foreach($paymentPlan as $key => $periodic){ ?>
                        <?php if($key != 'C' && $key != 'I'){ ?>
                            <option class="" value="<?= $key?>" <?php if($key == 'I'){echo 'selected';} ?>><?= $periodic ?></option>
                        <?php } ?>
                    <?php } ?>
                </select>
                <label for="from-account-list" class="form-label">Rate Period:</label>
            </div>
        </div>
    </div>
</div>
<div class="col-12 mb-3 d-none TODO::remove-later">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="claim-item-cost" name="claim-item-cost" value="claim-item-cost">
        <label class="form-check-label">Claim Item cost</label>
    </div>
</div>
<div id="claim-stackholder" class="d-none d-flex row  TODO::remove-later">
    <div class="col-6">
        <div class="form-floating">
            <select class="form-select stackholder-name" name="stackholder-name" id="stackholder-name">
                <?php if(isset($stackholderInfo) && count($stackholderInfo) > 0){?>
                    <?php foreach($stackholderInfo as $key => $stackholder){ ?>
                        <?php if(isset($stackholder['StackholderName'])){?>
                            <option class="" value="<?= $key ?>" <?php if($key == 0){echo 'selected';} ?>><?= $stackholder['StackholderName'].' - '.$stackholder['StackholderRelationship'] ?></option>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </select>
            <label for="from-account-list" class="form-label">Stackholder:</label>
        </div>
    </div>
</div>
