<?php defined('KSI_APP') || exit; ?>
<!-- ══ PROCESS PAYMENT ══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-process-payment">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Process Payment</div>
        <div class="modal-subtitle" id="pp-subtitle"></div>
      </div>
      <div style="display:flex;align-items:center;gap:4px">
        <button class="modal-maximize-btn" type="button" title="Maximize" onclick="toggleModalMaximize('modal-process-payment', this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button>
        <button class="modal-close" type="button" onclick="closeModal('modal-process-payment')" aria-label="Close">&times;</button>
      </div>
    </div>
    <div class="modal-body">
      <div id="pp-alerts"></div>
      <div class="payment-panel">
        <div class="payment-panel-title">Bill</div>
        <div class="pp-grid" id="pp-details"></div>
      </div>
      <div class="discount-calc-box" id="pp-amount" style="margin-top:12px"></div>
      <div class="payment-panel" style="margin-top:16px" id="pp-method-panel">
        <div class="payment-panel-title">Payment Method</div>
        <div class="form-group" style="margin-bottom:10px">
          <div class="radio-group" data-field="method">
            <label class="radio-card"><input type="radio" name="pp-method" value="cash" onchange="csOnMethodChange()"><div><div class="radio-card-label">Cash</div><div class="radio-card-sub">Collected at the counter</div></div></label>
            <label class="radio-card"><input type="radio" name="pp-method" value="lipa_number" onchange="csOnMethodChange()"><div><div class="radio-card-label">Lipa Number</div><div class="radio-card-sub">Mobile money transfer</div></div></label>
          </div>
        </div>
        <div class="form-group" id="pp-ref-group" style="display:none;margin-bottom:0">
          <label class="form-label" for="pp-txn-ref">Transaction Reference <span class="required">*</span></label>
          <input class="form-input" id="pp-txn-ref" data-field="txn_ref" maxlength="60" autocomplete="off" placeholder="Reference from the mobile money message" style="text-transform:uppercase">
        </div>
        <p class="section-note">Payments are for the full amount due.</p>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-process-payment')">Close</button>
      <div class="flex-gap" style="flex-wrap:wrap">
        <button class="btn btn-secondary" type="button" id="pp-cancel-btn" style="color:var(--red)" onclick="csOpenCancelBill()">Request Cancellation</button>
        <button class="btn btn-secondary" type="button" id="pp-discount-btn" style="color:var(--blue)" onclick="csOpenDiscount()">Request Discount</button>
        <button class="btn btn-primary" type="button" id="pp-confirm-btn" onclick="csConfirmPayment()">Confirm Payment</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ REQUEST DISCOUNT ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-discount">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Request Discount</div>
        <div class="modal-subtitle">An approver must accept the discount before the bill can be paid</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-discount')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="dr-form" novalidate onsubmit="event.preventDefault()">
      <div class="payment-panel">
        <div class="payment-panel-title">Bill</div>
        <div class="pp-grid" id="dr-details"></div>
      </div>
      <div class="form-section-title">Discount</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label" for="dr-type">Discount Type <span class="required">*</span></label>
          <select class="form-select" id="dr-type" data-field="discount_type"></select>
        </div>
        <div class="form-group">
          <label class="form-label" for="dr-amount">Discount Amount (TZS) <span class="required">*</span></label>
          <input class="form-input" type="number" min="1" step="1" id="dr-amount" data-field="amount" inputmode="numeric" oninput="csRecalcDiscount()">
        </div>
        <div class="form-group">
          <label class="form-label" for="dr-sponsor">Sponsor / Authority</label>
          <input class="form-input" id="dr-sponsor" data-field="sponsor" maxlength="150" placeholder="Organisation or person">
        </div>
        <div class="form-group col-2">
          <label class="form-label" for="dr-remarks">Remarks</label>
          <textarea class="form-input" id="dr-remarks" data-field="remarks" rows="2" maxlength="500" placeholder="Why the discount is needed"></textarea>
        </div>
        <div class="form-group col-2">
          <label class="form-label">Supporting Documents <span class="text-xs text-muted">(optional · PDF, JPG, PNG, DOCX · up to 5 files, 10 MB each)</span></label>
          <div class="doc-upload-area" onclick="document.getElementById('dr-files').click()">
            <span class="doc-upload-hint">Click to attach a sponsor letter, insurance authorisation…</span>
          </div>
          <input type="file" id="dr-files" multiple accept=".pdf,.jpg,.jpeg,.png,.docx" style="display:none" onchange="csOnDiscountFiles(this)">
          <div class="doc-file-list" id="dr-file-list"></div>
        </div>
      </div>
      <div class="discount-calc-box" style="margin-top:14px">
        <div class="dcb-row"><span class="dcb-label">Amount Due</span><span id="dr-orig">TZS 0</span></div>
        <div class="dcb-row"><span class="dcb-label">Discount</span><span id="dr-disc">TZS 0</span></div>
        <div class="dcb-row"><span class="dcb-label">Equivalent</span><span id="dr-pct">0%</span></div>
        <div class="dcb-row"><span class="dcb-label">Due After Discount</span><strong id="dr-final">TZS 0</strong></div>
      </div>
    </form>
    <div class="modal-footer">
      <span class="text-xs text-muted">Sent to an approver</span>
      <div class="flex-gap">
        <button class="btn btn-secondary" type="button" onclick="closeModal('modal-discount')">Cancel</button>
        <button class="btn btn-primary" type="button" id="dr-submit" onclick="csSubmitDiscount()">Submit for Approval</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ REQUEST BILL CANCELLATION ════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-cancel-bill">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Request Bill Cancellation</div>
        <div class="modal-subtitle">An approver must accept the cancellation</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-cancel-bill')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="cb-form" novalidate onsubmit="event.preventDefault();csSubmitCancelBill()">
      <div class="payment-panel">
        <div class="payment-panel-title">Bill</div>
        <div class="pp-grid" id="cb-details"></div>
      </div>
      <div class="form-group" style="margin-top:14px">
        <label class="form-label" for="cb-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" id="cb-reason" data-field="reason" rows="3" maxlength="500" placeholder="Why should this bill be cancelled?"></textarea>
      </div>
      <p class="section-note">If approved and the visit has not started, the visit is cancelled too.</p>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-cancel-bill')">Back</button>
      <button class="btn btn-danger" type="button" id="cb-submit" onclick="csSubmitCancelBill()">Request Cancellation</button>
    </div>
  </div>
