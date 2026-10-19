/**
 * Plan items page: add / edit / end / delete items, categories and allocation %.
 */
$(document).ready(function () {
    var $form = $('#plan-item-form');
    var errorSlot = '#plan-item-error';

    function direction() {
        return $editing ? $editing.direction : $form.find('input[name="plan-item-direction"]:checked').val();
    }

    var $editing = null;

    function syncDirection() {
        var dir = direction();
        $('#plan-item-account-label').text({ IN: 'Receive into account', TRANSFER: 'Move from account' }[dir] || 'Pay from account');
        $form.find('.plan-item-transfer-only').toggleClass('d-none', dir !== 'TRANSFER');
        $form.find('.plan-item-out-only').toggleClass('d-none', dir !== 'OUT' || $editing !== null);
        $form.find('.plan-item-income-only').toggleClass('d-none', dir !== 'IN');
        if (dir !== 'OUT') { $('#plan-item-kind').val('DUE'); }
    }

    function resetForm() {
        $editing = null;
        $('#plan-item-id').val('');
        $('#plan-item-name, #plan-item-amount, #plan-item-due-day, #plan-item-end, #plan-item-ratio, #plan-item-stakeholder, #plan-item-notes').val('');
        $('#plan-item-category, #plan-item-budget, #plan-item-source, #plan-item-to-account').val('');
        $('#plan-item-kind').val('DUE');
        $('#plan-item-amount-type').val('FIXED');
        $('#plan-item-recurring').prop('checked', true);
        $form.find('input[name="plan-item-weekend"][value="NONE"]').prop('checked', true);
        $form.find('.plan-item-new-only').removeClass('d-none');
        $('#plan-item-form-title').text('Add plan item');
        $('#plan-item-save').text('Add Item');
        $('#plan-item-cancel').addClass('d-none');
        $form.find('#current-account-list').val('');
        LB.syncPaymentMethods($form);
        syncDirection();
        LB.clearError(errorSlot);
    }

    $(document).on('click', '#advance-save', function () {
        var amount = parseFloat(String($('#advance-amount').val()).replace(/,/g, ''));

        if (isNaN(amount) || amount <= 0) {
            LB.showError('Enter an advance amount greater than zero', '#advance-error');
            return;
        }

        LB.post('js-request/plan/salary-advance', {
            item_id: $('#advance-item').val(),
            month: $('#advance-month').val(),
            amount: amount.toFixed(2)
        }, function () {
            LB.refreshRegion('#plan-items-region');
        }, '#advance-error', $(this));
    });

    $form.find('input[name="plan-item-direction"]').on('change', syncDirection);
    $form.on('change', '.current-account-list', function () { LB.syncPaymentMethods($form); });
    $('#plan-item-cancel').on('click', resetForm);

    $(document).on('click', '.plan-item-edit', function () {
        var item = JSON.parse($(this).attr('data-item'));
        $editing = item;

        $('#plan-item-id').val(item.id);
        $('#plan-item-name').val(item.name);
        $('#plan-item-category').val(item.category_id || '');
        $('#plan-item-amount').val(Number(item.default_amount || 0).toFixed(2));
        $('#plan-item-amount-type').val(item.amount_type);
        $('#plan-item-due-day').val(item.due_day || '');
        $form.find('input[name="plan-item-weekend"][value="' + (item.weekend_rule || 'NONE') + '"]').prop('checked', true);
        $('#plan-item-end').val(item.end_date || '');
        $('#plan-item-recurring').prop('checked', Number(item.recurring) === 1);
        $form.find('#current-account-list').val(item.account_id || '');
        LB.syncPaymentMethods($form);
        if (item.payment_method) {
            $form.find('.payment-method-list').val(item.payment_method);
            LB.paymentMethodHint($form);
        }
        $('#plan-item-to-account').val(item.to_account_id || '');
        $('#plan-item-budget').val(item.budget_id || '');
        $('#plan-item-source').val(item.source_item_id || '');
        $('#plan-item-ratio').val(item.share_ratio ? Number(item.share_ratio) : '');
        $('#plan-item-stakeholder').val(item.stakeholder || '');
        $('#plan-item-notes').val(item.notes || '');
        if (item.source_item_id || item.stakeholder || item.notes) { $('#plan-item-more').attr('open', true); }

        $form.find('.plan-item-new-only').addClass('d-none');
        $('#plan-item-form-title').text('Edit ' + item.name);
        $('#plan-item-save').text('Save Changes');
        $('#plan-item-cancel').removeClass('d-none');
        syncDirection();
        LB.clearError(errorSlot);
        $('html, body').animate({ scrollTop: $form.offset().top - 80 }, 200);
    });

    $('#plan-item-save').on('click', function () {
        var name = $.trim($('#plan-item-name').val());
        var amount = parseFloat(String($('#plan-item-amount').val()).replace(/,/g, ''));

        if (name === '') {
            LB.showError('Name is required', errorSlot);
            return;
        }

        if (isNaN(amount) || amount < 0) {
            LB.showError('The monthly amount must be zero or more', errorSlot);
            return;
        }

        var data = {
            name: name,
            category_id: $('#plan-item-category').val(),
            amount_type: $('#plan-item-amount-type').val(),
            default_amount: amount.toFixed(2),
            due_day: $('#plan-item-due-day').val(),
            weekend_rule: direction() === 'IN' ? ($form.find('input[name="plan-item-weekend"]:checked').val() || 'NONE') : 'NONE',
            end_date: $('#plan-item-end').val(),
            recurring: $('#plan-item-recurring').is(':checked') ? 1 : 0,
            account_id: $form.find('#current-account-list').val(),
            payment_method: LB.paymentMethodOf($form),
            to_account_id: direction() === 'TRANSFER' ? $('#plan-item-to-account').val() : '',
            budget_id: $('#plan-item-budget').val(),
            source_item_id: $('#plan-item-source').val(),
            share_ratio: $('#plan-item-ratio').val(),
            stakeholder: $('#plan-item-stakeholder').val(),
            notes: $('#plan-item-notes').val()
        };

        if ($editing) {
            data.item_id = $editing.id;
            LB.post('js-request/plan/item/update', data, function (response) {
                var blocked = (response.data && response.data.blocked) || [];
                if (blocked.length) { window.alert(blocked.join('\n')); }
                LB.refreshRegion('#plan-items-region', resetForm);
            }, errorSlot, $(this));
            return;
        }

        data.direction = direction();
        data.kind = $('#plan-item-kind').val();
        data.start_month = $('#plan-item-start').val();

        LB.post('js-request/plan/item/create', data, function () { LB.refreshRegion('#plan-items-region', resetForm); }, errorSlot, $(this));
    });

    $(document).on('click', '.plan-item-end', function () {
        var today = new Date();
        var suggestion = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        var endDate = window.prompt('Last date of "' + $(this).attr('data-name') + '" (YYYY-MM-DD). Unpaid months after it are skipped.', suggestion);

        if (!endDate) { return; }

        LB.post('js-request/plan/item/end', { item_id: $(this).attr('data-id'), end_date: $.trim(endDate) }, function (response) {
            var blocked = (response.data && response.data.blocked) || [];
            if (blocked.length) { window.alert(blocked.join('\n')); }
            LB.refreshRegion('#plan-items-region');
        }, '#plan-items-error', $(this));
    });

    $(document).on('click', '.plan-item-delete', function () {
        if (!window.confirm('Delete "' + $(this).attr('data-name') + '" and all its months? This can\'t be undone.')) { return; }
        LB.post('js-request/plan/item/delete', { item_id: $(this).attr('data-id') }, function () { LB.refreshRegion('#plan-items-region'); }, '#plan-items-error', $(this));
    });

    // ---- categories ----
    $(document).on('click', '.plan-category-save', function () {
        var $row = $(this).closest('tr');
        LB.post('js-request/plan/category/update', {
            category_id: $row.attr('data-category'),
            name: $.trim($row.find('.plan-category-name').val()),
            allocation_percent: $.trim($row.find('.plan-category-percent').val())
        }, function () { LB.refreshRegion('#plan-items-region'); }, '#plan-category-error', $(this));
    });

    $(document).on('click', '#plan-category-add', function () {
        LB.post('js-request/plan/category/create', {
            name: $.trim($('#plan-category-new-name').val()),
            direction: $('#plan-category-new-direction').val(),
            allocation_percent: $.trim($('#plan-category-new-percent').val())
        }, function () { LB.refreshRegion('#plan-items-region'); }, '#plan-category-error', $(this));
    });

    resetForm();
});
