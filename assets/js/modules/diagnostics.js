'use strict';
// ─── DIAGNOSTICS ──────────────────────────────────────────────────────────
// Booking desk, reporting queue and history. A booking carries no charge:
// the bill is raised when the patient arrives, and the test is never blocked
// by payment. Cancellations and "no charge" are decided in Approvals.

const DIAG = {
  options: null,
  bookings: [],
  queue: [],
  history: [],
  selected: null,       // booking open in the report panel
  editing: null,        // booking open in the booking modal
  patient: null,        // patient chosen in the booking modal
  procedures: [],       // [{price_item_id, remark, laser_type_ids, laser_subtype_ids}]
  cancelTarget: null,
  checkInTarget: null,
  pendingReport: null,  // report saved while the test had no bill
  meds: [],             // medicine lines on the open report, sent to the pharmacy on save
};

async function diagOptions() {
  if (!DIAG.options) DIAG.options = await KSI.get('diagnostics/options');
  return DIAG.options;
}

function diagProcedureById(id) {
  return (DIAG.options?.procedures || []).find(p => p.id === Number(id)) || null;
}

function diagEyeLabel(eye) {
  return { right: 'Right eye', left: 'Left eye', both: 'Both eyes' }[eye] || eye || '—';
}

function diagStatusBadge(status) {
  return uiBadge('diagnosticStatus', status);
}

function diagBillCell(booking) {
  if (!booking.bill) return '<span class="muted-note">Not charged yet</span>';
  const waived = booking.bill.status === 'exempted';
  return `<div class="money">${waived ? 'No charge' : 'TZS ' + KSI.fmtMoney(booking.bill.amount)}</div>
    <div class="muted-note">${KSI.esc(booking.bill.bill_no)} · ${KSI.esc(uiLabel('billStatus', booking.bill.status))}</div>`;
}

function diagProcedureCell(booking) {
  const laser = booking.laser_type ? `<div class="diag-sub">${KSI.esc(booking.laser_type)}</div>` : '';
  const remark = booking.remark ? `<div class="muted-note" title="${KSI.esc(booking.remark)}">${KSI.esc(booking.remark.slice(0, 60))}</div>` : '';
  return `<span class="badge badge-normal">${KSI.esc(booking.procedure.name)}</span>${laser}${remark}`;
}

// ─── Booking list ─────────────────────────────────────────────────────────

async function diagOpenBooking() {
  await diagOptions();
  diagFillProcedureFilters();
  await diagLoadBookings();
}

function diagFillProcedureFilters() {
  const items = (DIAG.options?.procedures || []).map(p => ({ id: p.id, name: p.name }));
  ['diag-book-filter-proc', 'diag-hist-filter-proc'].forEach(id => {
    const select = document.getElementById(id);
    if (!select || select.dataset.filled) return;
    admFillSelect(id, 'All procedures', items.map(p => [p.id, p.name]));
    select.dataset.filled = '1';
  });
}

function diagFilters(prefix) {
  const value = id => document.getElementById(id)?.value || '';
  return {
    search: value(`${prefix}-search`),
    status: value(`${prefix}-filter-status`),
    price_item_id: value(`${prefix}-filter-proc`),
    from: value(`${prefix}-from`),
    to: value(`${prefix}-to`),
  };
}

async function diagLoadBookings() {
  if (!document.getElementById('diag-book-tbody')) return;
  admTableMessage('diag-book-tbody', 8, 'Loading bookings…');
  try {
    const { bookings } = await KSI.get('diagnostics/bookings', { scope: 'bookings', ...diagFilters('diag-book') }, { trackActivity: false });
    DIAG.bookings = bookings;
    diagRenderBookings();
  } catch (error) {
    admTableMessage('diag-book-tbody', 8, 'Could not load the bookings.');
  }
}

function diagRenderBookings() {
  const tbody = document.getElementById('diag-book-tbody');
  const count = document.getElementById('diag-book-count');
  if (!tbody) return;
  if (count) count.textContent = `${DIAG.bookings.length} booking${DIAG.bookings.length === 1 ? '' : 's'}`;
  if (!DIAG.bookings.length) {
    admTableMessage('diag-book-tbody', 8, 'No bookings match your filters.');
    return;
  }
  tbody.innerHTML = DIAG.bookings.map(b => `<tr>
    <td>
      <div style="font-weight:600">${KSI.esc(b.patient.full_name)}${uiFastTrackTag(b)}</div>
      <div class="muted-note">${KSI.esc(b.patient.reg_no)}</div>
    </td>
    <td>${diagProcedureCell(b)}</td>
    <td>${KSI.esc(diagEyeLabel(b.eye))}</td>
    <td>
      <div>${KSI.fmtDateTime(b.scheduled_for)}</div>
      <div class="muted-note">${KSI.esc(b.booking_no)}</div>
    </td>
    <td>
      <div>${KSI.esc(b.referring.name)}</div>
      <div class="muted-note">${KSI.esc(uiLabel('diagnosticSource', b.source))}</div>
    </td>
    <td>${diagBillCell(b)}</td>
    <td>${diagStatusBadge(b.status)}</td>
    <td>
      <div class="row-actions">
        ${b.status === 'scheduled' && KSI.can('diagnostics.booking.checkin')
          ? `<button class="action-btn action-status" onclick="diagOpenCheckIn(${b.id})">Check in</button>` : ''}
        ${['scheduled', 'arrived'].includes(b.status) && KSI.can('diagnostics.booking.manage')
          ? `<button class="action-btn action-edit" onclick="diagOpenEdit(${b.id})">Edit</button>` : ''}
        ${['scheduled', 'arrived'].includes(b.status) && KSI.can('diagnostics.booking.cancel')
          ? `<button class="action-btn action-cancel" onclick="diagOpenCancel(${b.id})">Cancel</button>` : ''}
        ${b.status === 'cancellation_requested' ? '<span class="muted-note">Waiting for approval</span>' : ''}
      </div>
    </td>
  </tr>`).join('');
}

