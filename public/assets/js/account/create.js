$(document).ready(function(){
    $('#account-name').on('focusin',function(){
        $('.error-message').html("&nbsp;");
    });
    
    $('#amount').on('focusout',function(){
        //  TODO:: currently no decimal places are allowed, need to fix this for 2 decimal places
        $(this).val().replace(/[^0-9]/g, '');
        
        if($(this).val() == ''){$(this).val(0);}

        $('#amount').val(LB.amountValue($(this).val()).toFixed(2));
        
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

        // Ticked but no card picked: say so, rather than silently creating the account without the link
        if ($('#link-card-check').is(':checked') && !$('#linked-card-list').val()) {
            $('.error-message').text('* Choose the debit card of another account, or untick the box');
            return;
        }

        LB.post('js-request/account/create', {
            groupID: $('#account-group-list').val(),
            accountName: $('#account-name').val(),
            amount: $('#amount').val(),
            method_type: $('#method-type').val(),
            method_name: $('#method-name').val(),
            method_reference: $('#method-reference').val(),
            linked_method_id: $('#link-card-check').is(':checked') ? ($('#linked-card-list').val() || '') : '',
            credit_limit: $('#credit-limit').val(),
            account_type: $typeSection.find('#account-type').val(),
            bank_name: $typeSection.find('#bank-name').val(),
            account_number: $('#account-number').val(),
            bank_product_id: $typeSection.find('#bank-product').val(),
            auto_interest: $typeSection.find('#new-auto-interest').is(':checked') ? 1 : 0
        }, function () {
            window.location.reload();
        }, '.error-message', $(this));
    });

    // ---- Account type and bank product (Add Account form only) ----
    var $typeSection = $('#account-type-section');

    function syncAccountType() {
        var type = $typeSection.find('#account-type').val();
        var isCash = type === 'CASH';
        var $product = $typeSection.find('#bank-product');

        $typeSection.find('.bank-only').toggleClass('d-none', isCash);
        $('#auto-interest-row').toggleClass('d-none', isCash || !$product.val());

        // Only products of this type (savings, current ...) can be picked
        $product.find('option[data-type]').each(function () {
            var fits = $(this).attr('data-type') === type;
            $(this).prop('hidden', !fits).prop('disabled', !fits);
        });
        if ($product.find('option:selected').prop('disabled')) { $product.val(''); }

        // Sensible default payment method for the type
        var method = { CASH: 'CASH', CREDIT_CARD: 'CREDIT_CARD', SAVINGS: 'DEBIT_CARD', CURRENT: 'DEBIT_CARD' }[type] || 'BANK_TRANSFER';
        $('#method-type').val(method).trigger('change');
        productHint();
    }

    function productHint() {
        var $option = $typeSection.find('#bank-product option:selected');
        var $hint = $('#bank-product-hint');

        var isCash = $typeSection.find('#account-type').val() === 'CASH';

        if (!$option.val()) {
            $hint.html($hint.data('default'));
            $('#auto-interest-row').addClass('d-none');
            $('#bank-name-col').toggleClass('d-none', isCash);
            $('#account-number-col').toggleClass('d-none', isCash);
            return;
        }

        // The product names the bank; interest posting only matters when it pays interest
        $('#bank-name-col').addClass('d-none');
        $typeSection.find('#bank-name').val('');
        $hint.text($option.attr('data-rate') + '% p.a.' + ($option.attr('data-fees') ? ' · Charges: ' + $option.attr('data-fees') : ''));
        $('#auto-interest-row').toggleClass('d-none', parseFloat($option.attr('data-rate')) <= 0);
    }

    if ($typeSection.length) {
        $('#bank-product-hint').data('default', $('#bank-product-hint').html());
        $typeSection.on('change', '#account-type', syncAccountType);
        $typeSection.on('change', '#bank-product', productHint);
        syncAccountType();
    }

    // Credit limit only applies to credit cards
    // Linked card: the account has no card of its own, so its own method fields are hidden
    $('#link-card-check').on('change', function () {
        var linked = $(this).is(':checked');
        $('#link-card-select-col').toggleClass('d-none', !linked);
        $('#method-type, #method-name, #method-reference').closest('.col-6').toggleClass('d-none', linked);
        if (linked) {
            $('#credit-limit-input').addClass('d-none');
        } else {
            $('#method-type').trigger('change');
        }
    });

    // Apply the checkbox state on page load, so the fields match it before the user touches anything
    $('#link-card-check').trigger('change');

    $('#method-type').on('change', function () {
        $('#credit-limit-input').toggleClass('d-none', $(this).val() !== 'CREDIT_CARD');
    });

  
    $(document).on('click', '.update-account-btn', function () {
        session_id = $(this).data('account-id');
        $('#update-account-btn').data('session-id',session_id);

        $('#account-name').val($('#'+session_id+' .account-name').html());
        $('#amount').val($('#'+session_id+' .account-balance').html().replace(/,/g, ''));
        $('#account-group-list').val($('#'+session_id+' .account-group').data('account-group-id'));
        $('#account-number').val($('#'+session_id).attr('data-account-number') || '');
        $('#account-number-col').removeClass('d-none');

        $('#create-account-btn').addClass('d-none');
        $('#update-account-btn').removeClass('d-none');
        $('#default-method-section').addClass('d-none');
        $('#account-type-section').addClass('d-none');
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
                account_balance:$('#amount').val(),
                account_number:$('#account-number').val(),
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
                    window.location.reload();
                }
            });
        }
    });

    function resetAccountForm(){
        $('#create-account-btn').removeClass('d-none');
        $('#update-account-btn').addClass('d-none');
        $('#default-method-section').removeClass('d-none');
        $('#account-type-section').removeClass('d-none');
        $('#account-name').val('');
        $('#account-number').val('');
        $('#link-card-check').prop('checked', false).trigger('change');
        $('#amount').val(0);
        $('#account-group-list').val($('#account-group-list').data('default-value'));
        $('.error-message').html("&nbsp;");
        $('#update-account-btn').data('session-id','');

    }
});