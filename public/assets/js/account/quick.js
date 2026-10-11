/*
 * Account page: link a bank product, and the Quick add form.
 * Every action posts to the same endpoints as the full Transactions forms,
 * so funds, budget and payment-method checks are identical.
 */
$(document).ready(function () {
    var $page = $('#account-page');
    if ($page.length === 0) { return; }

    var accountId = $page.data('account');
    var $modal = $('#quick-modal');
    var modal = $modal.length ? new bootstrap.Modal($modal[0]) : null;
    var action = null;
    var quote = null;

    var titles = {
        deposit: 'Deposit',
        withdraw: 'Withdraw',
        transfer: 'Transfer',
        pay: 'Pay',
        interest: 'Post interest'
    };
    var defaults = {
        deposit: 'Deposit',
        withdraw: 'Cash withdrawal',
        transfer: '',
        pay: ''
    };

    function reload() { window.location.reload(); }

    function amountValue() {
        return parseFloat(String($('#quick-amount').val()).replace(/,/g, '')) || 0;
    }

    // ---- Bank product link -------------------------------------------------
    function saveProduct(confirmReset) {
        LB.post('js-request/account/bank-product', {
            account_id: accountId,
            account_type: $('#account-type-edit').val(),
            bank_name: $('#account-bank-edit').val(),
            product_id: $('#bank-product').val(),
            auto_interest: $('#auto-interest').is(':checked') ? 1 : 0,
            confirm_reset: confirmReset ? 1 : 0
        }, reload, '#product-error', $('#save-product-btn')).fail(function (xhr) {
            var response = LB.parse(xhr.responseText);
            // 0062: unposted interest would be lost by changing the product
            if (response.code === '0062' && window.confirm(response.message)) {
                LB.clearError('#product-error');
                saveProduct(true);
            }
        });
    }

    $('#save-product-btn').on('click', function () { saveProduct(false); });

    // A bank product sets the account type and bank; cash accounts have neither
    $('#bank-product').on('change', function () {
        var $option = $(this).find('option:selected');
        var linked = !!$option.val();
        if (linked && $option.attr('data-type')) {
            $('#account-type-edit').val($option.attr('data-type'));
            $('#account-bank-edit').val($option.attr('data-bank'));
        }
        $('#account-bank-edit').prop('readonly', linked);
    });

    $('#account-type-edit').on('change', function () {
        var type = $(this).val();
        var $product = $('#bank-product');
        var $selected = $product.find('option:selected');
        if ($selected.val() && $selected.attr('data-type') !== type) {
            $product.val('').trigger('change'); // the product no longer matches the type
        }
        $('#account-bank-col').toggleClass('d-none', type === 'CASH');
    }).trigger('change');

    // ---- Bank charge for withdraw / transfer ------------------------------------
    var feeTouched = false;
    var transferQuote = null;

    function loadTransferQuote(keepChannel) {
        if (action !== 'withdraw' && action !== 'transfer') { return; }
        $('#quick-submit').prop('disabled', true);
        $('#quick-fee-rule').text('Updating...');
        LB.post('js-request/account/transfer/quote', {
            from_account: accountId,
            to_account: $('#quick-to-account').val(),
            amount: amountValue().toFixed(2),
            channel: keepChannel ? ($('#quick-channel').val() || '') : '',
            payment_date: $('#quick-date').val()
        }, function (response) {
            var q = transferQuote = response.data;
            var $channel = $('#quick-channel').empty();
            $.each(q.Channels, function (_, c) { $('<option>').val(c.code).text(c.label).appendTo($channel); });
            $channel.val(q.Channel);
            var hasChannels = q.Channels.length > 0;
            $('#quick-channel, #quick-fee').closest('.quick-field').toggleClass('d-none', !hasChannels);
            if (!feeTouched) { $('#quick-fee').val(Number(q.Fee).toFixed(2)); }
            $('#quick-fee-rule').text(hasChannels ? (q.KindLabel + ': ' + (q.FeeRule || 'no charge listed') + ' · charged to ' + q.FeeAccountName) : q.KindLabel);
            $('#quick-title').text(q.KindLabel);
        }, '#quick-error').always(function () { $('#quick-submit').prop('disabled', false); });
    }

    $('#quick-to-account, #quick-date').on('change', function () { feeTouched = false; loadTransferQuote(false); });
    $('#quick-channel').on('change', function () { feeTouched = false; loadTransferQuote(true); });
    $('#quick-fee').on('input', function () { feeTouched = true; });
    $('#quick-amount').on('change', function () { loadTransferQuote(true); });

    // ---- Quick add ------------------------------------------------------------
    function showFields() {
        $modal.find('.quick-field').each(function () {
            var forList = String($(this).data('for')).split(' ');
            $(this).toggleClass('d-none', forList.indexOf(action) === -1);
        });
    }

    function loadQuote() {
        quote = null;
        $('#interest-quote').text('Calculating...');
        LB.post('js-request/account/interest/quote', {
            account_id: accountId,
            through: $('input[name="interest-through"]:checked').val()
        }, function (response) {
            quote = response.data;
            // Completed months already posted: offer the interest earned so far this month instead
            if (!quote.Days && $('#through-month').is(':checked')) {
                $('#through-today').prop('checked', true);
                loadQuote();
                return;
            }
            if (!quote.Days) {
                $('#interest-quote').text('Interest is already posted up to ' + quote.To + '.');
                $('#quick-amount').val('');
                return;
            }
            $('#interest-quote').text(
                quote.From + ' to ' + quote.To + ' (' + quote.Days + ' days) at ' + quote.Rate + '% on an average balance of ' +
                LB.money(quote.AverageBalance) + '. Change the amount if the bank credited something different.'
            );
            $('#quick-amount').val(Number(quote.Amount).toFixed(2));
        }, '#quick-error');
    }

    $('.lb-quick-btn[data-action]').on('click', function () {
        action = $(this).data('action');
        $('#quick-title').text(titles[action] || 'Quick add');
        $('#quick-amount').val('');
        $('#quick-description').val(defaults[action] || '');
        LB.clearError('#quick-error');

        // Withdraw goes to a cash account when there is one
        if (action === 'withdraw') {
            var $cash = $('#quick-to-account option[data-cash="1"]').first();
            if ($cash.length) { $('#quick-to-account').val($cash.val()); }
        }

        showFields();
        modal.show();

        if (action === 'interest') { loadQuote(); }
        if (action === 'withdraw' || action === 'transfer') { feeTouched = false; loadTransferQuote(false); }
    });

    $('input[name="interest-through"]').on('change', loadQuote);

    $modal.on('shown.bs.modal', function () { $('#quick-amount').trigger('focus'); });

    $('#quick-amount').on('keydown', function (e) {
        if (e.key === 'Enter') { $('#quick-submit').trigger('click'); }
    });

    $('#quick-submit').on('click', function () {
        var amount = amountValue();
        var $btn = $(this);
        var common = {
            payment_method: $('#quick-method').val() || '',
            payment_date: $('#quick-date').val()
        };

        if (action === 'interest') {
            if (!quote || !quote.Days) {
                LB.showError('There is no interest to post for this period.', '#quick-error');
                return;
            }
            if (amount < 0) {
                LB.showError('The amount cannot be negative.', '#quick-error');
                return;
            }
            LB.post('js-request/account/interest/post', {
                account_id: accountId,
                through: $('input[name="interest-through"]:checked').val(),
                amount: amount.toFixed(2),
                payment_method: common.payment_method
            }, reload, '#quick-error', $btn);
            return;
        }

        if (amount <= 0) {
            LB.showError('Please enter an amount greater than zero.', '#quick-error');
            return;
        }

        var description = $.trim($('#quick-description').val());
        var budget = $('#quick-budget').val();

        if (action === 'deposit') {
            LB.post('js-request/payment/income', $.extend({
                type: 'one-time',
                amount: amount.toFixed(2),
                account_id: accountId,
                budget_id: budget,
                description: description || 'Deposit'
            }, common), reload, '#quick-error', $btn);
        } else if (action === 'pay') {
            LB.post('js-request/payment/expense', $.extend({
                amount: amount.toFixed(2),
                current_account: accountId,
                budget: budget,
                external_pay_type: 'O',
                description: description || 'Payment'
            }, common), reload, '#quick-error', $btn);
        } else {
            var withCharge = transferQuote && transferQuote.Channels.length > 0;
            LB.post('js-request/account/transferred', $.extend({
                amount: amount.toFixed(2),
                from_account: accountId,
                to_account: $('#quick-to-account').val(),
                budget: budget,
                channel: withCharge ? ($('#quick-channel').val() || '') : '',
                fee: (withCharge && feeTouched) ? (parseFloat($('#quick-fee').val()) || 0).toFixed(2) : '',
                description: description
            }, common), reload, '#quick-error', $btn);
        }
    });
});
