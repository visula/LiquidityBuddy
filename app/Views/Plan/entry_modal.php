<?php
/**
 * Edit / skip / restore one month of a plan item (shared by the year grid and
 * the month view). public/assets/js/plan/entry.js opens it from any element
 * with class "plan-entry-open" and these data attributes:
 * data-entry, data-label, data-amount, data-paid, data-state, data-editable.
 */
?>
<div class="modal fade" id="plan-entry-modal" tabindex="-1" aria-labelledby="plan-entry-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="plan-entry-title">Edit month</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="plan-entry-id" value="">
                <p class="text-muted small mb-2" id="plan-entry-note"></p>
                <div class="plan-entry-editable">
                    <div class="form-floating mb-2">
                        <input type="text" class="form-control" id="plan-entry-amount" placeholder="Amount" inputmode="decimal">
                        <label for="plan-entry-amount">Planned amount</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="plan-entry-forward">
                        <label class="form-check-label" for="plan-entry-forward">Apply to the following months too (settled months are left as they are)</label>
                    </div>
                </div>
                <p class="error-message mb-0" id="plan-entry-error">&nbsp;</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto plan-entry-editable" id="plan-entry-skip">Skip this month</button>
                <button type="button" class="btn btn-outline-primary d-none" id="plan-entry-restore">Restore this month</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary plan-entry-editable" id="plan-entry-save">Save</button>
            </div>
        </div>
    </div>
</div>
