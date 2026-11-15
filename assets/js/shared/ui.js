'use strict';
// ─── SHARED UI HELPERS (database-backed screens) ───────────────────────────
// Labels and badges for API codes, money/date formatting, tables, pagination,
// debounced input, and the patient search picker used by several modals.

const UI_LABELS = {
  visitStatus: {
    pending_payment: 'Pending Payment', waiting_triage: 'Waiting For Triage', waiting_doctor: 'Waiting For Doctor',
    waiting_investigation: 'Waiting For Investigation', waiting_optometry: 'Waiting For Optometry',
    waiting_ophthalmology: 'Waiting For Ophthalmology', in_progress: 'In Progress', completed: 'Complete', cancelled: 'Cancelled',
  },
  visitType: { consultation: 'Consultation', follow_up: 'Follow-Up' },
  subType: { normal: 'Normal', fast_track: 'Fast Track', doctor_follow_up: 'Doctor Follow-Up', surgery_follow_up: 'Surgery Follow-Up' },
  destination: { doctor_room: 'Doctor Room', triage: 'Triage', investigation: 'Investigation', optometry: 'Optometry' },
  billStatus: {
    pending: 'Pending', discount_requested: 'Discount Requested', cancellation_requested: 'Cancellation Requested',
    paid: 'Paid', exempted: 'Exempted', cancelled: 'Cancelled', refunded: 'Refunded', partially_refunded: 'Partly Refunded',
    no_bill: 'No Bill Yet',
  },
  billType: { consultation: 'Consultation', investigation: 'Investigation', optical: 'Optical', surgery: 'Surgery', pharmacy: 'Pharmacy', other: 'Other' },
  approvalStatus: { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' },
  approvalType: { discount: 'Discount', bill_cancellation: 'Bill Cancellation', refund: 'Refund', diagnostic_cancellation: 'Diagnostic Cancellation' },
  refundStatus: { requested: 'Awaiting Approval', approved: 'Approved — To Pay Out', rejected: 'Rejected', paid_out: 'Paid Out' },
  method: { cash: 'Cash', lipa_number: 'Lipa Number' },
  gender: { male: 'Male', female: 'Female' },
  eye: { right: 'Right eye', left: 'Left eye', both: 'Both eyes' },
  investigationStatus: { draft: 'Draft', requested: 'Requested', booked: 'Booked At Diagnostics', cancelled: 'Cancelled' },
  diagnosticStatus: {
    scheduled: 'Scheduled', arrived: 'Checked In', completed: 'Reported',
    cancellation_requested: 'Cancellation Requested', cancelled: 'Cancelled',
  },
  diagnosticSource: { desk: 'Booked at the desk', ophthalmology: 'Requested by the doctor', visit: 'From a visit', optometry: 'Requested by optometry' },
  requisitionStatus: {
    draft: 'Draft', submitted: 'Sent To The Store', approved: 'Approved', rejected: 'Refused',
    partly_issued: 'Partly Issued', issued: 'Issued', cancelled: 'Cancelled',
  },
  requisitionPriority: { normal: 'Normal', urgent: 'Urgent', emergency: 'Emergency' },
  quotationStatus: { draft: 'Draft', under_review: 'Under Review', selected: 'Chosen', rejected: 'Not Chosen' },
  orderStatus: {
    draft: 'Draft', pending_admin: 'Waiting For Administration', pending_store: 'Waiting For The Store Manager',
    approved: 'Approved', sent: 'Sent To Supplier', closed: 'Closed', cancelled: 'Cancelled',
  },
  deliveryStatus: { recorded: 'Recorded', verified: 'Checked', approved: 'In Stock', cancelled: 'Cancelled' },
  storeMovement: { received: 'Received', issued: 'Issued', adjusted: 'Correction', returned: 'Returned', written_off: 'Written Off' },
  expiryStatus: { none: '', ok: 'In Date', expiring_soon: 'Expiring Soon', expired: 'Expired' },
  theatreStatus: {
    booked: 'Booked', cleared: 'Cleared For Theatre', in_theatre: 'In Theatre', post_op: 'Post-Op Care',
    discharged: 'Discharged', cancellation_requested: 'Cancellation Requested', cancelled: 'Cancelled',
  },
  pharmacyStatus: {
    pending_review: 'Waiting For Review', awaiting_payment: 'Waiting For Payment', paid: 'Paid — Ready',
    partially_dispensed: 'Partly Handed Over', completed: 'Completed', cancelled: 'Cancelled',
  },
  stockLevel: { in_stock: 'In Stock', low_stock: 'Low Stock', out_of_stock: 'Out Of Stock' },
  stockReason: { received: 'Received', dispensed: 'Dispensed', adjusted: 'Correction', returned: 'Returned' },
  opticalStatus: {
    ordered: 'Ordered', sent_to_supplier: 'At Supplier', lens_received: 'Lens Received',
    ready_for_dispensing: 'Ready To Hand Over', dispensed: 'Handed Over',
    cancellation_requested: 'Cancellation Requested', cancelled: 'Cancelled',
  },
  surgeryStatus: { draft: 'Draft', recommended: 'Recommended', booked: 'Booked', cancelled: 'Cancelled' },
  urgency: { elective: 'Elective', urgent: 'Urgent', emergency: 'Emergency' },
  priority: { routine: 'Routine', important: 'Important', urgent: 'Urgent' },
  followUpStatus: { due: 'Due', booked: 'Booked', cancelled: 'Cancelled' },
  clinicalSource: { optometry: 'Optometry', ophthalmology: 'Ophthalmology' },
  mtuhaOutcome: {
    admission: 'Admission (Surgery)', referral: 'Referral', follow_up: 'Discharged — Follow-Up',
    discharged: 'Discharged', pending: 'In Queue / Pending', cancelled: 'Cancelled',
  },
};

const UI_BADGES = {
  visitStatus: {
    pending_payment: 'badge-pending', waiting_triage: 'badge-waiting-triage', waiting_doctor: 'badge-waiting-doctor',
    waiting_investigation: 'badge-waiting-inv', waiting_optometry: 'badge-waiting-doctor', waiting_ophthalmology: 'badge-waiting-doctor',
    in_progress: 'badge-in-progress', completed: 'badge-complete', cancelled: 'badge-cancelled',
  },
  billStatus: {
    pending: 'badge-pending', discount_requested: 'badge-discount-req', cancellation_requested: 'badge-cancel-req', paid: 'badge-paid',
    exempted: 'badge-exempted', cancelled: 'badge-cancelled', refunded: 'badge-refund-req', partially_refunded: 'badge-refund-req',
  },
  approvalStatus: { pending: 'badge-appr-pending', approved: 'badge-approved-act', rejected: 'badge-rejected' },
  refundStatus: { requested: 'badge-appr-pending', approved: 'badge-approved', rejected: 'badge-rejected', paid_out: 'badge-paid' },
  visitType: { consultation: 'badge-consult', follow_up: 'badge-followup' },
  investigationStatus: { requested: 'badge-pending', booked: 'badge-paid', cancelled: 'badge-cancelled' },
  diagnosticStatus: {
    scheduled: 'badge-pending', arrived: 'badge-in-progress', completed: 'badge-complete',
    cancellation_requested: 'badge-cancel-req', cancelled: 'badge-cancelled',
  },
  requisitionStatus: {
    draft: 'badge-stores-draft', submitted: 'badge-stores-submitted', approved: 'badge-stores-approved',
    rejected: 'badge-stores-rejected', partly_issued: 'badge-stores-partial', issued: 'badge-stores-fulfilled',
    cancelled: 'badge-cancelled',
  },
  requisitionPriority: { normal: 'badge-stores-normal', urgent: 'badge-stores-urgent', emergency: 'badge-stores-emergency' },
  quotationStatus: { draft: 'badge-stores-draft', under_review: 'badge-stores-processing', selected: 'badge-stores-selected', rejected: 'badge-stores-rejected' },
  orderStatus: {
    draft: 'badge-stores-draft', pending_admin: 'badge-stores-pending-appr', pending_store: 'badge-stores-pending-appr',
    approved: 'badge-stores-approved', sent: 'badge-stores-sent', closed: 'badge-stores-closed', cancelled: 'badge-cancelled',
  },
  deliveryStatus: { recorded: 'badge-stores-submitted', verified: 'badge-stores-verified', approved: 'badge-stores-fulfilled', cancelled: 'badge-cancelled' },
  expiryStatus: { ok: 'badge-expiry-ok', expiring_soon: 'badge-expiry-soon', expired: 'badge-expiry-expired', none: 'badge-expiry-none' },
  theatreStatus: {
    booked: 'badge-pending', cleared: 'badge-approved', in_theatre: 'badge-in-progress', post_op: 'badge-waiting-doctor',
    discharged: 'badge-complete', cancellation_requested: 'badge-cancel-req', cancelled: 'badge-cancelled',
  },
  pharmacyStatus: {
    pending_review: 'badge-pharm-pending', awaiting_payment: 'badge-pharm-await-pay', paid: 'badge-pharm-paid',
    partially_dispensed: 'badge-pharm-partial', completed: 'badge-pharm-completed', cancelled: 'badge-pharm-cancelled',
  },
  stockLevel: { in_stock: 'badge-stock-in', low_stock: 'badge-stock-low', out_of_stock: 'badge-stock-out' },
  opticalStatus: {
    ordered: 'badge-pending', sent_to_supplier: 'badge-in-progress', lens_received: 'badge-in-progress',
    ready_for_dispensing: 'badge-approved', dispensed: 'badge-complete',
    cancellation_requested: 'badge-cancel-req', cancelled: 'badge-cancelled',
  },
  surgeryStatus: { recommended: 'badge-waiting-doctor', booked: 'badge-complete', cancelled: 'badge-cancelled' },
  followUpStatus: { due: 'badge-pending', booked: 'badge-complete', cancelled: 'badge-cancelled' },
  priority: { urgent: 'badge-cancel-req', important: 'badge-discount-req' },
};

function uiLabel(group, value) {
  return UI_LABELS[group]?.[value] ?? value ?? '—';
}

function uiBadge(group, value, text) {
  return `<span class="badge ${UI_BADGES[group]?.[value] || 'badge-normal'}">${KSI.esc(text ?? uiLabel(group, value))}</span>`;
}

/** Today's date (YYYY-MM-DD) in the hospital's time zone. */
function uiToday() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Dar_es_Salaam' }).format(new Date());
}