// ─── Booking modal ────────────────────────────────────────────────────────

async function diagOpenNew() {
  await diagOptions();
  DIAG.editing = null;
  DIAG.patient = null;
  DIAG.procedures = [];
  const modal = document.getElementById('modal-diag-booking');
  KSI.clearFieldErrors(modal);
  modal.querySelector('.modal-title').textContent = 'New diagnostic booking';
  document.getElementById('diag-form-submit').textContent = 'Book test';
  document.getElementById('diag-patient-search').value = '';
  document.getElementById('diag-patient-search').disabled = false;
  document.getElementById('diag-patient-results').innerHTML = '';
  document.getElementById('diag-patient-card').innerHTML = '';
  document.getElementById('diag-patient-row').style.display = '';
  admFillSelect('diag-ref-doctor', 'Select the referring doctor', DIAG.options.doctors.map(d => [d.id, d.name]));
  uiSetRadio('diag-eye', '');
  uiSetRadio('diag-timing', 'now');
  diagTimingChanged();
  document.getElementById('diag-sched-date').value = uiToday();
  document.getElementById('diag-sched-time').value = '08:00';
  diagRenderProcedureChips();
  diagRenderProcedureDetails();
  diagStaffNote();
  diagSetupPatientPicker();
  openModal('modal-diag-booking');
}

async function diagOpenEdit(id) {
  await diagOptions();
  const booking = DIAG.bookings.find(b => b.id === id) || (await KSI.get(`diagnostics/bookings/${id}`)).booking;
  DIAG.editing = booking;
  DIAG.patient = booking.patient;
  DIAG.procedures = [{
    price_item_id: booking.procedure.id,
    remark: booking.remark || '',
    laser_type_ids: booking.laser_type_id ? [booking.laser_type_id] : [],
    laser_subtype_ids: (booking.laser_subtypes || []).map(s => s.id),
  }];
  const modal = document.getElementById('modal-diag-booking');
  KSI.clearFieldErrors(modal);
  modal.querySelector('.modal-title').textContent = `Edit booking ${booking.booking_no}`;
  document.getElementById('diag-form-submit').textContent = 'Save changes';
  document.getElementById('diag-patient-row').style.display = 'none';
  document.getElementById('diag-patient-card').innerHTML = uiPatientCard(booking.patient);
  admFillSelect('diag-ref-doctor', 'Select the referring doctor', DIAG.options.doctors.map(d => [d.id, d.name]));
  document.getElementById('diag-ref-doctor').value = booking.referring.id;
  uiSetRadio('diag-eye', booking.eye);
  uiSetRadio('diag-timing', 'later');
  diagTimingChanged();
  const local = new Date(booking.scheduled_for);
  document.getElementById('diag-sched-date').value = local.toISOString().slice(0, 10);
  document.getElementById('diag-sched-time').value = local.toTimeString().slice(0, 5);
  diagRenderProcedureChips();
  diagRenderProcedureDetails();
  diagStaffNote();
  openModal('modal-diag-booking');
}

function diagRenderProcedureChips() {
  const grid = document.getElementById('diag-proc-grid');
  if (!grid) return;
  const locked = !!DIAG.editing;
  grid.classList.toggle('is-locked', locked);
  grid.innerHTML = (DIAG.options?.procedures || []).map(p => {
    const chosen = DIAG.procedures.some(s => s.price_item_id === p.id);
    return `<div class="diag-proc-chip ${chosen ? 'selected' : ''}" ${locked ? '' : `onclick="diagToggleProcedure(${p.id})"`}>
      <div class="chip-check"></div>
      <div>
        <div>${KSI.esc(p.name)}</div>
        <div class="muted-note">TZS ${KSI.fmtMoney(p.unit_price)}</div>
      </div>
    </div>`;
  }).join('');
  const note = document.getElementById('diag-proc-count');
  if (note) {
    note.textContent = DIAG.procedures.length === 0 || locked ? ''
      : `${DIAG.procedures.length} procedure${DIAG.procedures.length > 1 ? 's' : ''} selected — each one becomes its own booking.`;
  }
}

function diagToggleProcedure(id) {
  const index = DIAG.procedures.findIndex(p => p.price_item_id === id);
  if (index >= 0) DIAG.procedures.splice(index, 1);
  else DIAG.procedures.push({ price_item_id: id, remark: '', laser_type_ids: [], laser_subtype_ids: [] });
  diagRenderProcedureChips();
  diagRenderProcedureDetails();
}

