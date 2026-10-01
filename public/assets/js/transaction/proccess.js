if (!window.__process_initialized__) {
    window.__process_initialized__ = true;
    $(document).ready(function () {
        // Every form has its own account selects (same ids in each tab), so bind by
        // class and scope to the form the select lives in.
        $(document).on('change', '.current-account-list, .to-account-list', function () {
            var $container = $(this).closest('.transaction-type-list');
            var isFrom = $(this).hasClass('current-account-list');
            var otherList = isFrom ? '.to-account-list' : '.current-account-list';
            var balanceField = isFrom ? '#current-running-balance' : '#to-running-balance';

            $container.find(balanceField).val($(this).find('option:selected').data('running-balance'));
            $container.find(otherList + ' option').removeClass('d-none');
            $container.find(otherList + ' option[value="' + $(this).val() + '"]').addClass('d-none');

            if (isFrom) {
                LB.syncPaymentMethods($container);
            }
        });

        $('.select-transction-type').on('click',function () {
            if($(this).hasClass('active')){
               return;
            }
            transid = $(this).data('transaction-type');

            if(transid == 'internal' || transid == 'income' || transid == 'dued'){
                $('#schedule-payment-section').addClass('d-none');
                $('#schedule-payment').addClass('d-none');
            }else{
                $('#schedule-payment-section').removeClass('d-none');
                if($('#schedule-payment-checkbox').is(':checked')){
                    $('#schedule-payment').removeClass('d-none');
                }
            }

            $('.transaction-type-list').each(function() {
                if(!$(this).hasClass('d-none')){
                    $(this).addClass('d-none');
                }
            });

            $('#transaction-type-list .active').removeClass('active');
            $(this).addClass('active');
            $('#'+transid).removeClass('d-none');

            LB.clearError();
        });


        $(document).on('input','#amount',function() {
            if ($(this).prop('readonly')) { return; }
            var amount = $(this).val().replace(/[^\d.]/g, '');

            // Remove leading zeros
            amount = amount.replace(/^0+(?!\.|$)/, '');

            // Allow only one decimal point
            if (amount.indexOf('.') !== -1) {
                amount = amount.substring(0, amount.indexOf('.') + 1) + amount.substring(amount.indexOf('.') + 1).replace(/\./g, '');
            }

            $(this).val(amount);
        }).on('focusout','#amount',function() {
            var amount = ($(this).val() != '') ? parseFloat($(this).val()) : 0;

            LB.clearError();
            $(this).val(amount.toFixed(2));
        });

        $('#transferred-btn').on('click',function(){
            var $internal = $('#internal');
            var amount = parseFloat($internal.find('#amount').val()) || 0;

            if (amount <= 0) {
                LB.showError('The transfer amount must be greater than zero.');
                return;
            }

            LB.post('js-request/account/transferred', {
                amount: amount.toFixed(2),
                from_account: $internal.find('#current-account-list').val(),
                to_account: $internal.find('#to-account-list').val(),
                budget: $internal.find('#budget-list').val(),
                payment_method: LB.paymentMethodOf($internal),
                description: $internal.find('#description').val(),
                reference: $internal.find('#reference').val()
            }, function () {
                window.location.reload();
            }, null, $(this));
        });
    });
}
