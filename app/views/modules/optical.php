<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: INCOMING PRESCRIPTIONS ════════════════════════════════ -->
    <div id="module-opt-incoming" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">Incoming Prescriptions</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Incoming Prescriptions</span>
          </div>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="info-bar">
          <span class="info-bar-title">How a spectacle order runs</span>
          <div class="diag-flow">
            <div class="diag-flow-step active"><div class="diag-flow-dot"></div>Prescription from optometry</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Order confirmed — bill to cashier</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Lens ordered and received</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Handed over once paid</div>
          </div>
        </div>

        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="opt-in-search" placeholder="Search patient or file number…" oninput="uiDebounce(optLoadIncoming, 350)()">
            </div>
            <input type="date" class="filter-select" id="opt-in-from" onchange="optLoadIncoming()" title="Prescribed from">
            <input type="date" class="filter-select" id="opt-in-to" onchange="optLoadIncoming()" title="Prescribed to">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Visit</th>
                  <th>Prescribed</th>
                  <th>Optometrist</th>
                  <th>Lens chosen</th>
                  <th>Price</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="opt-in-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="opt-in-count">0 prescriptions</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: CONFIRM ORDER ═════════════════════════════════════════ -->
    <div id="module-opt-create" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">Confirm Spectacle Order</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Confirm Order</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="showModule('opt-incoming')">Back</button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="clinical-grid">
          <div class="card" id="opt-create-rx"></div>
          <div class="card" id="opt-create-order"></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: ACTIVE ORDERS ═════════════════════════════════════════ -->
    <div id="module-opt-active" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">Active Orders</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Active Orders</span>
          </div>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="stats-row" id="opt-act-stats"></div>
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="opt-act-search" placeholder="Search patient, file or order number…" oninput="uiDebounce(optLoadActive, 350)()">
            </div>
            <select class="filter-select" id="opt-act-filter-status" onchange="optLoadActive()">
              <option value="">All statuses</option>
              <option value="ordered">Ordered</option>
              <option value="sent_to_supplier">Sent to supplier</option>
              <option value="lens_received">Lens received</option>
              <option value="ready_for_dispensing">Ready to hand over</option>
              <option value="cancellation_requested">Cancellation requested</option>
            </select>
            <select class="filter-select" id="opt-act-filter-supplier" onchange="optLoadActive()"></select>
            <select class="filter-select" id="opt-act-filter-payment" onchange="optLoadActive()">
              <option value="">Paid and unpaid</option>
              <option value="paid">Paid</option>
              <option value="unpaid">Not paid</option>
            </select>
            <input type="date" class="filter-select" id="opt-act-from" onchange="optLoadActive()" title="Ordered from">
            <input type="date" class="filter-select" id="opt-act-to" onchange="optLoadActive()" title="Ordered to">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Patient</th>
                  <th>Lens</th>
                  <th>Status</th>
                  <th>Supplier</th>
                  <th>Lens received</th>
                  <th>Payment</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="opt-act-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="opt-act-count">0 orders</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: READY FOR DISPENSING ══════════════════════════════════ -->
    <div id="module-opt-dispensing" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">Ready for Dispensing</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Ready for Dispensing</span>
          </div>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="opt-disp-search" placeholder="Search patient or order number…" oninput="uiDebounce(optLoadDispensing, 350)()">
            </div>
            <input type="date" class="filter-select" id="opt-disp-from" onchange="optLoadDispensing()" title="Ordered from">
            <input type="date" class="filter-select" id="opt-disp-to" onchange="optLoadDispensing()" title="Ordered to">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Patient</th>
                  <th>Phone</th>
                  <th>Lens</th>
                  <th>Amount</th>
                  <th>Payment</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="opt-disp-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="opt-disp-count">0 orders</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: EXTERNAL CUSTOMER ═════════════════════════════════════ -->
    <div id="module-opt-external" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">External Customer</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">External Customer</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="clinical-grid">
          <form class="card" id="opt-ext-form" novalidate onsubmit="event.preventDefault()">
            <div class="card-head"><div class="card-title">Customer</div></div>
            <div class="muted-note" style="margin-bottom:10px">For someone buying spectacles who is not registered as a patient.</div>
            <div class="form-group" data-field="name">
              <label class="form-label" for="opt-ext-name">Name <span class="required">*</span></label>
              <input class="form-input" id="opt-ext-name" maxlength="160">
            </div>
            <div class="form-grid">
              <div class="form-group" data-field="phone">
                <label class="form-label" for="opt-ext-phone">Phone <span class="required">*</span></label>
                <input class="form-input" id="opt-ext-phone" maxlength="20" placeholder="255712345678">
              </div>
              <div class="form-group" data-field="gender">
                <label class="form-label" for="opt-ext-gender">Gender</label>
                <select class="form-select" id="opt-ext-gender"><option value="">—</option><option value="male">Male</option><option value="female">Female</option></select>
              </div>
            </div>
            <div class="form-group" data-field="age">
              <label class="form-label" for="opt-ext-age">Age</label>
              <input type="number" class="form-input" id="opt-ext-age" min="0" max="120">
            </div>
            <div class="card-head" style="margin-top:8px"><div class="card-title">What they are buying</div></div>
            <div class="form-group" data-field="frame_id">
              <label class="form-label" for="opt-ext-frame">Frame</label>
              <select class="form-select" id="opt-ext-frame" onchange="optExternalTotal()"></select>
            </div>
            <div class="form-group" data-field="lens_ids">
              <label class="form-label">Lenses</label>
              <div id="opt-ext-lenses" class="findings-grid"></div>
            </div>
          </form>
          <div class="card">
            <div class="card-head"><div class="card-title">Total</div></div>
            <div id="opt-ext-summary"></div>
            <div class="alert alert-info" style="margin-top:12px">The bill goes to the cashier. Lenses are ordered from the supplier as usual;
              a frame on its own is ready to hand over as soon as it is paid.</div>
            <div class="modal-footer" style="padding:16px 0 0">
              <button class="btn btn-secondary" type="button" onclick="optResetExternal()">Clear</button>
              <button class="btn btn-primary" type="button" onclick="optSubmitExternal(this)">Raise the bill</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: FRAMES ════════════════════════════════════════════════ -->
    <div id="module-opt-frames" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">Frames</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Frames</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" id="opt-frame-add" onclick="optOpenFrame()">Add frame</button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="stats-row" id="opt-frames-stats"></div>
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="opt-frames-search" placeholder="Search model, brand, colour or code…" oninput="optRenderFrames()">
            </div>
            <label class="finding-cb"><input type="checkbox" id="opt-frames-inactive" onchange="optLoadFrames()"> <span class="finding-cb-label">Show frames no longer offered</span></label>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Frame</th><th>Code</th><th>In stock</th><th>Set aside</th><th>Free to sell</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody id="opt-frames-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="opt-frames-count">0 frames</span>
            <span class="muted-note">Frames arrive when the store issues them to the Optical Room, and leave when spectacles are handed over.</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: COMPLETED ORDERS ══════════════════════════════════════ -->
    <div id="module-opt-completed" class="hidden opt-page">
      <div class="page-header">
        <div>
          <div class="page-title">Completed Orders</div>
          <div class="page-breadcrumb">
            <span>Optical Room</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Completed Orders</span>
          </div>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="opt-cmp-search" placeholder="Search patient, file or order number…" oninput="uiDebounce(optLoadCompleted, 350)()">
            </div>
            <select class="filter-select" id="opt-cmp-filter-supplier" onchange="optLoadCompleted()"></select>
            <input type="date" class="filter-select" id="opt-cmp-from" data-default-today onchange="optLoadCompleted()" title="Handed over from">
            <input type="date" class="filter-select" id="opt-cmp-to" data-default-today onchange="optLoadCompleted()" title="Handed over to">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Order</th>
                  <th>Patient</th>
                  <th>Lens</th>
                  <th>Supplier</th>
                  <th>Handed over</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="opt-cmp-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="opt-cmp-count">0 orders</span>
          </div>
        </div>
      </div>
    </div>
