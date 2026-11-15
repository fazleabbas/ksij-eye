<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: RAISE A REQUISITION ═══════════════════════════════════ -->
    <div id="module-stores-requisition-form" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">New Requisition</div>
          <div class="page-breadcrumb">
            <span>Requisition</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">New Requisition</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="card-head"><div class="card-title" id="st-req-form-title">What does your department need?</div></div>
          <div class="form-grid">
            <div class="form-group" data-field="department_id">
              <label class="form-label" for="st-req-department">Department <span class="required">*</span></label>
              <select class="form-select" id="st-req-department"></select>
            </div>
            <div class="form-group" data-field="priority">
              <label class="form-label" for="st-req-priority">Priority <span class="required">*</span></label>
              <select class="form-select" id="st-req-priority">
                <option value="normal">Normal</option>
                <option value="urgent">Urgent</option>
                <option value="emergency">Emergency</option>
              </select>
            </div>
          </div>
          <div class="form-group" data-field="items">
            <label class="form-label">Items</label>
            <select class="form-select" id="st-req-item-pick" onchange="stReqAddItem(this.value)"></select>
            <div class="table-wrap" style="margin-top:8px">
              <table>
                <thead><tr><th>Item</th><th>In the store</th><th>How many</th><th>Note</th><th></th></tr></thead>
                <tbody id="st-req-items"></tbody>
              </table>
            </div>
          </div>
          <div class="form-group" data-field="remarks">
            <label class="form-label" for="st-req-remarks">Remarks</label>
            <textarea class="form-input" rows="2" id="st-req-remarks" maxlength="500" placeholder="Anything the storekeeper should know"></textarea>
          </div>
          <div class="modal-footer" style="padding:16px 0 0">
            <button class="btn btn-secondary" onclick="showModule('stores-my-requisitions')">Cancel</button>
            <button class="btn btn-secondary" onclick="stSaveRequisition(this, false)">Save draft</button>
            <button class="btn btn-primary" onclick="stSaveRequisition(this, true)">Send to the store</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: MY REQUISITIONS ═══════════════════════════════════════ -->
    <div id="module-stores-my-requisitions" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">My Requisitions</div>
          <div class="page-breadcrumb">
            <span>Requisition</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">My Requisitions</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" onclick="stNewRequisition()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Requisition
          </button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="st-mine-search" placeholder="Search by number…" oninput="uiDebounce(stLoadMine, 350)()">
            </div>
            <select class="filter-select" id="st-mine-filter-status" onchange="stLoadMine()">
              <option value="">All statuses</option>
              <option value="draft">Draft</option>
              <option value="submitted">Sent to the store</option>
              <option value="approved">Approved</option>
              <option value="partly_issued">Partly issued</option>
              <option value="issued">Issued</option>
              <option value="rejected">Refused</option>
            </select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Number</th><th>Department</th><th>Items</th><th>Priority</th><th>Raised</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-mine-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-mine-count">0 requisitions</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: REQUESTS TO THE STORE ═════════════════════════════════ -->
    <div id="module-stores-requisition-requests" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Requests To The Store</div>
          <div class="page-breadcrumb">
            <span>Requisition</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Requests</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="st-req-search" placeholder="Search number, department or person…" oninput="uiDebounce(stLoadRequests, 350)()">
            </div>
            <select class="filter-select" id="st-req-filter-status" onchange="stLoadRequests()">
              <option value="">Waiting and in progress</option>
              <option value="submitted">Waiting for a decision</option>
              <option value="approved">Approved</option>
              <option value="partly_issued">Partly issued</option>
            </select>
            <select class="filter-select" id="st-req-filter-department" onchange="stLoadRequests()"></select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Number</th><th>Department</th><th>Items</th><th>Priority</th><th>Raised by</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-req-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-req-count">0 requests</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: STORE STOCK ═══════════════════════════════════════════ -->
    <div id="module-stores-stock" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Store Stock</div>
          <div class="page-breadcrumb">
            <span>Stores</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Store Stock</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="stOpenSuppliers()">Suppliers</button>
          <button class="btn btn-primary" onclick="stOpenItem()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Item
          </button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="stats-row" id="st-stock-stats"></div>
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="st-stock-search" placeholder="Search item, code or category…" oninput="uiDebounce(stLoadStock, 350)()">
            </div>
            <select class="filter-select" id="st-stock-filter-category" onchange="stLoadStock()"></select>
            <select class="filter-select" id="st-stock-filter-status" onchange="stLoadStock()">
              <option value="">All stock levels</option>
              <option value="in_stock">In stock</option>
              <option value="low_stock">Low stock</option>
              <option value="out_of_stock">Out of stock</option>
            </select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Item</th><th>Category</th><th>Unit</th><th>In the store</th><th>Reorder at</th><th>Soonest expiry</th><th>Value</th><th>Level</th><th>Actions</th></tr></thead>
              <tbody id="st-stock-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-stock-count">0 items</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: QUOTATIONS ════════════════════════════════════════════ -->
    <div id="module-stores-quotations" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Quotations</div>
          <div class="page-breadcrumb">
            <span>Stores</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Quotations</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="stOpenComparison()">Compare suppliers</button>
          <button class="btn btn-primary" onclick="stOpenQuotation()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Quotation
          </button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <select class="filter-select" id="st-quote-filter-status" onchange="stLoadQuotations()">
              <option value="">All statuses</option>
              <option value="draft">Draft</option>
              <option value="under_review">Under review</option>
              <option value="selected">Chosen</option>
            </select>
            <select class="filter-select" id="st-quote-filter-supplier" onchange="stLoadQuotations()"></select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Number</th><th>Supplier</th><th>Items</th><th>Total</th><th>Valid until</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-quote-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-quote-count">0 quotations</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: PURCHASE ORDERS ═══════════════════════════════════════ -->
    <div id="module-stores-lpos" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Purchase Orders</div>
          <div class="page-breadcrumb">
            <span>Stores</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Purchase Orders</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="stats-row" id="st-order-stats"></div>
        <div class="card">
          <div class="filters-bar">
            <select class="filter-select" id="st-order-filter-status" onchange="stLoadOrders()">
              <option value="">All statuses</option>
              <option value="pending_admin">Waiting for administration</option>
              <option value="pending_store">Waiting for the store manager</option>
              <option value="approved">Approved</option>
              <option value="sent">Sent to the supplier</option>
              <option value="closed">Closed</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <select class="filter-select" id="st-order-filter-supplier" onchange="stLoadOrders()"></select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Number</th><th>Supplier</th><th>Items</th><th>Value</th><th>Expected</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-order-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-order-count">0 orders</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: GOODS RECEIVED ════════════════════════════════════════ -->
    <div id="module-stores-grns" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Goods Received</div>
          <div class="page-breadcrumb">
            <span>Stores</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Goods Received</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" onclick="stOpenReceive()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Record a Delivery
          </button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="info-bar">
          <span class="info-bar-title">How goods reach the shelf</span>
          <div class="diag-flow">
            <div class="diag-flow-step active"><div class="diag-flow-dot"></div>Delivery recorded</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Checked against the order</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Approved — stock goes up</div>
          </div>
        </div>
        <div class="card">
          <div class="filters-bar">
            <select class="filter-select" id="st-grn-filter-status" onchange="stLoadDeliveries()">
              <option value="">All statuses</option>
              <option value="recorded">Recorded</option>
              <option value="verified">Checked</option>
              <option value="approved">Approved</option>
            </select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Number</th><th>Order</th><th>Supplier</th><th>Received</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-grn-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-grn-count">0 deliveries</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: REQUISITION HISTORY ═══════════════════════════════════ -->
    <div id="module-stores-requisition-history" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Requisition History</div>
          <div class="page-breadcrumb">
            <span>Requisition</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">History</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="st-hist-search" placeholder="Search number or department…" oninput="uiDebounce(stLoadRequisitionHistory, 350)()">
            </div>
            <select class="filter-select" id="st-hist-filter-department" onchange="stLoadRequisitionHistory()"></select>
            <input type="date" class="filter-select" id="st-hist-from" data-default-today onchange="stLoadRequisitionHistory()" title="From">
            <input type="date" class="filter-select" id="st-hist-to" data-default-today onchange="stLoadRequisitionHistory()" title="To">
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Number</th><th>Department</th><th>Items</th><th>Raised</th><th>Decided by</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-hist-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-hist-count">0 requisitions</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: PROCUREMENT HISTORY ═══════════════════════════════════ -->
    <div id="module-stores-history" class="hidden st-page">
      <div class="page-header">
        <div>
          <div class="page-title">Procurement History</div>
          <div class="page-breadcrumb">
            <span>Stores</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">History</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <select class="filter-select" id="st-pr-hist-type" onchange="stLoadProcurementHistory()">
              <option value="">Everything</option>
              <option value="quotation">Quotations</option>
              <option value="order">Purchase orders</option>
              <option value="delivery">Goods received</option>
            </select>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Reference</th><th>Type</th><th>Supplier</th><th>Date</th><th>Value</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="st-pr-hist-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="st-pr-hist-count">0 records</span></div>
        </div>
      </div>
    </div>
