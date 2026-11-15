<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: APPROVALS — PENDING ══════════════════════════════════ -->
    <div id="module-approvals-pending" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Pending Approvals</div>
          <div class="page-breadcrumb">
            <span>Approvals</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Pending</span>
          </div>
        </div>
      </div>
      <div class="appr-queue-grid" id="ap-queue-cards"></div>
      <div class="section-padding">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ap-search" placeholder="Patient, REG, APR-, PAY- or REF- number…" oninput="apFilterChanged()" maxlength="80">
            </div>
            <select class="filter-select" id="ap-filter-type" onchange="apFilterChanged()">
              <option value="">All Types</option>
              <option value="discount">Discount</option>
              <option value="bill_cancellation">Bill Cancellation</option>
              <option value="refund">Refund</option>
              <option value="diagnostic_cancellation">Diagnostic Cancellation</option>
              <option value="optical_cancellation">Spectacle Cancellation</option>
              <option value="theatre_cancellation">Theatre Cancellation</option>
            </select>
            <input type="date" class="filter-select" id="ap-from" onchange="apFilterChanged()" title="Requested from">
            <input type="date" class="filter-select" id="ap-to" onchange="apFilterChanged()" title="Requested to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Approval No.</th>
                  <th>Type</th>
                  <th>Patient</th>
                  <th>Bill / Visit</th>
                  <th>Details</th>
                  <th>Requested By</th>
                  <th>Requested</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="ap-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="ap-count">0 requests</span>
            <div class="pagination" id="ap-pagination"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: APPROVALS — HISTORY ═══════════════════════════════════ -->
    <div id="module-approvals-history" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Approval History</div>
          <div class="page-breadcrumb">
            <span>Approvals</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">History</span>
          </div>
        </div>
      </div>
      <div class="section-padding">
        <div class="card">
          <div class="tab-bar" id="ah-tabs" style="margin:0;padding:0 12px">
            <button class="tab-btn active" data-tab="approved" onclick="apSwitchHistoryTab('approved')">Approved</button>
            <button class="tab-btn" data-tab="rejected" onclick="apSwitchHistoryTab('rejected')">Rejected</button>
          </div>
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="ah-search" placeholder="Patient, REG or document number…" oninput="apHistoryFilterChanged()" maxlength="80">
            </div>
            <select class="filter-select" id="ah-filter-type" onchange="apHistoryFilterChanged()">
              <option value="">All Types</option>
              <option value="discount">Discount</option>
              <option value="bill_cancellation">Bill Cancellation</option>
              <option value="refund">Refund</option>
              <option value="diagnostic_cancellation">Diagnostic Cancellation</option>
              <option value="optical_cancellation">Spectacle Cancellation</option>
              <option value="theatre_cancellation">Theatre Cancellation</option>
            </select>
            <input type="date" class="filter-select" id="ah-from" data-default-today onchange="apHistoryFilterChanged()" title="Decided from">
            <input type="date" class="filter-select" id="ah-to" data-default-today onchange="apHistoryFilterChanged()" title="Decided to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Approval No.</th>
                  <th>Type</th>
                  <th>Patient</th>
                  <th>Details</th>
                  <th>Requested By</th>
                  <th>Requested</th>
                  <th>Decided</th>
                  <th>Decided By</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody id="ah-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="ah-count">0 decisions</span>
            <div class="pagination" id="ah-pagination"></div>
          </div>
        </div>
      </div>
    </div>

