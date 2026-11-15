'use strict';
// ─── RECEPTION · PATIENTS & VISITS ─────────────────────────────────────────
// Patient registration and records, today's patients, the visit list and the
// New Visit form. Everything is loaded from the API; the server encrypts
// patient details and records every time a record or file is opened.

const PAT_UI = { tab: 'today', todayPage: 1, allPage: 1, perPage: 25, record: null, editing: null, kinOptions: null, removeFileId: null };
const VISIT_UI = { page: 1, perPage: 25, options: null, patient: null, quoteSeq: 0, picker: null, detail: null };
const VISIT_OPEN_STATUSES = ['pending_payment', 'waiting_triage', 'waiting_doctor', 'waiting_investigation', 'waiting_optometry', 'waiting_ophthalmology'];

// ════════════════════════════════════════════════════════════════════════
// Manage Visit
// ════════════════════════════════════════════════════════════════════════
function vsRenderModule() {
  document.getElementById('vs-new-btn').style.display = KSI.can('visits.create') ? '' : 'none';
  VISIT_UI.page = 1;
  vsLoad();
}

const vsFilterChanged = uiDebounce(() => {
  VISIT_UI.page = 1;
  vsLoad();
});

function vsDestinationText(visit) {
  if (visit.destination === 'doctor_room' && visit.room) return `Doctor Room — ${visit.room}`;
  if (visit.destination === 'investigation' && visit.investigation) return `Investigation — ${visit.investigation}`;
  return uiLabel('destination', visit.destination);
}

function vsTypeCell(visit) {
  return `${uiBadge('visitType', visit.visit_type)}<div class="sub-text">${KSI.esc(uiLabel('subType', visit.sub_type))}${visit.is_emergency ? ' · <strong style="color:var(--red)">Emergency</strong>' : ''}${visit.is_fast_track && visit.sub_type !== 'fast_track' ? ' · <span class="badge badge-fasttrack">Fast track</span>' : ''}</div>`;
}

function vsBillCell(bill) {
  if (!bill) return '—';
  const later = bill.is_deferred && bill.status === 'pending' ? ' · emergency' : '';
  return `${uiBadge('billStatus', bill.status)}<div class="sub-text">${uiMoney(bill.net_amount)}${later}</div>`;
}

function vsRowActions(visit) {
  const actions = [`<button class="action-btn action-view" onclick="vsOpenVisit(${visit.id})">View</button>`];
  if (KSI.can('patients.view')) actions.push(`<button class="action-btn action-edit" onclick="ptOpenPatient(${visit.patient.id})">Patient</button>`);
  return `<div class="row-actions">${actions.join('')}</div>`;
}

async function vsLoad() {
  uiTableMessage('vs-tbody', 8, 'Loading visits…');
  try {
    const data = await KSI.get('visits', {
      search: document.getElementById('vs-search').value.trim(),
      visit_type: document.getElementById('vs-filter-type').value,
      destination: document.getElementById('vs-filter-dest').value,
      status: document.getElementById('vs-filter-status').value,
      date_from: document.getElementById('vs-from').value,
      date_to: document.getElementById('vs-to').value,
      page: VISIT_UI.page,
      per_page: VISIT_UI.perPage,
    });
    if (!data.visits.length) {
      uiTableMessage('vs-tbody', 8, 'No visits match the filters.');
    } else {
      document.getElementById('vs-tbody').innerHTML = data.visits.map(visit => `<tr>
        <td><span class="visit-id">${KSI.esc(visit.visit_no)}</span></td>
        <td>${uiPatientCell(visit.patient)}</td>
        <td style="white-space:nowrap">${uiDate(visit.visit_date)}</td>
        <td>${vsTypeCell(visit)}</td>
        <td style="font-size:12px">${KSI.esc(vsDestinationText(visit))}</td>
        <td>${uiBadge('visitStatus', visit.status)}</td>
        <td>${vsBillCell(visit.bill)}</td>
        <td>${vsRowActions(visit)}</td>
      </tr>`).join('');
    }
    document.getElementById('vs-count').textContent = uiCountText(data.page, data.per_page, data.total, 'visits');
    uiPagination('vs-pagination', data.page, data.per_page, data.total, page => { VISIT_UI.page = page; vsLoad(); });
  } catch (error) {
    uiTableMessage('vs-tbody', 8, error.message);
  }
}

function vsRefreshLists() {
  if (!document.getElementById('module-manage-visit').classList.contains('hidden')) vsLoad();
  if (!document.getElementById('module-manage-patient').classList.contains('hidden')) ptSwitchTab(PAT_UI.tab);
}

