<?php defined('KSI_APP') || exit; ?>

<!-- ══ STOCK ITEM ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-item">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="st-item-title">New store item</div>
        <div class="modal-subtitle">Quantities are not set here — they move through deliveries, issues and corrections.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-item')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" data-field="name">
        <label class="form-label" for="st-item-name">Item name <span class="required">*</span></label>
        <input class="form-input" id="st-item-name" maxlength="150" placeholder="e.g. Examination Gloves">
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="category">
          <label class="form-label" for="st-item-category">Category <span class="required">*</span></label>
          <select class="form-select" id="st-item-category"></select>
        </div>
        <div class="form-group" data-field="unit">
          <label class="form-label" for="st-item-unit">Counted in <span class="required">*</span></label>
          <select class="form-select" id="st-item-unit"></select>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="reorder_level">
          <label class="form-label" for="st-item-reorder">Reorder at</label>
          <input type="number" class="form-input" id="st-item-reorder" min="0" value="0">
        </div>
        <div class="form-group" data-field="cost_price">
          <label class="form-label" for="st-item-cost">Cost each (TZS)</label>
          <input type="number" class="form-input" id="st-item-cost" min="0" step="100" value="0">
        </div>
      </div>
      <div class="form-group" data-field="supplier_id">
        <label class="form-label" for="st-item-supplier">Usual supplier</label>
        <select class="form-select" id="st-item-supplier"></select>
      </div>
      <div class="form-group" data-field="drug_id">
        <label class="form-label" for="st-item-drug">Pharmacy medicine</label>
        <select class="form-select" id="st-item-drug" onchange="stToggleNewDrug()"></select>
        <div class="muted-note">To sell this in the pharmacy, choose an existing medicine, or "+ Add to the pharmacy as a new medicine" and enter its selling price. Issuing it to the pharmacy then tops up the pharmacy's stock automatically.</div>
      </div>
      <div class="form-grid" id="st-item-new-drug" style="display:none">
        <div class="form-group" data-field="new_drug_price">
          <label class="form-label" for="st-item-drug-price">Pharmacy selling price (TZS) <span class="required">*</span></label>
          <input type="number" class="form-input" id="st-item-drug-price" min="1" step="50">
          <div class="muted-note" id="st-item-drug-price-note" style="display:none">Only an administrator can change the selling price.</div>
        </div>
        <div class="form-group" data-field="new_drug_form" id="st-item-drug-form-group">
          <label class="form-label" for="st-item-drug-form">Form</label>
          <input class="form-input" id="st-item-drug-form" maxlength="40" placeholder="e.g. Eye Drops">
        </div>
      </div>
      <div class="form-group" data-field="frame_id">
        <label class="form-label" for="st-item-frame">Optical frame</label>
        <select class="form-select" id="st-item-frame"></select>
        <div class="muted-note">Link a frame, and issuing this item to the Optical Room adds it to the frame stock.</div>
      </div>
      <div class="form-group" id="st-item-active-row" style="display:none">
        <label class="finding-cb"><input type="checkbox" id="st-item-active"> <span class="finding-cb-label">In use</span></label>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-store-item')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="stSaveItem(this)">Save</button>
    </div>
  </div>
</div>

<!-- ══ STOCK DETAIL AND CORRECTION ═════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-stock">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="st-stock-title">Item</div>
        <div class="modal-subtitle">Batches, movements and corrections.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-stock')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="st-stock-body"></div>
    <div class="modal-footer" id="st-stock-footer"></div>
  </div>
</div>

<!-- ══ SUPPLIERS ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-suppliers">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Suppliers</div>
        <div class="modal-subtitle">Who the store buys from.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-suppliers')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th>Preferred</th><th>Status</th><th></th></tr></thead>
          <tbody id="st-supplier-rows"></tbody>
        </table>
      </div>
      <div class="form-label" style="margin-top:14px" id="st-supplier-form-title">Add a supplier</div>
      <div class="form-grid">
        <div class="form-group" data-field="name">
          <label class="form-label" for="st-supplier-name">Name <span class="required">*</span></label>
          <input class="form-input" id="st-supplier-name" maxlength="150">
        </div>
        <div class="form-group" data-field="contact_name">
          <label class="form-label" for="st-supplier-contact">Contact person</label>
          <input class="form-input" id="st-supplier-contact" maxlength="120">
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group" data-field="phone">
          <label class="form-label" for="st-supplier-phone">Phone</label>
          <input class="form-input" id="st-supplier-phone" maxlength="40">
        </div>
        <div class="form-group" data-field="email">
          <label class="form-label" for="st-supplier-email">Email</label>
          <input class="form-input" id="st-supplier-email" maxlength="190">
        </div>
      </div>
      <div class="form-group" data-field="address">
        <label class="form-label" for="st-supplier-address">Address</label>
        <input class="form-input" id="st-supplier-address" maxlength="255">
      </div>
      <div class="form-group">
        <label class="finding-cb"><input type="checkbox" id="st-supplier-preferred"> <span class="finding-cb-label">Preferred supplier</span></label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-store-suppliers')">Close</button>
      <button class="btn btn-primary" type="button" onclick="stSaveSupplier(this)">Save supplier</button>
    </div>
  </div>
