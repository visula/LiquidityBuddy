if (!window.__account_group_initialized__) {
    window.__account_group_initialized__ = true;
    $(document).ready(function () {
        $(document).on('focusin','#account-group-name',function(){
            console.log('focusin');
            $('.error-message').html("&nbsp;");
        });
        
        $(document).on('click', '#create-account-group-btn', function () {
            if($('#account-group-name').val() == ''){
                $('.error-message').text('* Name is required');
                return;
            }

            $.ajax({
                type: "POST",
                url: base_url + "/js-request/account-group/create",
                data: {
                    account_group_name:$('#account-group-name').val(),
                    account_group_type:$('#account-group-type').val(),
                },
                success: function (data) {
                    window.location.reload();
                }
            });                
        });

        $(document).on('click', '.update-account-group-btn', function () {
            session_id = $(this).data('account-group-id');
            $('#update-account-group-btn').data('session-id',session_id);

            $('#account-group-name').val($('#'+session_id+' .account-group-name').html());
            $('#account-group-type').val($('#'+session_id+' .account-group-type').attr('data-account-group-type') || 'OTHER');

            $('#create-account-group-btn').addClass('d-none');
            $('#update-account-group-btn').removeClass('d-none');
        })

        $(document).on('click', '#cancel-account-group-btn', function () {            
            resetAccountGroupForm();
        })

        $(document).on('click', '#update-account-group-btn', function () {
            if($('#account-group-name').val() == ''){
                $('.error-message').text('* Name is required');
                return;
            }

            session_id = $(this).data('session-id');

            $.ajax({
                type: "POST",
                url: base_url + "/js-request/account-group/update",
                data: {
                    account_group_id:session_id,
                    account_group_name:$('#account-group-name').val(),
                    account_group_type:$('#account-group-type').val(),
                },
                success: function (data) {
                    window.location.reload();
                }
            });                
        });

        $(document).on('click', '.delete-account-group-btn', function () {
            session_id = $(this).data('account-group-id');
            if (confirm("Are you sure you want to delete this account group?")) {
                $.ajax({
                    type: "POST",
                    url: base_url + "/js-request/account-group/delete",
                    data: {
                        account_group_id: session_id,
                    },
                    success: function (data) {
                        console.log(data);
                        window.location.reload();
                    }
                });
            }
        });

        function resetAccountGroupForm(){
            $('#create-account-group-btn').removeClass('d-none');
            $('#update-account-group-btn').addClass('d-none');
            $('#account-group-name').val('');
            $('.error-message').html("&nbsp;");
        }
    });
}