// ── Visit detail & cancellation ──
async function vsOpenVisit(visitId) {
  const body = document.getElementById('vd-body');
  body.innerHTML = '<div class="muted-note" style="padding:20px;text-align:center">Loading…</div>';
  document.getElementById('vd-title').textContent = 'Visit';
  document.getElementById('vd-subtitle').textContent = '';
  document.getElementById('vd-cancel-btn').style.display = 'none';
  openModal('modal-visit-detail');
  try {
    const { visit, history } = await KSI.get(`visits/${visitId}`);
    VISIT_UI.detail = visit;
    document.getElementById('vd-title').textContent = `Visit ${visit.visit_no}`;
    document.getElementById('vd-subtitle').textContent = `${visit.patient.full_name} · ${visit.patient.reg_no} · ${visit.patient_age_years} yrs`;
    const fields = [
      uiField('Status', uiBadge('visitStatus', visit.status)),
      uiField('Visit Date', KSI.esc(uiDate(visit.visit_date))),
      uiField('Visit Type', KSI.esc(`${uiLabel('visitType', visit.visit_type)} — ${uiLabel('subType', visit.sub_type)}`)),
      uiField('Destination', KSI.esc(vsDestinationText(visit))),
      uiField('Bill', vsBillCell(visit.bill)),
      uiField('Created', KSI.esc(`${KSI.fmtDateTime(visit.created_at)} · ${visit.created_by}`)),
    ];
    if (visit.is_emergency) fields.push(uiField('Emergency', `<strong style="color:var(--red)">${KSI.esc(visit.emergency_reason || 'Yes')}</strong>`, true));
    if (visit.destination === 'investigation') {
      fields.push(uiField('Eye', KSI.esc(uiLabel('eye', visit.investigation_eye))));
      fields.push(uiField('Referring Doctor', KSI.esc(visit.referring_doctor || '—')));
      fields.push(uiField('Clinical Reason', KSI.esc(visit.investigation_reason || '—'), true));
    }
    if (visit.exemption_reason) fields.push(uiField('Fee', `<span style="color:var(--green)">${KSI.esc(visit.exemption_reason)}</span>`, true));
    if (visit.status === 'cancelled') fields.push(uiField('Cancelled', KSI.esc(`${KSI.fmtDateTime(visit.cancelled_at)} — ${visit.cancel_reason || ''}`), true));
    body.innerHTML = `
      <div class="detail-grid">${fields.join('')}</div>
      <div class="form-section-title">Status History</div>
      <ul class="status-timeline">${history.map(h => `<li>
        <strong>${KSI.esc(uiLabel('visitStatus', h.to))}</strong>
        <span>${KSI.fmtDateTime(h.at)} · ${KSI.esc(h.by)}</span>
        ${h.note ? `<div class="sub-text">${KSI.esc(h.note)}</div>` : ''}
      </li>`).join('')}</ul>`;
    const cancellable = KSI.can('visits.cancel') && VISIT_OPEN_STATUSES.includes(visit.status);
    document.getElementById('vd-cancel-btn').style.display = cancellable ? '' : 'none';
  } catch (error) {
    body.innerHTML = `<div class="muted-note" style="padding:20px;text-align:center">${KSI.esc(error.message)}</div>`;
  }
}

function vsOpenCancel() {
  const visit = VISIT_UI.detail;
  if (!visit) return;
  const form = document.getElementById('cv-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('cv-subtitle').textContent = `${visit.visit_no} · ${visit.patient.full_name}`;
  openModal('modal-cancel-visit');
  setTimeout(() => document.getElementById('cv-reason').focus(), 50);
}

async function vsConfirmCancel() {
  const visit = VISIT_UI.detail;
  const form = document.getElementById('cv-form');
  KSI.clearFieldErrors(form);
  await admBusy(document.getElementById('cv-confirm'), async () => {
    try {
      await KSI.post(`visits/${visit.id}/cancel`, { reason: document.getElementById('cv-reason').value.trim() });
      closeModal('modal-cancel-visit');
      closeModal('modal-visit-detail');
      showToast('Visit cancelled', `${visit.visit_no} and its unpaid bill were cancelled.`, 'success');
      vsRefreshLists();
    } catch (error) {
      KSI.reportError(error, form, 'Visit not cancelled');
    }
  });
}

// ── New Visit ──
async function vsOpenNewVisit(patient = null, followUp = null) {
  if (!KSI.can('visits.create')) return;
  try {
    VISIT_UI.options = await KSI.get('visits/options');
  } catch (error) {
    showToast('Cannot start a visit', error.message, 'error');
    return;
  }
  const options = VISIT_UI.options;
  const modal = document.getElementById('modal-new-visit');
  KSI.clearFieldErrors(modal);
  modal.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => { input.checked = false; });
  uiSyncRadioCards(modal);
  document.getElementById('nv-sub-type-group').style.display = 'none';
  document.getElementById('nv-fast-track-group').style.display = 'none';
  document.getElementById('nv-sub-types').innerHTML = '';
  document.getElementById('nv-reason').value = '';
  document.getElementById('nv-emergency-reason').value = '';
  document.getElementById('nv-visit-date').textContent = uiDate(options.today);
  document.getElementById('nv-room').innerHTML = '<option value="">Select room…</option>'
    + options.rooms.map(room => `<option value="${KSI.esc(room)}">${KSI.esc(room)}</option>`).join('');
  document.getElementById('nv-investigation').innerHTML = '<option value="">Select investigation…</option>'
    + options.investigations.map(i => `<option value="${i.id}">${KSI.esc(i.name)} — ${uiMoney(i.price)}</option>`).join('');
  document.getElementById('nv-doctor').innerHTML = '<option value="">Not specified</option>'
    + options.doctors.map(d => `<option value="${d.id}">${KSI.esc(d.name)}</option>`).join('');
  document.getElementById('nv-emergency-group').style.display = options.can_emergency ? '' : 'none';

  if (!VISIT_UI.picker) VISIT_UI.picker = uiPatientPicker('nv-patient-search', 'nv-search-results', vsSelectPatient);
  VISIT_UI.picker.reset();
  closeModal('modal-view-patient');
  vsSelectPatient(patient);
  const note = document.getElementById('nv-followup-note');
  VISIT_UI.followUp = patient ? followUp : null;
  if (VISIT_UI.followUp) {
    uiSetRadio('nv-visit-type', 'follow_up');
    vsOnTypeChange();
    uiSetRadio('nv-sub-type', 'doctor_follow_up');
    note.textContent = `Booking the follow-up due ${uiDate(followUp.due_date)}${followUp.reason ? ` (${followUp.reason})` : ''} requested by ${followUp.created_by}.`;
    note.style.display = '';
  } else {
    note.style.display = 'none';
  }
  vsOnFormChange();
  openModal('modal-new-visit');
  if (!patient) setTimeout(() => document.getElementById('nv-patient-search').focus(), 50);
}

