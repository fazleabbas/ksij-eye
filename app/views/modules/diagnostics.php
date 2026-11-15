<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: DIAGNOSTIC BOOKING ════════════════════════════════════ -->
    <div id="module-diag-booking" class="hidden diag-page">
      <div class="page-header">
        <div>
          <div class="page-title">Diagnostic Booking</div>
          <div class="page-breadcrumb">
            <span>Diagnostic</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Diagnostic Booking</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" onclick="diagOpenNew()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Diagnostic Booking
          </button>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="info-bar">
          <span class="info-bar-title">How a test is charged</span>
          <div class="diag-flow">
            <div class="diag-flow-step active"><div class="diag-flow-dot"></div>Booked (no charge yet)</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Checked in — bill raised</div>
            <span class="diag-flow-arrow">→</span>
            <div class="diag-flow-step"><div class="diag-flow-dot"></div>Test done and reported</div>
          </div>
          <span class="muted-note" style="margin-left:auto">A test is never held up by payment. Cancellations need an administrator.</span>
        </div>

        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="diag-book-search" placeholder="Search patient, file number or booking number…" oninput="uiDebounce(diagLoadBookings, 350)()">
            </div>
            <select class="filter-select" id="diag-book-filter-status" onchange="diagLoadBookings()">
              <option value="">All statuses</option>
              <option value="scheduled">Scheduled</option>
              <option value="arrived">Checked in</option>
              <option value="cancellation_requested">Cancellation requested</option>
            </select>
            <select class="filter-select" id="diag-book-filter-proc" onchange="diagLoadBookings()"></select>
            <input type="date" class="filter-select" id="diag-book-from" onchange="diagLoadBookings()" title="Booked from">
            <input type="date" class="filter-select" id="diag-book-to" onchange="diagLoadBookings()" title="Booked to">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Procedure</th>
                  <th>Eye</th>
                  <th>Scheduled</th>
                  <th>Referred by</th>
                  <th>Charge</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="diag-book-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="diag-book-count">0 bookings</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: DIAGNOSTIC REPORT ═════════════════════════════════════ -->
    <div id="module-diag-report" class="hidden diag-page">
      <div class="page-header">
        <div>
          <div class="page-title">Diagnostic Report</div>
          <div class="page-breadcrumb">
            <span>Diagnostic</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Diagnostic Report</span>
          </div>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="clinical-grid">
          <div class="card diag-queue-panel">
            <div class="card-head">
              <div class="card-title">Waiting for a report</div>
              <span class="table-count" id="diag-report-queue-count">0 waiting</span>
            </div>
            <div class="filters-bar filters-bar-stacked">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="diag-report-queue-search" placeholder="Search patient…" oninput="uiDebounce(diagLoadQueue, 350)()">
              </div>
              <input type="date" class="filter-select" id="diag-report-queue-from" onchange="diagLoadQueue()" title="Scheduled from">
              <input type="date" class="filter-select" id="diag-report-queue-to" onchange="diagLoadQueue()" title="Scheduled to">
            </div>
            <div id="diag-report-queue-body" class="diag-report-queue"></div>
          </div>

          <div class="card" id="diag-report-panel"></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: DIAGNOSTIC HISTORY ════════════════════════════════════ -->
    <div id="module-diag-history" class="hidden diag-page">
      <div class="page-header">
        <div>
          <div class="page-title">Diagnostic History</div>
          <div class="page-breadcrumb">
            <span>Diagnostic</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Diagnostic History</span>
          </div>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="diag-hist-search" placeholder="Search patient, file number or booking number…" oninput="uiDebounce(diagLoadHistory, 350)()">
            </div>
            <select class="filter-select" id="diag-hist-filter-proc" onchange="diagLoadHistory()"></select>
            <input type="date" class="filter-select" id="diag-hist-from" data-default-today onchange="diagLoadHistory()" title="Reported from">
            <input type="date" class="filter-select" id="diag-hist-to" data-default-today onchange="diagLoadHistory()" title="Reported to">
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Reported</th>
                  <th>Patient</th>
                  <th>Procedure</th>
                  <th>Eye</th>
                  <th>Referred by</th>
                  <th>Reported by</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="diag-hist-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="diag-hist-count">0 records</span>
          </div>
        </div>
      </div>
    </div>