</div>

<!-- ══ REQUISITION DETAIL / DECISION / ISSUE ═══════════════════════════ -->
<div class="modal-overlay" id="modal-store-requisition">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="st-reqd-title">Requisition</div>
        <div class="modal-subtitle" id="st-reqd-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-requisition')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="st-reqd-body"></div>
    <div class="modal-footer" id="st-reqd-footer"></div>
  </div>
</div>

<!-- ══ QUOTATION ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-quotation">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="st-quote-title">New quotation</div>
        <div class="modal-subtitle">What a supplier has offered.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-quotation')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-grid">
        <div class="form-group" data-field="supplier_id">
          <label class="form-label" for="st-quote-supplier">Supplier <span class="required">*</span></label>
          <select class="form-select" id="st-quote-supplier"></select>
        </div>
        <div class="form-group" data-field="valid_until">
          <label class="form-label" for="st-quote-valid">Valid until</label>
          <input type="date" class="form-input" id="st-quote-valid">
        </div>
      </div>
      <div class="form-group" data-field="items">
        <label class="form-label">Items quoted</label>
        <select class="form-select" id="st-quote-item-pick" onchange="stQuoteAddItem(this.value)"></select>
        <div class="table-wrap" style="margin-top:8px">
          <table>
            <thead><tr><th>Item</th><th>How many</th><th>Price each</th><th>Tax %</th><th>Line total</th><th></th></tr></thead>
            <tbody id="st-quote-items"></tbody>
          </table>
        </div>
      </div>
      <div class="opt-price-box">
        <div><div class="pcd-label">Quotation total</div><div class="muted-note">Including tax where it applies.</div></div>
        <div class="opt-price-amount" id="st-quote-total">TZS 0</div>
      </div>
      <div class="form-group" data-field="remarks" style="margin-top:12px">
        <label class="form-label" for="st-quote-remarks">Remarks</label>
        <textarea class="form-input" rows="2" id="st-quote-remarks" maxlength="500"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-store-quotation')">Cancel</button>
      <button class="btn btn-secondary" type="button" onclick="stSaveQuotation(this, false)">Save draft</button>
      <button class="btn btn-primary" type="button" onclick="stSaveQuotation(this, true)">Send for review</button>
    </div>
  </div>
</div>

<!-- ══ COMPARE SUPPLIERS ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-compare">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Compare suppliers</div>
        <div class="modal-subtitle">Every quotation under review, side by side. The cheapest price is marked.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-compare')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="st-compare-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-store-compare')">Close</button>
    </div>
  </div>
</div>

<!-- ══ PURCHASE ORDER ══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-order">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="st-order-title">Purchase order</div>
        <div class="modal-subtitle" id="st-order-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-order')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="st-order-body"></div>
    <div class="modal-footer" id="st-order-footer"></div>
  </div>
</div>

<!-- ══ RECORD A DELIVERY ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-receive">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Record a delivery</div>
        <div class="modal-subtitle">Stock only goes up once the note is checked and approved.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-receive')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-grid">
        <div class="form-group" data-field="order_id">
          <label class="form-label" for="st-grn-order">Against which order <span class="required">*</span></label>
          <select class="form-select" id="st-grn-order" onchange="stReceiveOrderChanged(this.value)"></select>
        </div>
        <div class="form-group" data-field="received_on">
          <label class="form-label" for="st-grn-date">Date received <span class="required">*</span></label>
          <input type="date" class="form-input" id="st-grn-date">
        </div>
      </div>
      <div class="form-group" data-field="delivery_note">
        <label class="form-label" for="st-grn-note">Supplier's delivery note</label>
        <input class="form-input" id="st-grn-note" maxlength="60" placeholder="e.g. DN-4471">
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Item</th><th>Ordered</th><th>Still due</th><th>Received</th><th>Rejected</th><th>Batch</th><th>Expiry</th><th>Price each</th></tr></thead>
          <tbody id="st-grn-items"></tbody>
        </table>
      </div>
      <div class="form-group" data-field="remarks" style="margin-top:12px">
        <label class="form-label" for="st-grn-remarks">Remarks</label>
        <textarea class="form-input" rows="2" id="st-grn-remarks" maxlength="500" placeholder="Anything wrong with the delivery"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-store-receive')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="stSaveDelivery(this)">Record the delivery</button>
    </div>
  </div>
</div>

<!-- ══ DELIVERY DETAIL ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-store-delivery">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="st-grnd-title">Goods received</div>
        <div class="modal-subtitle" id="st-grnd-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-store-delivery')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="st-grnd-body"></div>
    <div class="modal-footer" id="st-grnd-footer"></div>
  </div>
</div>
