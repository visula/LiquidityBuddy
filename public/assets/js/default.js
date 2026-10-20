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

/**
 * Re-render one region of the current page from the server and swap it in, instead of
 * reloading the whole page. The region is the same markup the page renders, so the table
 * shows what the server holds. `done` runs after the swap, for anything that must rebind
 * or reset. Scripts bound to elements inside a region must delegate from `document`.
 */
LB.refreshRegion = function (selector, done) {
    $.get(window.location.href).done(function (html) {
        var $region = $('<div>').append($.parseHTML(html)).find(selector);

        if ($region.length === 0) {
            window.location.reload();
            return;
        }

        $(selector).replaceWith($region);
        $(document).trigger('lb:refreshed', [selector]);

        if (typeof done === 'function') { done(); }
    }).fail(function () {
        window.location.reload();
    });
};

/** The number in an amount field, with thousands separators allowed ("1,234.50" -> 1234.5). */
LB.amountValue = function (value) {
    return parseFloat(String(value === undefined || value === null ? '' : value).replace(/,/g, '')) || 0;
};

/**
 * Amount fields show thousands separators when they lose focus and go back to plain
 * digits while being edited, so typing never fights the separators.
 */
$(document).on('focusout', '#amount, input[name="amount"]', function () {
    var $field = $(this);
    if ($field.prop('readonly') || $field.val() === '') { return; }
    $field.val(Number(LB.amountValue($field.val())).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
});

$(document).on('focus', '#amount, input[name="amount"]', function () {
    var $field = $(this);
    if ($field.val() === '') { return; }
    $field.val(String(LB.amountValue($field.val())));
});

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