/** A remark per procedure, plus the laser type and Retina sub-types where they apply. */
function diagRenderProcedureDetails() {
  const wrap = document.getElementById('diag-proc-details');
  if (!wrap) return;
  wrap.innerHTML = DIAG.procedures.map((selection, index) => {
    const procedure = diagProcedureById(selection.price_item_id);
    if (!procedure) return '';
    const single = !!DIAG.editing;
    const laserBlock = procedure.is_laser ? `
      <div class="diag-laser-box" data-field="procedures.${index}.laser_type_ids">
        <div class="form-label">Laser type${single ? '' : ' (one booking per type)'}</div>
        <div class="check-row">
          ${DIAG.options.laser_types.map(t => `
            <label class="finding-cb">
              <input type="${single ? 'radio' : 'checkbox'}" name="diag-laser-${index}"
                     ${selection.laser_type_ids.includes(t.id) ? 'checked' : ''}
                     onchange="diagToggleLaser(${index}, ${t.id}, this.checked, ${single})">
              <span class="finding-cb-label">${KSI.esc(t.label)}</span>
            </label>`).join('')}
        </div>
        ${diagRetinaChosen(selection) ? `
        <div class="diag-laser-sub">
          <div class="form-label">Retina — sub-type</div>
          <div class="check-row">
            ${DIAG.options.laser_subtypes.map(s => `
              <label class="finding-cb">
                <input type="checkbox" ${selection.laser_subtype_ids.includes(s.id) ? 'checked' : ''}
                       onchange="diagToggleSubtype(${index}, ${s.id}, this.checked)">
                <span class="finding-cb-label">${KSI.esc(s.label)}</span>
              </label>`).join('')}
          </div>
        </div>` : ''}
      </div>` : '';
    return `<div class="diag-proc-detail">
      ${laserBlock}
      <div class="form-group" style="margin:0">
        <label class="form-label">${KSI.esc(procedure.name)} — reason or instructions</label>
        <textarea class="form-input" rows="2" data-field="procedures.${index}.remark"
                  oninput="DIAG.procedures[${index}].remark = this.value"
                  placeholder="Why the test is being done, and anything the technician should know">${KSI.esc(selection.remark || '')}</textarea>
      </div>
    </div>`;
  }).join('');
}

function diagRetinaChosen(selection) {
  const retina = (DIAG.options?.laser_types || []).find(t => t.label.toLowerCase() === 'retina');
  return !!retina && selection.laser_type_ids.includes(retina.id);
}

function diagToggleLaser(index, typeId, checked, single) {
  const selection = DIAG.procedures[index];
  if (single) selection.laser_type_ids = checked ? [typeId] : [];
  else if (checked) selection.laser_type_ids.push(typeId);
  else selection.laser_type_ids = selection.laser_type_ids.filter(id => id !== typeId);
  if (!diagRetinaChosen(selection)) selection.laser_subtype_ids = [];
  diagRenderProcedureDetails();
}

function diagToggleSubtype(index, valueId, checked) {
  const selection = DIAG.procedures[index];
  selection.laser_subtype_ids = checked
    ? [...selection.laser_subtype_ids, valueId]
    : selection.laser_subtype_ids.filter(id => id !== valueId);
}

function diagTimingChanged() {
  const later = uiRadioValue('diag-timing') === 'later';
  document.getElementById('diag-schedule-fields').style.display = later ? '' : 'none';
}

/** Wired the first time the booking form is opened. */
/** Without a doctor on file nothing can be booked: say so rather than failing on save. */
function diagStaffNote() {
  const note = document.getElementById('diag-doctor-note');
  if (!note) return;
  const missing = !(DIAG.options?.doctors || []).length;
  note.style.display = missing ? '' : 'none';
  note.textContent = missing
    ? 'No doctors are set up yet. An administrator must give at least one member of staff the Ophthalmologist, Surgeon or Optometrist designation.'
    : '';
  document.getElementById('diag-form-submit').disabled = missing;
}

function diagSetupPatientPicker() {
  if (DIAG.pickerReady) return;
  DIAG.pickerReady = true;
  uiPatientPicker('diag-patient-search', 'diag-patient-results', patient => {
    DIAG.patient = patient;
    document.getElementById('diag-patient-card').innerHTML = uiPatientCard(patient);
    document.getElementById('diag-patient-search').value = patient.full_name;
  });
}

async function diagSaveBooking(button) {
  const modal = document.getElementById('modal-diag-booking');
  KSI.clearFieldErrors(modal);
  const payload = {
    referring_user_id: Number(document.getElementById('diag-ref-doctor').value) || 0,
    eye: uiRadioValue('diag-eye'),
    timing: uiRadioValue('diag-timing'),
    scheduled_date: document.getElementById('diag-sched-date').value,
    scheduled_time: document.getElementById('diag-sched-time').value,
  };
  if (!DIAG.editing && !DIAG.patient) {
    KSI.showFieldErrors(modal, { patient_id: 'Choose a patient.' });
    return;
  }
  if (!DIAG.procedures.length) {
    KSI.showFieldErrors(modal, { procedures: 'Choose at least one procedure.' });
    return;
  }
  await admBusy(button, async () => {
    try {
      if (DIAG.editing) {
        const selection = DIAG.procedures[0];
        await KSI.put(`diagnostics/bookings/${DIAG.editing.id}`, {
          ...payload,
          remark: selection.remark,
          laser_type_id: selection.laser_type_ids[0] || null,
          laser_subtype_ids: selection.laser_subtype_ids,
          row_version: DIAG.editing.row_version,
        });
        showToast('Booking updated', `Changes saved for ${DIAG.editing.booking_no}`, 'success');
      } else {
        const { bookings } = await KSI.post('diagnostics/bookings', {
          ...payload, patient_id: DIAG.patient.id, procedures: DIAG.procedures,
        });
        showToast('Booking created', `${bookings.length} test${bookings.length > 1 ? 's' : ''} booked for ${DIAG.patient.full_name}`, 'success');
      }
      closeModal('modal-diag-booking');
      await diagLoadBookings();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not save the booking');
    }
  });
}

