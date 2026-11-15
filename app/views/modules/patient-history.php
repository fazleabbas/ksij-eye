<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: PATIENT HISTORY ═══════════════════════════════════════ -->
    <div id="module-patient-history" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Patient History</div>
          <div class="page-breadcrumb">
            <span>Clinical</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Patient History</span>
          </div>
        </div>
      </div>
      <div class="section-padding" style="padding-top:0">
        <div class="card">
          <div class="muted-note" style="margin-bottom:8px">Find any registered patient to read their past visits. Nothing can be changed here.</div>
          <div class="search-wrap" style="max-width:none;position:relative">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="ph-search" placeholder="Search by name, phone or file number…" autocomplete="off">
            <div id="ph-results"></div>
          </div>
        </div>
        <div id="ph-patient" style="margin-top:14px"></div>
        <div class="card" id="ph-panel" style="display:none;margin-top:14px">
          <div class="ophtho-right-tabs" id="ph-tabs">
            <div class="ophtho-right-tab active" data-tab="visits" onclick="histTab('visits')">History</div>
            <div class="ophtho-right-tab" data-tab="orders" onclick="histTab('orders')">Orders</div>
            <div class="ophtho-right-tab" data-tab="files" onclick="histTab('files')">Scanned</div>
          </div>
          <div class="ophtho-right-body" id="ph-body"></div>
        </div>
      </div>
    </div>
