/**
 * Edit / skip / restore one month of a plan item (year grid + month view).
 * Opened from any .plan-entry-open element (see app/Views/Plan/entry_modal.php).
 */
$(document).ready(function () {
    var $modal = $('#plan-entry-modal');
    if ($modal.length === 0) { return; }

    var modal = new bootstrap.Modal($modal[0]);
    var errorSlot = '#plan-entry-error';

    var notes = {
        settled: 'This month is settled, so it can\'t be changed.',
        historical: 'Before tracking started: changing it only changes the plan totals.',
        envelope: 'A spending envelope: the planned amount is the cap for that month.',
        skipped: 'This month is skipped. Restore it to plan it again.'
    };

    $(document).on('click', '.plan-entry-open', function (event) {
        event.preventDefault();
        var $cell = $(this);
        var state = $cell.attr('data-state');
        var editable = $cell.attr('data-editable') === '1';
        var paid = parseFloat($cell.attr('data-paid')) || 0;

        if (!editable && state !== 'skipped' && state !== 'settled') { return; }

        $('#plan-entry-id').val($cell.attr('data-entry'));
        $('#plan-entry-title').text($cell.attr('data-label'));
        $('#plan-entry-amount').val(Number($cell.attr('data-amount') || 0).toFixed(2));
        $('#plan-entry-forward').prop('checked', false);

        var note = notes[state] || '';
        if (paid > 0 && state !== 'settled') {
            note = LB.money(paid) + ' is already paid: the amount can\'t go below that (equal to it settles the month). It can\'t be skipped.';
        }
        $('#plan-entry-note').text(note);

        $modal.find('.plan-entry-editable').toggleClass('d-none', !editable);
        $('#plan-entry-skip').toggleClass('d-none', !editable || paid > 0);
        $('#plan-entry-restore').toggleClass('d-none', state !== 'skipped');
        LB.clearError(errorSlot);
        modal.show();
    });

    // refresh the table of the page this modal was opened from, then close the modal
    function done() {
        var region = $('#plan-year-region').length ? '#plan-year-region' : '#plan-month-region';
        LB.refreshRegion(region, function () { modal.hide(); });
    }

    $('#plan-entry-save').on('click', function () {
        var amount = parseFloat(String($('#plan-entry-amount').val()).replace(/,/g, ''));

        if (isNaN(amount) || amount < 0) {
            LB.showError('Please enter an amount of zero or more', errorSlot);
            return;
        }

        LB.post('js-request/plan/entry/update', {
            entry_id: $('#plan-entry-id').val(),
            amount: amount.toFixed(2),
            apply_forward: $('#plan-entry-forward').is(':checked') ? 1 : 0
        }, function (response) {
            var notChanged = (response.data && response.data.not_changed) || [];
            if (notChanged.length) {
                window.alert('Not changed (settled or part paid above the new amount): ' + notChanged.join(', '));
            }
            done();
        }, errorSlot, $(this));
    });

    $('#plan-entry-skip').on('click', function () {
        if (!window.confirm('Skip ' + $('#plan-entry-title').text() + '? Its due is cancelled; you can restore it later.')) { return; }
        LB.post('js-request/plan/entry/skip', { entry_id: $('#plan-entry-id').val() }, done, errorSlot, $(this));
    });

    $('#plan-entry-restore').on('click', function () {
        LB.post('js-request/plan/entry/restore', { entry_id: $('#plan-entry-id').val() }, done, errorSlot, $(this));
    });
});
