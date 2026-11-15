<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: CASHIER — MANAGE PAYMENTS ════════════════════════════ -->
    <div id="module-cashier-payments" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Manage Payments</div>
          <div class="page-breadcrumb">
            <span>Cashier</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Manage Payments</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" id="cs-fast-track-btn" style="display:none" onclick="csOpenFastTrack()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Fast Track a Visit
          </button>
          <button class="btn btn-secondary" onclick="csLoadQueue(true)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.18-3.94"/></svg>
            Refresh Queue
          </button>
        </div>
      </div>

      <div class="pay-queue-grid" id="cs-queue-cards"></div>

      <div class="section-padding">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="cs-search" placeholder="Patient, phone, REG or PAY- number…" oninput="csFilterChanged()" maxlength="80">
            </div>
            <select class="filter-select" id="cs-filter-status" onchange="csFilterChanged()">
              <option value="">All Waiting</option>
              <option value="pending">Pending Payment</option>
              <option value="discount_requested">Awaiting Discount Approval</option>
              <option value="cancellation_requested">Awaiting Cancellation Approval</option>
            </select>
            <select class="filter-select" id="cs-filter-type" onchange="csFilterChanged()">
              <option value="">All Payment Types</option>
              <option value="consultation">Consultation</option>
              <option value="investigation">Investigation</option>
              <option value="optical">Optical</option>
              <option value="surgery">Surgery</option>
              <option value="pharmacy">Pharmacy</option>
            </select>
            <input type="date" class="filter-select" id="cs-from" onchange="csFilterChanged()" title="Raised from">
            <input type="date" class="filter-select" id="cs-to" onchange="csFilterChanged()" title="Raised to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Bill No.</th>
                  <th>Patient</th>
                  <th>Type</th>
                  <th>Service</th>
                  <th style="text-align:right">Amount</th>
                  <th style="text-align:right">Discount</th>
                  <th style="text-align:right">Due</th>
                  <th>Status</th>
                  <th>Raised</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="cs-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="cs-count">0 bills</span>
            <div class="pagination" id="cs-pagination"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: CASHIER — REFUND REQUESTS ════════════════════════════ -->
    <div id="module-cashier-refunds" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Refund Requests</div>
          <div class="page-breadcrumb">
            <span>Cashier</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Refund Requests</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" id="rf-new-btn" onclick="rfOpenNew()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Refund Request
          </button>
        </div>
      </div>
      <div class="section-padding">
        <div class="card">
          <div class="tab-bar" id="rf-tabs" style="margin:0;padding:0 12px">
            <button class="tab-btn active" data-tab="pending" onclick="rfSwitchTab('pending')">In Progress</button>
            <button class="tab-btn" data-tab="history" onclick="rfSwitchTab('history')">Refund History</button>
          </div>
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="rf-search" placeholder="Patient, phone or file number…" oninput="rfFilterChanged()" maxlength="80">
            </div>
            <input type="date" class="filter-select" id="rf-from" onchange="rfFilterChanged()" title="Requested from">
            <input type="date" class="filter-select" id="rf-to" onchange="rfFilterChanged()" title="Requested to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Refund No.</th>
                  <th>Patient</th>
                  <th>Bill / Receipt</th>
                  <th>Service</th>
                  <th style="text-align:right">Requested</th>
                  <th style="text-align:right">Approved</th>
                  <th>Reason</th>
                  <th>Status</th>
                  <th>Requested</th>
                  <th>Decision</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="rf-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="rf-count">0 refunds</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: CASHIER — PAYMENT HISTORY ════════════════════════════ -->
    <div id="module-cashier-history" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Payment History</div>
          <div class="page-breadcrumb">
            <span>Cashier</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Payment History</span>
          </div>
        </div>
      </div>
      <div class="section-padding">
        <div class="card">
          <div class="tab-bar" id="ch-tabs" style="margin:0;padding:0 12px">
            <button class="tab-btn active" data-tab="paid" onclick="csSwitchHistoryTab('paid')">Paid</button>
            <button class="tab-btn" data-tab="unpaid" onclick="csSwitchHistoryTab('unpaid')">Unpaid</button>
            <button class="tab-btn" data-tab="all" onclick="csSwitchHistoryTab('all')">All Bills</button>
          </div>
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ch-search" placeholder="Patient, REG, PAY- or RCP- number…" oninput="csHistoryFilterChanged()" maxlength="80">
            </div>
            <input type="date" class="filter-select" id="ch-from" data-default-today onchange="csHistoryFilterChanged()" title="From date">
            <input type="date" class="filter-select" id="ch-to" data-default-today onchange="csHistoryFilterChanged()" title="To date">
            <button class="btn btn-secondary btn-sm" type="button" onclick="csClearHistoryDates()">Clear Dates</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Bill No.</th>
                  <th>Patient</th>
                  <th>Type</th>
                  <th style="text-align:right">Amount</th>
                  <th style="text-align:right">Discount</th>
                  <th style="text-align:right">Due / Paid</th>
                  <th>Status</th>
                  <th>Method</th>
                  <th>Txn Ref</th>
                  <th>Cashier</th>
                  <th>Date Paid</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="ch-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="ch-count">0 bills</span>
            <div class="pagination" id="ch-pagination"></div>
          </div>
        </div>
      </div>
    </div>