// ─── Check-in ─────────────────────────────────────────────────────────────

async function diagOpenCheckIn(id) {
  await diagOptions();
  const booking = DIAG.bookings.find(b => b.id === id);
  if (!booking) return;
  DIAG.checkInTarget = booking;
  const modal = document.getElementById('modal-diag-checkin');
  KSI.clearFieldErrors(modal);
  document.getElementById('diag-checkin-info').innerHTML = `
    <div><strong>${KSI.esc(booking.patient.full_name)}</strong> · ${KSI.esc(booking.patient.reg_no)}</div>
    <div class="muted-note">${KSI.esc(booking.procedure.name)} · ${KSI.esc(diagEyeLabel(booking.eye))} · ${KSI.esc(booking.booking_no)}</div>`;
  document.getElementById('diag-checkin-amount').textContent = `TZS ${KSI.fmtMoney(booking.procedure.unit_price)}`;
  admFillSelect('diag-checkin-reason', 'Select a reason', DIAG.options.no_charge_reasons.map(r => [r.label, r.label]));
  document.getElementById('diag-checkin-remarks').value = '';
  uiSetRadio('diag-checkin-mode', 'bill');
  diagCheckInModeChanged();
  openModal('modal-diag-checkin');
}

function diagCheckInModeChanged() {
  const noCharge = uiRadioValue('diag-checkin-mode') === 'no_charge';
  document.getElementById('diag-checkin-nocharge').style.display = noCharge ? '' : 'none';
  document.getElementById('diag-checkin-bill-note').style.display = noCharge ? 'none' : '';
}

async function diagSubmitCheckIn(button) {
  const modal = document.getElementById('modal-diag-checkin');
  KSI.clearFieldErrors(modal);
  const noCharge = uiRadioValue('diag-checkin-mode') === 'no_charge';
  const reason = document.getElementById('diag-checkin-reason').value;
  if (noCharge && !reason) {
    KSI.showFieldErrors(modal, { no_charge_reason: 'Choose why the test is not charged.' });
    return;
  }
  await admBusy(button, async () => {
    try {
      await KSI.post(`diagnostics/bookings/${DIAG.checkInTarget.id}/check-in`, {
        no_charge: noCharge,
        no_charge_reason: noCharge ? reason : '',
        remarks: document.getElementById('diag-checkin-remarks').value,
      });
      closeModal('modal-diag-checkin');
      showToast('Checked in', noCharge
        ? 'The test can go ahead. The no-charge request is waiting for an administrator.'
        : 'The bill has been raised and is waiting at the cashier.', 'success');
      await diagLoadBookings();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not check the patient in');
    }
  });
}

// ─── Cancellation ─────────────────────────────────────────────────────────

function diagOpenCancel(id) {
  const booking = DIAG.bookings.find(b => b.id === id);
  if (!booking) return;
  DIAG.cancelTarget = booking;
  const modal = document.getElementById('modal-diag-cancel');
  KSI.clearFieldErrors(modal);
  document.getElementById('diag-cancel-info').innerHTML = `
    <div><strong>${KSI.esc(booking.patient.full_name)}</strong> · ${KSI.esc(booking.procedure.name)} · ${KSI.esc(diagEyeLabel(booking.eye))}</div>
    <div class="muted-note">${KSI.esc(booking.booking_no)} · ${KSI.fmtDateTime(booking.scheduled_for)}</div>`;
  document.getElementById('diag-cancel-reason').value = '';
  openModal('modal-diag-cancel');
}

async function diagSubmitCancel(button) {
  const modal = document.getElementById('modal-diag-cancel');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`diagnostics/bookings/${DIAG.cancelTarget.id}/cancel`, {
        reason: document.getElementById('diag-cancel-reason').value,
      });
      closeModal('modal-diag-cancel');
      showToast('Sent for approval', `${booking.booking_no} will be cancelled once an administrator agrees.`, 'info');
      await diagLoadBookings();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not request the cancellation');
    }
  });
}

// ─── Reporting queue ──────────────────────────────────────────────────────

async function diagOpenReport() {
  await diagOptions();
  await diagLoadQueue();
}

async function diagLoadQueue() {
  const body = document.getElementById('diag-report-queue-body');
  if (!body) return;
  try {
    const { bookings } = await KSI.get('diagnostics/bookings', { scope: 'queue', ...diagFilters('diag-report-queue') }, { trackActivity: false });
    DIAG.queue = bookings;
    diagRenderQueue();
  } catch (error) {
    body.innerHTML = `<div class="muted-note" style="padding:24px;text-align:center">${KSI.esc(error.message || 'Could not load the queue.')}</div>`;
  }
}

