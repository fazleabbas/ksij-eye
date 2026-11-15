<?php defined('KSI_APP') || exit; ?>

<!-- ══ PRESCRIPTION FROM OPTOMETRY ═════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-rx">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="opt-rx-title">Prescription</div>
        <div class="modal-subtitle">Recorded by optometry. The optical room cannot change it.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-rx')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="opt-rx-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-rx')">Close</button>
      <button class="btn btn-primary" type="button" onclick="optPrintRx()">Print</button>
    </div>
  </div>
</div>

<!-- ══ ORDER DETAIL AND TRACKING ═══════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-detail">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="opt-detail-title">Order</div>
        <div class="modal-subtitle" id="opt-detail-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-detail')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="opt-detail-body"></div>
    <div class="modal-footer" id="opt-detail-footer"></div>
  </div>
</div>

<!-- ══ HAND OVER THE SPECTACLES ════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-dispense">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Hand over the spectacles</div>
        <div class="modal-subtitle">Recorded against your name and the time.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-dispense')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="cl-note" id="opt-dispense-info"></div>
      <div class="alert alert-info" style="margin-top:12px">Check the patient's name and the prescription before handing them over.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-dispense')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="optConfirmDispense(this)">Hand over</button>
    </div>
  </div>
</div>

<!-- ══ ASK FOR A DISCOUNT ══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-discount">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Ask for a discount</div>
        <div class="modal-subtitle">An administrator decides. The bill waits until then.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-discount')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="opt-discount-info"></div>
      <div class="form-grid" style="margin-top:12px">
        <div class="form-group" data-field="amount">
          <label class="form-label" for="opt-discount-amount">Amount off <span class="required">*</span></label>
          <input type="number" class="form-input" id="opt-discount-amount" min="1" step="100">
        </div>
        <div class="form-group" data-field="discount_type">
          <label class="form-label" for="opt-discount-type">Type <span class="required">*</span></label>
          <select class="form-select" id="opt-discount-type"></select>
        </div>
      </div>
      <div class="form-group" data-field="sponsor">
        <label class="form-label" for="opt-discount-sponsor">Sponsor</label>
        <input class="form-input" id="opt-discount-sponsor" maxlength="150" placeholder="Who is covering it, if anyone">
      </div>
      <div class="form-group" data-field="remarks">
        <label class="form-label" for="opt-discount-remarks">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="opt-discount-remarks" maxlength="500" placeholder="Why the discount is being asked for"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-discount')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="optSubmitDiscount(this)">Send for approval</button>
    </div>
  </div>
</div>

<!-- ══ ADD OR EDIT A FRAME ═════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-frame">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="opt-frame-title">Add frame</div>
        <div class="modal-subtitle">Every frame costs the same; the prices are in the Price List.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-frame')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="opt-frame-form" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" data-field="name">
        <label class="form-label" for="opt-frame-name">Model <span class="required">*</span></label>
        <input class="form-input" id="opt-frame-name" maxlength="150">
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="brand">
          <label class="form-label" for="opt-frame-brand">Brand</label>
          <input class="form-input" id="opt-frame-brand" maxlength="80">
        </div>
        <div class="form-group" data-field="colour">
          <label class="form-label" for="opt-frame-colour">Colour</label>
          <input class="form-input" id="opt-frame-colour" maxlength="40">
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="size">
          <label class="form-label" for="opt-frame-size">Size</label>
          <input class="form-input" id="opt-frame-size" maxlength="20" placeholder="e.g. 52-18-140">
        </div>
        <div class="form-group" data-field="reorder_level">
          <label class="form-label" for="opt-frame-reorder">Reorder at</label>
          <input type="number" class="form-input" id="opt-frame-reorder" min="0" value="0">
        </div>
      </div>
      <div class="form-group" id="opt-frame-active-row" style="display:none">
        <label class="finding-cb"><input type="checkbox" id="opt-frame-active"> <span class="finding-cb-label">Still offered</span></label>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-frame')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="optSaveFrame(this)">Save</button>
    </div>
  </div>
</div>

<!-- ══ FRAME STOCK: HISTORY AND CORRECTION ═════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-frame-stock">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="opt-frame-stock-title">Frame</div>
        <div class="modal-subtitle">Every change to the count, with who and why.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-frame-stock')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="opt-frame-stock-body"></div>
    <div class="modal-footer" id="opt-frame-stock-footer"></div>
  </div>
</div>

