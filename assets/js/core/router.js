'use strict';

function showModule(mod, name='') {
  // Screens open only for users whose roles grant them; the API independently
  // refuses any data the user is not permitted to see.
  if(!KSI.canModule(mod)){showToast('Access Denied','You do not have permission to open this screen','error');return;}
  uiDefaultDates();
  ['module-dashboard','module-manage-visit','module-manage-patient','module-cashier-payments','module-cashier-refunds',
   'module-cashier-history','module-approvals-pending','module-approvals-history','module-triage',
   'module-optometry','module-ophthalmology','module-patient-history',
   'module-theatre-booking','module-theatre-preop','module-theatre-intraop','module-theatre-postop','module-theatre-history',
   'module-diag-booking','module-diag-report','module-diag-history',
   'module-opt-incoming','module-opt-create','module-opt-active','module-opt-dispensing','module-opt-completed','module-opt-external','module-opt-frames',
   'module-pharm-orders','module-pharm-dispense','module-pharm-partial','module-pharm-balance','module-pharm-refills','module-pharm-history','module-pharm-inventory',
   'module-stores-requisition-form','module-stores-my-requisitions','module-stores-requisition-requests','module-stores-requisition-history',
   'module-stores-stock','module-stores-quotations','module-stores-lpos','module-stores-grns','module-stores-history',
   'module-report-master','module-report-financial','module-report-investigation','module-report-surgery','module-report-terminal',
   'module-users-roles','module-settings-users','module-price-list','module-admin-reports','module-audit-log',
   'module-placeholder']
    .forEach(id=>{ const el=document.getElementById(id); if(el) el.classList.add('hidden'); });
  document.querySelectorAll('.nav-child').forEach(c=>c.classList.remove('active'));
  document.querySelectorAll('.sidebar-footer-item').forEach(c=>c.classList.remove('active'));

  // Helper: expand the nav group containing a given child, if sidebar is open
  function expandParentGroup(childId) {
    const child = document.getElementById(childId);
    if (!child || sidebarCollapsed) return;
    const group = child.closest('.nav-group');
    if (group) group.classList.add('expanded');
  }

  if(mod==='dashboard'){
    document.getElementById('module-dashboard').classList.remove('hidden');
    document.getElementById('nav-dashboard')?.classList.add('active');
    dbLoad();
  } else if(mod==='manage-visit'){
    document.getElementById('module-manage-visit').classList.remove('hidden');
    document.getElementById('nav-manage-visit')?.classList.add('active');
    expandParentGroup('nav-manage-visit');
    vsRenderModule();
  } else if(mod==='manage-patient'){
    document.getElementById('module-manage-patient').classList.remove('hidden');
    ptRenderModule();
    document.getElementById('nav-manage-patient')?.classList.add('active');
    expandParentGroup('nav-manage-patient');
  } else if(mod==='cashier-payments'){
    document.getElementById('module-cashier-payments').classList.remove('hidden');
    csRenderPayments();
    document.getElementById('nav-manage-payments').classList.add('active');
    expandParentGroup('nav-manage-payments');
  } else if(mod==='cashier-refunds'){
    document.getElementById('module-cashier-refunds').classList.remove('hidden');
    rfRenderModule();
    document.getElementById('nav-refunds').classList.add('active');
    expandParentGroup('nav-refunds');
  } else if(mod==='cashier-history'){
    document.getElementById('module-cashier-history').classList.remove('hidden');
    csRenderHistory();
    document.getElementById('nav-pay-history').classList.add('active');
    expandParentGroup('nav-pay-history');
  } else if(mod==='approvals-pending'){
    document.getElementById('module-approvals-pending').classList.remove('hidden');
    apRenderPending();
    document.getElementById('nav-approvals-pending').classList.add('active');
    expandParentGroup('nav-approvals-pending');
  } else if(mod==='approvals-history'){
    document.getElementById('module-approvals-history').classList.remove('hidden');
    apRenderHistory();
    document.getElementById('nav-approvals-history').classList.add('active');
    expandParentGroup('nav-approvals-history');
  } else if(mod==='triage'){
    document.getElementById('module-triage').classList.remove('hidden');
    TRIAGE.open();
    document.getElementById('nav-triage').classList.add('active');
    expandParentGroup('nav-triage');
  } else if(mod==='optometry'){
    document.getElementById('module-optometry').classList.remove('hidden');
    OPTO.open();
    document.getElementById('nav-optometry').classList.add('active');
    expandParentGroup('nav-optometry');
  } else if(mod==='ophthalmology'){
    document.getElementById('module-ophthalmology').classList.remove('hidden');
    OPH.open();
    document.getElementById('nav-ophthalmology').classList.add('active');
    expandParentGroup('nav-ophthalmology');
  } else if(mod==='patient-history'){
    document.getElementById('module-patient-history').classList.remove('hidden');
    histOpen();
    document.getElementById('nav-patient-history')?.classList.add('active');
    expandParentGroup('nav-patient-history');
  } else if(mod==='theatre-booking'){
    document.getElementById('module-theatre-booking').classList.remove('hidden');
    thOpenBookings();
    document.getElementById('nav-theatre-booking')?.classList.add('active');
    expandParentGroup('nav-theatre-booking');
  } else if(mod==='theatre-preop'){
    document.getElementById('module-theatre-preop').classList.remove('hidden');
    thOpenPreop();
    document.getElementById('nav-theatre-preop')?.classList.add('active');
    expandParentGroup('nav-theatre-preop');
  } else if(mod==='theatre-intraop'){
    document.getElementById('module-theatre-intraop').classList.remove('hidden');
    thOpenOperations();
    document.getElementById('nav-theatre-intraop')?.classList.add('active');
    expandParentGroup('nav-theatre-intraop');
  } else if(mod==='theatre-postop'){
    document.getElementById('module-theatre-postop').classList.remove('hidden');
    thOpenPostop();
    document.getElementById('nav-theatre-postop')?.classList.add('active');
    expandParentGroup('nav-theatre-postop');
  } else if(mod==='theatre-history'){
    document.getElementById('module-theatre-history').classList.remove('hidden');
    thOpenHistory();
    document.getElementById('nav-theatre-history')?.classList.add('active');
    expandParentGroup('nav-theatre-history');
  } else if(mod==='diag-booking'){
    document.getElementById('module-diag-booking').classList.remove('hidden');
    diagOpenBooking();
    document.getElementById('nav-diag-booking')?.classList.add('active');
    expandParentGroup('nav-diag-booking');
  } else if(mod==='diag-report'){
    document.getElementById('module-diag-report').classList.remove('hidden');
    diagOpenReport();
    document.getElementById('nav-diag-report')?.classList.add('active');
    expandParentGroup('nav-diag-report');
  } else if(mod==='diag-history'){
    document.getElementById('module-diag-history').classList.remove('hidden');
    diagOpenHistory();
    document.getElementById('nav-diag-history')?.classList.add('active');
    expandParentGroup('nav-diag-history');
  } else if(mod==='opt-create'){
    // Reached from Incoming Prescriptions; the menu stays on that entry.
    document.getElementById('module-opt-create').classList.remove('hidden');
    document.getElementById('nav-opt-incoming')?.classList.add('active');
    expandParentGroup('nav-opt-incoming');
  } else if(mod==='opt-incoming'){
    document.getElementById('module-opt-incoming').classList.remove('hidden');
    optOpenIncoming();
    document.getElementById('nav-opt-incoming')?.classList.add('active');
    expandParentGroup('nav-opt-incoming');
  } else if(mod==='opt-active'){
    document.getElementById('module-opt-active').classList.remove('hidden');
    optOpenActive();
    document.getElementById('nav-opt-active')?.classList.add('active');
    expandParentGroup('nav-opt-active');
  } else if(mod==='opt-dispensing'){
    document.getElementById('module-opt-dispensing').classList.remove('hidden');
    optOpenDispensing();
    document.getElementById('nav-opt-dispensing')?.classList.add('active');
    expandParentGroup('nav-opt-dispensing');
  } else if(mod==='opt-completed'){
    document.getElementById('module-opt-completed').classList.remove('hidden');
    optOpenCompleted();
    document.getElementById('nav-opt-completed')?.classList.add('active');
    expandParentGroup('nav-opt-completed');
  } else if(mod==='opt-external'){
    document.getElementById('module-opt-external').classList.remove('hidden');
    optOpenExternal();
    document.getElementById('nav-opt-external')?.classList.add('active');
    expandParentGroup('nav-opt-external');
  } else if(mod==='opt-frames'){
    document.getElementById('module-opt-frames').classList.remove('hidden');
    optOpenFrames();
    document.getElementById('nav-opt-frames')?.classList.add('active');
    expandParentGroup('nav-opt-frames');
  } else if(mod==='pharm-orders') {
    document.getElementById('module-pharm-orders').classList.remove('hidden');
    phOpenOrders();
    document.getElementById('nav-pharm-orders')?.classList.add('active');
    expandParentGroup('nav-pharm-orders');
  } else if(mod==='pharm-dispense') {
    document.getElementById('module-pharm-dispense').classList.remove('hidden');
    phOpenDispenseList();
    document.getElementById('nav-pharm-dispense')?.classList.add('active');
    expandParentGroup('nav-pharm-dispense');
  } else if(mod==='pharm-partial') {
    document.getElementById('module-pharm-partial').classList.remove('hidden');
    phOpenPartial();
    document.getElementById('nav-pharm-partial')?.classList.add('active');
    expandParentGroup('nav-pharm-partial');
  } else if(mod==='pharm-balance') {
    document.getElementById('module-pharm-balance').classList.remove('hidden');
    phOpenBalance();
    document.getElementById('nav-pharm-balance')?.classList.add('active');
    expandParentGroup('nav-pharm-balance');
  } else if(mod==='pharm-refills') {
    document.getElementById('module-pharm-refills').classList.remove('hidden');
    phOpenRefills();
    document.getElementById('nav-pharm-refills')?.classList.add('active');
    expandParentGroup('nav-pharm-refills');
  } else if(mod==='pharm-history') {
    document.getElementById('module-pharm-history').classList.remove('hidden');
    phOpenHistory();
    document.getElementById('nav-pharm-history')?.classList.add('active');
    expandParentGroup('nav-pharm-history');
  } else if(mod==='pharm-inventory') {
    document.getElementById('module-pharm-inventory').classList.remove('hidden');
    phOpenStock();
    document.getElementById('nav-pharm-inventory')?.classList.add('active');
    expandParentGroup('nav-pharm-inventory');
  } else if(mod==='stores-requisition-form') {
    document.getElementById('module-stores-requisition-form').classList.remove('hidden');
    stOpenRequisitionForm();
    document.getElementById('nav-req-form')?.classList.add('active');
    expandParentGroup('nav-req-form');
  } else if(mod==='stores-my-requisitions') {
    document.getElementById('module-stores-my-requisitions').classList.remove('hidden');
    stOpenMine();
    document.getElementById('nav-req-my')?.classList.add('active');
    expandParentGroup('nav-req-my');
  } else if(mod==='stores-requisition-requests') {
    document.getElementById('module-stores-requisition-requests').classList.remove('hidden');
    stOpenRequests();
    document.getElementById('nav-req-requests')?.classList.add('active');
    expandParentGroup('nav-req-requests');
  } else if(mod==='stores-requisition-history') {
    document.getElementById('module-stores-requisition-history').classList.remove('hidden');
    stOpenRequisitionHistory();
    document.getElementById('nav-req-history')?.classList.add('active');
    expandParentGroup('nav-req-history');
  } else if(mod==='stores-stock') {
    document.getElementById('module-stores-stock').classList.remove('hidden');
    stOpenStock();
    document.getElementById('nav-stores-stock')?.classList.add('active');
    expandParentGroup('nav-stores-stock');
  } else if(mod==='stores-quotations') {
    document.getElementById('module-stores-quotations').classList.remove('hidden');
    stOpenQuotations();
    document.getElementById('nav-stores-quotations')?.classList.add('active');
    expandParentGroup('nav-stores-quotations');
  } else if(mod==='stores-lpos') {
    document.getElementById('module-stores-lpos').classList.remove('hidden');
    stOpenOrders();
    document.getElementById('nav-stores-lpos')?.classList.add('active');
    expandParentGroup('nav-stores-lpos');
  } else if(mod==='stores-grns') {
    document.getElementById('module-stores-grns').classList.remove('hidden');
    stOpenDeliveries();
    document.getElementById('nav-stores-grns')?.classList.add('active');
    expandParentGroup('nav-stores-grns');
  } else if(mod==='stores-history') {
    document.getElementById('module-stores-history').classList.remove('hidden');
    stOpenProcurementHistory();
    document.getElementById('nav-stores-history')?.classList.add('active');
    expandParentGroup('nav-stores-history');
  } else if(mod==='report-terminal'||mod==='report-master'||mod==='report-financial'||mod==='report-investigation'||mod==='report-surgery') {
    document.getElementById('module-report-terminal').classList.remove('hidden');
    rpOpen();
    document.getElementById('nav-report-terminal')?.classList.add('active');
    expandParentGroup('nav-report-terminal');
  } else if(['mtuha-main','mtuha-surgery','mtuha-pediatric','mtuha-glaucoma'].includes(mod)) {
    // The registers are reports like any other; the menu just opens the screen on them.
    document.getElementById('module-report-terminal').classList.remove('hidden');
    rpOpen({ 'mtuha-main': 'mtuha_main', 'mtuha-surgery': 'mtuha_surgery', 'mtuha-pediatric': 'pediatric', 'mtuha-glaucoma': 'glaucoma' }[mod]);
    document.getElementById(`nav-${mod}`)?.classList.add('active');
    expandParentGroup(`nav-${mod}`);
  } else if(mod==='users-roles'){
    document.getElementById('module-users-roles').classList.remove('hidden');
    document.getElementById('nav-users-roles')?.classList.add('active');
    expandParentGroup('nav-users-roles');
    renderUsersRolesModule();
  } else if(mod==='settings-users'){
    document.getElementById('module-settings-users').classList.remove('hidden');
    document.getElementById('nav-settings-users')?.classList.add('active');
    expandParentGroup('nav-settings-users');
    renderSettingsUsersModule();
  } else if(mod==='price-list'){
    document.getElementById('module-price-list').classList.remove('hidden');
    document.getElementById('nav-price-list')?.classList.add('active');
    expandParentGroup('nav-price-list');
    renderPriceListModule();
  } else if(mod==='admin-reports'){
    document.getElementById('module-admin-reports').classList.remove('hidden');
    document.getElementById('nav-admin-reports')?.classList.add('active');
    expandParentGroup('nav-admin-reports');
    renderAdminReportsModule();
  } else if(mod==='audit-log'){
    document.getElementById('module-audit-log').classList.remove('hidden');
    document.getElementById('nav-audit-log')?.classList.add('active');
    renderAuditLogModule();
  } else {
    document.getElementById('module-placeholder').classList.remove('hidden');
    document.getElementById('placeholder-name').textContent=name+' Module';
  }
}

