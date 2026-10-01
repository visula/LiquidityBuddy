$(document).ready(function(){

    $('#income input[name="income-type"]').on('click',function(){
        var selection = $(this).val();
        if(selection == 'one-time'){
            $('#income #initate-date').addClass('d-none');
            $('#income #until-period').addClass('d-none');
            $('#income #until-date').addClass('d-none');
            $('#income #first-payment-section').addClass('d-none');
        }else if(selection == 'monthly'){
            $('#income #initate-date').removeClass('d-none');
            $('#income #until-period').addClass('d-none');
            $('#income #until-date').addClass('d-none');
            $('#income #first-payment-section').removeClass('d-none');
        }else{
            $('#income #initate-date').removeClass('d-none');
            $('#income #until-period').removeClass('d-none');
            $('#income #until-date').removeClass('d-none');
            $('#income #first-payment-section').removeClass('d-none');
        }
    });

    $('#income input[name="first-payment"]').on('click',function(){
        if($(this).is(':checked')){
            var today = new Date().toISOString().slice(0, 10);
            $('#start-date').val(today);
            $('#start-date').addClass('disabled').attr('disabled',true);
            // $('#first-payment-section').removeClass('d-none');
        }else{
            $('#start-date').removeClass('disabled').attr('disabled',false);
            // $('#first-payment-section').addClass('d-none');
        }
    })

    $('#income #payment-btn').on('click',function(){
        console.log('clicked');
        var amount = $('#income #amount').val();
        var description = $('#income #description').val();
        console.log(amount);
        if(amount == '' || parseFloat(amount) <= 0){
            LB.showError('Please enter amount');
            return;
        }

        console.log(description);
        if(description == ''){
            LB.showError('Please enter description');
            return;
        }

        var type = $('#income input[name="income-type"]:checked').val();
        var collectionPlan = [];
        console.log(collectionPlan);
        if(type == 'till'){
            var date = $('#income #start-date').val();
            var planType = $('#until-plan-type').val();
            var paymentPeriod = $('#until-payment-period').val();
            collectionPlan = {
                schedule_type: planType,
                period: paymentPeriod,
                start_date: date,
                make_initial_payment : $('#first-payment').is(':checked')
            }
            
        }else if(type == 'monthly'){
            var date = $('#income #start-date').val();
            collectionPlan = {
                start_date: date,
                schedule_type: 'C',
                make_initial_payment : $('#first-payment').is(':checked')
            }
        }

        var $income = $('#income');

        LB.post('js-request/payment/income', {
            amount: amount,
            description: description,
            account_id: $income.find('#current-account-list').val(),
            payment_method: LB.paymentMethodOf($income),
            reference: $income.find('#reference').val(),
            budget_id: $income.find('#budget-list').val(),
            collection_plan: collectionPlan,
            type: type
        }, function () {
            window.location.reload();
        }, null, $(this));
    });
});