<!-- ══ CHANGE THE FRAME ON EARLIER SPECTACLES ══════════════════════════ -->
<div class="modal-overlay" id="modal-opt-change-frame">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Change frame</div>
        <div class="modal-subtitle">The lenses stay; the new frame is charged at the normal frame price.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-change-frame')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="opt-change-form" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="opt-change-info"></div>
      <div class="form-group" data-field="frame_id" style="margin-top:12px">
        <label class="form-label" for="opt-change-frame">New frame <span class="required">*</span></label>
        <select class="form-select" id="opt-change-frame"></select>
      </div>
      <div class="form-group" data-field="old_frame">
        <label class="form-label" for="opt-change-old">Frame taken off</label>
        <input class="form-input" id="opt-change-old" maxlength="190">
      </div>
      <div class="form-group" data-field="reason">
        <label class="form-label" for="opt-change-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="2" id="opt-change-reason" maxlength="500" placeholder="e.g. Hinge broke"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-change-frame')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="optSubmitChangeFrame(this)">Raise the bill</button>
    </div>
  </div>
</div>

<!-- ══ ANOTHER PAIR ON THE SAME PRESCRIPTION ═══════════════════════════ -->
<div class="modal-overlay" id="modal-opt-extra-pair">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Add another pair</div>
        <div class="modal-subtitle">Same prescription and lenses, at full price. Tracked and handed over as its own order.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-extra-pair')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="opt-extra-form" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="opt-extra-info"></div>
      <div class="form-group" data-field="set_no" id="opt-extra-set-group" style="margin-top:12px">
        <label class="form-label" for="opt-extra-set">Which prescription</label>
        <select class="form-select" id="opt-extra-set" onchange="optExtraPairTotal()"></select>
      </div>
      <div class="form-group" data-field="frame_id" style="margin-top:12px">
        <label class="form-label" for="opt-extra-frame">Frame</label>
        <select class="form-select" id="opt-extra-frame" onchange="optExtraPairTotal()"></select>
      </div>
      <div id="opt-extra-summary"></div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-extra-pair')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="optSubmitExtraPair(this)">Raise the bill</button>
    </div>
  </div>
</div>

<!-- ══ WHICH PRESCRIPTIONS TO PRINT ════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-print-choice">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Print prescription</div>
        <div class="modal-subtitle">This visit has more than one prescription. Choose which to print.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-print-choice')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body"><div id="opt-print-sets" style="display:flex;flex-direction:column;gap:8px"></div></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-print-choice')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="optConfirmPrintChoice()">Print</button>
    </div>
  </div>
</div>

<!-- ══ ASK TO CANCEL THE ORDER ═════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-opt-cancel">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Request cancellation</div>
        <div class="modal-subtitle">An administrator decides. Any unpaid bill is cancelled with it.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-cancel')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="opt-cancel-info"></div>
      <div class="form-group" data-field="reason" style="margin-top:12px">
        <label class="form-label" for="opt-cancel-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="opt-cancel-reason" maxlength="500" placeholder="Why the order is not going ahead"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-cancel')">Keep order</button>
      <button class="btn btn-danger" type="button" onclick="optSubmitCancel(this)">Request cancellation</button>
    </div>
  </div>
</div>

<!-- ══ CHANGE THE LENS (patient's choice; confirm page or an unpaid order not yet with the supplier) ══ -->
<div class="modal-overlay" id="modal-opt-change-lens">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Change lens</div>
        <div class="modal-subtitle">The prescription stays as Optometry recorded it; only the lens changes, at its price-list price.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-opt-change-lens')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="opt-lens-form" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="opt-lens-info"></div>
      <div class="form-group" data-field="set_no" id="opt-lens-set-group" style="margin-top:12px">
        <label class="form-label" for="opt-lens-set">Which pair <span class="required">*</span></label>
        <select class="form-select" id="opt-lens-set" onchange="optLensSetChanged()"></select>
      </div>
      <div class="form-group" data-field="lens_ids" style="margin-top:12px">
        <label class="form-label">New lens <span class="required">*</span></label>
        <div id="opt-lens-list" style="max-height:260px;overflow-y:auto"></div>
      </div>
      <div id="opt-lens-total"></div>
      <div class="form-group" data-field="reason">
        <label class="form-label" for="opt-lens-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="2" id="opt-lens-reason" maxlength="500" placeholder="e.g. Patient prefers photochromic lenses"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-opt-change-lens')">Cancel</button>
      <button class="btn btn-primary" type="button" id="opt-lens-save" onclick="optSubmitLensChange(this)">Change lens</button>
    </div>
  </div>
</div>