</div>

<!-- ══ RECEIPT ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-receipt">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Payment Receipt</div>
        <div class="modal-subtitle" id="rc-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-receipt')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="receipt-box" id="rc-content"></div>
    </div>
    <div class="modal-footer">
      <span class="text-xs text-muted" id="rc-meta"></span>
      <button class="btn btn-primary" type="button" onclick="csPrintReceipt()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Receipt
      </button>
    </div>
  </div>
</div>

<!-- ══ BILL DETAIL ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-bill-detail">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="bd-title">Bill</div>
        <div class="modal-subtitle" id="bd-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-bill-detail')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="bd-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-bill-detail')">Close</button>
      <button class="btn btn-primary" type="button" id="bd-receipt-btn" style="display:none">View Receipt</button>
    </div>
  </div>
</div>

<!-- ══ NEW REFUND REQUEST ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-new-refund">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">New Refund Request</div>
        <div class="modal-subtitle">The refund is paid out only after an approver accepts it</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-new-refund')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="nr-form" novalidate onsubmit="event.preventDefault()">
      <div class="form-section-title" style="margin-top:0">1 · Patient</div>
      <div class="form-group" id="nr-picker" data-field="patient_id">
        <div class="search-wrap" style="max-width:100%">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" class="search-input" id="nr-patient-search" placeholder="Name, phone or file number…" autocomplete="off" maxlength="80" style="max-width:100%">
        </div>
        <div id="nr-results" style="display:none;margin-top:6px"></div>
      </div>
      <div id="nr-selected" style="display:none"></div>

      <div id="nr-step-bill" style="display:none">
        <div class="form-section-title">2 · Paid Bill</div>
        <div class="form-group"><div class="choice-list" id="nr-bills" data-field="bill_id"></div></div>
      </div>

      <div id="nr-step-details" style="display:none">
        <div class="form-section-title">3 · Refund</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="nr-amount">Amount to Refund (TZS) <span class="required">*</span></label>
            <input class="form-input" type="number" min="1" step="1" id="nr-amount" data-field="amount" inputmode="numeric">
            <span class="form-hint" id="nr-amount-hint"></span>
          </div>
          <div class="form-group col-2">
            <label class="form-label" for="nr-reason">Reason <span class="required">*</span></label>
            <textarea class="form-input" id="nr-reason" data-field="reason" rows="3" maxlength="500" placeholder="Why is the money being returned?"></textarea>
          </div>
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-new-refund')">Cancel</button>
      <button class="btn btn-primary" type="button" id="nr-submit" disabled onclick="rfSubmit()">Submit for Approval</button>
    </div>
  </div>
