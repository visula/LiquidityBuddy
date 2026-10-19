<?php
/**
 * Payment method picker for a transaction form.
 * Options for every account are rendered; default.js (LB.syncPaymentMethods)
 * shows only the methods of the account picked in the same form.
 *
 * @var array  $paymentMethodInfo rows from PaymentService::paymentMethodList()
 * @var string $label
 * @var bool   $showReference
 */
$label = $label ?? 'Payment Method';
$showReference = $showReference ?? true;
?>
<div class="col-6 mb-3">
    <div class="form-floating">
        <select class="form-select payment-method-list" name="payment-method-list" id="payment-method-list">
            <option class="no-method-option" value="">Account balance (no method)</option>
            <?php foreach (($paymentMethodInfo ?? []) as $method) { ?>
                <?php if (($method['PaymentMethodShowInDropdown'] ?? 'Y') === 'N') { continue; } // e.g. the account has no card ?>
                <option value="<?= esc($method['PaymentMethodSessionID'], 'attr') ?>"
                        data-account="<?= esc($method['AccountSessionID'], 'attr') ?>"
                        data-type="<?= esc($method['PaymentMethodType'], 'attr') ?>"
                        data-type-label="<?= esc($method['PaymentMethodTypeLabel'], 'attr') ?>"
                        data-available="<?= esc($method['AvailableFunds'], 'attr') ?>"
                        data-credit-limit="<?= esc($method['PaymentMethodCreditLimit'], 'attr') ?>"
                        data-default="<?= esc($method['PaymentMethodIsDefault'], 'attr') ?>"
                        hidden disabled>
                    <?= esc($method['PaymentMethodLabel']) ?> (<?= esc($method['PaymentMethodTypeLabel']) ?>)
                </option>
            <?php } ?>
        </select>
        <label for="payment-method-list" class="form-label"><?= esc($label) ?>:</label>
    </div>
    <small class="text-muted payment-method-hint"></small>
</div>
<?php if ($showReference) { ?>
<div class="col-6 mb-3">
    <div class="form-floating">
        <input type="text" class="form-control" placeholder="Reference" name="reference" id="reference" maxlength="50">
        <label for="reference">Reference / Receipt No (optional)</label>
    </div>
</div>
<?php } ?>