function diagRenderQueue() {
  const body = document.getElementById('diag-report-queue-body');
  const count = document.getElementById('diag-report-queue-count');
  if (!body) return;
  if (count) count.textContent = `${DIAG.queue.length} waiting`;
  if (!DIAG.queue.length) {
    body.innerHTML = '<div class="muted-note" style="padding:24px;text-align:center">No tests are waiting for a report.</div>';
    return;
  }
  body.innerHTML = DIAG.queue.map(b => `
    <div class="diag-report-queue-item ${DIAG.selected?.id === b.id ? 'selected' : ''}" onclick="diagSelectCase(${b.id})">
      <div class="diag-report-queue-patient">${KSI.esc(b.patient.full_name)}${uiFastTrackTag(b)}</div>
      <div class="diag-report-queue-proc">${KSI.esc(b.procedure.name)}${b.laser_type ? ` (${KSI.esc(b.laser_type)})` : ''} — ${KSI.esc(diagEyeLabel(b.eye))}</div>
      <div class="diag-report-queue-meta">
        ${diagStatusBadge(b.status)}
        <span>${KSI.fmtDateTime(b.scheduled_for)}</span>
      </div>
      ${b.bill ? '' : '<div class="muted-note">Not charged yet</div>'}
    </div>`).join('');
}

async function diagSelectCase(id) {
  const { booking } = await KSI.get(`diagnostics/bookings/${id}`);
  DIAG.selected = booking;
  DIAG.meds = [];
  diagRenderQueue();
  diagRenderReportPanel();
}

function diagClearCase() {
  DIAG.selected = null;
  DIAG.meds = [];
  diagRenderQueue();
  diagRenderReportPanel();
}

function diagRenderReportPanel() {
  const panel = document.getElementById('diag-report-panel');
  if (!panel) return;
  const b = DIAG.selected;
  if (!b) {
    panel.innerHTML = `<div class="cl-queue-empty">
      <div style="font-size:13px;font-weight:600">Select a test to write its report</div>
      <div class="muted-note">The queue on the left holds every test that has not been reported.</div>
    </div>`;
    return;
  }
  const report = b.report;
  const readOnly = !!report && !report.correctable_until;
  const doctors = DIAG.options.doctors;
  const technicians = DIAG.options.technicians;
  panel.innerHTML = `
    <div class="diag-report-form">
      <div class="cl-mode">
        <div>
          <div class="cl-head-title">${report ? 'Report' : 'Write the report'}</div>
          <div class="muted-note">${KSI.esc(b.booking_no)} · booked ${KSI.fmtDateTime(b.created_at)}${b.visit_no ? ` · visit ${KSI.esc(b.visit_no)}` : ''}</div>
        </div>
        ${diagStatusBadge(b.status)}
      </div>

      ${uiPatientCard(b.patient)}

      <div class="diag-report-facts">
        <div><span class="pcd-label">Procedure</span><span class="pcd-value">${KSI.esc(b.procedure.name)}${b.laser_type ? ` (${KSI.esc(b.laser_type)})` : ''}</span></div>
        <div><span class="pcd-label">Eye</span><span class="pcd-value">${KSI.esc(diagEyeLabel(b.eye))}</span></div>
        <div><span class="pcd-label">Referred by</span><span class="pcd-value">${KSI.esc(b.referring.name)}</span></div>
        <div><span class="pcd-label">Charge</span><span class="pcd-value">${b.bill
          ? `${b.bill.status === 'exempted' ? 'No charge' : 'TZS ' + KSI.fmtMoney(b.bill.amount)} · ${KSI.esc(uiLabel('billStatus', b.bill.status))}`
          : 'Not charged yet'}</span></div>
        ${(b.laser_subtypes || []).length ? `<div><span class="pcd-label">Retina sub-type</span><span class="pcd-value">${KSI.esc(b.laser_subtypes.map(s => s.label).join(', '))}</span></div>` : ''}
      </div>

      ${b.remark ? `<div class="cl-note">
        <div class="pcd-label">Reason / instructions</div>
        <div>${KSI.esc(b.remark)}</div>
      </div>` : ''}

      ${readOnly ? `<div class="cl-lock-note">This report can no longer be changed. Add an addendum if something needs to be said.</div>` : ''}
      ${report && !readOnly ? `<div class="cl-note">Report submitted. You can still attach scans and make changes until
        ${KSI.esc(KSI.fmtDateTime(report.correctable_until))}; each change is kept as a correction.</div>` : ''}

      <div class="form-grid">
        <div class="form-group" data-field="technician_user_id">
          <label class="form-label">Nurse / technician</label>
          <select class="form-select" id="diag-rep-technician" ${readOnly ? 'disabled' : ''}></select>
        </div>
        <div class="form-group" data-field="reporting_user_id">
          <label class="form-label">Reporting doctor <span class="required">*</span></label>
          <select class="form-select" id="diag-rep-doctor" ${readOnly ? 'disabled' : ''}></select>
        </div>
      </div>

      <div class="form-group" data-field="result">
        <label class="form-label">Result</label>
        <textarea class="form-input" rows="6" id="diag-rep-result" ${readOnly ? 'disabled' : ''}
          placeholder="Findings, measurements and the result of this test…">${KSI.esc(report?.result || '')}</textarea>
      </div>

      <div class="form-group" data-field="comments">
        <label class="form-label">Comments for the referring doctor</label>
        <textarea class="form-input" rows="3" id="diag-rep-comments" ${readOnly ? 'disabled' : ''}
          placeholder="Anything the referring doctor should note…">${KSI.esc(report?.comments || '')}</textarea>
      </div>

      ${diagMedicinesHtml(report, readOnly)}

      ${report ? diagFilesHtml(b) : '<div class="muted-note">Scans and printouts can be attached once the report is saved.</div>'}
      ${report ? diagAddendaHtml(report) : ''}

      <div class="modal-footer" style="padding:16px 0 0">
        <button class="btn btn-secondary" onclick="diagClearCase()">Close</button>
        ${report && readOnly && KSI.can('diagnostics.report.record')
          ? `<button class="btn btn-secondary" onclick="diagOpenAddendum(${b.id})">Add addendum</button>` : ''}
        ${!readOnly && KSI.can('diagnostics.report.record')
          ? `<button class="btn btn-primary" onclick="diagSaveReport(this)">${report ? 'Save' : 'Submit report'}</button>` : ''}
      </div>
    </div>`;

  admFillSelect('diag-rep-technician', 'Not recorded', technicians.map(t => [t.id, t.name]));
  admFillSelect('diag-rep-doctor', 'Select the reporting doctor', doctors.map(d => [d.id, d.name]));
  if (report) {
    document.getElementById('diag-rep-technician').value = report.technician?.id || '';
    document.getElementById('diag-rep-doctor').value = report.reporting_doctor.id;
  }
  diagRenderMeds();
}

