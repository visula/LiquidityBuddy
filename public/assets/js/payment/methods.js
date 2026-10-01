$(document).ready(function () {
    var errorSlot = '#method-error';

    function toggleCreditLimit() {
        $('#method-credit-limit-group').toggleClass('d-none', $('#method-type').val() !== 'CREDIT_CARD');
    }

    function resetForm() {
        $('#method-id').val('');
        $('#method-account').prop('disabled', false);
        $('#method-type').val('CASH');
        $('#method-name').val('');
        $('#method-reference').val('');
        $('#method-credit-limit').val('0.00');
        $('#method-default').prop('checked', false);
        $('#method-form-title').text('Add payment method');
        $('#save-method-btn').text('Add Method');
        $('#cancel-method-btn').addClass('d-none');
        LB.clearError(errorSlot);
        toggleCreditLimit();
    }

    $('#method-type').on('change', toggleCreditLimit);
    $('#cancel-method-btn').on('click', resetForm);

    $(document).on('click', '.edit-method-btn', function () {
        var $btn = $(this);
        $('#method-id').val($btn.attr('data-id'));
        $('#method-account').val($btn.attr('data-account')).prop('disabled', true);
        $('#method-type').val($btn.attr('data-type'));
        $('#method-name').val($btn.attr('data-name'));
        $('#method-reference').val($btn.attr('data-reference'));
        $('#method-credit-limit').val(Number($btn.attr('data-credit-limit') || 0).toFixed(2));
        $('#method-default').prop('checked', $btn.attr('data-default') === 'Y');
        $('#method-form-title').text('Edit payment method');
        $('#save-method-btn').text('Save Changes');
        $('#cancel-method-btn').removeClass('d-none');
        LB.clearError(errorSlot);
        toggleCreditLimit();
        $('html, body').animate({ scrollTop: $('#method-form-title').offset().top - 20 }, 200);
    });

    $('#save-method-btn').on('click', function () {
        var id = $('#method-id').val();
        var name = $.trim($('#method-name').val());

        if (name === '') {
            LB.showError('Name is required', errorSlot);
            return;
        }

        var data = {
            name: name,
            type: $('#method-type').val(),
            reference: $('#method-reference').val(),
            credit_limit: $('#method-credit-limit').val(),
            is_default: $('#method-default').is(':checked') ? 1 : 0
        };

        if (id) {
            data.payment_method_id = id;
        } else {
            data.account_id = $('#method-account').val();
        }

        LB.post(id ? 'js-request/payment-method/update' : 'js-request/payment-method/create', data, function () {
            window.location.reload();
        }, errorSlot, $(this));
    });

    $(document).on('click', '.delete-method-btn', function () {
        if (!confirm('Remove payment method "' + $(this).attr('data-name') + '"? Past payments keep their history.')) {
            return;
        }

        LB.post('js-request/payment-method/delete', { payment_method_id: $(this).attr('data-id') }, function () {
            window.location.reload();
        }, errorSlot, $(this));
    });

    toggleCreditLimit();
});
