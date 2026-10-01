$(document).ready(function(){

    $('#external input[name="expense-type"]').on('click',function(){
        var selection = $(this).val();
        if(selection == 'charity' || selection == 'other'){
            $('#external #description-input').removeClass('d-none');
            $('#external #travel-mode-input').addClass('d-none');
            $('#external #from-location-input').addClass('d-none');
            $('#external #to-location-input').addClass('d-none');

        }else{
            $('#external #description-input').addClass('d-none');
            $('#external #travel-mode-input').removeClass('d-none');
            $('#external #from-location-input').removeClass('d-none');
            $('#external #to-location-input').removeClass('d-none');
        }
    })

    $('#external #payment-btn').on('click',function(){
        var $external = $('#external');
        var external_pay_type = 'O';
        var description = $external.find('#description').val();
        var external_data_list = {};
        var today = new Date().toISOString().slice(0, 10);
        var amount = parseFloat($external.find('#amount').val()) || 0;

        if (amount <= 0) {
            LB.showError('The payment amount must be greater than zero.');
            return;
        }

        if($external.find('input[name="expense-type"]:checked').val() == 'travel'){
            external_pay_type = 'T';
            external_data_list = {
                travel_mode: $external.find('#travel-mode').val(),
                from_location: $external.find('#from-location').val(),
                to_location: $external.find('#to-location').val()
            };

            description = [external_data_list.travel_mode, external_data_list.from_location, external_data_list.to_location]
                .filter(function (part) { return part; }).join(' - ');
        }else if($external.find('input[name="expense-type"]:checked').val() == 'charity'){
            external_pay_type = 'C';
        }

        var scheduled_info = '';

        if($('#schedule-payment-checkbox').is(':checked')){
            scheduled_info = {
                start_date : $('#payment-plan-start-date').val() || today,
                period: ($('#payment-plan-type').val() != 'I') ? $('#scheduled-payment-period').val() : 1,
                schedule_type: $('#payment-plan-type').val() || 'I',
                make_initial_payment: $('#init-payment').is(':checked') ? 1 : 0
            };
        }

        LB.post('js-request/payment/expense', {
            amount: amount.toFixed(2),
            description: description,
            current_account: $external.find('#current-account-list').val(),
            payment_method: LB.paymentMethodOf($external),
            reference: $external.find('#reference').val(),
            budget: $external.find('#budget-list').val(),
            scheduled_info: scheduled_info,
            external_pay_type: external_pay_type,
            external_data_list: external_data_list
        }, function () {
            window.location.reload();
        }, null, $(this));
    });
});