$(document).ready(function () {
    var $kind = $('#account-kind');
    var $bankType = $('#account-type');
    var $product = $('#bank-product');
    var $parent = $('#parent-account');
    var editingID = '';

    function kind() { return $kind.val(); }
    function isBank() { return kind() === 'BANK'; }
    function cardMode() { return $('input[name="card-mode"]:checked').val() || 'none'; }

    $('#account-name').on('focusin', function () {
        $('.error-message').html('&nbsp;');
    });

    $('#amount').on('focusout', function () {
        if ($(this).val() == '') { $(this).val(0); }
        $('#amount').val(LB.amountValue($(this).val()).toFixed(2));

        if ($(this).val() != '' && $(this).val() != '0.00') {
            $('.error-message').html('&nbsp;');
        }
    });

    // ---- Kind: bank shows the bank details and card; cash and other show neither ----
    function syncKind() {
        var bank = isBank();
        $('#bank-section, #card-section').toggleClass('d-none', !bank);
        $('#account-number-col').toggleClass('d-none', !bank);
        syncProduct();
        syncCard();
    }

    // The full product list (placeholder + one optgroup per bank), kept so it can be filtered again and again
    var allProducts = null;

    function normalise(text) {
        return String(text || '').trim().toLowerCase();
    }

    // Products are narrowed by the bank typed in (when it names a known bank) and then by the bank product type
    function syncProduct() {
        if (allProducts === null) { allProducts = $product.children().clone(); }

        var type = $bankType.val();
        var bank = normalise($('#bank-name').val());
        var bankKnown = allProducts.filter('optgroup').toArray().some(function (group) { return normalise(group.label) === bank; });
        var $list = $();

        allProducts.each(function () {
            if (this.tagName === 'OPTION') {
                $list = $list.add($(this).clone());
                return;
            }

            if (bankKnown && normalise(this.label) !== bank) { return; }

            var $group = $('<optgroup></optgroup>').attr('label', this.label);
            $(this).children('option').filter(function () { return $(this).attr('data-type') === type; })
                .each(function () { $group.append($(this).clone()); });

            if ($group.children().length) { $list = $list.add($group); }
        });

        var previous = $product.val();
        $product.empty().append($list);
        $product.val($product.find('option[value="' + previous + '"]').length ? previous : '');
        productHint();
    }

    // A chosen product names its bank and may pay interest
    function productHint() {
        var $option = $product.find('option:selected');
        var $hint = $('#bank-product-hint');

        if (!$option.val()) {
            $hint.html($product.find('optgroup').length ? $hint.data('default') : 'No bank product matches this bank and type. Choose "Not listed / choose later" or change them.');
            $('#auto-interest-row').addClass('d-none');
            return;
        }

        $hint.text($option.attr('data-rate') + '% p.a.' + ($option.attr('data-fees') ? ' · Charges: ' + $option.attr('data-fees') : ''));
        $('#auto-interest-row').toggleClass('d-none', parseFloat($option.attr('data-rate')) <= 0);
    }

    // ---- Card: none, a card already in the system, or a new card ----
    function syncCard() {
        var mode = cardMode();
        $('#card-new-col').toggleClass('d-none', !(isBank() && mode === 'new'));
        $('#credit-limit-input').toggleClass('d-none', !(mode === 'new' && $('#method-type').val() === 'CREDIT_CARD'));
    }

    $kind.on('change', syncKind);
    $bankType.on('change', syncProduct);
    $('#bank-name').on('input change', syncProduct);

    // Picking a product sets the bank name to the product's bank
    $product.on('change', function () {
        var bank = $product.find('option:selected').closest('optgroup').attr('label') || '';
        if (bank) { $('#bank-name').val(bank); }
        productHint();
    });
    $('input[name="card-mode"]').on('change', syncCard);
    $('#method-type').on('change', syncCard);

    $('#bank-product-hint').data('default', $('#bank-product-hint').html());
    syncKind();

    // The form's values, the same for creating and for updating
    function collect() {
        var bank = isBank();
        var data = {
            groupID: $('#account-group-list').val(),
            accountName: $('#account-name').val(),
            amount: $('#amount').val(),
            account_kind: kind(),
            // cash and other accounts have no bank details, and get a plain default method
            account_type: bank ? $bankType.val() : kind(),
            method_type: kind() === 'CASH' ? 'CASH' : 'BANK_TRANSFER',
            method_name: '',
            method_reference: '',
            credit_limit: '0',
            bank_name: bank ? $('#bank-name').val() : '',
            bank_product_id: bank ? $product.val() : '',
            account_number: bank ? $('#account-number').val() : '',
            parent_account_id: bank ? $parent.val() : '',
            auto_interest: bank && $('#new-auto-interest').is(':checked') ? 1 : 0,
            card_mode: bank ? cardMode() : 'none'
        };

        if (bank && cardMode() === 'new') {
            data.method_type = $('#method-type').val();
            data.method_name = $('#method-name').val();
            data.method_reference = $('#method-reference').val();
            data.credit_limit = $('#credit-limit').val();
        }

        return data;
    }

    function validate() {
        if ($('#account-name').val() == '') {
            $('.error-message').text('* Name is required');
            return false;
        }

        if ($('#amount').val() == '') {
            $('.error-message').text('* Amount is required');
            return false;
        }

        return true;
    }

    // ---- Create ----
    $('#create-account-btn').on('click', function () {
        if (!validate()) { return; }

        LB.post('js-request/account/create', collect(), function () {
            window.location.reload();
        }, '.error-message', $(this));
    });

    // ---- Edit an account from the list: load its details into the same sections as creation ----
    $(document).on('click', '.update-account-btn', function () {
        var accountID = $(this).data('account-id');

        LB.post('js-request/account/details', { account_id: accountID }, function (response) {
            fillForm(response.data);
            editingID = accountID;
            $('#create-account-btn').addClass('d-none');
            $('#update-account-btn').removeClass('d-none');
            $('html, body').animate({ scrollTop: $('#account-name').offset().top - 80 }, 200);
        }, '.error-message', $(this));
    });

    function fillForm(d) {
        $('#account-name').val(d.name);
        $('#account-group-list').val(d.group_id);
        $('#amount').val(Number(d.balance || 0).toFixed(2));
        $('#account-number').val(d.account_number || '');

        // bank and type go in before the kind sync, so the product list is filtered for this account
        $kind.val(d.kind);
        $bankType.val(d.kind === 'BANK' ? d.account_type : 'SAVINGS');
        $('#bank-name').val(d.bank_name || '');
        syncKind();

        $product.val(d.bank_product_id || '');
        productHint();
        $('#new-auto-interest').prop('checked', !!d.auto_interest);

        // An account can't be its own parent, and one with sub-accounts stays a main account
        $parent.find('option').each(function () {
            var self = $(this).val() === d.account_id;
            $(this).prop('hidden', self).prop('disabled', self);
        });
        $parent.val(d.parent_account_id || '').prop('disabled', !!d.has_sub_accounts);
        $kind.prop('disabled', !!d.has_sub_accounts);
        $('#parent-note').text(d.has_sub_accounts ? 'This account has sub-accounts, so it stays a main account.' : '').toggleClass('d-none', !d.has_sub_accounts);

        // Card: show the current one, and start from "no change"
        $('input[name="card-mode"][value="none"]').prop('checked', true);
        var current = d.own_card
            ? 'Current card: ' + d.own_card.name + ' (' + String(d.own_card.type).replace('_', ' ').toLowerCase() + '). Pick another below to add it.'
            : 'No card on this account.';
        $('#card-current small').text(current);
        $('#card-current').removeClass('d-none');
        syncCard();
    }

    $(document).on('click', '#cancel-account-btn', function () {
        resetAccountForm();
    });

    // ---- Update: the same fields as creation, plus the account's id ----
    $(document).on('click', '#update-account-btn', function () {
        if (!validate()) { return; }

        var data = collect();
        data.account_id = editingID;
        data.account_name = data.accountName;
        data.account_group = data.groupID;
        data.account_balance = data.amount;
        data.account_number = $('#account-number').val();

        LB.post('js-request/account/update', data, function () {
            window.location.reload();
        }, '.error-message', $(this));
    });

    // Move every sub-account's balance into this main account, one internal transfer each
    $(document).on('click', '.settle-main-btn', function () {
        var $button = $(this);
        var name = $button.data('account-name');

        if (!window.confirm('Move the balance of every sub-account of "' + name + '" into it? Each move is recorded as an internal transfer.')) { return; }

        LB.post('js-request/account/settle-main', { account_id: $button.data('account-id') }, function (response) {
            var data = response.data || {};
            var lines = (data.moved || []).map(function (m) {
                return m.name + ': ' + LB.money(m.amount) + (m.fee > 0 ? ' (bank charge ' + LB.money(m.fee) + ')' : '');
            });
            if ((data.skipped || []).length) {
                lines.push('Not moved:');
                lines = lines.concat(data.skipped);
            }
            window.alert(lines.length ? lines.join('\n') : 'No sub-account has a balance to settle.');
            window.location.reload();
        }, '.error-message', $button);
    });

    $(document).on('click', '.delete-account-btn', function () {
        var session_id = $(this).data('account-id');
        var account_balance = $('#' + session_id + ' .account-balance').html();

        if (account_balance != 0) {
            alert('Cannot delete account with balance');
            return;
        }
        if (confirm('Are you sure you want to delete this account?')) {
            $.ajax({
                type: 'POST',
                url: base_url + '/js-request/account/delete',
                data: { account_id: session_id },
                success: function () {
                    window.location.reload();
                }
            });
        }
    });

    function resetAccountForm() {
        editingID = '';
        $('#create-account-btn').removeClass('d-none');
        $('#update-account-btn').addClass('d-none');
        $('#account-name, #account-number, #bank-name, #method-name, #method-reference').val('');
        $('#amount').val(0);
        $('#account-group-list').val($('#account-group-list').data('default-value'));
        $kind.val('CASH').prop('disabled', false);
        $bankType.val('SAVINGS');
        $product.val('');
        $parent.val('').prop('disabled', false);
        $parent.find('option').prop('hidden', false).prop('disabled', false);
        $('#parent-note').addClass('d-none').text('');
        $('#card-none').prop('checked', true);
        $('#card-current').addClass('d-none');
        $('#method-type').val('DEBIT_CARD');
        $('#credit-limit').val('0.00');
        $('#new-auto-interest').prop('checked', false);
        $('.error-message').html('&nbsp;');
        syncKind();
    }
});
