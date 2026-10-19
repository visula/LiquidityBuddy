/**
 * Month view: pick dues of one direction and settle them through the payment
 * ledger (POST js-request/payment/settle, the same endpoint as the Dued tab).
 */
function init() {
    var $dues = $('#plan-month-dues');
    var $settle = $('#plan-settle');
    var errorSlot = '#plan-settle-error';

    function direction() {
        return $dues.find('input[name="plan-direction"]:checked').val();
    }

    function $checked() {
        return $dues.find('.plan-due-row:not(.d-none) .plan-due-check:checked');
    }

    function filterRows() {
        var showClosed = $('#plan-show-closed').is(':checked');
        $dues.find('.plan-due-row').each(function () {
            var $row = $(this);
            var visible = $row.attr('data-direction') === direction() && (showClosed || !$row.hasClass('plan-due-closed'));
            $row.toggleClass('d-none', !visible);
            if (!visible) { $row.find('.plan-due-check').prop('checked', false); }
        });
        $('#plan-select-all').prop('checked', false);

        var labels = { IN: ['Receive into account', 'Collect Selected'], TRANSFER: ['Transfer from account', 'Transfer Selected'] };
        var label = labels[direction()] || ['Pay from account', 'Settle Selected'];
        $('#plan-settle-account-label').text(label[0]);
        $('#plan-settle-btn').text(label[1]);
        refresh();
    }

    function total() {
        var sum = 0;
        $checked().each(function () { sum += parseFloat($(this).attr('data-outstanding')) || 0; });
        return Math.round(sum * 100) / 100;
    }

    function refresh() {
        var partial = $settle.find('input[name="plan-mode"]:checked').val() === 'partial';
        var sum = total();
        var $amount = $('#plan-settle-amount');

        $amount.prop('readonly', !partial);
        if (!partial || (parseFloat($amount.val()) || 0) > sum) {
            $amount.val(sum.toFixed(2));
        }
    }

    // the selected dues' own account / method / budget, when they all agree
    function applyDefaults() {
        var $rows = $checked().closest('tr');
        var same = function (attr) {
            var values = $.unique($rows.map(function () { return $(this).attr(attr) || ''; }).get());
            return (values.length === 1 && values[0]) ? values[0] : null;
        };

        var account = same('data-account');
        if (account && $settle.find('#current-account-list option[value="' + account + '"]').length) {
            $settle.find('#current-account-list').val(account);
            LB.syncPaymentMethods($settle);
            var method = same('data-method');
            if (method && $settle.find('.payment-method-list option[value="' + method + '"]:not([disabled])').length) {
                $settle.find('.payment-method-list').val(method);
                LB.paymentMethodHint($settle);
            }
        }

        var budget = same('data-budget');
        if (budget && $('#plan-settle-budget option[value="' + budget + '"]').length) {
            $('#plan-settle-budget').val(budget);
        }
    }

    $dues.find('input[name="plan-direction"]').on('change', filterRows);
    $('#plan-show-closed').on('change', filterRows);

    $('#plan-select-all').on('change', function () {
        $dues.find('.plan-due-row:not(.d-none) .plan-due-check').prop('checked', $(this).is(':checked'));
        applyDefaults();
        refresh();
    });

    $dues.on('change', '.plan-due-check', function () {
        applyDefaults();
        refresh();
    });

    $settle.on('change', 'input[name="plan-mode"]', refresh);
    $settle.on('change', '.current-account-list', function () { LB.syncPaymentMethods($settle); });

    $('#plan-settle-btn').on('click', function () {
        var ids = $checked().map(function () { return $(this).val(); }).get();
        var amount = parseFloat(String($('#plan-settle-amount').val()).replace(/,/g, '')) || 0;

        if (ids.length === 0) {
            LB.showError('Please select at least one due', errorSlot);
            return;
        }

        if (amount <= 0) {
            LB.showError('Please enter the amount to pay', errorSlot);
            return;
        }

        LB.post('js-request/payment/settle', {
            payable_ids: ids,
            mode: $settle.find('input[name="plan-mode"]:checked').val(),
            amount: amount.toFixed(2),
            account_id: $settle.find('#current-account-list').val(),
            payment_method: LB.paymentMethodOf($settle),
            budget_id: $('#plan-settle-budget').val(),
            reference: $('#plan-settle-reference').val()
        }, function () { LB.refreshRegion('#plan-month-region'); }, errorSlot, $(this));
    });

    filterRows();
}

$(document).ready(function () {
    init();

    // the entry modal refreshes this region too, so rebind after any swap
    $(document).on('lb:refreshed', function (event, selector) {
        if (selector === '#plan-month-region') { init(); }
    });
});