// ─── Medicines: sent to the pharmacy with the report, billed there as usual ──

function diagMedicinesHtml(report, readOnly) {
  if (report?.prescription_no) {
    return `<div class="cl-note">
      <div class="pcd-label">Medicine sent to the pharmacy · ${KSI.esc(report.prescription_no)}</div>
      ${(report.medicines || []).map(m => `<div>${KSI.esc(m.name)} × ${m.quantity}${[m.dose, m.frequency, m.duration].filter(Boolean).length
        ? ` — ${KSI.esc([m.dose, m.frequency, m.duration].filter(Boolean).join(', '))}` : ''}</div>`).join('')}
    </div>`;
  }
  if (readOnly || !KSI.can('diagnostics.report.record')) return '';
  return `<div class="form-group" data-field="medicines">
      <label class="form-label">Medicines</label>
      <select class="form-select" id="diag-rep-drug" onchange="diagAddMed(this.value)"></select>
      <div class="table-wrap" style="margin-top:8px">
        <table>
          <thead><tr><th>Medicine</th><th>How many</th><th>Dose</th><th>How often</th><th>For how long</th><th></th></tr></thead>
          <tbody id="diag-rep-meds"></tbody>
        </table>
      </div>
    </div>
    <div class="form-group" data-field="pharmacy_instructions">
      <label class="form-label">Note for the pharmacy</label>
      <input class="form-input" id="diag-rep-pharm-instructions" maxlength="200">
      <div class="muted-note">Sent to the pharmacy when you ${report ? 'save' : 'submit the report'}; the patient pays at the cashier before it is handed over.</div>
    </div>`;
}

function diagRenderMeds() {
  const body = document.getElementById('diag-rep-meds');
  if (!body) return;
  const chosen = DIAG.meds.map(m => m.drug_id);
  admFillSelect('diag-rep-drug', 'Add a medicine', (DIAG.options.drugs || []).filter(d => !chosen.includes(d.id)).map(d => [d.id, d.name]));
  body.innerHTML = DIAG.meds.length
    ? DIAG.meds.map((m, index) => `<tr>
        <td>${KSI.esc(m.name)}</td>
        <td><input type="number" class="form-input" min="1" value="${m.quantity}" style="width:70px" onchange="diagSetMed(${index}, 'quantity', this.value)"></td>
        <td><input class="form-input" value="${KSI.esc(m.dose || '')}" style="width:90px" onchange="diagSetMed(${index}, 'dose', this.value)"></td>
        <td><input class="form-input" value="${KSI.esc(m.frequency || '')}" style="width:90px" onchange="diagSetMed(${index}, 'frequency', this.value)"></td>
        <td><input class="form-input" value="${KSI.esc(m.duration || '')}" style="width:100px" onchange="diagSetMed(${index}, 'duration', this.value)"></td>
        <td><button class="action-btn action-cancel" onclick="diagRemoveMed(${index})">Remove</button></td>
      </tr>`).join('')
    : '<tr class="table-state"><td colspan="6">No medicine added.</td></tr>';
}

function diagAddMed(drugId) {
  const drug = (DIAG.options.drugs || []).find(d => d.id === Number(drugId));
  if (!drug) return;
  DIAG.meds.push({
    drug_id: drug.id, name: drug.name, quantity: 1,
    dose: drug.default_dose || '', frequency: drug.default_frequency || '', duration: drug.default_duration || '',
  });
  diagRenderMeds();
}

function diagSetMed(index, field, value) {
  if (!DIAG.meds[index]) return;
  DIAG.meds[index][field] = field === 'quantity' ? Math.max(1, Number(value) || 1) : value;
}

function diagRemoveMed(index) {
  DIAG.meds.splice(index, 1);
  diagRenderMeds();
}

function diagFilesHtml(booking) {
  const files = booking.report.files || [];
  return `<div class="cl-files">
    <div class="form-label">Report files</div>
    ${files.length ? `<div class="cl-file-list">${files.map(f => `
      <div class="cl-file">
        <button class="link-btn" onclick="KSI.openFile('diagnostics/bookings/${booking.id}/files/${f.id}')">${KSI.esc(f.name)}</button>
        <span class="muted-note">${Math.round(f.size_bytes / 1024)} KB · ${KSI.esc(f.uploaded_by)}</span>
        ${KSI.can('diagnostics.report.record') ? `<button class="med-row-remove" title="Remove this file"
          onclick="diagRemoveFile(${booking.id}, ${f.id}, ${KSI.esc(JSON.stringify(f.name))})">&times;</button>` : ''}
      </div>`).join('')}</div>` : '<div class="muted-note">No files attached.</div>'}
    ${KSI.can('diagnostics.report.record') ? `
      <label class="btn btn-secondary btn-sm" style="margin-top:8px">
        Attach a file
        <input type="file" hidden accept=".pdf,.jpg,.jpeg,.png" onchange="diagUploadFile(${booking.id}, this)">
      </label>` : ''}
  </div>`;
}

