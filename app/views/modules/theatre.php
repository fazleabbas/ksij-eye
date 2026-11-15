<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: THEATRE BOOKING ═══════════════════════════════════════ -->
    <div id="module-theatre-booking" class="hidden th-page">
      <div class="page-header">
        <div>
          <div class="page-title">Theatre Booking</div>
          <div class="page-breadcrumb">
            <span>Theatre</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Theatre Booking</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="thPrintBookings()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print List
          </button>
          <button class="btn btn-primary" onclick="thOpenBooking()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Theatre Booking
          </button>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="info-bar">
          <span class="info-bar-title">How a case runs</span>
          <div class="diag-flow">
            <div class="diag-flow-step active"><div class="diag-flow-dot"></div>Booked</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Bill settled</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Cleared in pre-op</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Surgery</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Post-op and discharge</div>
          </div>
        </div>

        <div class="card" id="th-reco-card" style="display:none">
          <div class="card-head">
            <div class="card-title">Recommended by the doctors</div>
            <span class="table-count" id="th-reco-count">0 waiting</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Patient</th><th>Surgery</th><th>Eye</th><th>Urgency</th><th>Recommended</th><th>Actions</th></tr></thead>
              <tbody id="th-reco-tbody"></tbody>
            </table>
          </div>
        </div>

        <div class="stats-row" id="th-book-stats"></div>

        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="th-book-search" placeholder="Search patient, file number or booking number…" oninput="uiDebounce(thLoadBookings, 350)()">
            </div>
            <select class="filter-select" id="th-book-filter-status" onchange="thLoadBookings()">
              <option value="">All statuses</option>
              <option value="booked">Booked</option>
              <option value="cleared">Cleared for theatre</option>
              <option value="in_theatre">In theatre</option>
              <option value="post_op">Post-op care</option>
              <option value="cancellation_requested">Cancellation requested</option>
            </select>
            <select class="filter-select" id="th-book-filter-surgeon" onchange="thLoadBookings()"></select>
            <select class="filter-select" id="th-book-filter-payment" onchange="thLoadBookings()">
              <option value="">Paid and unpaid</option>
              <option value="paid">Paid</option>
              <option value="unpaid">Not paid</option>
            </select>
            <input type="date" class="filter-select" id="th-book-from" onchange="thLoadBookings()" title="Scheduled from">
            <input type="date" class="filter-select" id="th-book-to" onchange="thLoadBookings()" title="Scheduled to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Case</th><th>Patient</th><th>Surgery</th><th>Eye</th><th>Surgeon</th><th>Scheduled</th><th>Bill</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody id="th-book-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="th-book-count">0 cases</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: PRE-OP ════════════════════════════════════════════════ -->
    <div id="module-theatre-preop" class="hidden th-page">
      <div class="page-header">
        <div>
          <div class="page-title">Pre-Op Assessment</div>
          <div class="page-breadcrumb">
            <span>Theatre</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Pre-Op Assessment</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="clinical-grid">
          <div class="card th-queue-panel">
            <div class="card-head">
              <div class="card-title" id="th-preop-title">Waiting for clearance</div>
              <span class="table-count" id="th-preop-count">0 cases</span>
            </div>
            <div class="filters-bar filters-bar-stacked">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="th-preop-search" placeholder="Search patient…" oninput="uiDebounce(thLoadPreop, 350)()">
              </div>
              <select class="filter-select" id="th-preop-filter-stage" onchange="thStageChanged('th-preop')" title="Which patients to list">
                <option value="">Waiting here</option>
                <option value="sent">Sent to theatre</option>
              </select>
            </div>
            <div id="th-preop-queue" class="th-queue"></div>
          </div>
          <div class="card" id="th-preop-panel"></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: IN THEATRE ════════════════════════════════════════════ -->
    <div id="module-theatre-intraop" class="hidden th-page">
      <div class="page-header">
        <div>
          <div class="page-title">In Theatre</div>
          <div class="page-breadcrumb">
            <span>Theatre</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">In Theatre</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="clinical-grid">
          <div class="card th-queue-panel">
            <div class="card-head">
              <div class="card-title" id="th-op-title">Cleared for theatre</div>
              <span class="table-count" id="th-op-count">0 cases</span>
            </div>
            <div class="filters-bar filters-bar-stacked">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="th-op-search" placeholder="Search patient…" oninput="uiDebounce(thLoadOperations, 350)()">
              </div>
              <select class="filter-select" id="th-op-filter-stage" onchange="thStageChanged('th-op')" title="Which patients to list">
                <option value="">Waiting here</option>
                <option value="sent">Sent to post-op</option>
              </select>
            </div>
            <div id="th-op-queue" class="th-queue"></div>
          </div>
          <div class="card" id="th-op-panel"></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: POST-OP ═══════════════════════════════════════════════ -->
    <div id="module-theatre-postop" class="hidden th-page">
      <div class="page-header">
        <div>
          <div class="page-title">Post-Op Care</div>
          <div class="page-breadcrumb">
            <span>Theatre</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Post-Op Care</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary hidden" id="th-postop-back" onclick="thPostopShowList()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Back to list
          </button>
        </div>
      </div>

      <!-- List: every patient still under post-op care -->
      <div class="section-padding" style="padding-top:0" id="th-postop-list-view">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="th-postop-search" placeholder="Search patient name or file number…" oninput="uiDebounce(thLoadPostop, 350)()">
            </div>
            <select class="filter-select" id="th-postop-filter-surgery" onchange="thLoadPostop()"></select>
            <select class="filter-select" id="th-postop-filter-surgeon" onchange="thLoadPostop()"></select>
            <input type="date" class="filter-select" id="th-postop-from" onchange="thLoadPostop()" title="Surgery date from">
            <input type="date" class="filter-select" id="th-postop-to" onchange="thLoadPostop()" title="Surgery date to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Patient</th><th>Surgery</th><th>Eye</th><th>Surgeon</th><th>Surgery date</th><th>Reviews</th><th>Last review</th><th>Next review</th><th>Action</th></tr>
              </thead>
              <tbody id="th-postop-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="th-postop-count">0 cases</span></div>
        </div>
      </div>

      <!-- Detail: one case — reviews on the left, the review form on the right -->
      <div class="card th-postop-detail hidden" id="th-postop-detail-view">
        <div class="pop-detail-layout">
          <div class="pop-detail-left">
            <div class="pop-detail-left-header">
              <div class="th-postop-name" id="th-postop-name">—</div>
              <div class="muted-note" id="th-postop-case-line">—</div>
              <button class="btn btn-primary th-postop-new" id="th-postop-new" onclick="thNewPostopVisit()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Post-Op Visit
              </button>
            </div>
            <div class="th-postop-visits" id="th-postop-visits"></div>
          </div>
          <div class="pop-detail-right"><div class="pop-form-wrap" id="th-postop-panel"></div></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: THEATRE HISTORY ═══════════════════════════════════════ -->
    <div id="module-theatre-history" class="hidden th-page">
      <div class="page-header">
        <div>
          <div class="page-title">Theatre History</div>
          <div class="page-breadcrumb">
            <span>Theatre</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Theatre History</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="thPrintHistory()">Print list</button>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="th-hist-search" placeholder="Search patient, file number or case number…" oninput="uiDebounce(thLoadHistory, 350)()">
            </div>
            <select class="filter-select" id="th-hist-filter-surgeon" onchange="thLoadHistory()"></select>
            <input type="date" class="filter-select" id="th-hist-from" data-default-today onchange="thLoadHistory()" title="Discharged from">
            <input type="date" class="filter-select" id="th-hist-to" data-default-today onchange="thLoadHistory()" title="Discharged to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Case</th><th>Patient</th><th>Surgery</th><th>Eye</th><th>Surgeon</th><th>Surgery date</th><th>Discharged</th><th>Status</th><th>Actions</th></tr>
              </thead>
              <tbody id="th-hist-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="th-hist-count">0 cases</span></div>
        </div>
      </div>
    </div>
