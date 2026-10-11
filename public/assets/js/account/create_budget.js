if (!window.__budget_initialized__) {
    window.__budget_initialized__ = true;
    $(document).ready(function () {
        $(document).on('focusin','#budget-name',function(){
            console.log('focusin');
            $('.error-message').html("&nbsp;");
        });
        
        $(document).on('focusout','#budget-amount',function(){
            //  TODO:: currently no decimal places are allowed, need to fix this for 2 decimal places
            $(this).val().replace(/[^0-9]/g, '');
            
            if($(this).val() == ''){$(this).val(0);}

            $('#budget-amount').val(parseFloat($(this).val()).toFixed(2));
            
            if($(this).val() != '' && $(this).val() != '0.00'){
                $('.error-message').html("&nbsp;");
            }
        });

        $(document).on('click', '#create-budget-btn', function () {
                if($('#budget-name').val() == ''){
                    $('.error-message').text('* Name is required');
                    return;
                }

                LB.post('js-request/budget/create', {
                    budgetName: $('#budget-name').val(),
                    budgetPlan: $('#budget-plan').val(),
                    budgetAmount: $('#budget-amount').val()
                }, function () {
                    window.location.reload();
                }, '.error-message', $(this));
        });

        $(document).on('click', '.update-budget-btn', function () {
            session_id = $(this).data('budget-id');
            $('#update-budget-btn').data('session-id',session_id);

            $('#budget-name').val($('#'+session_id+' .budget-name').html());
            $('#budget-amount').val($('#'+session_id+' .budget-amount').text().replace(/[^0-9.]/g, ''));
            $('#budget-plan').val($('#'+session_id+' .budget-period').data('budget-period'));

            $('#create-budget-btn').addClass('d-none');
            $('#update-budget-btn').removeClass('d-none');
        })

        $(document).on('click', '#cancel-budget-btn', function () {            
            resetBudgetForm();
        })

        $(document).on('click', '#update-budget-btn', function () {
            if($('#budget-name').val() == ''){
                $('.error-message').text('* Name is required');
                return;
            }

            session_id = $(this).data('session-id');

            LB.post('js-request/budget/update', {
                budget_id: session_id,
                budget_name: $('#budget-name').val(),
                budget_plan: $('#budget-plan').val(),
                budget_amount: $('#budget-amount').val()
            }, function () {
                window.location.reload();
            }, '.error-message', $(this));
        });

        $(document).on('click', '.delete-budget-btn', function () {
            session_id = $(this).data('budget-id');
            if (confirm("Are you sure you want to delete this budget?")) {
                $.ajax({
                    type: "POST",
                    url: base_url + "/js-request/budget/delete",
                    data: {
                        budget_id: session_id,
                    },
                    success: function (data) {
                        console.log(data);
                        window.location.reload();
                    }
                });
            }
        });

        function resetBudgetForm(){
            $('#create-budget-btn').removeClass('d-none');
            $('#update-budget-btn').addClass('d-none');
            $('#budget-name').val('');
            $('#budget-amount').val(0);
            $('#budget-plan').val('M');
            $('.error-message').html("&nbsp;");
        }
    });
}