function diagAddendaHtml(report) {
  if (!report.addenda?.length) return '';
  return `<div class="cl-addenda">
    <div class="form-label">Addenda</div>
    ${report.addenda.map(a => `<div class="cl-addendum">
      <div class="cl-addendum-head">${KSI.esc(a.reason)} — ${KSI.esc(a.created_by)} · ${KSI.fmtDateTime(a.created_at)}</div>
      <div>${KSI.esc(a.note)}</div>
    </div>`).join('')}
  </div>`;
}

async function diagSaveReport(button) {
  const panel = document.getElementById('diag-report-panel');
  KSI.clearFieldErrors(panel);
  const booking = DIAG.selected;
  const payload = {
    reporting_user_id: Number(document.getElementById('diag-rep-doctor').value) || 0,
    technician_user_id: document.getElementById('diag-rep-technician').value || null,
    result: document.getElementById('diag-rep-result').value,
    comments: document.getElementById('diag-rep-comments').value,
    medicines: DIAG.meds.map(m => ({ drug_id: m.drug_id, quantity: m.quantity, dose: m.dose, frequency: m.frequency, duration: m.duration })),
    pharmacy_instructions: document.getElementById('diag-rep-pharm-instructions')?.value || '',
    row_version: booking.report?.row_version || 0,
  };
  await admBusy(button, async () => {
    try {
      const saved = await KSI.put(`diagnostics/bookings/${booking.id}/report`, payload);
      DIAG.selected = saved.booking;
      DIAG.meds = [];
      const rx = saved.booking.report?.prescription_no;
      showToast('Report saved', `${saved.booking.booking_no} has been reported.${payload.medicines.length && rx ? ` Medicine sent to the pharmacy (${rx}).` : ''}`, 'success');
      if (saved.unbilled) diagOpenUnbilled(saved.booking);
      await diagLoadQueue();
      diagRenderReportPanel();
    } catch (error) {
      KSI.reportError(error, panel, 'Could not save the report');
    }
  });
}

/** The test was done without the desk checking the patient in: settle the charge now. */
async function diagOpenUnbilled(booking) {
  await diagOptions();
  DIAG.checkInTarget = booking;
  const modal = document.getElementById('modal-diag-checkin');
  KSI.clearFieldErrors(modal);
  document.getElementById('diag-checkin-info').innerHTML = `
    <div><strong>${KSI.esc(booking.patient.full_name)}</strong> · ${KSI.esc(booking.patient.reg_no)}</div>
    <div class="muted-note">${KSI.esc(booking.procedure.name)} · ${KSI.esc(booking.booking_no)}</div>
    <div class="alert alert-warning" style="margin-top:8px">This test has been reported but was never charged.</div>`;
  document.getElementById('diag-checkin-amount').textContent = `TZS ${KSI.fmtMoney(booking.procedure.unit_price)}`;
  admFillSelect('diag-checkin-reason', 'Select a reason', DIAG.options.no_charge_reasons.map(r => [r.label, r.label]));
  document.getElementById('diag-checkin-remarks').value = '';
  uiSetRadio('diag-checkin-mode', 'bill');
  diagCheckInModeChanged();
  openModal('modal-diag-checkin');
}

async function diagUploadFile(bookingId, input) {
  const file = input.files?.[0];
  if (!file) return;
  const data = new FormData();
  data.append('file', file);
  try {
    await KSI.upload(`diagnostics/bookings/${bookingId}/files`, data);
    showToast('File attached', file.name, 'success');
    await diagSelectCase(bookingId);
  } catch (error) {
    KSI.reportError(error, null, 'Could not attach the file');
  } finally {
    input.value = '';
  }
}

/** Takes a file off the report when it was attached by mistake. */
async function diagRemoveFile(bookingId, fileId, name) {
  if (!confirm(`Remove ${name} from this report?`)) return;
  try {
    await KSI.del(`diagnostics/bookings/${bookingId}/files/${fileId}`);
    showToast('File removed', name, 'success');
    await diagSelectCase(bookingId);
  } catch (error) {
    KSI.reportError(error, null, 'Could not remove the file');
  }
}

// ─── Addendum ─────────────────────────────────────────────────────────────

function diagOpenAddendum(bookingId) {
  DIAG.selected = DIAG.selected?.id === bookingId ? DIAG.selected : null;
  const modal = document.getElementById('modal-diag-addendum');
  KSI.clearFieldErrors(modal);
  document.getElementById('diag-addendum-reason').value = '';
  document.getElementById('diag-addendum-note').value = '';
  openModal('modal-diag-addendum');
}

async function diagSaveAddendum(button) {
  const modal = document.getElementById('modal-diag-addendum');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`diagnostics/bookings/${DIAG.selected.id}/addenda`, {
        reason: document.getElementById('diag-addendum-reason').value,
        note: document.getElementById('diag-addendum-note').value,
      });
      DIAG.selected = booking;
      closeModal('modal-diag-addendum');
      showToast('Addendum added', 'The report now carries your note.', 'success');
      diagRenderReportPanel();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not add the addendum');
    }
  });
}