/**
 * History lists open on today's records: their From / To filters (marked data-default-today)
 * are set to today the first time the screen opens; staff can widen or clear them after that.
 */
function uiDefaultDates(root = document) {
  root.querySelectorAll('input[type="date"][data-default-today]:not([data-defaulted])').forEach(input => {
    input.dataset.defaulted = '1';
    if (!input.value) input.value = uiToday();
  });
}

/** A "Today" button that fills the date field next to it. */
function uiTodayButton(inputId) {
  return `<button class="btn btn-ghost btn-sm" type="button" onclick="(i => { i.value = uiToday(); i.dispatchEvent(new Event('change', { bubbles: true })); })(document.getElementById('${inputId}'))">Today</button>`;
}

function uiField(label, valueHtml, wide = false) {
  return `<div class="detail-field"${wide ? ' style="grid-column:1/-1"' : ''}><div class="detail-label">${KSI.esc(label)}</div><div class="detail-value">${valueHtml}</div></div>`;
}

function uiMoney(amount) {
  return 'TZS ' + KSI.fmtMoney(amount);
}

/** "2026-09-13" → "13 Sep 2026" (calendar dates from the API are already local). */
function uiDate(ymd) {
  if (!ymd) return '—';
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

function uiTableMessage(tbodyId, columns, message) {
  document.getElementById(tbodyId).innerHTML = `<tr class="table-state"><td colspan="${columns}">${KSI.esc(message)}</td></tr>`;
}

function uiPatientCell(patient) {
  if (!patient) return '—';
  return `<div class="patient-name">${KSI.esc(patient.full_name)}</div><div class="sub-text">${KSI.esc(patient.reg_no)}</div>`;
}

function uiDebounce(fn, wait = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
}

/** Renders ‹ 1 2 … › into containerId; onPage(page) is called on click. */
function uiPagination(containerId, page, perPage, total, onPage) {
  const container = document.getElementById(containerId);
  const pages = Math.max(1, Math.ceil(total / perPage));
  if (pages <= 1) {
    container.innerHTML = '';
    return;
  }
  const numbers = [];
  for (let p = 1; p <= pages; p++) {
    if (p === 1 || p === pages || Math.abs(p - page) <= 2) numbers.push(p);
    else if (numbers[numbers.length - 1] !== '…') numbers.push('…');
  }
  container.innerHTML = `<button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>`
    + numbers.map(n => n === '…' ? '<span class="page-btn" style="border:none">…</span>'
      : `<button class="page-btn${n === page ? ' active' : ''}" data-page="${n}">${n}</button>`).join('')
    + `<button class="page-btn" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''} aria-label="Next page">›</button>`;
  container.querySelectorAll('button[data-page]').forEach(button => {
    button.addEventListener('click', () => onPage(Number(button.dataset.page)));
  });
}

function uiCountText(page, perPage, total, noun) {
  if (!total) return `0 ${noun}`;
  const first = (page - 1) * perPage + 1;
  return `Showing ${first}–${Math.min(total, page * perPage)} of ${total} ${noun}`;
}

function uiRadioValue(name) {
  return document.querySelector(`input[name="${name}"]:checked`)?.value || '';
}

function uiSetRadio(name, value) {
  document.querySelectorAll(`input[name="${name}"]`).forEach(input => {
    input.checked = input.value === value;
    input.closest('.radio-card')?.classList.toggle('selected', input.checked);
  });
}

/** Keeps .radio-card highlighting in sync inside a container. */
function uiSyncRadioCards(container) {
  container.querySelectorAll('.radio-card input[type="radio"]').forEach(input => {
    input.closest('.radio-card').classList.toggle('selected', input.checked);
  });
}

/**
 * Patient picker: typing in inputId searches the API; results render in resultsId;
 * choosing one calls onSelect(patient).
 */
function uiPatientPicker(inputId, resultsId, onSelect) {
  const input = document.getElementById(inputId);
  const results = document.getElementById(resultsId);
  let latest = 0;
  const run = uiDebounce(async () => {
    const query = input.value.trim();
    if (query.length < 2) {
      results.style.display = 'none';
      results.innerHTML = '';
      return;
    }
    const call = ++latest;
    results.style.display = '';
    results.innerHTML = '<div class="muted-note" style="padding:10px 14px">Searching…</div>';
    try {
      const { patients } = await KSI.get('patients', { search: query, per_page: 8 });
      if (call !== latest) return;
      results.innerHTML = patients.length
        ? `<div class="search-results-list">${patients.map(p => `
            <div class="search-result-item" data-id="${p.id}">
              <div class="sri-avatar">${KSI.esc((p.first_name[0] || '') + (p.last_name[0] || ''))}</div>
              <div><div class="sri-name">${KSI.esc(p.full_name)}</div>
              <div class="sri-meta">${KSI.esc(p.reg_no)} · ${KSI.esc(uiLabel('gender', p.gender))} · ${p.age} yrs · ${KSI.esc(p.phone || '')}</div></div>
            </div>`).join('')}</div>`
        : '<div class="muted-note" style="padding:10px 14px">No patient found. Check the spelling, or register the patient first.</div>';
      results.querySelectorAll('.search-result-item').forEach(item => {
        item.addEventListener('click', () => {
          const patient = patients.find(p => p.id === Number(item.dataset.id));
          results.style.display = 'none';
          onSelect(patient);
        });
      });
    } catch (error) {
      if (call === latest) results.innerHTML = `<div class="muted-note" style="padding:10px 14px">${KSI.esc(error.message)}</div>`;
    }
  });
  input.addEventListener('input', run);
  return {
    reset() {
      input.value = '';
      results.style.display = 'none';
      results.innerHTML = '';
    },
  };
}

/** The FAST TRACK badge for a list row whose visit paid to be seen first. */
function uiFastTrackTag(item) {
  return item && item.is_fast_track ? ' <span class="badge badge-fasttrack">Fast track</span>' : '';
}

function uiPatientCard(patient, actionHtml = '') {
  return `<div class="patient-card">
    <div class="patient-card-header">
      <div class="patient-card-avatar">${KSI.esc((patient.full_name.split(' ')[0]?.[0] || '') + (patient.full_name.split(' ').slice(-1)[0]?.[0] || ''))}</div>
      <div style="flex:1"><div class="patient-card-name">${KSI.esc(patient.full_name)}</div><div class="patient-card-reg">${KSI.esc(patient.reg_no)}</div></div>
      ${actionHtml}
    </div>
    <div class="patient-card-details">
      <div class="pcd-item"><span class="pcd-label">Gender</span><span class="pcd-value">${KSI.esc(uiLabel('gender', patient.gender))}</span></div>
      <div class="pcd-item"><span class="pcd-label">Age</span><span class="pcd-value">${patient.age} years</span></div>
      <div class="pcd-item"><span class="pcd-label">Phone</span><span class="pcd-value">${KSI.esc(patient.phone || '—')}</span></div>
    </div>
  </div>`;
}

function uiFileSize(bytes) {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}
