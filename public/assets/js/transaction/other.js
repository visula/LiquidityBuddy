$(document).ready(function(){
    $(document).on('focusout','#amount',function() {
        amountBreakDown();
    });

    $('#schedule-payment-checkbox').on('change', function(){
        if ($(this).is(':checked')) {
            $('#schedule-payment').removeClass('d-none');
            amountBreakDown();
        }else{
            $('#schedule-payment').addClass('d-none');
            $('#purchase #partialamount').val('0.00');
        }
    });

    $('#int-rate').on('change', function(){
        if ($(this).is(':checked')) {
            $('#interest-rate').removeClass('d-none');
        }else{
            $('#interest-rate').addClass('d-none');
        }
    });

    $('#init-payment').on('change', function(){
        if ($(this).is(':checked')) {
            var today = new Date().toISOString().slice(0, 10);
            $('#payment-plan-start-date').val(today);
            $('#payment-plan-start-date').addClass('disabled').attr('disabled',true);
        }else{
            $('#payment-plan-start-date').removeClass('disabled').attr('disabled',false);
        }
    });

    $(document).on('focusout','#scheduled-payment-period',function(){
        amountBreakDown();
    });

    $('#claim-item-cost').on('change', function(){
        if ($(this).is(':checked')) {
            $('#claim-stackholder').removeClass('d-none');
        }else{
            $('#claim-stackholder').addClass('d-none');
        }
    });

    function amountBreakDown()
    {
        var id = $('.active.select-transction-type').data('transaction-type');

        if($(`#${id} #amount`).val() == ''){
            $(`#${id} #amount`).val('0.00');
        }

        if($(`#scheduled-payment-period`).val() == 0 || $(`#scheduled-payment-period`).val() == ''){
            $(`#scheduled-payment-period`).val(1);
            console.log('Test');
        }

        partial_amount = (LB.amountValue($(`#${id} #amount`).val()) / $(`#scheduled-payment-period`).val());

        console.log($(`#${id} #amount`).val());
        console.log($(`#scheduled-payment-period`).val());
        console.log(partial_amount);

        $('#partialamount').val(partial_amount.toFixed(2));

    }
});