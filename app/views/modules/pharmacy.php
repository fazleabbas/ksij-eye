<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: MEDICATION ORDERS ═════════════════════════════════════ -->
    <div id="module-pharm-orders" class="hidden pharm-page">
      <div class="page-header">
        <div>
          <div class="page-title">Medication Orders</div>
          <div class="page-breadcrumb">
            <span>Pharmacy</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Medication Orders</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" onclick="phOpenWalkIn()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Counter Sale
          </button>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="info-bar">
          <span class="info-bar-title">How medicine reaches the patient</span>
          <div class="diag-flow">
            <div class="diag-flow-step active"><div class="diag-flow-dot"></div>Prescription arrives</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Pharmacy review — bill raised</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Paid at the cashier</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Handed over</div>
          </div>
        </div>

        <div class="stats-row" id="ph-ord-stats"></div>

        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ph-ord-search" placeholder="Search patient, file number or order number…" oninput="uiDebounce(phLoadOrders, 350)()">
            </div>
            <select class="filter-select" id="ph-ord-filter-status" onchange="phLoadOrders()">
              <option value="">All statuses</option>
              <option value="pending_review">Waiting for review</option>
              <option value="awaiting_payment">Waiting for payment</option>
              <option value="paid">Paid — ready to hand over</option>
              <option value="partially_dispensed">Partly handed over</option>
            </select>
            <select class="filter-select" id="ph-ord-filter-source" onchange="phLoadOrders()">
              <option value="">Prescriptions and counter sales</option>
              <option value="prescription">Prescriptions</option>
              <option value="walk_in">Counter sales</option>
            </select>
            <input type="date" class="filter-select" id="ph-ord-from" onchange="phLoadOrders()" title="From">
            <input type="date" class="filter-select" id="ph-ord-to" onchange="phLoadOrders()" title="To">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Patient / buyer</th>
                  <th>Prescribed by</th>
                  <th>Medicines</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="ph-ord-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="ph-ord-count">0 orders</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: DISPENSE ══════════════════════════════════════════════ -->
    <div id="module-pharm-dispense" class="hidden pharm-page">
      <div class="page-header">
        <div>
          <div class="page-title">Dispense Medication</div>
          <div class="page-breadcrumb">
            <span>Pharmacy</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Dispense Medication</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ph-disp-search" placeholder="Search patient or order number…" oninput="uiDebounce(phLoadDispense, 350)()">
            </div>
            <input type="date" class="filter-select" id="ph-disp-from" onchange="phLoadDispense()" title="From">
            <input type="date" class="filter-select" id="ph-disp-to" onchange="phLoadDispense()" title="To">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Order</th><th>Patient / buyer</th><th>Phone</th><th>Medicines</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody id="ph-disp-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="ph-disp-count">0 orders</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: PART-DISPENSED ════════════════════════════════════════ -->
    <div id="module-pharm-partial" class="hidden pharm-page">
      <div class="page-header">
        <div>
          <div class="page-title">Partly Handed Over</div>
          <div class="page-breadcrumb">
            <span>Pharmacy</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Partly Handed Over</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ph-part-search" placeholder="Search patient or order number…" oninput="uiDebounce(phLoadPartial, 350)()">
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Order</th><th>Patient / buyer</th><th>Still owed</th><th>Handed over</th><th>Amount</th><th>Actions</th></tr>
              </thead>
              <tbody id="ph-part-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="ph-part-count">0 orders</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: REFILLS ═══════════════════════════════════════════════ -->
    <div id="module-pharm-refills" class="hidden pharm-page">
      <div class="page-header">
        <div>
          <div class="page-title">Refills</div>
          <div class="page-breadcrumb">
            <span>Pharmacy</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Refills</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="info-bar">
          <span class="info-bar-title">Refills</span>
          <span class="muted-note">A completed prescription can be repeated at no charge. The stock still comes off the shelf and every repeat is recorded.</span>
        </div>
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ph-ref-search" placeholder="Search patient or order number…" oninput="uiDebounce(phLoadRefills, 350)()">
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Order</th><th>Patient</th><th>Medicines</th><th>Last handed over</th><th>Refills so far</th><th>Actions</th></tr>
              </thead>
              <tbody id="ph-ref-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="ph-ref-count">0 orders</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: HISTORY ═══════════════════════════════════════════════ -->
    <div id="module-pharm-history" class="hidden pharm-page">
      <div class="page-header">
        <div>
          <div class="page-title">Pharmacy History</div>
          <div class="page-breadcrumb">
            <span>Pharmacy</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Pharmacy History</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ph-hist-search" placeholder="Search patient, buyer or order number…" oninput="uiDebounce(phLoadHistory, 350)()">
            </div>
            <select class="filter-select" id="ph-hist-filter-source" onchange="phLoadHistory()">
              <option value="">Prescriptions and counter sales</option>
              <option value="prescription">Prescriptions</option>
              <option value="walk_in">Counter sales</option>
            </select>
            <input type="date" class="filter-select" id="ph-hist-from" data-default-today onchange="phLoadHistory()" title="From">
            <input type="date" class="filter-select" id="ph-hist-to" data-default-today onchange="phLoadHistory()" title="To">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Finished</th><th>Order</th><th>Patient / buyer</th><th>Medicines</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody id="ph-hist-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="ph-hist-count">0 orders</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: STOCK ═════════════════════════════════════════════════ -->
    <div id="module-pharm-inventory" class="hidden pharm-page">
      <div class="page-header">
        <div>
          <div class="page-title">Pharmacy Stock</div>
          <div class="page-breadcrumb">
            <span>Pharmacy</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Pharmacy Stock</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="stats-row" id="ph-stock-stats"></div>
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ph-stock-search" placeholder="Search medicine or category…" oninput="uiDebounce(phLoadStock, 350)()">
            </div>
            <select class="filter-select" id="ph-stock-filter-category" onchange="phLoadStock()"></select>
            <select class="filter-select" id="ph-stock-filter-status" onchange="phLoadStock()">
              <option value="">All stock levels</option>
              <option value="in_stock">In stock</option>
              <option value="low_stock">Low stock</option>
              <option value="out_of_stock">Out of stock</option>
            </select>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Medicine</th><th>Category</th><th>Price</th><th>On the shelf</th><th>Reorder at</th><th>Expiry</th><th>Level</th><th>Actions</th></tr>
              </thead>
              <tbody id="ph-stock-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="ph-stock-count">0 medicines</span></div>
        </div>
      </div>
    </div>
