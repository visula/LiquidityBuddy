$(document).ready(function () {
    var $form = $('#recurring-form');
    var errorSlot = '#recurring-error';

    function direction() {
        return $form.find('input[name="recurring-direction"]:checked').val();
    }

    function resetForm() {
        $('#recurring-id').val('');
        $('#recurring-description, #recurring-amount, #recurring-end-date').val('');
        $form.find('.recurring-new-only').removeClass('d-none');
        $('#recurring-form-title').text('Add recurring transaction');
        $('#save-recurring-btn').text('Add Recurring');
        $('#cancel-recurring-btn').addClass('d-none');
        LB.clearError(errorSlot);
    }

    $form.find('input[name="recurring-direction"]').on('change', function () {
        $('#recurring-account-label').text(direction() === 'IN' ? 'Receive into account' : 'Pay from account');
    });

    $form.on('change', '.current-account-list', function () {
        LB.syncPaymentMethods($form);
    });

    $('#cancel-recurring-btn').on('click', resetForm);

    $(document).on('click', '.recurring-edit-btn', function () {
        var $btn = $(this);
        $('#recurring-id').val($btn.attr('data-id'));
        $('#recurring-description').val($btn.attr('data-description'));
        $('#recurring-amount').val(Number($btn.attr('data-amount') || 0).toFixed(2));
        $('#recurring-end-date').val($btn.attr('data-end-date'));
        $form.find('.recurring-new-only').addClass('d-none');
        $('#recurring-form-title').text('Edit recurring transaction');
        $('#save-recurring-btn').text('Save Changes');
        $('#cancel-recurring-btn').removeClass('d-none');
        LB.clearError(errorSlot);
    });

    $('#save-recurring-btn').on('click', function () {
        var id = $('#recurring-id').val();
        var description = $.trim($('#recurring-description').val());
        var amount = parseFloat(String($('#recurring-amount').val()).replace(/,/g, '')) || 0;

        if (description === '') {
            LB.showError('Description is required', errorSlot);
            return;
        }

        if (amount <= 0) {
            LB.showError('The amount must be greater than zero', errorSlot);
            return;
        }

        if (id) {
            LB.post('js-request/recurring/update', {
                recurring_id: id,
                action: 'edit',
                description: description,
                amount: amount.toFixed(2),
                end_date: $('#recurring-end-date').val()
            }, function () { window.location.reload(); }, errorSlot, $(this));
            return;
        }

        LB.post('js-request/recurring/create', {
            direction: direction(),
            description: description,
            amount: amount.toFixed(2),
            periodic: $('#recurring-periodic').val(),
            start_date: $('#recurring-start-date').val(),
            end_date: $('#recurring-end-date').val(),
            account_id: $form.find('.current-account-list').val(),
            payment_method: LB.paymentMethodOf($form),
            budget_id: $('#recurring-budget').val()
        }, function () { window.location.reload(); }, errorSlot, $(this));
    });

    $(document).on('click', '.recurring-action-btn', function () {
        var action = $(this).attr('data-action');

        if (action === 'delete' && !confirm('Remove recurring transaction "' + $(this).attr('data-name') + '"? Payments already posted stay in your history.')) {
            return;
        }

        LB.post('js-request/recurring/update', {
            recurring_id: $(this).attr('data-id'),
            action: action
        }, function () { window.location.reload(); }, '#recurring-list-error', $(this));
    });
});
