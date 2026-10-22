$(document).ready(function(){
    // currentMenuActive('main-menu');

    // $('#current-account-list, #to-account-list').on('change',function(){
    //     if($(this).hasClass('current-account-list')){
    //         account_list_id = 'to-account-list';
    //         currnet_running_balance_id = 'current-running-balance';
    //     }else if($(this).hasClass('to-account-list')){
    //         account_list_id = 'current-account-list';
    //         currnet_running_balance_id = 'to-running-balance';
    //     }

    //     var id = $('.active.select-transction-type').data('transaction-type');

    //     $(`#${id} #${currnet_running_balance_id}`).val($(this).find('option:selected').data('running-balance'));
    //     $(`#${id} #${account_list_id} .d-none`).removeClass('d-none');
    //     $(`#${account_list_id} .${$(this).val()}`).addClass('d-none');
    // });

    // $('.select-transction-type').on('click',function () {
    //     if($(this).hasClass('active')){
    //        return; 
    //     }
    //     transid = $(this).data('transaction-type');

    //     if(transid == 'internal'){
    //         $('#schedule-payment-section').addClass('d-none');
    //         $('#schedule-payment').addClass('d-none');
    //     }else{
    //         $('#schedule-payment-section').removeClass('d-none');
    //         if($('#schedule-payment-checkbox').is(':checked')){
    //             $('#schedule-payment').removeClass('d-none');
    //         }
    //     }
        
    //     $('.transaction-type-list').each(function() {
    //         // Your code here, using `this` to refer to the current element
    //         if(!$(this).hasClass('d-none')){
    //             $(this).addClass('d-none');
    //         }
    //     });

    //     $('.active').removeClass('active');
    //     $(this).addClass('active');
    //     $('#'+transid).removeClass('d-none');

    //     $('#common-error').text('');    
    //     $('#common-error').addClass('d-none');
    // });


    // $(document).on('input','#amount',function() {
    //      var amount = $(this).val().replace(/[^\d.]/g, '');

    //     // Remove leading zeros
    //     amount = amount.replace(/^0+(?!\.|$)/, '');

    //     // Allow only one decimal point
    //     if (amount.indexOf('.') !== -1) {
    //         // Remove any additional decimal points
    //         amount = amount.substring(0, amount.indexOf('.') + 1) + amount.substring(amount.indexOf('.') + 1).replace(/\./g, '');
    //     }

    //     $(this).val(amount);
    // }).on('focusout','#amount',function() {
    //     var amount = 0;
    //     amount = ($(this).val() != '') ? parseFloat($(this).val()): 0;

    //     $('#common-error').text('');    
    //     $('#common-error').addClass('d-none');

    //     $(this).val(amount.toFixed(2));
    // });

    // $('#transferred-btn').on('click',function(){
    //     runningBalance = Number($('#internal #transaction-type').val() == 'expense') ? $('#internal #current-running-balance').val().replace(',','').replace('.00','') : $('#internal #to-running-balance').val().replace(',','').replace('.00','');
        
    //     if(runningBalance < Number($('#amount').val())){
    //         return;
    //     }
    
    //     $.ajax({
    //         type: "POST",
    //         url: "js-request/account/transferred",
    //         data: {
    //             amount:($('#internal #amount').val() == '')?0:$('#internal #amount').val(),
    //             from_account:$('#internal #current-account-list').val(),
    //             to_account:$('#internal #to-account-list').val(),
    //             budget:$('#internal #budget-list').val(),
    //         },
    //         success: function (response) {
    //             apiresponse = $.parseJSON(response);
    //             if(apiresponse.success){
    //                 // $('#current-running-balance').val(apiresponse.data.fromTransactionChanges);
    //                 // $('#to-running-balance').val(apiresponse.data.toTransactionChanges);

    //             }else{
    //                 $('#common-error').removeClass('d-none');
    //                 $('#common-error').text('* '+apiresponse.message);    
    //             }
    //         },
    //         error: function (xhr, ajaxOptions, thrownError) {
    //         }

    //     });

    //     $('#to-transaction-type').val();
    //     $('#to-running-balance').val();
    // });
});