<?php defined('KSI_APP') || exit; ?>
<!-- ══ APP BODY ══════════════════════════════════════════════════════════ -->
<div id="app-body">
  <div id="sidebar-backdrop" aria-hidden="true"></div>

  <!-- ─ SIDEBAR ─────────────────────────────────────────────────────── -->
  <aside id="sidebar">
    <div class="sidebar-section-label">Main Menu</div>

    <!-- ── Scrollable Nav Area ── -->
    <div id="sidebar-nav">

    <!-- Dashboard -->
    <div class="nav-child" id="nav-dashboard" onclick="showModule('dashboard')" style="padding:10px 16px;display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;border-radius:6px;margin:4px 8px 8px">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      <span class="ng-label">Dashboard</span>
    </div>

    <!-- Patient Group -->
    <div class="nav-group expanded" id="ng-patient">
      <div class="nav-group-header" onclick="toggleGroup('ng-patient')">
        <span class="ng-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </span>
        <span class="ng-label">Patient</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-manage-visit" onclick="showModule('manage-visit')">Manage Visit</div>
        <div class="nav-child" id="nav-manage-patient" onclick="showModule('manage-patient')">Manage Patient</div>
      </div>
    </div>

    <!-- Cashier -->
    <div class="nav-group" id="ng-cashier">
      <div class="nav-group-header" onclick="toggleGroup('ng-cashier')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></span>
        <span class="ng-label">Cashier</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-manage-payments" onclick="showModule('cashier-payments')">Manage Payments</div>
        <div class="nav-child" id="nav-refunds" onclick="showModule('cashier-refunds')">Refund Requests</div>
        <div class="nav-child" id="nav-pay-history" onclick="showModule('cashier-history')">Payment History</div>
      </div>
    </div>

    <!-- Approvals — shield-check icon, no red dot -->
    <div class="nav-group" id="ng-approvals">
      <div class="nav-group-header" onclick="toggleGroup('ng-approvals')">
        <span class="ng-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        </span>
        <span class="ng-label">Approvals</span>
        <span class="notif-badge" id="approvals-badge" style="position:static;margin-left:auto;margin-right:4px;display:none">0</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-approvals-pending" onclick="showModule('approvals-pending')">Pending Approvals</div>
        <div class="nav-child" id="nav-approvals-history" onclick="showModule('approvals-history')">Approval History</div>
      </div>
    </div>

    <!-- Clinical -->
    <div class="nav-group" id="ng-clinical">
      <div class="nav-group-header" onclick="toggleGroup('ng-clinical')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
        <span class="ng-label">Clinical</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-triage" onclick="showModule('triage')">Triage</div>
        <div class="nav-child" id="nav-optometry" onclick="showModule('optometry')">Optometry</div>
        <div class="nav-child" id="nav-ophthalmology" onclick="showModule('ophthalmology')">Ophthalmology</div>
        <div class="nav-child" id="nav-patient-history" onclick="showModule('patient-history')">Patient History</div>
      </div>
    </div>

    <!-- Theatre -->
    <div class="nav-group" id="ng-theatre">
      <div class="nav-group-header" onclick="toggleGroup('ng-theatre')">
        <span class="ng-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </span>
        <span class="ng-label">Theatre</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-theatre-booking"  onclick="showModule('theatre-booking')">Theatre Booking</div>
        <div class="nav-child" id="nav-theatre-preop"    onclick="showModule('theatre-preop')">Pre-Op</div>
        <div class="nav-child" id="nav-theatre-intraop"  onclick="showModule('theatre-intraop')">Intra-Op</div>
        <div class="nav-child" id="nav-theatre-postop"   onclick="showModule('theatre-postop')">Post-Op</div>
        <div class="nav-child" id="nav-theatre-history"  onclick="showModule('theatre-history')">Theatre History</div>
      </div>
    </div>

    <!-- Diagnostic -->
    <div class="nav-group" id="ng-diagnostic">
      <div class="nav-group-header" onclick="toggleGroup('ng-diagnostic')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
        <span class="ng-label">Diagnostic</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-diag-booking" onclick="showModule('diag-booking')">Diagnostic Booking</div>
        <div class="nav-child" id="nav-diag-report"  onclick="showModule('diag-report')">Diagnostic Report</div>
        <div class="nav-child" id="nav-diag-history" onclick="showModule('diag-history')">Diagnostic History</div>
      </div>
    </div>

    <!-- Optical Room -->
    <div class="nav-group" id="ng-optical">
      <div class="nav-group-header" onclick="toggleGroup('ng-optical')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></span>
        <span class="ng-label">Optical Room</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-opt-incoming" onclick="showModule('opt-incoming')">Incoming Prescriptions</div>
        <div class="nav-child" id="nav-opt-active" onclick="showModule('opt-active')">Active Orders</div>
        <div class="nav-child" id="nav-opt-dispensing" onclick="showModule('opt-dispensing')">Ready for Dispensing</div>
        <div class="nav-child" id="nav-opt-completed" onclick="showModule('opt-completed')">Completed Orders</div>
        <div class="nav-child" id="nav-opt-external" onclick="showModule('opt-external')">External Customer</div>
        <div class="nav-child" id="nav-opt-frames" onclick="showModule('opt-frames')">Frames</div>
      </div>
    </div>

    <!-- Pharmacy -->
    <div class="nav-group" id="ng-pharmacy">
      <div class="nav-group-header" onclick="toggleGroup('ng-pharmacy')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg></span>
        <span class="ng-label">Pharmacy</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-pharm-orders"    onclick="showModule('pharm-orders')">New Medication Orders</div>
        <div class="nav-child" id="nav-pharm-dispense"  onclick="showModule('pharm-dispense')">Dispense Medication</div>
        <div class="nav-child" id="nav-pharm-partial"   onclick="showModule('pharm-partial')">Partial Dispensing</div>
        <div class="nav-child" id="nav-pharm-balance"   onclick="showModule('pharm-balance')">Balance to Collect</div>
        <div class="nav-child" id="nav-pharm-refills"   onclick="showModule('pharm-refills')">Refills</div>
        <div class="nav-child" id="nav-pharm-history"   onclick="showModule('pharm-history')">Orders History</div>
        <div class="nav-child" id="nav-pharm-inventory" onclick="showModule('pharm-inventory')">Inventory</div>
      </div>
    </div>

    <!-- Requisition -->
    <div class="nav-group" id="ng-requisition">
      <div class="nav-group-header" onclick="toggleGroup('ng-requisition')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg></span>
        <span class="ng-label">Requisition</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-req-form"     onclick="showModule('stores-requisition-form')">Requisition Form</div>
        <div class="nav-child" id="nav-req-my"       onclick="showModule('stores-my-requisitions')">My Requisitions</div>
        <div class="nav-child" id="nav-req-requests" onclick="showModule('stores-requisition-requests')">Requisition Requests</div>
        <div class="nav-child" id="nav-req-history"  onclick="showModule('stores-requisition-history')">Requisition History</div>
      </div>
    </div>

    <!-- Stores -->
    <div class="nav-group" id="ng-stores">
      <div class="nav-group-header" onclick="toggleGroup('ng-stores')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 8h14M5 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm14 0a2 2 0 1 0 4 0 2 2 0 0 0-4 0zM3 8l2 13h14l2-13"/><path d="M10 8v5m4-5v5"/></svg></span>
        <span class="ng-label">Stores</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-stores-stock"       onclick="showModule('stores-stock')">Inventory</div>
        <div class="nav-child" id="nav-stores-quotations"  onclick="showModule('stores-quotations')">Purchase Requests</div>
        <div class="nav-child" id="nav-stores-lpos"        onclick="showModule('stores-lpos')">Purchase Orders</div>
        <div class="nav-child" id="nav-stores-grns"        onclick="showModule('stores-grns')">Goods Received Notes</div>
        <div class="nav-child" id="nav-stores-history"     onclick="showModule('stores-history')">History</div>
      </div>
    </div>

    <!-- Administration -->
    <div class="nav-group" id="ng-admin">
      <div class="nav-group-header" onclick="toggleGroup('ng-admin')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg></span>
        <span class="ng-label">Administration</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-users-roles" onclick="showModule('users-roles')">Users &amp; Roles</div>
        <div class="nav-child" id="nav-settings-users" onclick="showModule('settings-users')">Settings</div>
        <div class="nav-child" id="nav-price-list" onclick="showModule('price-list')">Price List</div>
        <div class="nav-child" id="nav-admin-reports" onclick="showModule('admin-reports')">Reports</div>
      </div>
    </div>

    <!-- MTUHA registers -->
    <div class="nav-group" id="ng-mtuha">
      <div class="nav-group-header" onclick="toggleGroup('ng-mtuha')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
        <span class="ng-label">MTUHA</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-mtuha-main"    onclick="showModule('mtuha-main')">Main MTUHA</div>
        <div class="nav-child" id="nav-mtuha-surgery" onclick="showModule('mtuha-surgery')">Surgery MTUHA</div>
        <div class="nav-child" id="nav-mtuha-pediatric" onclick="showModule('mtuha-pediatric')">Pediatric Report</div>
        <div class="nav-child" id="nav-mtuha-glaucoma" onclick="showModule('mtuha-glaucoma')">Glaucoma Report</div>
      </div>
    </div>

    <!-- Reports -->
    <div class="nav-group" id="ng-reports">
      <div class="nav-group-header" onclick="toggleGroup('ng-reports')">
        <span class="ng-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
        <span class="ng-label">Reports</span>
        <svg class="ng-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
      </div>
      <div class="nav-children">
        <div class="nav-child" id="nav-report-terminal"      onclick="showModule('report-terminal')">Report Terminal</div>
      </div>
    </div>

    </div><!-- end #sidebar-nav -->

    <div class="sidebar-footer">
      <div class="sidebar-footer-item" id="nav-audit-log" onclick="showModule('audit-log')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
        <span>Audit Log</span>
      </div>
      <div class="sidebar-footer-item" onclick="doLogout()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Logout</span>
      </div>
    </div>
  </aside>

  <!-- ─ MAIN CONTENT ─────────────────────────────────────────────────── -->
  <main id="main">

