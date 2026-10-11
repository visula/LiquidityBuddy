/**
 * Shared helpers for the JSON endpoints (window.LB).
 * LB.post() normalises both response styles used by the app (echo'd JSON and
 * setJSON responses with 4xx status) and shows the server message on failure.
 */
window.LB = window.LB || {};

LB.url = function (path) {
    return String(base_url).replace(/\/+$/, '') + '/' + String(path).replace(/^\/+/, '');
};

LB.parse = function (response) {
    if (typeof response === 'string') {
        try { return JSON.parse(response); } catch (e) { return { success: false, message: 'Unexpected response from the server.' }; }
    }
    return response || {};
};

LB.showError = function (message, target) {
    $(target || '#common-error').text('* ' + message).removeClass('d-none');
};

LB.clearError = function (target) {
    if (target) {
        $(target).html('&nbsp;');   // inline message slots keep their height
        return;
    }
    $('#common-error').text('').addClass('d-none');
};

LB.post = function (path, data, onSuccess, errorTarget, $button) {
    LB.clearError(errorTarget);
    if ($button) { $button.addClass('disable-btn'); }

    return $.ajax({ type: 'POST', url: LB.url(path), data: data, dataType: 'text' })
        .done(function (raw) {
            var response = LB.parse(raw);
            if (response.success === false) {
                LB.showError(response.message || response.response || 'The request could not be completed.', errorTarget);
                return;
            }
            onSuccess(response);
        })
        .fail(function (xhr) {
            var response = LB.parse(xhr.responseText);

            // 0046 = over budget: the user may confirm and pay anyway (use case "Budget plan overboard")
            if (response.code === '0046' && data && typeof data === 'object' && !data.allow_over_budget) {
                if (window.confirm(response.message + '\n\nGo over the budget and continue?')) {
                    if ($button) { $button.removeClass('disable-btn'); }
                    LB.post(path, $.extend({}, data, { allow_over_budget: 1 }), onSuccess, errorTarget, $button);
                    return;
                }
            }

            LB.showError(response.message || ('The request failed (' + xhr.status + ').'), errorTarget);
        })
        .always(function () {
            if ($button) { $button.removeClass('disable-btn'); }
        });
};

LB.money = function (value) {
    return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

/**
 * Payment method dropdowns are filtered to the account selected in the same
 * form (.transaction-type-list). Options carry data-account / data-available.
 */
LB.syncPaymentMethods = function ($container) {
    var $methods = $container.find('.payment-method-list');
    if ($methods.length === 0) { return; }

    var account = $container.find('.current-account-list').val();
    var $forAccount = $methods.find('option[data-account]').filter(function () {
        return $(this).attr('data-account') === account;
    });

    $methods.find('option[data-account]').prop('hidden', true).prop('disabled', true);
    $forAccount.prop('hidden', false).prop('disabled', false);

    var hasMethods = $forAccount.length > 0;
    var $default = $forAccount.filter('[data-default="Y"]').first();
    var $selected = $default.length ? $default : $forAccount.first();

    $methods.find('.no-method-option').prop('hidden', hasMethods).prop('disabled', hasMethods);
    $methods.val(hasMethods ? $selected.val() : '');
    LB.paymentMethodHint($container);
};

LB.paymentMethodHint = function ($container) {
    var $option = $container.find('.payment-method-list option:selected');
    var $hint = $container.find('.payment-method-hint');
    if ($option.length === 0 || !$option.attr('data-account')) {
        $hint.text('No payment method on this account - the account balance is used.');
        return;
    }
    var text = $option.data('type-label') + ' · available ' + LB.money($option.data('available'));
    if ($option.data('type') === 'CREDIT_CARD') {
        text += ' (incl. credit limit ' + LB.money($option.data('credit-limit')) + ')';
    }
    $hint.text(text);
};

LB.paymentMethodOf = function ($container) {
    return $container.find('.payment-method-list').val() || '';
};

$(document).ready(function () {
    $('.transaction-type-list').each(function () { LB.syncPaymentMethods($(this)); });

    $(document).on('change', '.payment-method-list', function () {
        LB.paymentMethodHint($(this).closest('.transaction-type-list'));
    });
});
