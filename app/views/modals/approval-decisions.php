<?php defined('KSI_APP') || exit; ?>
<!-- ══ VIEW APPROVAL REQUEST ════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-view-approval">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Approval Request</div>
        <div class="modal-subtitle" id="va-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-view-approval')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div id="va-alerts"></div>
      <div class="payment-panel">
        <div class="payment-panel-title">Request</div>
        <div class="pp-grid" id="va-info"></div>
      </div>
      <div class="payment-panel" style="margin-top:14px">
        <div class="payment-panel-title">Details</div>
        <div class="pp-grid" id="va-details"></div>
      </div>
      <div class="payment-panel" style="margin-top:14px" id="va-files-panel">
        <div class="payment-panel-title">Supporting Documents</div>
        <div class="file-list" id="va-files"></div>
      </div>
      <div class="payment-panel" style="margin-top:14px" id="va-patient-files-panel">
        <div class="payment-panel-title">Already on File for This Patient</div>
        <div class="text-xs text-muted" style="margin-bottom:8px">Documents attached to this patient's earlier discount requests.</div>
        <div class="file-list" id="va-patient-files"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-view-approval')">Close</button>
      <div class="flex-gap" id="va-actions"></div>
    </div>
  </div>
</div>

<!-- ══ APPROVE ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-approve">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Approve Request</div>
        <div class="modal-subtitle" id="apv-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-approve')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="apv-form" novalidate onsubmit="event.preventDefault();apConfirmApprove()">
      <div class="alert alert-success" style="margin-bottom:14px" id="apv-summary"></div>
      <div id="apv-amount-section" style="display:none;margin-bottom:14px">
        <div class="discount-calc-box" style="margin-bottom:10px">
          <div class="dcb-row"><span class="dcb-label" id="apv-base-label">Bill amount</span><span id="apv-base">TZS 0</span></div>
          <div class="dcb-row"><span class="dcb-label">Requested</span><span id="apv-requested">TZS 0</span></div>
        </div>
        <div class="form-group">
          <label class="form-label" for="apv-amount" id="apv-amount-label">Approved Amount (TZS) <span class="required">*</span></label>
          <input class="form-input" type="number" min="1" step="1" id="apv-amount" data-field="approved_amount" inputmode="numeric" oninput="apRecalcApprove()">
          <span class="form-hint" id="apv-amount-hint">You may approve less than requested, never more.</span>
        </div>
        <div class="discount-calc-box" style="margin-top:10px">
          <div class="dcb-row"><span class="dcb-label" id="apv-result-label">Result</span><strong id="apv-result">TZS 0</strong></div>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="apv-remarks">Remarks <span class="text-xs text-muted">(optional)</span></label>
        <textarea class="form-input" id="apv-remarks" data-field="remarks" rows="3" maxlength="500"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-approve')">Cancel</button>
      <button class="btn btn-primary" type="button" id="apv-confirm" style="background:var(--green);border-color:var(--green)" onclick="apConfirmApprove()">Confirm Approval</button>
    </div>
  </div>
</div>

<!-- ══ REJECT ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-reject">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Reject Request</div>
        <div class="modal-subtitle" id="rej-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-reject')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="rej-form" novalidate onsubmit="event.preventDefault();apConfirmReject()">
      <div class="alert alert-danger" style="margin-bottom:14px">The request is closed and the requester can see your reason.</div>
      <div class="form-group">
        <label class="form-label" for="rej-remarks">Reason <span class="required">*</span></label>
        <textarea class="form-input" id="rej-remarks" data-field="remarks" rows="3" maxlength="500"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-reject')">Cancel</button>
      <button class="btn btn-danger" type="button" id="rej-confirm" onclick="apConfirmReject()">Confirm Rejection</button>
    </div>
  </div>
</div>
