/*
 * Internal tab: deposit (cash -> bank), withdrawal (bank -> cash) or transfer.
 * Asks the server what the movement is, which channels it can use and what
 * the bank charges (js-request/account/transfer/quote), then saves the
 * transfer and the charge together (js-request/account/transferred).
 * The charge is pre-filled but can be changed to what the bank actually took.
 */
if (!window.__internal_initialized__) {
    window.__internal_initialized__ = true;
    $(document).ready(function () {
        var $internal = $('#internal');
        if ($internal.length === 0 || $internal.find('#transferred-btn').length === 0) { return; }

        var quote = null;
        var feeTouched = false;
        var timer = null;
        var requestNo = 0;

        var hints = {
            DEPOSIT: 'Cash going into a bank account.',
            WITHDRAWAL: 'Cash taken out of a bank account. Banks may charge for it, depending on where you withdraw.',
            TRANSFER: 'Money moving between two bank accounts.',
            MOVE: 'Money moving between two of your accounts (no bank charge).'
        };
        var channelLabels = { DEPOSIT: 'Deposited at', WITHDRAWAL: 'Withdrawn at', TRANSFER: 'Sent by' };

        function amount() {
            return parseFloat(String($internal.find('#amount').val()).replace(/,/g, '')) || 0;
        }

        function fee() {
            return parseFloat(String($internal.find('#internal-fee').val()).replace(/,/g, '')) || 0;
        }

        function summary() {
            if (!quote) { return; }
            var a = amount();
            var f = quote.Kind === 'MOVE' ? 0 : fee();
            var text = '';

            if (a > 0) {
                text = LB.money(a) + ' from ' + quote.From.name + ' to ' + quote.To.name;
                if (f > 0) {
                    text += ', plus a ' + LB.money(f) + ' charge on ' + quote.FeeAccountName +
                        (quote.FeeAccountSessionID === quote.From.id ? ' (' + LB.money(a + f) + ' leaves ' + quote.From.name + ')' : '');
                }
                text += '.';
            }
            $internal.find('#internal-summary').html(text ? $('<span>').text(text).html() : '&nbsp;');
        }

        function render(q) {
            quote = q;
            $internal.find('#internal-kind').text(q.KindLabel).attr('data-kind', q.Kind);
            $internal.find('#internal-kind-hint').text(hints[q.Kind] || '');
            $('#transferred-btn').text({ DEPOSIT: 'Save deposit', WITHDRAWAL: 'Save withdrawal' }[q.Kind] || 'Save transfer');

            var hasChannels = q.Channels.length > 0;
            $internal.find('#internal-channel-col, #internal-fee-col').toggleClass('d-none', !hasChannels);

            var $channel = $internal.find('#internal-channel').empty();
            $.each(q.Channels, function (_, c) {
                $('<option>').val(c.code).text(c.label).appendTo($channel);
            });
            $channel.val(q.Channel);
            $internal.find('#internal-channel-label').text(channelLabels[q.Kind] || 'How');

            if (!feeTouched) {
                $internal.find('#internal-fee').val(Number(q.Fee).toFixed(2));
            }
            $internal.find('#internal-fee-rule').text(q.FeeRule ? q.FeeRule + ' · charged to ' + q.FeeAccountName : ' ');

            var $warnings = $internal.find('#internal-warnings').empty();
            $.each(q.Warnings || [], function (_, w) { $('<div>').text(w).appendTo($warnings); });
            $warnings.toggleClass('d-none', !(q.Warnings || []).length);

            if (!$internal.find('#description').data('typed')) {
                $internal.find('#description').attr('placeholder', q.Description || 'Description').val('');
            }
            summary();
        }

        function loadQuote(keepChannel) {
            var from = $internal.find('#current-account-list').val();
            var to = $internal.find('#to-account-list').val();
            if (!from || !to || from === to) { return; }

            var mine = ++requestNo;
            // Until the answer is back the form may show an old charge: no saving meanwhile
            $('#transferred-btn').prop('disabled', true);
            $internal.find('#internal-fee-rule').text('Updating...');
            LB.post('js-request/account/transfer/quote', {
                from_account: from,
                to_account: to,
                amount: amount().toFixed(2),
                channel: keepChannel ? ($internal.find('#internal-channel').val() || '') : '',
                payment_date: $internal.find('#internal-date').val()
            }, function (response) {
                if (mine === requestNo) { render(response.data); }  // ignore answers to older requests
            }).always(function () {
                if (mine === requestNo) { $('#transferred-btn').prop('disabled', false); }
            });
        }

        function loadQuoteSoon(keepChannel) {
            clearTimeout(timer);
            timer = setTimeout(function () { loadQuote(keepChannel); }, 250);
        }

        // Account change: new kind of movement, so channel and charge start over
        $internal.on('change', '#current-account-list, #to-account-list', function () {
            feeTouched = false;
            setTimeout(function () { loadQuote(false); }, 0); // after proccess.js updated the lists
        });
        $internal.on('change', '#internal-channel', function () { feeTouched = false; loadQuote(true); });
        $internal.on('input', '#amount', function () { loadQuoteSoon(true); });
        $internal.on('change', '#internal-date', function () { loadQuote(true); });
        $internal.on('input', '#internal-fee', function () { feeTouched = true; summary(); });
        $internal.on('input', '#description', function () { $(this).data('typed', $(this).val() !== ''); });

        $('#transferred-btn').on('click', function () {
            var a = amount();

            if (a <= 0) {
                LB.showError('The amount must be greater than zero.');
                return;
            }

            if (fee() < 0) {
                LB.showError('The bank charge cannot be negative.');
                return;
            }

            LB.post('js-request/account/transferred', {
                amount: a.toFixed(2),
                from_account: $internal.find('#current-account-list').val(),
                to_account: $internal.find('#to-account-list').val(),
                budget: $internal.find('#budget-list').val(),
                channel: $internal.find('#internal-channel').val() || '',
                // Only a charge the user typed is sent; otherwise the server works it out
                fee: (feeTouched && quote && quote.Kind !== 'MOVE') ? fee().toFixed(2) : '',
                payment_date: $internal.find('#internal-date').val(),
                payment_method: LB.paymentMethodOf($internal),
                description: $internal.find('#description').val(),
                reference: $internal.find('#reference').val()
            }, function () {
                window.location.reload();
            }, null, $(this));
        });

        loadQuote(false);
    });
}