function vsSelectPatient(patient) {
  VISIT_UI.patient = patient;
  if (!patient && VISIT_UI.followUp) {
    VISIT_UI.followUp = null;
    document.getElementById('nv-followup-note').style.display = 'none';
  }
  const box = document.getElementById('nv-selected-patient');
  document.getElementById('nv-picker').style.display = patient ? 'none' : '';
  box.style.display = patient ? '' : 'none';
  box.innerHTML = patient
    ? uiPatientCard(patient, '<button class="btn btn-ghost btn-sm" type="button" onclick="vsSelectPatient(null)">Change</button>')
    : '';
  if (!patient && VISIT_UI.picker) VISIT_UI.picker.reset();
  vsQuote();
}

function vsOnTypeChange() {
  const type = uiRadioValue('nv-visit-type');
  const subTypes = {
    consultation: [['normal', 'Normal', 'Standard queue'], ['fast_track', 'Fast Track', 'Priority service']],
    follow_up: [['doctor_follow_up', 'Doctor Follow-Up', 'Review by the doctor'], ['surgery_follow_up', 'Surgery Follow-Up', 'After an operation']],
  }[type] || [];
  document.getElementById('nv-sub-type-group').style.display = subTypes.length ? '' : 'none';
  // A consultation chooses fast track as its sub-type; a follow-up of either kind ticks it here.
  document.getElementById('nv-fast-track-group').style.display = type === 'follow_up' ? '' : 'none';
  if (type !== 'follow_up') document.getElementById('nv-fast-track').checked = false;
  document.getElementById('nv-sub-types').innerHTML = subTypes.map(([value, label, sub], index) => `
    <label class="radio-card"><input type="radio" name="nv-sub-type" value="${value}"${index === 0 ? ' checked' : ''} onchange="vsOnFormChange()">
      <div><div class="radio-card-label">${label}</div><div class="radio-card-sub">${sub}</div></div></label>`).join('');
  vsOnFormChange();
}

function vsOnFormChange() {
  const modal = document.getElementById('modal-new-visit');
  uiSyncRadioCards(modal);
  const destination = uiRadioValue('nv-destination');
  const emergency = document.getElementById('nv-emergency').checked;
  document.getElementById('nv-room-group').style.display = destination === 'doctor_room' ? '' : 'none';
  document.getElementById('nv-investigation-group').style.display = destination === 'investigation' ? '' : 'none';
  document.getElementById('nv-emergency-reason-group').style.display = emergency ? '' : 'none';
  vsQuote();
}

const vsQuote = uiDebounce(async () => {
  const box = document.getElementById('nv-fee');
  const patient = VISIT_UI.patient;
  const visitType = uiRadioValue('nv-visit-type');
  const subType = uiRadioValue('nv-sub-type');
  const destination = uiRadioValue('nv-destination');
  const investigation = document.getElementById('nv-investigation').value;
  if (!patient || !visitType || !subType || !destination || (destination === 'investigation' && !investigation)) {
    box.innerHTML = '<div class="muted-note">Choose the patient, visit type and destination to see the fee.</div>';
    return;
  }
  const call = ++VISIT_UI.quoteSeq;
  box.innerHTML = '<div class="muted-note">Calculating fee…</div>';
  try {
    const { quote } = await KSI.get('visits/quote', {
      patient_id: patient.id, visit_type: visitType, sub_type: subType, destination, investigation_item_id: investigation,
      fast_track: document.getElementById('nv-fast-track').checked ? 1 : 0,
    });
    if (call !== VISIT_UI.quoteSeq) return;
    const emergency = document.getElementById('nv-emergency').checked;
    box.innerHTML = quote.exempt
      ? `<div class="dcb-row"><span class="dcb-label">${KSI.esc(quote.description)}</span><span><s>${uiMoney(quote.gross_amount)}</s> <strong style="color:var(--green)">Free</strong></span></div>
         <div class="muted-note" style="margin-top:6px">${KSI.esc(quote.exemption_reason)}. The patient goes straight to the queue.</div>`
      : `<div class="dcb-row"><span class="dcb-label">${KSI.esc(quote.description)}</span><strong>${uiMoney(quote.net_amount)}</strong></div>
         <div class="muted-note" style="margin-top:6px">${emergency
           ? 'Emergency: the patient is sent for treatment now and the bill stays open at the cashier.'
           : 'The patient pays at the cashier, then joins the queue.'}</div>`;
    if (destination === 'investigation') {
      box.innerHTML += `<div class="muted-note" style="margin-top:6px">The investigation goes straight to Diagnostics and is
        billed separately when the patient checks in there.</div>`;
    }
  } catch (error) {
    if (call === VISIT_UI.quoteSeq) box.innerHTML = `<div class="field-error">${KSI.esc(error.message)}</div>`;
  }
}, 250);

