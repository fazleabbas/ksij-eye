<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: PLACEHOLDER ══════════════════════════════════════════ -->
    <div id="module-placeholder" class="hidden">
      <div class="section-padding">
        <div class="placeholder-module">
          <div class="pm-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-3)" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          </div>
          <h3 id="placeholder-name">Module</h3>
          <p>This module is not yet available at your terminal. Contact the system administrator for access.</p>
        </div>
      </div>
    </div>

    <!-- Old report entries all open the one report screen. -->
    <div id="module-report-master" class="hidden"></div>
    <div id="module-report-financial" class="hidden"></div>
    <div id="module-report-investigation" class="hidden"></div>
    <div id="module-report-surgery" class="hidden"></div>

    <!-- ══ MODULE: REPORTS ═══════════════════════════════════════════════ -->
    <div id="module-report-terminal" class="hidden rp-page">
      <div class="page-header">
        <div>
          <div class="page-title">Reports</div>
          <div class="page-breadcrumb">
            <span>Reports</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active" id="rp-crumb">Daily Collections</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="rpPrint()">Print</button>
          <button class="btn btn-primary" onclick="rpExport()">Export to CSV</button>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="filters-bar">
            <select class="filter-select" id="rp-type" onchange="rpTypeChanged()"></select>
            <input type="date" class="filter-select" id="rp-from" onchange="rpLoad()" title="From">
            <input type="date" class="filter-select" id="rp-to" onchange="rpLoad()" title="To">
            <select class="filter-select" id="rp-status" onchange="rpLoad()" style="display:none"></select>
            <select class="filter-select" id="rp-scheme" onchange="rpLoad()" style="display:none"></select>
            <input type="number" class="filter-select" id="rp-max-age" onchange="rpLoad()" placeholder="Discounts: up to age" style="display:none;max-width:170px">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="rp-search" placeholder="Search this report…" oninput="uiDebounce(rpLoad, 400)()">
            </div>
            <button class="btn btn-secondary btn-sm" onclick="rpReset()">Reset</button>
          </div>
        </div>

        <div class="stats-row" id="rp-kpis"></div>
        <div id="rp-summary"></div>

        <div class="card">
          <div class="table-wrap">
            <table id="rp-table">
              <thead id="rp-head"></thead>
              <tbody id="rp-body"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="rp-count">0 rows</span>
            <span class="muted-note" id="rp-period"></span>
          </div>
        </div>
      </div>
    </div>
