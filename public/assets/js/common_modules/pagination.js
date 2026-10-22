if (!window.__pagination_initialized__) {
    window.__pagination_initialized__ = true;
    $(document).ready(function () {
        // Unbind and bind events to avoid duplication
        $(document)
            .off('click', '.page-list')
            .on('click', '.page-list', function () {
                const transaction_type = $(this).parent().parent().attr('pagination-type');
                
                $('.' + transaction_type + ' .page-list').parent().removeClass('active');
                $(this).parent().addClass('active');

                if ($(this).parent().hasClass('first')) {
                    $(this).closest('ul').find('.previous-page').parent().addClass('disabled').attr('disabled', true);
                    $(this).closest('ul').find('.next-page').parent().removeClass('disabled').attr('disabled', false);
                } else if ($(this).parent().hasClass('last')) {
                    $(this).closest('ul').find('.previous-page').parent().removeClass('disabled').attr('disabled', false);
                    $(this).closest('ul').find('.next-page').parent().addClass('disabled').attr('disabled', true);
                } else {
                    $(this).closest('ul').find('.previous-page').parent().removeClass('disabled').attr('disabled', false);
                    $(this).closest('ul').find('.next-page').parent().removeClass('disabled').attr('disabled', false);
                }

                // Hide all actions
                $('.t-row-' + transaction_type + '-action').addClass('d-none');

                // Show selected action
                $('.t-row-' + transaction_type + '-action[data-page-no="' + $(this).attr('id') + '"]').removeClass('d-none');
            });

        $(document)
            .off('click', '.previous-page')
            .on('click', '.previous-page', function () {
                const transaction_type = $(this).parent().parent().attr('pagination-type');

                $('.' + transaction_type + ' .page-item.active').prev().find('.page-list').click();
            });

        $(document)
            .off('click', '.next-page')
            .on('click', '.next-page', function () {
                const transaction_type = $(this).parent().parent().attr('pagination-type');

                $('.' + transaction_type + ' .page-item.active').next().find('.page-list').click();
            });
    });
}