</div>

<!-- ══ REFUND PAYOUT ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-refund-payout">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Record Refund Payout</div>
        <div class="modal-subtitle" id="po-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-refund-payout')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="po-form" novalidate onsubmit="event.preventDefault();rfConfirmPayout()">
      <div class="payment-panel">
        <div class="payment-panel-title">Approved Refund</div>
        <div class="pp-grid" id="po-details"></div>
      </div>
      <div class="form-group" style="margin-top:14px">
        <label class="form-label">Paid Out By <span class="required">*</span></label>
        <div class="radio-group" data-field="method" id="po-methods">
          <label class="radio-card"><input type="radio" name="po-method" value="cash" onchange="rfOnPayoutMethod()"><div class="radio-card-label">Cash</div></label>
          <label class="radio-card"><input type="radio" name="po-method" value="lipa_number" onchange="rfOnPayoutMethod()"><div class="radio-card-label">Mobile Money</div></label>
        </div>
      </div>
      <div class="form-group" id="po-ref-group" style="display:none">
        <label class="form-label" for="po-reference">Transaction Reference <span class="required">*</span></label>
        <input class="form-input" id="po-reference" data-field="reference" maxlength="60" autocomplete="off" style="text-transform:uppercase">
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-refund-payout')">Back</button>
      <button class="btn btn-primary" type="button" id="po-confirm" onclick="rfConfirmPayout()">Record Payout</button>
    </div>
  </div>
</div>

<!-- ══ FAST TRACK A VISIT ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-fast-track">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Fast Track a Visit</div>
        <div class="modal-subtitle">The patient pays the difference and moves ahead of the normal queue once it is paid.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-fast-track')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group" id="ft-picker">
        <label class="form-label" for="ft-patient-search">Search by name, phone or file number</label>
        <div class="search-wrap" style="max-width:100%">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" class="search-input" id="ft-patient-search" placeholder="e.g. Amina Hassan or 3127" autocomplete="off" maxlength="80" style="max-width:100%">
        </div>
        <div id="ft-search-results" style="display:none;margin-top:6px"></div>
      </div>
      <div id="ft-selected-patient" style="display:none"></div>
      <div id="ft-visits" style="margin-top:12px"></div>
      <div class="discount-calc-box" id="ft-quote" style="display:none;margin-top:12px"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-fast-track')">Close</button>
      <button class="btn btn-primary" type="button" id="ft-confirm-btn" disabled onclick="csConfirmFastTrack()">Fast Track</button>
    </div>
  </div>
</div>