async function vsSaveVisit(confirmOpenVisit = false) {
  const modal = document.getElementById('modal-new-visit');
  KSI.clearFieldErrors(modal);
  if (!VISIT_UI.patient) {
    KSI.showFieldErrors(modal, { patient_id: 'Search for the patient and select them.' });
    return;
  }
  const destination = uiRadioValue('nv-destination');
  const emergency = document.getElementById('nv-emergency').checked;
  const body = {
    patient_id: VISIT_UI.patient.id,
    visit_type: uiRadioValue('nv-visit-type'),
    sub_type: uiRadioValue('nv-sub-type'),
    fast_track: document.getElementById('nv-fast-track').checked,
    destination,
    room: destination === 'doctor_room' ? document.getElementById('nv-room').value : '',
    investigation_item_id: destination === 'investigation' ? document.getElementById('nv-investigation').value : '',
    investigation_eye: destination === 'investigation' ? uiRadioValue('nv-eye') : '',
    referring_doctor_id: destination === 'investigation' ? document.getElementById('nv-doctor').value : '',
    investigation_reason: destination === 'investigation' ? document.getElementById('nv-reason').value.trim() : '',
    is_emergency: emergency,
    emergency_reason: emergency ? document.getElementById('nv-emergency-reason').value.trim() : '',
    confirm_open_visit: confirmOpenVisit,
    follow_up_id: VISIT_UI.followUp?.id ?? null,
  };
  if (!body.sub_type && body.visit_type) {
    KSI.showFieldErrors(modal, { sub_type: 'Choose a sub-type.' });
    return;
  }

  const saved = await admBusy(document.getElementById('nv-save'), async () => {
    try {
      return (await KSI.post('visits', body)).visit;
    } catch (error) {
      if (error.code === 'open_visit_exists') return { retry: confirm(`${error.message}\n\nCreate another visit for this patient anyway?`) };
      KSI.reportError(error, modal, 'Visit not created');
      return null;
    }
  });
  if (saved?.retry) return vsSaveVisit(true);
  if (!saved || saved.retry === false) return;

  closeModal('modal-new-visit');
  const next = saved.status === 'pending_payment'
    ? `Send the patient to the cashier to pay ${uiMoney(saved.bill.net_amount)}.`
    : `${uiLabel('visitStatus', saved.status)}${saved.is_emergency ? ' — bill to be settled later' : ''}.`;
  showToast(`Visit ${saved.visit_no} created`, next, 'success');
  vsRefreshLists();
}

// ════════════════════════════════════════════════════════════════════════
// Manage Patient
// ════════════════════════════════════════════════════════════════════════
function ptRenderModule() {
  document.getElementById('pt-new-btn').style.display = KSI.can('patients.create') ? '' : 'none';
  const canFollowUps = KSI.can('visits.followups.view');
  document.getElementById('pt-tab-followups').style.display = canFollowUps ? '' : 'none';
  if (PAT_UI.tab === 'followups' && !canFollowUps) PAT_UI.tab = 'today';
  ptSwitchTab(PAT_UI.tab);
}

function ptSwitchTab(tab) {
  PAT_UI.tab = tab;
  ['today', 'all', 'followups'].forEach(name => {
    document.getElementById(`pt-tab-${name}`).classList.toggle('active', tab === name);
    document.getElementById(`pt-panel-${name}`).style.display = tab === name ? '' : 'none';
  });
  document.getElementById('pt-crumb').textContent = { today: "Today's Patients", all: 'All Patients', followups: 'Follow-ups Due' }[tab];
  if (tab === 'today') ptLoadToday();
  else if (tab === 'all') ptLoadAll();
  else ptLoadFollowUps();
}

// ── Follow-ups booked by clinicians ──
const ptFollowUpFilterChanged = uiDebounce(() => { PAT_UI.fuPage = 1; ptLoadFollowUps(); });

async function ptLoadFollowUps() {
  uiTableMessage('pt-fu-tbody', 8, 'Loading follow-ups…');
  const status = document.getElementById('pt-fu-status').value;
  try {
    const data = await KSI.get('follow-ups', {
      status,
      search: document.getElementById('pt-fu-search').value.trim(),
      from: document.getElementById('pt-fu-from').value,
      to: document.getElementById('pt-fu-to').value,
      page: PAT_UI.fuPage || 1,
      per_page: PAT_UI.perPage,
    });
    PAT_UI.followUps = data.follow_ups;
    const c = data.counts;
    document.getElementById('pt-fu-badge').textContent = c.due || '';
    document.getElementById('pt-fu-kpis').innerHTML = [['Due', c.due, 'teal'], ['Due today', c.today, 'blue'], ['Overdue', c.overdue, 'amber']].map(([label, value, color]) => `
      <div class="stat-card"><div class="stat-icon ${color}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg></div>
      <div><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div></div>`).join('');
    if (!data.follow_ups.length) {
      uiTableMessage('pt-fu-tbody', 8, status === 'due' ? 'No follow-ups are due.' : 'No follow-ups match the filters.');
    } else {
      const canBook = KSI.can('visits.create');
      const canCancel = KSI.can('visits.followups.manage');
      document.getElementById('pt-fu-tbody').innerHTML = data.follow_ups.map(fu => `<tr>
        <td style="white-space:nowrap">${uiDate(fu.due_date)}${fu.is_overdue ? '<div class="sub-text" style="color:var(--red);font-weight:600">Overdue</div>' : ''}</td>
        <td>${uiPatientCell(fu.patient)}</td>
        <td style="font-family:var(--font-mono);font-size:12px">${KSI.esc(fu.patient.phone || '—')}</td>
        <td style="font-size:12px">${KSI.esc(fu.reason || '—')}${fu.notes ? `<div class="sub-text">${KSI.esc(fu.notes)}</div>` : ''}</td>
        <td>${uiBadge('priority', fu.priority)}</td>
        <td style="font-size:12px">${KSI.esc(uiLabel('clinicalSource', fu.source))} · ${KSI.esc(fu.created_by)}<div class="sub-text">Visit ${KSI.esc(fu.source_visit_no)} · ${KSI.fmtDate(fu.created_at)}</div></td>
        <td>${uiBadge('followUpStatus', fu.status)}${fu.booked_visit_no ? `<div class="sub-text">Visit ${KSI.esc(fu.booked_visit_no)}</div>` : ''}</td>
        <td><div class="row-actions">${fu.status === 'due' ? `
          ${canBook ? `<button class="action-btn action-status" onclick="ptBookFollowUp(${fu.id})">Book Visit</button>` : ''}
          ${canCancel ? `<button class="action-btn action-edit" onclick="ptOpenCancelFollowUp(${fu.id})">Cancel</button>` : ''}` : ''}
        </div></td>
      </tr>`).join('');
    }
    document.getElementById('pt-fu-count').textContent = uiCountText(data.page, data.per_page, data.total, 'follow-ups');
    uiPagination('pt-fu-pagination', data.page, data.per_page, data.total, page => { PAT_UI.fuPage = page; ptLoadFollowUps(); });
  } catch (error) {
    uiTableMessage('pt-fu-tbody', 8, error.message);
  }
}