// ─── History ──────────────────────────────────────────────────────────────

async function diagOpenHistory() {
  await diagOptions();
  diagFillProcedureFilters();
  await diagLoadHistory();
}

async function diagLoadHistory() {
  if (!document.getElementById('diag-hist-tbody')) return;
  admTableMessage('diag-hist-tbody', 7, 'Loading history…');
  try {
    const { bookings } = await KSI.get('diagnostics/bookings', { scope: 'history', ...diagFilters('diag-hist') }, { trackActivity: false });
    DIAG.history = bookings;
    diagRenderHistory();
  } catch (error) {
    admTableMessage('diag-hist-tbody', 7, 'Could not load the history.');
  }
}

function diagRenderHistory() {
  const tbody = document.getElementById('diag-hist-tbody');
  const count = document.getElementById('diag-hist-count');
  if (!tbody) return;
  if (count) count.textContent = `${DIAG.history.length} record${DIAG.history.length === 1 ? '' : 's'}`;
  if (!DIAG.history.length) {
    admTableMessage('diag-hist-tbody', 7, 'No reported tests match your filters.');
    return;
  }
  tbody.innerHTML = DIAG.history.map(b => `<tr>
    <td>
      <div>${KSI.fmtDateTime(b.reported_at)}</div>
      <div class="muted-note">${KSI.esc(b.booking_no)}</div>
    </td>
    <td>
      <div style="font-weight:600">${KSI.esc(b.patient.full_name)}${uiFastTrackTag(b)}</div>
      <div class="muted-note">${KSI.esc(b.patient.reg_no)}</div>
    </td>
    <td>${diagProcedureCell(b)}</td>
    <td>${KSI.esc(diagEyeLabel(b.eye))}</td>
    <td>${KSI.esc(b.referring.name)}</td>
    <td>${KSI.esc(b.reporting_doctor || '—')}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="diagViewReport(${b.id})">View</button>
        <button class="action-btn" onclick="diagDownloadReport(${b.id})">Download</button>
      </div>
    </td>
  </tr>`).join('');
}

async function diagViewReport(id) {
  const { booking } = await KSI.get(`diagnostics/bookings/${id}`);
  DIAG.selected = booking;
  document.getElementById('diag-view-title').textContent = `Report — ${booking.booking_no}`;
  document.getElementById('diag-view-body').innerHTML = `
    ${uiPatientCard(booking.patient)}
    <div class="diag-report-facts">
      <div><span class="pcd-label">Procedure</span><span class="pcd-value">${KSI.esc(booking.procedure.name)}${booking.laser_type ? ` (${KSI.esc(booking.laser_type)})` : ''}</span></div>
      <div><span class="pcd-label">Eye</span><span class="pcd-value">${KSI.esc(diagEyeLabel(booking.eye))}</span></div>
      <div><span class="pcd-label">Referred by</span><span class="pcd-value">${KSI.esc(booking.referring.name)}</span></div>
      <div><span class="pcd-label">Reported by</span><span class="pcd-value">${KSI.esc(booking.report?.reporting_doctor.name || '—')}</span></div>
      <div><span class="pcd-label">Technician</span><span class="pcd-value">${KSI.esc(booking.report?.technician?.name || '—')}</span></div>
      <div><span class="pcd-label">Reported</span><span class="pcd-value">${KSI.fmtDateTime(booking.reported_at)}</span></div>
    </div>
    <div class="form-label" style="margin-top:14px">Result</div>
    <div class="cl-readout-note">${KSI.esc(booking.report?.result || 'No result was recorded.')}</div>
    <div class="form-label" style="margin-top:14px">Comments</div>
    <div class="cl-readout-note">${KSI.esc(booking.report?.comments || 'No comments.')}</div>
    ${booking.report?.prescription_no ? diagMedicinesHtml(booking.report, true) : ''}
    ${booking.report ? diagFilesHtml(booking) : ''}
    ${booking.report ? diagAddendaHtml(booking.report) : ''}`;
  openModal('modal-diag-view');
}

/** The printable report, as the prototype downloaded it. */
function diagDownloadReport(id) {
  const b = DIAG.history.find(x => x.id === id) || DIAG.selected;
  if (!b) return;
  KSI.get(`diagnostics/bookings/${id}`).then(({ booking }) => {
    const lines = [
      'KSI EYE CENTRE — DIAGNOSTIC REPORT',
      '==================================',
      '',
      `Booking:            ${booking.booking_no}`,
      `Patient:            ${booking.patient.full_name}`,
      `Patient ID:         ${booking.patient.reg_no}`,
      `Age / Gender:       ${booking.patient.age} yrs · ${uiLabel('gender', booking.patient.gender)}`,
      `Procedure:          ${booking.procedure.name}${booking.laser_type ? ` (${booking.laser_type})` : ''}`,
      `Eye:                ${diagEyeLabel(booking.eye)}`,
      `Referring doctor:   ${booking.referring.name}`,
      `Reporting doctor:   ${booking.report?.reporting_doctor.name || '—'}`,
      `Reported:           ${KSI.fmtDateTime(booking.reported_at)}`,
      '',
      'RESULT',
      '------',
      booking.report?.result || '(no result recorded)',
      '',
      'COMMENTS',
      '--------',
      booking.report?.comments || '(none)',
      '',
      'Powered by Fabonic',
    ];
    const blob = new Blob([lines.join('\r\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `Diagnostic-Report-${booking.booking_no}.txt`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  });
}
