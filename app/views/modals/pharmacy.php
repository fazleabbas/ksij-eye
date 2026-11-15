<?php defined('KSI_APP') || exit; ?>

<!-- ══ ORDER DETAIL AND REVIEW ═════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-pharm-order">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="ph-order-title">Medication order</div>
        <div class="modal-subtitle" id="ph-order-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-pharm-order')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="ph-order-body"></div>
    <div class="modal-footer" id="ph-order-footer"></div>
  </div>
</div>

<!-- ══ HAND MEDICINE OVER ══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-pharm-dispense">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="ph-dispense-title">Hand medicine over</div>
        <div class="modal-subtitle" id="ph-dispense-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-pharm-dispense')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="ph-dispense-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-pharm-dispense')">Cancel</button>
      <button class="btn btn-primary" type="button" id="ph-dispense-save" onclick="phConfirmDispense(this)">Hand over</button>
    </div>
  </div>
</div>

<!-- ══ CANCEL WHAT IS LEFT ═════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-pharm-cancel">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Cancel what is left</div>
        <div class="modal-subtitle">Medicine already paid for but not handed over is refunded.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-pharm-cancel')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="ph-cancel-info"></div>
      <div class="alert alert-info" id="ph-cancel-refund" style="margin-top:10px"></div>
      <div class="form-group" data-field="reason">
        <label class="form-label" for="ph-cancel-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="ph-cancel-reason" maxlength="500" placeholder="Why the rest is not being supplied"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-pharm-cancel')">Keep the order open</button>
      <button class="btn btn-danger" type="button" onclick="phConfirmCancel(this)">Cancel the rest</button>
    </div>
  </div>
</div>

<!-- ══ COUNTER SALE ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-pharm-walkin">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Counter sale</div>
        <div class="modal-subtitle">For someone who is not a hospital patient. The bill goes to the cashier.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-pharm-walkin')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-grid">
        <div class="form-group" data-field="name">
          <label class="form-label" for="ph-wi-name">Buyer's name <span class="required">*</span></label>
          <input class="form-input" id="ph-wi-name" maxlength="160" placeholder="Full name">
        </div>
        <div class="form-group" data-field="phone">
          <label class="form-label" for="ph-wi-phone">Phone <span class="required">*</span></label>
          <input class="form-input" id="ph-wi-phone" maxlength="20" placeholder="e.g. 255712345678">
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="gender">
          <label class="form-label" for="ph-wi-gender">Sex</label>
          <select class="form-select" id="ph-wi-gender">
            <option value="">Not given</option>
            <option value="female">Female</option>
            <option value="male">Male</option>
          </select>
        </div>
        <div class="form-group" data-field="age">
          <label class="form-label" for="ph-wi-age">Age</label>
          <input type="number" class="form-input" id="ph-wi-age" min="0" max="120">
        </div>
      </div>

      <div class="form-group" data-field="items">
        <label class="form-label" for="ph-wi-drug">Medicine</label>
        <select class="form-select" id="ph-wi-drug" onchange="phWalkInAdd(this.value)"></select>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Medicine</th><th>On the shelf</th><th>Quantity</th><th>Price each</th><th>Total</th><th></th></tr></thead>
          <tbody id="ph-wi-items"></tbody>
        </table>
      </div>
      <div class="opt-price-box" style="margin-top:10px">
        <div><div class="pcd-label">Sale total</div><div class="muted-note">The price may be changed at the counter; the catalogue price is kept on record.</div></div>
        <div class="opt-price-amount" id="ph-wi-total">TZS 0</div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-pharm-walkin')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="phSubmitWalkIn(this)">Raise the bill</button>
    </div>
  </div>
</div>

<!-- ══ STOCK ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-pharm-stock">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="ph-stock-title">Stock</div>
        <div class="modal-subtitle">Every change is recorded with your name and the reason.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-pharm-stock')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="ph-stock-info"></div>
      <div class="form-grid" style="margin-top:12px">
        <div class="form-group" data-field="reason">
          <label class="form-label" for="ph-stock-reason">What is happening <span class="required">*</span></label>
          <select class="form-select" id="ph-stock-reason">
            <option value="received">Stock received</option>
            <option value="adjusted">Correcting the count</option>
            <option value="returned">Returned to the shelf</option>
          </select>
        </div>
        <div class="form-group" data-field="change">
          <label class="form-label" for="ph-stock-change">Change in quantity</label>
          <input type="number" class="form-input" id="ph-stock-change" step="1" placeholder="e.g. 50, or -3 to take off">
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="reorder_level">
          <label class="form-label" for="ph-stock-reorder">Reorder at</label>
          <input type="number" class="form-input" id="ph-stock-reorder" min="0">
        </div>
        <div class="form-group" data-field="expiry_date">
          <label class="form-label" for="ph-stock-expiry">Earliest expiry</label>
          <input type="date" class="form-input" id="ph-stock-expiry">
        </div>
      </div>
      <div class="form-group" data-field="note">
        <label class="form-label" for="ph-stock-note">Note</label>
        <input class="form-input" id="ph-stock-note" maxlength="190" placeholder="Delivery note number, reason for the correction…">
      </div>
      <div class="form-label" style="margin-top:8px">Recent movements</div>
      <div id="ph-stock-movements"></div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-pharm-stock')">Close</button>
      <button class="btn btn-primary" type="button" onclick="phSaveStock(this)">Save</button>
    </div>
  </div>
</div>