function ptBookFollowUp(followUpId) {
  const followUp = PAT_UI.followUps.find(f => f.id === followUpId);
  if (followUp) vsOpenNewVisit(followUp.patient, followUp);
}

function ptOpenCancelFollowUp(followUpId) {
  const followUp = PAT_UI.followUps.find(f => f.id === followUpId);
  if (!followUp) return;
  PAT_UI.cancelFollowUp = followUp;
  const form = document.getElementById('cf-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('cf-subtitle').textContent = `${followUp.patient.full_name} · due ${uiDate(followUp.due_date)}`;
  openModal('modal-cancel-followup');
  setTimeout(() => form.querySelector('[data-field="reason"]').focus(), 50);
}

async function ptConfirmCancelFollowUp() {
  const form = document.getElementById('cf-form');
  KSI.clearFieldErrors(form);
  const followUp = PAT_UI.cancelFollowUp;
  await admBusy(document.getElementById('cf-confirm'), async () => {
    try {
      await KSI.post(`follow-ups/${followUp.id}/cancel`, { reason: form.querySelector('[data-field="reason"]').value.trim() });
      closeModal('modal-cancel-followup');
      showToast('Follow-up cancelled', followUp.patient.full_name, 'success');
      ptLoadFollowUps();
    } catch (error) {
      KSI.reportError(error, form, 'Follow-up not cancelled');
    }
  });
}

const ptTodayFilterChanged = uiDebounce(() => { PAT_UI.todayPage = 1; ptLoadToday(); });
const ptAllFilterChanged = uiDebounce(() => { PAT_UI.allPage = 1; ptLoadAll(); });

async function ptLoadToday() {
  uiTableMessage('pt-today-tbody', 8, 'Loading today’s patients…');
  const today = uiToday();
  try {
    const [summary, data] = await Promise.all([
      KSI.get('visits/summary', { date: today }),
      KSI.get('visits', {
        date_from: today, date_to: today,
        search: document.getElementById('pt-today-search').value.trim(),
        status: document.getElementById('pt-today-status').value,
        visit_type: document.getElementById('pt-today-type').value,
        page: PAT_UI.todayPage, per_page: PAT_UI.perPage,
      }),
    ]);
    const c = summary.counts;
    const waiting = c.waiting_triage + c.waiting_doctor + c.waiting_investigation + c.waiting_optometry + c.waiting_ophthalmology;
    const kpis = [
      ['Visits Today', summary.total, 'teal'], ['Pending Payment', c.pending_payment, 'amber'], ['Waiting', waiting, 'blue'],
      ['In Progress', c.in_progress, 'purple'], ['Complete', c.completed, 'teal'], ['Cancelled', c.cancelled, 'amber'],
    ];
    document.getElementById('pt-today-kpis').innerHTML = kpis.map(([label, value, color]) => `
      <div class="stat-card"><div class="stat-icon ${color}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg></div>
      <div><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div></div>`).join('');
    document.getElementById('pt-today-badge').textContent = summary.total || '';

    if (!data.visits.length) {
      uiTableMessage('pt-today-tbody', 8, summary.total ? 'No visits today match the filters.' : 'No visits registered today yet.');
    } else {
      document.getElementById('pt-today-tbody').innerHTML = data.visits.map(visit => `<tr>
        <td><span class="visit-id">${KSI.esc(visit.visit_no)}</span></td>
        <td>${uiPatientCell(visit.patient)}</td>
        <td style="font-size:12px">${visit.patient_age_years} yrs · ${KSI.esc(uiLabel('gender', visit.patient.gender))}</td>
        <td>${vsTypeCell(visit)}</td>
        <td style="font-size:12px">${KSI.esc(vsDestinationText(visit))}</td>
        <td>${uiBadge('visitStatus', visit.status)}</td>
        <td>${vsBillCell(visit.bill)}</td>
        <td>${vsRowActions(visit)}</td>
      </tr>`).join('');
    }
    document.getElementById('pt-today-count').textContent = uiCountText(data.page, data.per_page, data.total, 'visits today');
    uiPagination('pt-today-pagination', data.page, data.per_page, data.total, page => { PAT_UI.todayPage = page; ptLoadToday(); });
  } catch (error) {
    uiTableMessage('pt-today-tbody', 8, error.message);
  }
}

async function ptLoadAll() {
  uiTableMessage('pt-tbody', 8, 'Loading patients…');
  try {
    const data = await KSI.get('patients', {
      search: document.getElementById('pt-search').value.trim(),
      registered_from: document.getElementById('pt-reg-from').value,
      registered_to: document.getElementById('pt-reg-to').value,
      page: PAT_UI.allPage, per_page: PAT_UI.perPage,
    });
    if (!data.patients.length) {
      uiTableMessage('pt-tbody', 8, 'No patients match the search.');
    } else {
      const canVisit = KSI.can('visits.create');
      document.getElementById('pt-tbody').innerHTML = data.patients.map(patient => `<tr>
        <td><span class="reg-num">${KSI.esc(patient.reg_no)}</span></td>
        <td class="patient-name">${KSI.esc(patient.full_name)}</td>
        <td style="font-family:var(--font-mono);font-size:12px">${KSI.esc(patient.phone || '—')}</td>
        <td>${patient.age}</td>
        <td>${KSI.esc(uiLabel('gender', patient.gender))}</td>
        <td>${patient.visit_count}</td>
        <td style="font-size:12px;white-space:nowrap">${uiDate(patient.last_visit_date)}</td>
        <td><div class="row-actions">
          <button class="action-btn action-view" onclick="ptOpenPatient(${patient.id})">View</button>
          ${canVisit ? `<button class="action-btn action-status" onclick='vsOpenNewVisit(${KSI.esc(JSON.stringify({ id: patient.id, reg_no: patient.reg_no, full_name: patient.full_name, first_name: patient.first_name, last_name: patient.last_name, gender: patient.gender, age: patient.age, phone: patient.phone }))})'>New Visit</button>` : ''}
        </div></td>
      </tr>`).join('');
    }
    document.getElementById('pt-count').textContent = uiCountText(data.page, data.per_page, data.total, 'patients');
    uiPagination('pt-pagination', data.page, data.per_page, data.total, page => { PAT_UI.allPage = page; ptLoadAll(); });
  } catch (error) {
    uiTableMessage('pt-tbody', 8, error.message);
  }
}

// ── Patient record ──
async function ptOpenPatient(patientId, tab = 'personal') {
  if (!KSI.can('patients.view')) return;
  document.getElementById('pv-title').textContent = 'Loading…';
  document.getElementById('pv-subtitle').textContent = '';
  ['pv-personal', 'pv-kin'].forEach(id => { document.getElementById(id).innerHTML = ''; });
  openModal('modal-view-patient');
  try {
    const record = await KSI.get(`patients/${patientId}`);
    PAT_UI.record = record;
    const p = record.patient;
    document.getElementById('pv-title').textContent = p.full_name;
    document.getElementById('pv-subtitle').textContent = `${p.reg_no} · ${uiLabel('gender', p.gender)} · ${p.age} years`;
    document.getElementById('pv-personal').innerHTML = [
      uiField('Registration Number', `<span class="reg-num">${KSI.esc(p.reg_no)}</span>`),
      uiField('Full Name', KSI.esc(p.full_name)),
      uiField('Gender', KSI.esc(uiLabel('gender', p.gender))),
      uiField('Date of Birth', KSI.esc(`${uiDate(p.date_of_birth)} (${p.age} years)`)),
      uiField('Phone', KSI.esc(p.phone)),
      uiField('Physical Address', KSI.esc(p.address), true),
    ].join('');
    document.getElementById('pv-kin').innerHTML = [
      uiField('Full Name', KSI.esc(p.kin_name || '—')),
      uiField('Relationship', KSI.esc(p.kin_relationship_other || p.kin_relationship || '—')),
      uiField('Phone', KSI.esc(p.kin_phone || '—')),
    ].join('');
    document.getElementById('pv-visits').innerHTML = record.visits.length
      ? record.visits.map(v => `<tr>
          <td><span class="visit-id">${KSI.esc(v.visit_no)}</span></td>
          <td style="white-space:nowrap">${uiDate(v.visit_date)}</td>
          <td>${KSI.esc(`${uiLabel('visitType', v.visit_type)} — ${uiLabel('subType', v.sub_type)}`)}</td>
          <td style="font-size:12px">${KSI.esc(vsDestinationText(v))}</td>
          <td>${uiBadge('visitStatus', v.status)}</td>
          <td>${KSI.can('visits.view') ? `<button class="action-btn action-view" onclick="vsOpenVisit(${v.id})">View</button>` : ''}</td>
        </tr>`).join('')
      : '<tr class="table-state"><td colspan="6">No visits yet.</td></tr>';
    document.getElementById('pv-bills').innerHTML = record.bills.length
      ? record.bills.map(b => `<tr>
          <td><span class="visit-id">${KSI.esc(b.bill_no)}</span>${b.visit_no ? `<div class="sub-text">${KSI.esc(b.visit_no)}</div>` : ''}</td>
          <td style="white-space:nowrap;font-size:12px">${KSI.fmtDate(b.created_at)}</td>
          <td style="font-size:12px">${KSI.esc(b.description)}</td>
          <td style="text-align:right;font-family:var(--font-mono)">${uiMoney(b.net_amount)}</td>
          <td>${uiBadge('billStatus', b.status)}</td>
        </tr>`).join('')
      : '<tr class="table-state"><td colspan="5">No bills yet.</td></tr>';
    document.getElementById('pv-meta').textContent = `Registered ${KSI.fmtDateTime(p.registered_at)} by ${p.registered_by}`;
    document.getElementById('pv-edit-btn').style.display = KSI.can('patients.update') ? '' : 'none';
    document.getElementById('pv-fileno-btn').style.display = KSI.can('patients.number.correct') ? '' : 'none';
    document.getElementById('pv-visit-btn').style.display = KSI.can('visits.create') && p.status === 'active' ? '' : 'none';
    document.querySelector('#pv-tabs [data-tab="files"]').style.display = KSI.can('patients.files.view') ? '' : 'none';
    document.getElementById('pv-upload-wrap').style.display = KSI.can('patients.files.upload') ? '' : 'none';
    ptShowTab(tab);
  } catch (error) {
    document.getElementById('pv-title').textContent = 'Patient';
    document.getElementById('pv-personal').innerHTML = `<div class="muted-note">${KSI.esc(error.message)}</div>`;
    ptShowTab('personal');
  }
}

function ptShowTab(tab) {
  document.querySelectorAll('#pv-tabs .tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
  document.querySelectorAll('#modal-view-patient .tab-panel').forEach(panel => panel.classList.toggle('active', panel.id === `pv-tab-${tab}`));
  if (tab === 'files') ptLoadFiles();
}

async function ptLoadFiles() {
  const list = document.getElementById('pv-files');
  const patient = PAT_UI.record?.patient;
  if (!patient) return;
  list.innerHTML = '<div class="muted-note">Loading…</div>';
  try {
    const { files } = await KSI.get(`patients/${patient.id}/files`);
    const canRemove = KSI.can('patients.files.upload');
    list.innerHTML = files.length ? files.map(file => `
      <div class="file-item"${file.voided ? ' style="opacity:.55"' : ''}>
        <div class="file-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
        <div class="file-info">
          <div class="file-name">${KSI.esc(file.name)}</div>
          <div class="file-meta">${uiFileSize(file.size_bytes)} · ${KSI.fmtDateTime(file.uploaded_at)} · ${KSI.esc(file.uploaded_by)}${file.voided ? ` · Removed: ${KSI.esc(file.void_reason)}` : ''}</div>
        </div>
        ${file.voided ? '' : `<div class="file-actions">
          <button class="btn btn-secondary btn-sm" type="button" onclick="ptViewFile(${file.id})">View</button>
          <button class="btn btn-secondary btn-sm" type="button" onclick="ptDownloadFile(${file.id})">Download</button>
          ${canRemove ? `<button class="btn btn-ghost btn-sm" type="button" onclick="ptOpenRemoveFile(${file.id})">Remove</button>` : ''}
        </div>`}
      </div>`).join('') : '<div class="muted-note">No scanned records uploaded yet.</div>';
  } catch (error) {
    list.innerHTML = `<div class="muted-note">${KSI.esc(error.message)}</div>`;
  }
}

async function ptUploadFiles(input) {
  const patient = PAT_UI.record?.patient;
  const files = [...input.files];
  input.value = '';
  if (!patient || !files.length) return;
  const button = document.getElementById('pv-upload-btn');
  await admBusy(button, async () => {
    for (const file of files) {
      const form = new FormData();
      form.append('file', file, file.name);
      try {
        await KSI.upload(`patients/${patient.id}/files`, form);
        showToast('Record uploaded', file.name, 'success');
      } catch (error) {
        showToast(`Not uploaded: ${file.name}`, error.fields?.file || error.message, 'error');
      }
    }
  });
  ptLoadFiles();
}

function ptViewFile(fileId) {
  KSI.openFile(`patients/${PAT_UI.record.patient.id}/files/${fileId}`).catch(error => showToast('Cannot open file', error.message, 'error'));
}

function ptDownloadFile(fileId) {
  KSI.download(`patients/${PAT_UI.record.patient.id}/files/${fileId}`).catch(error => showToast('Cannot download file', error.message, 'error'));
}

function ptOpenRemoveFile(fileId) {
  PAT_UI.removeFileId = fileId;
  const form = document.getElementById('fr-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('fr-subtitle').textContent = PAT_UI.record.patient.reg_no;
  openModal('modal-file-remove');
}

async function ptConfirmRemoveFile() {
  const form = document.getElementById('fr-form');
  KSI.clearFieldErrors(form);
  await admBusy(document.getElementById('fr-confirm'), async () => {
    try {
      await KSI.post(`patients/${PAT_UI.record.patient.id}/files/${PAT_UI.removeFileId}/void`, { reason: document.getElementById('fr-reason').value.trim() });
      closeModal('modal-file-remove');
      showToast('Record removed', 'The file is hidden from the patient record.', 'success');
      ptLoadFiles();
    } catch (error) {
      KSI.reportError(error, form, 'Record not removed');
    }
  });
}

// ── Register / edit ──
async function ptLoadKinOptions() {
  if (!PAT_UI.kinOptions) PAT_UI.kinOptions = (await KSI.get('reference/lookups', { list: 'kin_relationship' })).values;
  document.getElementById('pf-kin-rel').innerHTML = '<option value="">Select…</option>'
    + PAT_UI.kinOptions.map(o => `<option value="${KSI.esc(o.value)}">${KSI.esc(o.label)}</option>`).join('');
}

function ptKinRelChanged() {
  const other = document.getElementById('pf-kin-rel').value === 'Other';
  document.getElementById('pf-kin-rel-other-group').style.display = other ? '' : 'none';
  if (!other) document.getElementById('pf-kin-rel-other').value = '';
}

function ptResetForm() {
  const form = document.getElementById('pf-form');
  form.reset();
  ptKinRelChanged();
  KSI.clearFieldErrors(form);
  document.getElementById('pf-duplicates').style.display = 'none';
  document.getElementById('pf-dob').max = uiToday();
  document.getElementById('pf-age').value = '';
  ptTypeChanged();
  return form;
}

/** An existing patient keeps the number on their paper file; a new one gets the next number. */
function ptTypeChanged() {
  const existing = uiRadioValue('pf-type') === 'existing';
  document.getElementById('pf-paper-group').style.display = existing ? '' : 'none';
  if (!existing) document.getElementById('pf-paper-no').value = '';
  document.getElementById('pf-subtitle').textContent = existing
    ? 'The patient keeps the number on their paper file'
    : 'The file number is assigned automatically when saved';
}

async function ptOpenRegister() {
  if (!KSI.can('patients.create')) return;
  try {
    await ptLoadKinOptions();
  } catch (error) {
    showToast('Cannot open form', error.message, 'error');
    return;
  }
  PAT_UI.editing = null;
  ptResetForm();
  document.getElementById('pf-title').textContent = 'Register Patient';
  document.getElementById('pf-type-section').style.display = '';
  ptTypeChanged();
  document.getElementById('pf-save').textContent = 'Register Patient';
  openModal('modal-patient-form');
  setTimeout(() => document.querySelector('#pf-form [data-field="first_name"]').focus(), 50);
}

async function ptOpenEdit() {
  const patient = PAT_UI.record?.patient;
  if (!patient || !KSI.can('patients.update')) return;
  try {
    await ptLoadKinOptions();
  } catch (error) {
    showToast('Cannot open form', error.message, 'error');
    return;
  }
  PAT_UI.editing = patient;
  const form = ptResetForm();
  ['first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'phone', 'address', 'kin_name', 'kin_relationship',
    'kin_relationship_other', 'kin_phone'].forEach(field => {
    form.querySelector(`[data-field="${field}"]`).value = patient[field] ?? '';
  });
  ptKinRelChanged();
  ptUpdateAge();
  document.getElementById('pf-title').textContent = `Edit Patient — ${patient.reg_no}`;
  document.getElementById('pf-type-section').style.display = 'none';
  document.getElementById('pf-subtitle').textContent = 'The file number is corrected separately, by an administrator';
  document.getElementById('pf-save').textContent = 'Save Changes';
  openModal('modal-patient-form');
}

function ptUpdateAge() {
  const dob = document.getElementById('pf-dob').value;
  if (!dob) {
    document.getElementById('pf-age').value = '';
    return;
  }
  const [y, m, d] = dob.split('-').map(Number);
  const [ty, tm, td] = uiToday().split('-').map(Number);
  let age = ty - y;
  if (tm < m || (tm === m && td < d)) age--;
  document.getElementById('pf-age').value = age >= 0 ? `${age} years` : '';
}

async function ptSavePatient(confirmDuplicate = false) {
  const form = document.getElementById('pf-form');
  KSI.clearFieldErrors(form);
  const body = {};
  form.querySelectorAll('[data-field]').forEach(input => { body[input.dataset.field] = input.value.trim(); });
  body.confirm_duplicate = confirmDuplicate;
  const editing = PAT_UI.editing;
  if (editing || uiRadioValue('pf-type') !== 'existing') delete body.paper_file_no;
  if (editing) body.row_version = editing.row_version;

  const result = await admBusy(document.getElementById('pf-save'), async () => {
    try {
      return editing ? await KSI.put(`patients/${editing.id}`, body) : await KSI.post('patients', body);
    } catch (error) {
      if (error.code === 'possible_duplicate') {
        ptShowDuplicates(error.message, error.fields.duplicates || []);
      } else {
        KSI.reportError(error, form, editing ? 'Changes not saved' : 'Patient not registered');
      }
      return null;
    }
  });
  if (!result) return;

  const patient = result.patient;
  closeModal('modal-patient-form');
  if (editing) {
    showToast('Patient updated', `${patient.reg_no} saved.`, 'success');
    ptOpenPatient(patient.id);
  } else {
    showToast('Patient registered', `${patient.full_name} — ${patient.reg_no}`, 'success');
    if (KSI.can('visits.create')) vsOpenNewVisit(patient);
  }
  vsRefreshLists();
}

function ptShowDuplicates(message, duplicates) {
  const box = document.getElementById('pf-duplicates');
  box.style.display = 'flex';
  box.innerHTML = `<div><strong>${KSI.esc(message)}</strong></div>
    ${duplicates.map(d => `<div class="dup-item">
      <div><strong>${KSI.esc(d.full_name)}</strong> · ${KSI.esc(d.reg_no)} · ${d.age} yrs · ${KSI.esc(d.phone || '')}
        <div class="sub-text">${KSI.esc(d.match)}</div></div>
      <button class="btn btn-secondary btn-sm" type="button" onclick="closeModal('modal-patient-form');ptOpenPatient(${d.id})">Open record</button>
    </div>`).join('')}
    <div style="margin-top:8px"><button class="btn btn-primary btn-sm" type="button" onclick="ptSavePatient(true)">Not the same person — save anyway</button></div>`;
  box.scrollIntoView({ block: 'nearest' });
}

// ── File number correction (administrators) ──
function ptOpenFileNumber() {
  const patient = PAT_UI.record?.patient;
  if (!patient || !KSI.can('patients.number.correct')) return;
  const form = document.getElementById('fn-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('fn-info').innerHTML = `<div><strong>${KSI.esc(patient.full_name)}</strong> · file number ${KSI.esc(patient.reg_no)}</div>
    <div class="muted-note">${patient.paper_file ? 'Registered under a paper file number.' : 'Registered as a new patient.'} The change is recorded with your name and the reason.</div>`;
  openModal('modal-file-number');
  setTimeout(() => document.getElementById('fn-number').focus(), 50);
}

async function ptSaveFileNumber() {
  const patient = PAT_UI.record?.patient;
  const form = document.getElementById('fn-form');
  KSI.clearFieldErrors(form);
  await admBusy(document.getElementById('fn-save'), async () => {
    try {
      const { patient: saved } = await KSI.put(`patients/${patient.id}/file-number`, {
        paper_file_no: document.getElementById('fn-number').value.trim(),
        reason: document.getElementById('fn-reason').value.trim(),
      });
      closeModal('modal-file-number');
      showToast('File number corrected', `${saved.full_name} is now file ${saved.reg_no}.`, 'success');
      ptOpenPatient(saved.id);
      vsRefreshLists();
    } catch (error) {
      KSI.reportError(error, form, 'File number not changed');
    }
  });
}
