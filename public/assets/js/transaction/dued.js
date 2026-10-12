$(document).ready(function(){
    var $dued = $('#dued');

    function selectedTotal() {
        var total = 0;
        $dued.find('.dued-row:not(.d-none) .payment-check:checked').each(function () {
            total += parseFloat($(this).data('outstanding')) || 0;
        });
        return Math.round(total * 100) / 100;
    }

    function refresh() {
        var total = selectedTotal();
        var partial = $dued.find('input[name="dued-mode"]:checked').val() === 'partial';

        $dued.find('#dued-selected-total').val(total.toFixed(2));
        $dued.find('#amount').prop('readonly', !partial);
        if (!partial) {
            $dued.find('#amount').val(total.toFixed(2));
        } else if ((parseFloat($dued.find('#amount').val()) || 0) > total) {
            $dued.find('#amount').val(total.toFixed(2));
        }
    }

    // Payables vs receivables: only one kind can be settled per payment
    $dued.find('input[name="dued-direction"]').on('change', function () {
        var direction = $(this).val();
        $dued.find('.payment-check, #dued-select-all').prop('checked', false);
        $dued.find('.dued-row').each(function () {
            $(this).toggleClass('d-none', $(this).data('direction') !== direction);
        });
        $dued.find('#dued-account-label').text({ IN: 'Receive into account:', TRANSFER: 'Transfer from account:' }[direction] || 'Pay from account:');
        $dued.find('#dued-payment-btn').text({ IN: 'Collect Selected', TRANSFER: 'Transfer Selected' }[direction] || 'Settle Selected');
        refresh();
    });

    // Plan dues know their account, method and budget: preselect them when every
    // selected due agrees (the user can still change them)
    function applyItemDefaults() {
        var $rows = $dued.find('.dued-row:not(.d-none)').filter(function () {
            return $(this).find('.payment-check').is(':checked');
        });
        var same = function (attr) {
            var values = $.unique($rows.map(function () { return $(this).attr(attr) || ''; }).get());
            return (values.length === 1 && values[0]) ? values[0] : null;
        };

        var account = same('data-account');
        if (account && $dued.find('#current-account-list option[value="' + account + '"]').length) {
            $dued.find('#current-account-list').val(account).trigger('change');
            var method = same('data-method');
            if (method && $dued.find('.payment-method-list option[value="' + method + '"]').length) {
                $dued.find('.payment-method-list').val(method).trigger('change');
            }
        }

        var budget = same('data-budget');
        if (budget && $dued.find('#budget-list option[value="' + budget + '"]').length) {
            $dued.find('#budget-list').val(budget);
        }
    }

    $dued.on('change', '.payment-check, #dued-select-all', applyItemDefaults);

    $dued.find('#dued-select-all').on('change', function () {
        $dued.find('.dued-row:not(.d-none) .payment-check').prop('checked', $(this).is(':checked'));
        refresh();
    });

    $dued.on('change', '.payment-check, input[name="dued-mode"]', refresh);

    $dued.find('#dued-payment-btn').on('click', function () {
        var payableIds = [];
        $dued.find('.dued-row:not(.d-none) .payment-check:checked').each(function () {
            payableIds.push($(this).val());
        });

        if (payableIds.length === 0) {
            LB.showError('Please select at least one due.');
            return;
        }

        var mode = $dued.find('input[name="dued-mode"]:checked').val();
        var amount = parseFloat($dued.find('#amount').val()) || 0;

        if (amount <= 0) {
            LB.showError('Please enter the amount to pay.');
            return;
        }

        LB.post('js-request/payment/settle', {
            payable_ids: payableIds,
            mode: mode,
            amount: amount.toFixed(2),
            account_id: $dued.find('#current-account-list').val(),
            payment_method: LB.paymentMethodOf($dued),
            budget_id: $dued.find('#budget-list').val(),
            description: $dued.find('#description').val(),
            reference: $dued.find('#reference').val()
        }, function () {
            window.location.reload();
        }, null, $(this));
    });

    refresh();
});
