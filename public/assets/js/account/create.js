$(document).ready(function(){
    $('#account-name').on('focusin',function(){
        $('.error-message').html("&nbsp;");
    });
    
    $('#amount').on('focusout',function(){
        //  TODO:: currently no decimal places are allowed, need to fix this for 2 decimal places
        $(this).val().replace(/[^0-9]/g, '');
        
        if($(this).val() == ''){$(this).val(0);}

        $('#amount').val(parseFloat($(this).val()).toFixed(2));
        
        if($(this).val() != '' && $(this).val() != '0.00'){
            $('.error-message').html("&nbsp;");
        }
    });

    // create account click
    $('#create-account-btn').on('click',function(){
        if($('#account-name').val() == ''){
            $('.error-message').text('* Name is required');
            return;
        }

        if($('#amount').val() == ''){
            $('.error-message').text('* Amount is required');
            return;
        }

        LB.post('js-request/account/create', {
            groupID: $('#account-group-list').val(),
            accountName: $('#account-name').val(),
            amount: $('#amount').val(),
            method_type: $('#method-type').val(),
            method_name: $('#method-name').val(),
            method_reference: $('#method-reference').val(),
            credit_limit: $('#credit-limit').val()
        }, function () {
            window.location.reload();
        }, '.error-message', $(this));
    });

    // Credit limit only applies to credit cards
    $('#method-type').on('change', function () {
        $('#credit-limit-input').toggleClass('d-none', $(this).val() !== 'CREDIT_CARD');
    });

  
    $(document).on('click', '.update-account-btn', function () {
        session_id = $(this).data('account-id');
        $('#update-account-btn').data('session-id',session_id);

        $('#account-name').val($('#'+session_id+' .account-name').html());
        $('#amount').val($('#'+session_id+' .account-balance').html());
        $('#amount').addClass('disabled').attr('disabled',true);
        $('#account-group-list').val($('#'+session_id+' .account-group').data('account-group-id'));

        $('#create-account-btn').addClass('d-none');
        $('#update-account-btn').removeClass('d-none');
        $('#default-method-section').addClass('d-none');
    })

    $(document).on('click', '#cancel-account-btn', function () {            
        resetAccountForm();
    })

    $(document).on('click', '#update-account-btn', function () {
        if($('#account-name').val() == ''){
            $('.error-message').text('* Name is required');
            return;
        }

        session_id = $(this).data('session-id');

        $.ajax({
            type: "POST",
            url: base_url + "/js-request/account/update",
            data: {
                account_id:session_id,
                account_name:$('#account-name').val(),
                account_group:$('#account-group-list').val(),
            },
            success: function (data) {
                // window.location.reload();
            }
        });                
    });

    $(document).on('click', '.delete-account-btn', function () {
        session_id = $(this).data('account-id');
        account_balance = $('#'+session_id+' .account-balance').html();

        if(account_balance != 0){
            alert('Cannot delete account with balance');
            return;
        }
        if (confirm("Are you sure you want to delete this account?")) {
            $.ajax({
                type: "POST",
                url: base_url + "/js-request/account/delete",
                data: {
                    account_id: session_id,
                },
                success: function (data) {
                    console.log(data);
                    window.location.reload();
                }
            });
        }
    });

    function resetAccountForm(){
        console.log($('#account-group-list').data('default-value'));
        $('#create-account-btn').removeClass('d-none');
        $('#update-account-btn').addClass('d-none');
        $('#default-method-section').removeClass('d-none');
        $('#account-name').val('');
        $('#amount').val(0);
        $('#account-group-list').val($('#account-group-list').data('default-value'));
        $('.error-message').html("&nbsp;");
        $('#update-account-btn').data('session-id','');

    }
});