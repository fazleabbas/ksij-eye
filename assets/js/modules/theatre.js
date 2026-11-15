'use strict';
// ─── THEATRE ──────────────────────────────────────────────────────────────
// One surgical case from booking to discharge: booking and its bill, pre-op
// clearance, the operation, post-op reviews and discharge. Surgery waits for
// the bill unless an emergency case is explicitly allowed through.

const TH = {
  options: null,
  bookings: [],
  recommendations: [],
  preop: [],
  operations: [],
  postop: [],
  history: [],
  current: null,        // case open in a panel or modal
  editing: null,        // booking open in the booking modal
  patient: null,
  procedures: [],       // lines on the booking form
  biometryNeeded: false,
  meds: [],             // medicine lines on the open form
  postopVisit: null,    // post-op visit being written
  addendum: null,       // { source, record_id }
  pickerReady: false,
};

async function thOptions() {
  if (!TH.options) TH.options = await KSI.get('theatre/options');
  return TH.options;
}

const thMoney = amount => 'TZS ' + KSI.fmtMoney(amount);
const thEye = eye => ({ right: 'Right eye', left: 'Left eye', both: 'Both eyes' }[eye] || eye || '—');

function thFilters(prefix) {
  const value = id => document.getElementById(id)?.value || '';
  return {
    search: value(`${prefix}-search`),
    status: value(`${prefix}-filter-status`),
    surgeon_user_id: value(`${prefix}-filter-surgeon`),
    payment: value(`${prefix}-filter-payment`),
    from: value(`${prefix}-from`),
    to: value(`${prefix}-to`),
  };
}

function thSurgeryCell(booking) {
  const parts = [];
  if (booking.surgery) parts.push(`<span class="badge badge-normal">${KSI.esc(booking.surgery.name)}</span>`);
  (booking.procedures || []).forEach(p => {
    parts.push(`<div class="muted-note">${KSI.esc(p.name)}${p.quantity > 1 ? ` ×${p.quantity}` : ''}</div>`);
  });
  return parts.join('') || '<span class="muted-note">—</span>';
}

function thBillCell(booking) {
  if (!booking.bill) return '<span class="muted-note">Not billed yet</span>';
  return `<div class="money">${thMoney(booking.bill.amount)}</div>
    <div class="muted-note">${KSI.esc(booking.bill.bill_no)} · ${KSI.esc(uiLabel('billStatus', booking.bill.status))}</div>
    ${booking.bill_deferred ? '<div class="muted-note">Emergency — allowed before payment</div>' : ''}`;
}

function thPatientCell(booking) {
  return `<div style="font-weight:600">${KSI.esc(booking.patient.full_name)}${uiFastTrackTag(booking)}</div>
    <div class="muted-note">${KSI.esc(booking.patient.reg_no)}</div>`;
}

// ─── Booking list ─────────────────────────────────────────────────────────

async function thOpenBookings() {
  await thOptions();
  thFillSurgeonFilters();
  await Promise.all([thLoadBookings(), thLoadRecommendations()]);
}

function thFillSurgeonFilters() {
  const surgeons = (TH.options?.surgeons || []).map(s => [s.id, s.name]);
  ['th-book-filter-surgeon', 'th-hist-filter-surgeon'].forEach(id => {
    const select = document.getElementById(id);
    if (!select || select.dataset.filled) return;
    admFillSelect(id, 'All surgeons', surgeons);
    select.dataset.filled = '1';
  });
}

async function thLoadRecommendations() {
  const card = document.getElementById('th-reco-card');
  if (!card) return;
  try {
    const { recommendations } = await KSI.get('theatre/recommendations', {}, { trackActivity: false });
    TH.recommendations = recommendations;
    card.style.display = recommendations.length ? '' : 'none';
    document.getElementById('th-reco-count').textContent = `${recommendations.length} waiting`;
    document.getElementById('th-reco-tbody').innerHTML = recommendations.map(r => `<tr>
      <td>
        <div style="font-weight:600">${KSI.esc(r.patient.full_name)}</div>
        <div class="muted-note">${KSI.esc(r.patient.reg_no)} · ${KSI.esc(r.visit_no)}</div>
      </td>
      <td>${KSI.esc(r.surgery.name)}<div class="muted-note">${KSI.esc(r.indication || '')}</div></td>
      <td>${KSI.esc(thEye(r.eye))}</td>
      <td>${uiBadge('urgency', r.urgency)}</td>
      <td>${KSI.fmtDate(r.recommended_at)}<div class="muted-note">${KSI.esc(r.recommended_by || '')}</div></td>
      <td><div class="row-actions">
        ${KSI.can('theatre.booking.manage') ? `<button class="action-btn action-edit" onclick="thOpenBooking(null, ${r.id})">Book it</button>` : ''}
      </div></td>
    </tr>`).join('');
  } catch (error) {
    card.style.display = 'none';
  }
}

async function thLoadBookings() {
  if (!document.getElementById('th-book-tbody')) return;
  admTableMessage('th-book-tbody', 9, 'Loading cases…');
  try {
    const { bookings } = await KSI.get('theatre/bookings', { scope: 'bookings', ...thFilters('th-book') }, { trackActivity: false });
    TH.bookings = bookings;
    thRenderBookings();
  } catch (error) {
    admTableMessage('th-book-tbody', 9, 'Could not load the cases.');
  }
}

function thRenderBookings() {
  const count = document.getElementById('th-book-count');
  if (count) count.textContent = `${TH.bookings.length} case${TH.bookings.length === 1 ? '' : 's'}`;
  const stats = document.getElementById('th-book-stats');
  if (stats) {
    const by = status => TH.bookings.filter(b => b.status === status).length;
    stats.innerHTML = [
      ['Booked', by('booked')],
      ['Awaiting payment', TH.bookings.filter(b => !b.may_operate).length],
      ['Cleared', by('cleared')],
      ['In theatre', by('in_theatre')],
      ['Post-op care', by('post_op')],
    ].map(([label, value]) => `<div class="stat-card"><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div>`).join('');
  }
  if (!TH.bookings.length) {
    admTableMessage('th-book-tbody', 9, 'No cases match your filters.');
    return;
  }
  document.getElementById('th-book-tbody').innerHTML = TH.bookings.map(b => `<tr>
    <td><div style="font-weight:600">${KSI.esc(b.booking_no)}</div><div class="muted-note">${KSI.fmtDate(b.created_at)}</div></td>
    <td>${thPatientCell(b)}</td>
    <td>${thSurgeryCell(b)}</td>
    <td>${KSI.esc(thEye(b.eye))}</td>
    <td>${KSI.esc(b.surgeon.name)}<div class="muted-note">${KSI.esc(b.theatre_room || '')}</div></td>
    <td>${KSI.fmtDateTime(b.scheduled_for)}</td>
    <td>${thBillCell(b)}</td>
    <td>${uiBadge('theatreStatus', b.status)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="thOpenCase(${b.id})">View</button>
        ${['booked', 'cleared'].includes(b.status) && KSI.can('theatre.booking.manage')
          ? `<button class="action-btn action-edit" onclick="thOpenBooking(${b.id})">Edit</button>` : ''}
        ${!b.bill && ['booked', 'cleared'].includes(b.status) && KSI.can('theatre.booking.manage')
          ? `<button class="action-btn action-status" onclick="thRaiseBill(${b.id}, this)">Raise bill</button>` : ''}
        ${!b.may_operate && ['booked', 'cleared'].includes(b.status) && KSI.can('theatre.booking.defer_payment')
          ? `<button class="action-btn action-edit" onclick="thOpenDefer(${b.id})">Emergency</button>` : ''}
        ${['booked', 'cleared'].includes(b.status) && KSI.can('theatre.booking.cancel')
          ? `<button class="action-btn action-cancel" onclick="thOpenCancel(${b.id})">Cancel</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

// ─── Booking form ─────────────────────────────────────────────────────────

async function thOpenBooking(bookingId = null, recommendationId = null) {
  await thOptions();
  const modal = document.getElementById('modal-theatre-booking');
  KSI.clearFieldErrors(modal);
  TH.editing = null;
  TH.patient = null;
  TH.procedures = [];
  TH.recommendationId = null;

  admFillSelect('th-surgery', 'No surgery — procedures only', TH.options.surgeries.map(s => [s.id, `${s.name} — ${thMoney(s.unit_price)}`]));
  admFillSelect('th-surgeon', 'Choose the surgeon', TH.options.surgeons.map(s => [s.id, s.name]));
  admFillSelect('th-room', 'Not assigned', TH.options.rooms.map(r => [r.id, r.label]));
  thFillProcedurePicker();
  document.getElementById('th-surgeon-note').style.display = TH.options.surgeons.length ? 'none' : '';
  document.getElementById('th-surgeon-note').textContent = TH.options.surgeons.length ? ''
    : 'No surgeons are set up yet. An administrator must give at least one member of staff the Surgeon or Ophthalmologist designation.';
  document.getElementById('th-booking-save').disabled = !TH.options.surgeons.length;

  if (bookingId) {
    const { booking } = await KSI.get(`theatre/bookings/${bookingId}`);
    TH.editing = booking;
    TH.patient = booking.patient;
    TH.procedures = booking.procedures.map(p => ({ ...p }));
    modal.querySelector('.modal-title').textContent = `Edit ${booking.booking_no}`;
    document.getElementById('th-booking-save').textContent = 'Save changes';
    document.getElementById('th-patient-row').style.display = 'none';
    document.getElementById('th-patient-card').innerHTML = uiPatientCard(booking.patient);
    document.getElementById('th-surgery').value = booking.surgery?.id || '';
    document.getElementById('th-surgeon').value = booking.surgeon.id;
    document.getElementById('th-room').value = booking.theatre_room_id || '';
    uiSetRadio('th-eye', booking.eye);
    uiSetRadio('th-timing', 'later');
    const local = new Date(booking.scheduled_for);
    document.getElementById('th-sched-date').value = local.toISOString().slice(0, 10);
    document.getElementById('th-sched-time').value = local.toTimeString().slice(0, 5);
    thFillBiometry(booking.biometry);
  } else {
    modal.querySelector('.modal-title').textContent = 'New theatre booking';
    document.getElementById('th-booking-save').textContent = 'Book the case';
    document.getElementById('th-patient-row').style.display = '';
    document.getElementById('th-patient-search').value = '';
    document.getElementById('th-patient-results').innerHTML = '';
    document.getElementById('th-patient-card').innerHTML = '';
    document.getElementById('th-surgery').value = '';
    document.getElementById('th-surgeon').value = '';
    document.getElementById('th-room').value = '';
    uiSetRadio('th-eye', '');
    uiSetRadio('th-timing', 'now');
    document.getElementById('th-sched-date').value = uiToday();
    document.getElementById('th-sched-time').value = '08:00';
    thFillBiometry(null);
    TH.bioCache = null;
    document.getElementById('th-bio-source').style.display = 'none';
    if (recommendationId) thApplyRecommendation(recommendationId);
    thSetupPatientPicker();
  }
  modal.querySelectorAll('input[name="th-eye"]').forEach(radio => { radio.onchange = thAutoBiometry; });
  thTimingChanged();
  thSurgeryChanged();
  thRenderProcedures();
  openModal('modal-theatre-booking');
}

/** Books what a doctor recommended, with the patient, surgery and eye already filled in. */
function thApplyRecommendation(recommendationId) {
  const reco = TH.recommendations.find(r => r.id === recommendationId);
  if (!reco) return;
  TH.patient = reco.patient;
  TH.recommendationId = reco.id;
  TH.visitId = reco.visit_id;
  document.getElementById('th-patient-card').innerHTML = uiPatientCard(reco.patient);
  document.getElementById('th-patient-search').value = reco.patient.full_name;
  // The doctor recommends surgery and procedures on separate cards; a procedure books as a procedure line.
  if ((TH.options?.procedures || []).some(p => p.id === reco.surgery.id)) {
    if (!TH.procedures.some(p => p.price_item_id === reco.surgery.id)) thAddProcedure(reco.surgery.id);
  } else {
    document.getElementById('th-surgery').value = reco.surgery.id;
  }
  uiSetRadio('th-eye', reco.eye);
}

function thSetupPatientPicker() {
  if (TH.pickerReady) return;
  TH.pickerReady = true;
  uiPatientPicker('th-patient-search', 'th-patient-results', patient => {
    TH.patient = patient;
    TH.recommendationId = null;
    TH.visitId = null;
    document.getElementById('th-patient-card').innerHTML = uiPatientCard(patient);
    document.getElementById('th-patient-search').value = patient.full_name;
    thAutoBiometry();
  });
}

function thFillProcedurePicker() {
  const chosen = TH.procedures.map(p => p.price_item_id);
  const available = (TH.options?.procedures || []).filter(p => !chosen.includes(p.id));
  admFillSelect('th-procedure-pick', 'Add a procedure', available.map(p => [p.id, `${p.name} — ${thMoney(p.unit_price)}`]));
}

function thAddProcedure(itemId) {
  const procedure = (TH.options?.procedures || []).find(p => p.id === Number(itemId));
  if (!procedure) return;
  TH.procedures.push({ price_item_id: procedure.id, name: procedure.name, quantity: 1, unit_price: procedure.unit_price });
  thRenderProcedures();
}

function thRemoveProcedure(itemId) {
  TH.procedures = TH.procedures.filter(p => p.price_item_id !== itemId);
  thRenderProcedures();
}

function thSetProcedureQty(itemId, value) {
  const line = TH.procedures.find(p => p.price_item_id === itemId);
  if (line) line.quantity = Math.max(1, Number(value) || 1);
  thRenderProcedures();
}

function thRenderProcedures() {
  const body = document.getElementById('th-procedure-rows');
  if (!body) return;
  body.innerHTML = TH.procedures.length
    ? TH.procedures.map(p => `<tr>
        <td>${KSI.esc(p.name)}</td>
        <td><input type="number" class="form-input" min="1" value="${p.quantity}" style="width:80px"
             onchange="thSetProcedureQty(${p.price_item_id}, this.value)"></td>
        <td class="money">${thMoney(p.unit_price)}</td>
        <td class="money">${thMoney(p.unit_price * p.quantity)}</td>
        <td><button class="action-btn action-cancel" onclick="thRemoveProcedure(${p.price_item_id})">Remove</button></td>
      </tr>`).join('')
    : '<tr class="table-state"><td colspan="5">No procedures added.</td></tr>';
  thFillProcedurePicker();
  thBookingTotal();
}

function thBookingTotal() {
  const surgeryId = Number(document.getElementById('th-surgery')?.value) || 0;
  const surgery = (TH.options?.surgeries || []).find(s => s.id === surgeryId);
  const total = (surgery ? surgery.unit_price : 0) + TH.procedures.reduce((sum, p) => sum + p.unit_price * p.quantity, 0);
  const el = document.getElementById('th-total');
  if (el) el.textContent = thMoney(total);
}

function thSurgeryChanged() {
  const surgeryId = Number(document.getElementById('th-surgery')?.value) || 0;
  const surgery = (TH.options?.surgeries || []).find(s => s.id === surgeryId);
  TH.biometryNeeded = !!surgery && surgery.name.toLowerCase().includes('cataract');
  const block = document.getElementById('th-biometry-block');
  if (block) block.style.display = TH.biometryNeeded ? '' : 'none';
  thBookingTotal();
  thAutoBiometry();
}

function thTimingChanged() {
  const later = uiRadioValue('th-timing') === 'later';
  document.getElementById('th-schedule-fields').style.display = later ? '' : 'none';
}

function thFillBiometry(biometry) {
  const set = (id, value) => { const el = document.getElementById(id); if (el) el.value = value ?? ''; };
  set('th-bio-model', biometry?.iol_model);
  set('th-bio-power', biometry?.iol_power);
  set('th-bio-axial', biometry?.axial_length);
  set('th-bio-constant', biometry?.a_constant);
  set('th-bio-steep', biometry?.steep_k);
  set('th-bio-flat', biometry?.flat_k);
  set('th-bio-acd', biometry?.acd);
  set('th-bio-cct', biometry?.corneal_thickness);
  set('th-bio-lt', biometry?.lens_thickness);
  set('th-bio-wtw', biometry?.white_to_white);
  set('th-bio-remarks', biometry?.remarks);
}

/**
 * New cataract booking: start from the patient's latest Optometry biometry for the chosen eye
 * (however old; the date is shown). Theatre may change the figures; the booking keeps its own copy.
 */
async function thAutoBiometry() {
  const note = document.getElementById('th-bio-source');
  if (!note) return;
  const patientId = TH.patient?.id;
  const eye = uiRadioValue('th-eye');
  // Editing keeps the booking's own figures.
  if (TH.editing || !TH.biometryNeeded || !patientId) {
    note.style.display = 'none';
    return;
  }
  if (TH.bioCache?.patientId !== patientId) {
    try {
      const { biometry } = await KSI.get(`theatre/patients/${patientId}/biometry`);
      TH.bioCache = { patientId, biometry };
    } catch (error) {
      note.style.display = '';
      note.innerHTML = `<div>Could not look up the biometry from Optometry: ${KSI.esc(error.message)}</div>`;
      return;
    }
  }
  // The patient, surgery or eye may have changed while waiting.
  if (TH.patient?.id !== patientId || uiRadioValue('th-eye') !== eye || !TH.biometryNeeded) return;
  const data = TH.bioCache.biometry;
  const source = b => `Optometry, visit ${KSI.esc(b.visit_no)}, ${KSI.fmtDate(b.recorded_at)}${b.recorded_by ? ` by ${KSI.esc(b.recorded_by)}` : ''}`;
  note.style.display = '';
  // No eye yet: say what Optometry has, so it is clear choosing the eye fills it in.
  if (!eye) {
    const recorded = [['right', 'Right'], ['left', 'Left']].filter(([e]) => data[e]).map(([e, label]) => `${label} eye (${KSI.fmtDate(data[e].recorded_at)})`);
    note.innerHTML = recorded.length
      ? `<div><strong>Optometry has biometry for: ${recorded.join(', ')}.</strong> Choose the eye above to fill it in.</div>`
      : '<div>No biometry has been recorded in Optometry for this patient. Enter it here.</div>';
    return;
  }
  const summary = b => [b.axial_length != null && `AL ${b.axial_length}`, b.steep_k != null && `K1 ${b.steep_k}`, b.flat_k != null && `K2 ${b.flat_k}`,
    b.iol_model && `IOL ${KSI.esc(b.iol_model)}`, b.iol_power != null && `${b.iol_power} D`].filter(Boolean).join(' · ');
  if (eye === 'both') {
    thFillBiometry(null);
    note.innerHTML = `<div><strong>Both eyes:</strong> enter the figures for this operation. From Optometry —
      Right: ${data.right ? `${summary(data.right)} (${source(data.right)})` : 'none recorded'};
      Left: ${data.left ? `${summary(data.left)} (${source(data.left)})` : 'none recorded'}.</div>`;
    return;
  }
  const found = data[eye];
  thFillBiometry(found);
  note.innerHTML = found
    ? `<div><strong>Filled from ${source(found)}.</strong> Check the figures and change them if needed — the Optometry record stays as it is.</div>`
    : `<div>No biometry has been recorded in Optometry for the ${eye} eye. Enter it here.</div>`;
}

function thBiometryPayload() {
  if (!TH.biometryNeeded) return null;
  const value = id => document.getElementById(id)?.value || '';
  return {
    iol_model: value('th-bio-model'), iol_power: value('th-bio-power'), axial_length: value('th-bio-axial'),
    a_constant: value('th-bio-constant'), steep_k: value('th-bio-steep'), flat_k: value('th-bio-flat'),
    acd: value('th-bio-acd'), corneal_thickness: value('th-bio-cct'), lens_thickness: value('th-bio-lt'),
    white_to_white: value('th-bio-wtw'), remarks: value('th-bio-remarks'),
  };
}

async function thSaveBooking(button) {
  const modal = document.getElementById('modal-theatre-booking');
  KSI.clearFieldErrors(modal);
  if (!TH.editing && !TH.patient) {
    KSI.showFieldErrors(modal, { patient_id: 'Choose a patient.' });
    return;
  }
  const payload = {
    surgery_item_id: document.getElementById('th-surgery').value || null,
    surgeon_user_id: Number(document.getElementById('th-surgeon').value) || 0,
    theatre_room_id: document.getElementById('th-room').value || null,
    eye: uiRadioValue('th-eye'),
    timing: uiRadioValue('th-timing'),
    scheduled_date: document.getElementById('th-sched-date').value,
    scheduled_time: document.getElementById('th-sched-time').value,
    procedures: TH.procedures.map(p => ({ price_item_id: p.price_item_id, quantity: p.quantity })),
    biometry: thBiometryPayload(),
  };
  await admBusy(button, async () => {
    try {
      if (TH.editing) {
        await KSI.put(`theatre/bookings/${TH.editing.id}`, { ...payload, row_version: TH.editing.row_version });
        showToast('Booking updated', `Changes saved for ${TH.editing.booking_no}`, 'success');
      } else {
        const { booking } = await KSI.post('theatre/bookings', {
          ...payload, patient_id: TH.patient.id, recommendation_id: TH.recommendationId || null, visit_id: TH.visitId || null,
        });
        showToast('Case booked', `${booking.booking_no} — ${booking.patient.full_name}`, 'success');
      }
      closeModal('modal-theatre-booking');
      await thRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not save the booking');
    }
  });
}

async function thRaiseBill(id, button) {
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`theatre/bookings/${id}/bill`, {});
      showToast('Bill raised', `${booking.booking_no} — ${booking.bill.bill_no} sent to the cashier.`, 'success');
      await thRefreshLists();
    } catch (error) {
      KSI.reportError(error, null, 'Could not raise the bill');
    }
  });
}

// ─── Emergency, cancellation ──────────────────────────────────────────────

async function thOpenDefer(id) {
  const { booking } = await KSI.get(`theatre/bookings/${id}`);
  TH.current = booking;
  const modal = document.getElementById('modal-theatre-defer');
  KSI.clearFieldErrors(modal);
  document.getElementById('th-defer-info').innerHTML = `
    <div><strong>${KSI.esc(booking.patient.full_name)}</strong> · ${KSI.esc(booking.booking_no)}</div>
    <div class="muted-note">${KSI.esc(booking.surgery?.name || 'Procedure')} · ${KSI.esc(thEye(booking.eye))} · ${thMoney(booking.total_amount)}</div>`;
  document.getElementById('th-defer-reason').value = '';
  openModal('modal-theatre-defer');
}

async function thSubmitDefer(button) {
  const modal = document.getElementById('modal-theatre-defer');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`theatre/bookings/${TH.current.id}/defer`, {
        reason: document.getElementById('th-defer-reason').value,
      });
      closeModal('modal-theatre-defer');
      showToast('Allowed through', `${booking.booking_no} may go ahead; the bill still stands.`, 'info');
      await thRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not allow the case through');
    }
  });
}

async function thOpenCancel(id) {
  const { booking } = await KSI.get(`theatre/bookings/${id}`);
  TH.current = booking;
  const modal = document.getElementById('modal-theatre-cancel');
  KSI.clearFieldErrors(modal);
  document.getElementById('th-cancel-info').innerHTML = `
    <div><strong>${KSI.esc(booking.patient.full_name)}</strong> · ${KSI.esc(booking.booking_no)}</div>
    <div class="muted-note">${KSI.esc(booking.surgery?.name || 'Procedure')} · ${KSI.fmtDateTime(booking.scheduled_for)}</div>`;
  document.getElementById('th-cancel-reason').value = '';
  openModal('modal-theatre-cancel');
}

async function thSubmitCancel(button) {
  const modal = document.getElementById('modal-theatre-cancel');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`theatre/bookings/${TH.current.id}/cancel`, {
        reason: document.getElementById('th-cancel-reason').value,
      });
      closeModal('modal-theatre-cancel');
      showToast('Sent for approval', `${booking.booking_no} will be cancelled once an administrator agrees.`, 'info');
      await thRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not request the cancellation');
    }
  });
}

// ─── Pre-op ───────────────────────────────────────────────────────────────

async function thOpenPreop() {
  await thOptions();
  await thLoadPreop();
}

async function thLoadPreop() {
  const queue = document.getElementById('th-preop-queue');
  if (!queue) return;
  try {
    const { bookings } = await KSI.get('theatre/bookings', { scope: 'preop', ...thFilters('th-preop') }, { trackActivity: false });
    TH.preop = bookings;
    thRenderQueue('th-preop-queue', 'th-preop-count', bookings, thSelectPreop, b => uiBadge('theatreStatus', b.status));
    if (TH.current && bookings.some(b => b.id === TH.current.id)) thRenderPreopPanel();
    else if (!bookings.length) document.getElementById('th-preop-panel').innerHTML = thEmptyPanel('No cases are waiting for clearance.');
  } catch (error) {
    queue.innerHTML = '<div class="muted-note" style="padding:20px">Could not load the queue.</div>';
  }
}

function thRenderQueue(queueId, countId, bookings, onSelect, badge) {
  const container = document.getElementById(queueId);
  const count = document.getElementById(countId);
  if (count) count.textContent = `${bookings.length} case${bookings.length === 1 ? '' : 's'}`;
  if (!container) return;
  window[onSelect.name] = onSelect;
  container.innerHTML = bookings.length
    ? bookings.map(b => `<div class="diag-report-queue-item ${TH.current?.id === b.id ? 'selected' : ''}" onclick="${onSelect.name}(${b.id})">
        <div class="diag-report-queue-patient">${KSI.esc(b.patient.full_name)}${uiFastTrackTag(b)}</div>
        <div class="diag-report-queue-proc">${KSI.esc(b.surgery?.name || 'Procedure')} — ${KSI.esc(thEye(b.eye))}</div>
        <div class="diag-report-queue-meta">${badge(b)}<span>${KSI.fmtDateTime(b.scheduled_for)}</span></div>
        ${b.may_operate ? '' : '<div class="muted-note">Bill not settled</div>'}
      </div>`).join('')
    : '<div class="muted-note" style="padding:20px;text-align:center">Nothing here at the moment.</div>';
}

function thEmptyPanel(message) {
  return `<div class="cl-queue-empty"><div style="font-size:13px;font-weight:600">${KSI.esc(message)}</div></div>`;
}

async function thSelectPreop(id) {
  const { booking } = await KSI.get(`theatre/bookings/${id}`);
  TH.current = booking;
  thRenderQueue('th-preop-queue', 'th-preop-count', TH.preop, thSelectPreop, b => uiBadge('theatreStatus', b.status));
  thRenderPreopPanel();
}

function thRenderPreopPanel() {
  const panel = document.getElementById('th-preop-panel');
  if (!panel) return;
  const b = TH.current;
  const p = b.preop || {};
  const locked = p.status === 'cleared' && !p.correctable_until;
  const value = (v) => v ?? '';
  panel.innerHTML = `
    <div class="cl-mode">
      <div>
        <div class="cl-head-title">Pre-op assessment</div>
        <div class="muted-note">${KSI.esc(b.booking_no)} · ${KSI.esc(b.surgery?.name || 'Procedure')} · ${KSI.esc(thEye(b.eye))}</div>
      </div>
      ${uiBadge('theatreStatus', b.status)}
    </div>
    ${uiPatientCard(b.patient)}
    ${b.may_operate ? '' : `<div class="alert alert-warning">The bill is not settled. Take payment, or allow the case through as an emergency on the booking screen.</div>`}
    ${p.status === 'on_hold' ? `<div class="alert alert-warning">On hold: ${KSI.esc(p.hold_reason || '')}</div>` : ''}
    ${locked ? '<div class="cl-lock-note">This assessment can no longer be changed. Add an addendum instead.</div>' : ''}

    <div class="form-grid">
      <div class="form-group"><label class="form-label">Vision right</label><input class="form-input" id="th-pre-va-r" maxlength="8" value="${KSI.esc(value(p.va_right))}" ${locked ? 'disabled' : ''}></div>
      <div class="form-group"><label class="form-label">Vision left</label><input class="form-input" id="th-pre-va-l" maxlength="8" value="${KSI.esc(value(p.va_left))}" ${locked ? 'disabled' : ''}></div>
    </div>
    <div class="form-grid">
      <div class="form-group"><label class="form-label">Pressure right (mmHg)</label><input type="number" class="form-input" id="th-pre-iop-r" value="${value(p.iop_right)}" ${locked ? 'disabled' : ''}></div>
      <div class="form-group"><label class="form-label">Pressure left (mmHg)</label><input type="number" class="form-input" id="th-pre-iop-l" value="${value(p.iop_left)}" ${locked ? 'disabled' : ''}></div>
    </div>
    <div class="form-grid">
      <div class="form-group"><label class="form-label">Blood pressure</label>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="number" class="form-input" id="th-pre-bp-s" placeholder="120" value="${value(p.bp_systolic)}" ${locked ? 'disabled' : ''}>
          <span>/</span>
          <input type="number" class="form-input" id="th-pre-bp-d" placeholder="80" value="${value(p.bp_diastolic)}" ${locked ? 'disabled' : ''}>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Blood sugar (mmol/L)</label><input type="number" step="0.1" class="form-input" id="th-pre-sugar" value="${value(p.blood_sugar)}" ${locked ? 'disabled' : ''}></div>
    </div>
    <div class="form-grid">
      <div class="form-group" data-field="fasting_since"><label class="form-label">Fasting since</label>
        <input type="datetime-local" class="form-input" id="th-pre-fasting" value="${p.fasting_since ? new Date(p.fasting_since).toISOString().slice(0, 16) : ''}" ${locked ? 'disabled' : ''}></div>
      <div class="form-group" data-field="anaesthesia_type"><label class="form-label">Anaesthesia <span class="required">*</span></label>
        <select class="form-select" id="th-pre-anaes" ${locked ? 'disabled' : ''}>
          <option value="">Not decided</option>
          <option value="local" ${p.anaesthesia_type === 'local' ? 'selected' : ''}>Local</option>
          <option value="general" ${p.anaesthesia_type === 'general' ? 'selected' : ''}>General</option>
        </select></div>
    </div>
    <div class="form-group"><label class="form-label">Findings</label>
      <textarea class="form-input" rows="2" id="th-pre-findings" ${locked ? 'disabled' : ''}>${KSI.esc(value(p.findings))}</textarea></div>
    <div class="form-group"><label class="form-label">Allergies</label>
      <input class="form-input" id="th-pre-allergies" maxlength="200" value="${KSI.esc(value(p.allergies))}" placeholder="None known" ${locked ? 'disabled' : ''}></div>

    <div class="form-label" style="margin-top:12px">Before the patient goes through</div>
    <div class="check-row" data-field="checks.site_marked">
      ${[['identity', 'Patient identity confirmed'], ['procedure', 'Procedure and eye confirmed'], ['site_marked', 'Site marked'],
         ['consent_signed', 'Consent form signed'], ['allergies', 'Allergies checked']]
        .map(([key, label]) => `<label class="finding-cb">
          <input type="checkbox" id="th-pre-check-${key}" ${p.checks?.[key] ? 'checked' : ''} ${locked ? 'disabled' : ''}>
          <span class="finding-cb-label">${label}</span></label>`).join('')}
    </div>
    <div class="form-group" data-field="consent_given" style="margin-top:8px">
      <label class="finding-cb"><input type="checkbox" id="th-pre-consent" ${p.consent_given ? 'checked' : ''} ${locked ? 'disabled' : ''}>
        <span class="finding-cb-label">The patient has consented to this operation</span></label>
    </div>
    <div class="form-group"><label class="form-label">Note for the surgeon</label>
      <textarea class="form-input" rows="2" id="th-pre-note" ${locked ? 'disabled' : ''}>${KSI.esc(value(p.surgeon_note))}</textarea></div>

    ${p.addenda?.length ? `<div class="cl-addenda"><div class="form-label">Addenda</div>
      ${p.addenda.map(a => `<div class="cl-addendum"><div class="cl-addendum-head">${KSI.esc(a.reason)} — ${KSI.esc(a.created_by)} · ${KSI.fmtDateTime(a.created_at)}</div><div>${KSI.esc(a.note)}</div></div>`).join('')}</div>` : ''}

    <div class="modal-footer" style="padding:16px 0 0">
      ${locked && KSI.can('theatre.preop.record')
        ? `<button class="btn btn-secondary" onclick="thOpenAddendum('theatre_preop', ${p.id})">Add addendum</button>` : ''}
      ${!locked && KSI.can('theatre.preop.record') ? `
        <button class="btn btn-secondary" onclick="thOpenHold('on_hold')">Put on hold</button>
        <button class="btn btn-danger" onclick="thOpenHold('cancelled')">Stop the case</button>
        <button class="btn btn-secondary" onclick="thSavePreop(this, false)">Save</button>
        <button class="btn btn-primary" onclick="thSavePreop(this, true)">Clear for theatre</button>` : ''}
    </div>`;
}

function thPreopPayload() {
  const value = id => document.getElementById(id)?.value || '';
  const checked = id => !!document.getElementById(id)?.checked;
  return {
    va_right: value('th-pre-va-r'), va_left: value('th-pre-va-l'),
    iop_right: value('th-pre-iop-r') || null, iop_left: value('th-pre-iop-l') || null,
    bp_systolic: value('th-pre-bp-s') || null, bp_diastolic: value('th-pre-bp-d') || null,
    blood_sugar: value('th-pre-sugar') || null,
    fasting_since: value('th-pre-fasting'),
    anaesthesia_type: value('th-pre-anaes') || null,
    findings: value('th-pre-findings'), allergies: value('th-pre-allergies'),
    consent_given: checked('th-pre-consent'),
    surgeon_note: value('th-pre-note'),
    checks: {
      identity: checked('th-pre-check-identity'),
      procedure: checked('th-pre-check-procedure'),
      site_marked: checked('th-pre-check-site_marked'),
      consent_signed: checked('th-pre-check-consent_signed'),
      allergies: checked('th-pre-check-allergies'),
    },
    row_version: TH.current?.preop?.row_version || 0,
  };
}

async function thSavePreop(button, clear) {
  const panel = document.getElementById('th-preop-panel');
  KSI.clearFieldErrors(panel);
  await admBusy(button, async () => {
    try {
      const result = await KSI.put(`theatre/bookings/${TH.current.id}/preop`, { ...thPreopPayload(), clear });
      TH.current = result.booking;
      showToast(clear ? 'Cleared for theatre' : 'Saved', `${result.booking.booking_no}`, 'success');
      await thLoadPreop();
      thRenderPreopPanel();
    } catch (error) {
      KSI.reportError(error, panel, clear ? 'Could not clear this case' : 'Could not save the assessment');
    }
  });
}

function thOpenHold(status) {
  TH.holdStatus = status;
  const modal = document.getElementById('modal-theatre-hold');
  KSI.clearFieldErrors(modal);
  modal.querySelector('.modal-title').textContent = status === 'on_hold' ? 'Put the case on hold' : 'Stop the case';
  document.getElementById('th-hold-save').textContent = status === 'on_hold' ? 'Put on hold' : 'Stop the case';
  document.getElementById('th-hold-reason').value = '';
  openModal('modal-theatre-hold');
}

async function thSubmitHold(button) {
  const modal = document.getElementById('modal-theatre-hold');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const result = await KSI.post(`theatre/bookings/${TH.current.id}/preop/hold`, {
        status: TH.holdStatus, reason: document.getElementById('th-hold-reason').value,
      });
      TH.current = result.booking;
      closeModal('modal-theatre-hold');
      showToast(TH.holdStatus === 'on_hold' ? 'On hold' : 'Stopped', result.booking.booking_no, 'info');
      await thLoadPreop();
      thRenderPreopPanel();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not update the case');
    }
  });
}

// ─── In theatre ───────────────────────────────────────────────────────────

async function thOpenOperations() {
  await thOptions();
  await thLoadOperations();
}

async function thLoadOperations() {
  const queue = document.getElementById('th-op-queue');
  if (!queue) return;
  try {
    const { bookings } = await KSI.get('theatre/bookings', { scope: 'theatre', ...thFilters('th-op') }, { trackActivity: false });
    TH.operations = bookings;
    thRenderQueue('th-op-queue', 'th-op-count', bookings, thSelectOperation, b => uiBadge('theatreStatus', b.status));
    if (TH.current && bookings.some(b => b.id === TH.current.id)) thRenderOperationPanel();
    else if (!bookings.length) document.getElementById('th-op-panel').innerHTML = thEmptyPanel('No cases have been cleared for theatre.');
  } catch (error) {
    queue.innerHTML = '<div class="muted-note" style="padding:20px">Could not load the queue.</div>';
  }
}

async function thSelectOperation(id) {
  const { booking } = await KSI.get(`theatre/bookings/${id}`);
  TH.current = booking;
  TH.meds = (booking.operation?.prescription_no) ? [] : TH.meds;
  thRenderQueue('th-op-queue', 'th-op-count', TH.operations, thSelectOperation, b => uiBadge('theatreStatus', b.status));
  thRenderOperationPanel();
}

function thRenderOperationPanel() {
  const panel = document.getElementById('th-op-panel');
  if (!panel) return;
  const b = TH.current;
  const o = b.operation || {};
  const locked = o.status === 'completed' && !o.correctable_until;
  const teamRole = role => (o.team || []).find(t => t.role === role)?.user_id || '';
  const staffOptions = (list, selected) => `<option value="">Not recorded</option>` +
    list.map(s => `<option value="${s.id}" ${Number(selected) === s.id ? 'selected' : ''}>${KSI.esc(s.name)}</option>`).join('');
  const localDateTime = iso => (iso ? new Date(iso).toISOString().slice(0, 16) : '');

  panel.innerHTML = `
    <div class="cl-mode">
      <div>
        <div class="cl-head-title">Operation record</div>
        <div class="muted-note">${KSI.esc(b.booking_no)} · ${KSI.esc(b.surgery?.name || 'Procedure')} · ${KSI.esc(thEye(b.eye))}</div>
      </div>
      ${uiBadge('theatreStatus', b.status)}
    </div>
    ${uiPatientCard(b.patient)}
    ${b.biometry ? `<div class="diag-report-facts">
      <div><span class="pcd-label">IOL</span><span class="pcd-value">${KSI.esc(b.biometry.iol_model || '—')} ${b.biometry.iol_power ?? ''}</span></div>
      <div><span class="pcd-label">Axial length</span><span class="pcd-value">${b.biometry.axial_length ?? '—'} mm</span></div>
      <div><span class="pcd-label">Steep K</span><span class="pcd-value">${b.biometry.steep_k ?? '—'}</span></div>
      <div><span class="pcd-label">Flat K</span><span class="pcd-value">${b.biometry.flat_k ?? '—'}</span></div>
    </div>` : ''}
    ${b.preop?.anaesthesia_type ? `<div class="muted-note">Pre-op planned ${b.preop.anaesthesia_type === 'local' ? 'local' : 'general'} anaesthesia.</div>` : ''}
    ${locked ? '<div class="cl-lock-note">This record can no longer be changed. Add an addendum instead.</div>' : ''}

    <div class="form-label" style="margin-top:12px">Who was in theatre</div>
    <div class="form-grid">
      <div class="form-group"><label class="form-label">Surgeon <span class="required">*</span></label>
        <select class="form-select" id="th-op-surgeon" ${locked ? 'disabled' : ''}>${staffOptions(TH.options.surgeons, teamRole('surgeon') || b.surgeon.id)}</select></div>
      <div class="form-group"><label class="form-label">Assistant</label>
        <select class="form-select" id="th-op-assistant" ${locked ? 'disabled' : ''}>${staffOptions(TH.options.surgeons, teamRole('assistant'))}</select></div>
    </div>
    <div class="form-grid">
      <div class="form-group"><label class="form-label">Scrub nurse</label>
        <select class="form-select" id="th-op-scrub" ${locked ? 'disabled' : ''}>${staffOptions(TH.options.nurses, teamRole('scrub_nurse'))}</select></div>
      <div class="form-group"><label class="form-label">Anaesthesia nurse</label>
        <select class="form-select" id="th-op-anaes-nurse" ${locked ? 'disabled' : ''}>${staffOptions(TH.options.anaesthetists, teamRole('anaesthesia_nurse'))}</select></div>
    </div>
    <div class="form-grid">
      <div class="form-group"><label class="form-label">Runner</label>
        <select class="form-select" id="th-op-runner" ${locked ? 'disabled' : ''}>${staffOptions(TH.options.nurses, teamRole('runner'))}</select></div>
      <div class="form-group" data-field="anaesthesia_type"><label class="form-label">Anaesthesia used <span class="required">*</span></label>
        <select class="form-select" id="th-op-anaes" ${locked ? 'disabled' : ''}>
          <option value="">Not recorded</option>
          <option value="local" ${o.anaesthesia_type === 'local' ? 'selected' : ''}>Local</option>
          <option value="general" ${o.anaesthesia_type === 'general' ? 'selected' : ''}>General</option>
        </select></div>
    </div>

    <div class="form-grid">
      <div class="form-group" data-field="started_at"><label class="form-label">Surgery started <span class="required">*</span></label>
        <input type="datetime-local" class="form-input" id="th-op-start" value="${localDateTime(o.started_at)}" ${locked ? 'disabled' : ''}>
        ${locked ? '' : '<button class="link-btn" style="margin-top:4px" onclick="thStampTime(\'th-op-start\')">Set to now</button>'}</div>
      <div class="form-group" data-field="ended_at"><label class="form-label">Surgery finished <span class="required">*</span></label>
        <input type="datetime-local" class="form-input" id="th-op-end" value="${localDateTime(o.ended_at)}" ${locked ? 'disabled' : ''}>
        ${locked ? '' : '<button class="link-btn" style="margin-top:4px" onclick="thStampTime(\'th-op-end\')">Set to now</button>'}</div>
    </div>

    <div class="form-group" data-field="completed_as_planned">
      <label class="form-label">Did it go as planned? <span class="required">*</span></label>
      <div class="radio-group">
        <label class="radio-card"><input type="radio" name="th-op-planned" value="yes" ${o.completed_as_planned === true ? 'checked' : ''} onchange="thPlannedChanged()" ${locked ? 'disabled' : ''}><div><div class="radio-card-label">Yes</div></div></label>
        <label class="radio-card"><input type="radio" name="th-op-planned" value="no" ${o.completed_as_planned === false ? 'checked' : ''} onchange="thPlannedChanged()" ${locked ? 'disabled' : ''}><div><div class="radio-card-label">No</div></div></label>
      </div>
    </div>
    <div id="th-op-change-block" style="display:${o.completed_as_planned === false ? '' : 'none'}">
      <div class="form-group" data-field="actual_procedure"><label class="form-label">What was actually done <span class="required">*</span></label>
        <textarea class="form-input" rows="2" id="th-op-actual" ${locked ? 'disabled' : ''}>${KSI.esc(o.actual_procedure || '')}</textarea></div>
      <div class="form-group" data-field="change_reason"><label class="form-label">Why the plan changed <span class="required">*</span></label>
        <textarea class="form-input" rows="2" id="th-op-change" ${locked ? 'disabled' : ''}>${KSI.esc(o.change_reason || '')}</textarea></div>
    </div>

    <div class="form-group" data-field="surgical_notes"><label class="form-label">Operation notes <span class="required">*</span></label>
      <textarea class="form-input" rows="5" id="th-op-notes" ${locked ? 'disabled' : ''}>${KSI.esc(o.surgical_notes || '')}</textarea></div>
    <div class="form-group" data-field="complications"><label class="form-label">Complications <span class="required">*</span></label>
      <textarea class="form-input" rows="2" id="th-op-complications" placeholder="Write 'None' if there were none" ${locked ? 'disabled' : ''}>${KSI.esc(o.complications || '')}</textarea></div>
    <div class="form-group"><label class="form-label">Remarks</label>
      <textarea class="form-input" rows="2" id="th-op-remarks" ${locked ? 'disabled' : ''}>${KSI.esc(o.remarks || '')}</textarea></div>

    ${o.prescription_no
      ? `<div class="cl-note">Medicine prescribed in theatre: ${KSI.esc(o.prescription_no)} — the pharmacy has it.</div>`
      : (locked ? '' : thMedicineEditor('op'))}

    ${o.addenda?.length ? `<div class="cl-addenda"><div class="form-label">Addenda</div>
      ${o.addenda.map(a => `<div class="cl-addendum"><div class="cl-addendum-head">${KSI.esc(a.reason)} — ${KSI.esc(a.created_by)} · ${KSI.fmtDateTime(a.created_at)}</div><div>${KSI.esc(a.note)}</div></div>`).join('')}</div>` : ''}

    <div class="modal-footer" style="padding:16px 0 0">
      ${locked && KSI.can('theatre.intraop.record')
        ? `<button class="btn btn-secondary" onclick="thOpenAddendum('theatre_operation', ${o.id})">Add addendum</button>` : ''}
      ${!locked && KSI.can('theatre.intraop.record') ? `
        <button class="btn btn-secondary" onclick="thSaveOperation(this, false)">Save</button>
        <button class="btn btn-primary" onclick="thSaveOperation(this, true)">${o.status === 'completed' ? 'Save' : 'Complete and send to post-op'}</button>` : ''}
    </div>`;
  thRenderMeds('op');
}

function thStampTime(id) {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  document.getElementById(id).value = now.toISOString().slice(0, 16);
}

function thPlannedChanged() {
  const planned = uiRadioValue('th-op-planned');
  document.getElementById('th-op-change-block').style.display = planned === 'no' ? '' : 'none';
}

async function thSaveOperation(button, complete) {
  const panel = document.getElementById('th-op-panel');
  KSI.clearFieldErrors(panel);
  const value = id => document.getElementById(id)?.value || '';
  const team = [
    ['th-op-surgeon', 'surgeon'], ['th-op-assistant', 'assistant'], ['th-op-scrub', 'scrub_nurse'],
    ['th-op-anaes-nurse', 'anaesthesia_nurse'], ['th-op-runner', 'runner'],
  ].filter(([id]) => value(id)).map(([id, role]) => ({ user_id: Number(value(id)), role }));
  const planned = uiRadioValue('th-op-planned');
  await admBusy(button, async () => {
    try {
      const result = await KSI.put(`theatre/bookings/${TH.current.id}/operation`, {
        anaesthesia_type: value('th-op-anaes') || null,
        started_at: value('th-op-start'), ended_at: value('th-op-end'),
        completed_as_planned: planned === '' ? null : planned === 'yes',
        actual_procedure: value('th-op-actual'), change_reason: value('th-op-change'),
        surgical_notes: value('th-op-notes'), complications: value('th-op-complications'),
        remarks: value('th-op-remarks'),
        team,
        medicines: TH.meds.map(m => ({ drug_id: m.drug_id, quantity: m.quantity, dose: m.dose, frequency: m.frequency, duration: m.duration })),
        pharmacy_instructions: value('th-op-pharm-instructions'),
        complete,
        row_version: TH.current.operation?.row_version || 0,
      });
      TH.current = result.booking;
      TH.meds = [];
      showToast(complete ? 'Surgery recorded' : 'Saved', result.booking.booking_no, 'success');
      await thLoadOperations();
      thRenderOperationPanel();
    } catch (error) {
      KSI.reportError(error, panel, 'Could not save the operation record');
    }
  });
}

// ─── Medicine editor (shared by the operation and post-op) ────────────────

function thMedicineEditor(prefix) {
  return `
    <div class="form-label" style="margin-top:12px">Medicine to send to the pharmacy</div>
    <select class="form-select" id="th-${prefix}-drug" onchange="thAddMed(this.value)"></select>
    <div class="table-wrap" style="margin-top:8px">
      <table>
        <thead><tr><th>Medicine</th><th>How many</th><th>Dose</th><th>How often</th><th>For how long</th><th></th></tr></thead>
        <tbody id="th-${prefix}-meds"></tbody>
      </table>
    </div>
    <div class="form-group" style="margin-top:8px"><label class="form-label">Note for the pharmacy</label>
      <input class="form-input" id="th-${prefix}-pharm-instructions" maxlength="200"></div>`;
}

async function thRenderMeds(prefix) {
  const body = document.getElementById(`th-${prefix}-meds`);
  if (!body) return;
  if (!TH.drugs) {
    try { TH.drugs = (await KSI.get('pharmacy/options', {}, { trackActivity: false })).drugs; } catch { TH.drugs = []; }
  }
  const chosen = TH.meds.map(m => m.drug_id);
  admFillSelect(`th-${prefix}-drug`, 'Add a medicine', TH.drugs.filter(d => !chosen.includes(d.id)).map(d => [d.id, d.name]));
  body.innerHTML = TH.meds.length
    ? TH.meds.map((m, index) => `<tr>
        <td>${KSI.esc(m.name)}</td>
        <td><input type="number" class="form-input" min="1" value="${m.quantity}" style="width:70px" onchange="thSetMed(${index}, 'quantity', this.value)"></td>
        <td><input class="form-input" value="${KSI.esc(m.dose || '')}" style="width:90px" onchange="thSetMed(${index}, 'dose', this.value)"></td>
        <td><input class="form-input" value="${KSI.esc(m.frequency || '')}" style="width:90px" onchange="thSetMed(${index}, 'frequency', this.value)"></td>
        <td><input class="form-input" value="${KSI.esc(m.duration || '')}" style="width:100px" onchange="thSetMed(${index}, 'duration', this.value)"></td>
        <td><button class="action-btn action-cancel" onclick="thRemoveMed(${index})">Remove</button></td>
      </tr>`).join('')
    : '<tr class="table-state"><td colspan="6">No medicine added.</td></tr>';
}

function thAddMed(drugId) {
  const drug = (TH.drugs || []).find(d => d.id === Number(drugId));
  if (!drug) return;
  TH.meds.push({ drug_id: drug.id, name: drug.name, quantity: 1, dose: '1 drop', frequency: 'QID', duration: '2 weeks' });
  thRenderMeds(document.getElementById('th-op-meds') ? 'op' : 'pop');
}

function thSetMed(index, field, value) {
  if (!TH.meds[index]) return;
  TH.meds[index][field] = field === 'quantity' ? Math.max(1, Number(value) || 1) : value;
}

function thRemoveMed(index) {
  TH.meds.splice(index, 1);
  thRenderMeds(document.getElementById('th-op-meds') ? 'op' : 'pop');
}

// ─── Post-op ──────────────────────────────────────────────────────────────

async function thOpenPostop() {
  await thOptions();
  await thLoadPostop();
}

async function thLoadPostop() {
  const queue = document.getElementById('th-postop-queue');
  if (!queue) return;
  try {
    const { bookings } = await KSI.get('theatre/bookings', { scope: 'postop', ...thFilters('th-postop') }, { trackActivity: false });
    TH.postop = bookings;
    thRenderQueue('th-postop-queue', 'th-postop-count', bookings, thSelectPostop, b => `<span class="badge badge-normal">${b.postop_visit_count} review${b.postop_visit_count === 1 ? '' : 's'}</span>`);
    if (TH.current && bookings.some(b => b.id === TH.current.id)) thRenderPostopPanel();
    else if (!bookings.length) document.getElementById('th-postop-panel').innerHTML = thEmptyPanel('No patients are under post-op care.');
  } catch (error) {
    queue.innerHTML = '<div class="muted-note" style="padding:20px">Could not load the queue.</div>';
  }
}

async function thSelectPostop(id) {
  const { booking } = await KSI.get(`theatre/bookings/${id}`);
  TH.current = booking;
  TH.postopVisit = null;
  TH.meds = [];
  thRenderQueue('th-postop-queue', 'th-postop-count', TH.postop, thSelectPostop, b => `<span class="badge badge-normal">${b.postop_visit_count} review${b.postop_visit_count === 1 ? '' : 's'}</span>`);
  thRenderPostopPanel();
}

function thRenderPostopPanel() {
  const panel = document.getElementById('th-postop-panel');
  if (!panel) return;
  const b = TH.current;
  const visits = b.postop_visits || [];
  const editing = TH.postopVisit;
  const locked = editing && editing.status === 'completed' && !editing.correctable_until;
  const value = v => v ?? '';

  panel.innerHTML = `
    <div class="cl-mode">
      <div>
        <div class="cl-head-title">Post-op care</div>
        <div class="muted-note">${KSI.esc(b.booking_no)} · ${KSI.esc(b.surgery?.name || 'Procedure')} · operated ${KSI.fmtDateTime(b.operation?.ended_at || b.started_at)}</div>
      </div>
      ${uiBadge('theatreStatus', b.status)}
    </div>
    ${uiPatientCard(b.patient)}

    <div class="form-label" style="margin-top:10px">Reviews so far</div>
    ${visits.length ? `<div class="cl-file-list">${visits.map(v => `<div class="cl-file">
      <div>
        <div><strong>Review ${v.visit_number}</strong> · ${KSI.esc(v.visit_date)} ${v.status === 'draft' ? '<span class="muted-note">(draft)</span>' : ''}</div>
        <div class="muted-note">${[v.va_right ? `VA R ${KSI.esc(v.va_right)}` : '', v.iop_right ? `IOP R ${v.iop_right}` : '', v.pain_score !== null ? `pain ${v.pain_score}/10` : ''].filter(Boolean).join(' · ')}</div>
      </div>
      <button class="link-btn" onclick="thEditPostopVisit(${v.id})">Open</button>
    </div>`).join('')}</div>` : '<div class="muted-note">No reviews recorded yet.</div>'}

    ${KSI.can('theatre.postop.record') ? `
      <div class="form-label" style="margin-top:14px">${editing ? `Review ${editing.visit_number}` : 'New review'}</div>
      ${locked ? '<div class="cl-lock-note">This review can no longer be changed. Add an addendum instead.</div>' : ''}
      <div class="form-grid">
        <div class="form-group" data-field="visit_date"><label class="form-label">Date <span class="required">*</span></label>
          <input type="date" class="form-input" id="th-pop-date" value="${value(editing?.visit_date) || uiToday()}" ${locked ? 'disabled' : ''}></div>
        <div class="form-group"><label class="form-label">Pain (0–10)</label>
          <input type="number" min="0" max="10" class="form-input" id="th-pop-pain" value="${value(editing?.pain_score)}" ${locked ? 'disabled' : ''}></div>
      </div>
      <div class="form-group"><label class="form-label">How the patient is</label>
        <textarea class="form-input" rows="2" id="th-pop-recovery" ${locked ? 'disabled' : ''}>${KSI.esc(value(editing?.recovery_notes))}</textarea></div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Vision right</label><input class="form-input" id="th-pop-va-r" maxlength="8" value="${KSI.esc(value(editing?.va_right))}" ${locked ? 'disabled' : ''}></div>
        <div class="form-group"><label class="form-label">Vision left</label><input class="form-input" id="th-pop-va-l" maxlength="8" value="${KSI.esc(value(editing?.va_left))}" ${locked ? 'disabled' : ''}></div>
      </div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Pressure right</label><input type="number" class="form-input" id="th-pop-iop-r" value="${value(editing?.iop_right)}" ${locked ? 'disabled' : ''}></div>
        <div class="form-group"><label class="form-label">Pressure left</label><input type="number" class="form-input" id="th-pop-iop-l" value="${value(editing?.iop_left)}" ${locked ? 'disabled' : ''}></div>
      </div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Wound</label>
          <select class="form-select" id="th-pop-wound" ${locked ? 'disabled' : ''}>
            ${['', 'clean', 'inflamed', 'leaking', 'other'].map(w => `<option value="${w}" ${editing?.wound_status === w ? 'selected' : ''}>${w === '' ? 'Not recorded' : w.charAt(0).toUpperCase() + w.slice(1)}</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">Counselling given</label>
          <select class="form-select" id="th-pop-counselling" ${locked ? 'disabled' : ''}>
            <option value="">Not recorded</option>
            ${(TH.options.counselling || []).map(c => `<option value="${KSI.esc(c)}" ${editing?.counselling_type === c ? 'selected' : ''}>${KSI.esc(c)}</option>`).join('')}
          </select></div>
      </div>
      <div class="form-group"><label class="form-label">Examination</label>
        <textarea class="form-input" rows="2" id="th-pop-exam" ${locked ? 'disabled' : ''}>${KSI.esc(value(editing?.eye_exam))}</textarea></div>
      <div class="form-group"><label class="form-label">Complications</label>
        <textarea class="form-input" rows="2" id="th-pop-complications" ${locked ? 'disabled' : ''}>${KSI.esc(value(editing?.complications))}</textarea></div>
      <div class="form-group"><label class="form-label">What the patient was told</label>
        <textarea class="form-input" rows="2" id="th-pop-counsel-notes" ${locked ? 'disabled' : ''}>${KSI.esc(value(editing?.counselling_notes))}</textarea></div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Next review</label>
          <input type="date" class="form-input" id="th-pop-followup" value="${value(editing?.follow_up_date)}" ${locked ? 'disabled' : ''}>
          <div class="muted-note">Reception sees this as a follow-up due.</div></div>
        <div class="form-group"><label class="form-label">Note about the review</label>
          <input class="form-input" id="th-pop-followup-notes" maxlength="200" value="${KSI.esc(value(editing?.follow_up_notes))}" ${locked ? 'disabled' : ''}></div>
      </div>
      ${editing?.prescription_no
        ? `<div class="cl-note">Medicine sent to the pharmacy: ${KSI.esc(editing.prescription_no)}</div>`
        : (locked ? '' : thMedicineEditor('pop'))}

      ${editing?.addenda?.length ? `<div class="cl-addenda"><div class="form-label">Addenda</div>
        ${editing.addenda.map(a => `<div class="cl-addendum"><div class="cl-addendum-head">${KSI.esc(a.reason)} — ${KSI.esc(a.created_by)} · ${KSI.fmtDateTime(a.created_at)}</div><div>${KSI.esc(a.note)}</div></div>`).join('')}</div>` : ''}

      <div class="modal-footer" style="padding:16px 0 0">
        ${editing ? `<button class="btn btn-secondary" onclick="thNewPostopVisit()">New review</button>` : ''}
        ${locked ? `<button class="btn btn-secondary" onclick="thOpenAddendum('theatre_postop', ${editing.id})">Add addendum</button>` : `
          <button class="btn btn-secondary" onclick="thSavePostop(this, false)">Save draft</button>
          <button class="btn btn-primary" onclick="thSavePostop(this, true)">Save review</button>`}
        ${visits.length ? `<button class="btn btn-danger" onclick="thOpenDischarge()">Discharge</button>` : ''}
      </div>` : ''}`;
  if (!editing?.prescription_no) thRenderMeds('pop');
}

function thEditPostopVisit(visitId) {
  TH.postopVisit = (TH.current.postop_visits || []).find(v => v.id === visitId) || null;
  TH.meds = [];
  thRenderPostopPanel();
}

function thNewPostopVisit() {
  TH.postopVisit = null;
  TH.meds = [];
  thRenderPostopPanel();
}

async function thSavePostop(button, complete) {
  const panel = document.getElementById('th-postop-panel');
  KSI.clearFieldErrors(panel);
  const value = id => document.getElementById(id)?.value || '';
  await admBusy(button, async () => {
    try {
      const result = await KSI.put(`theatre/bookings/${TH.current.id}/postop`, {
        visit_id: TH.postopVisit?.id || null,
        visit_date: value('th-pop-date'),
        pain_score: value('th-pop-pain') || null,
        recovery_notes: value('th-pop-recovery'),
        va_right: value('th-pop-va-r'), va_left: value('th-pop-va-l'),
        iop_right: value('th-pop-iop-r') || null, iop_left: value('th-pop-iop-l') || null,
        wound_status: value('th-pop-wound') || null,
        eye_exam: value('th-pop-exam'), complications: value('th-pop-complications'),
        counselling_type: value('th-pop-counselling'), counselling_notes: value('th-pop-counsel-notes'),
        follow_up_date: value('th-pop-followup') || null, follow_up_notes: value('th-pop-followup-notes'),
        medicines: TH.meds.map(m => ({ drug_id: m.drug_id, quantity: m.quantity, dose: m.dose, frequency: m.frequency, duration: m.duration })),
        pharmacy_instructions: value('th-pop-pharm-instructions'),
        complete,
        row_version: TH.postopVisit?.row_version || 0,
      });
      TH.current = result.booking;
      TH.postopVisit = result.visits[result.visits.length - 1];
      TH.meds = [];
      showToast('Review saved', `${result.booking.booking_no} — review ${TH.postopVisit.visit_number}`, 'success');
      await thLoadPostop();
      thRenderPostopPanel();
    } catch (error) {
      KSI.reportError(error, panel, 'Could not save the review');
    }
  });
}

function thOpenDischarge() {
  const modal = document.getElementById('modal-theatre-discharge');
  KSI.clearFieldErrors(modal);
  document.getElementById('th-discharge-info').innerHTML = `
    <div><strong>${KSI.esc(TH.current.patient.full_name)}</strong> · ${KSI.esc(TH.current.booking_no)}</div>
    <div class="muted-note">${TH.current.postop_visit_count} review${TH.current.postop_visit_count === 1 ? '' : 's'} recorded</div>`;
  document.getElementById('th-discharge-remarks').value = '';
  openModal('modal-theatre-discharge');
}

async function thSubmitDischarge(button) {
  const modal = document.getElementById('modal-theatre-discharge');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`theatre/bookings/${TH.current.id}/discharge`, {
        remarks: document.getElementById('th-discharge-remarks').value,
      });
      closeModal('modal-theatre-discharge');
      showToast('Discharged', `${booking.booking_no} is now in theatre history.`, 'success');
      TH.current = null;
      TH.postopVisit = null;
      await thLoadPostop();
      document.getElementById('th-postop-panel').innerHTML = thEmptyPanel('Choose a patient from the list.');
    } catch (error) {
      KSI.reportError(error, modal, 'Could not discharge the patient');
    }
  });
}

// ─── Addenda ──────────────────────────────────────────────────────────────

function thOpenAddendum(source, recordId) {
  TH.addendum = { source, record_id: recordId };
  const modal = document.getElementById('modal-theatre-addendum');
  KSI.clearFieldErrors(modal);
  document.getElementById('th-addendum-reason').value = '';
  document.getElementById('th-addendum-note').value = '';
  openModal('modal-theatre-addendum');
}

async function thSaveAddendum(button) {
  const modal = document.getElementById('modal-theatre-addendum');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { booking } = await KSI.post(`theatre/bookings/${TH.current.id}/addenda`, {
        ...TH.addendum,
        reason: document.getElementById('th-addendum-reason').value,
        note: document.getElementById('th-addendum-note').value,
      });
      TH.current = booking;
      closeModal('modal-theatre-addendum');
      showToast('Addendum added', 'The record now carries your note.', 'success');
      if (!document.getElementById('module-theatre-preop').classList.contains('hidden')) thRenderPreopPanel();
      if (!document.getElementById('module-theatre-intraop').classList.contains('hidden')) thRenderOperationPanel();
      if (!document.getElementById('module-theatre-postop').classList.contains('hidden')) thRenderPostopPanel();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not add the addendum');
    }
  });
}

// ─── History ──────────────────────────────────────────────────────────────

async function thOpenHistory() {
  await thOptions();
  thFillSurgeonFilters();
  await thLoadHistory();
}

async function thLoadHistory() {
  if (!document.getElementById('th-hist-tbody')) return;
  admTableMessage('th-hist-tbody', 9, 'Loading cases…');
  try {
    const { bookings } = await KSI.get('theatre/bookings', { scope: 'history', ...thFilters('th-hist') }, { trackActivity: false });
    TH.history = bookings;
    thRenderHistory();
  } catch (error) {
    admTableMessage('th-hist-tbody', 9, 'Could not load the history.');
  }
}

function thRenderHistory() {
  const count = document.getElementById('th-hist-count');
  if (count) count.textContent = `${TH.history.length} case${TH.history.length === 1 ? '' : 's'}`;
  if (!TH.history.length) {
    admTableMessage('th-hist-tbody', 9, 'No finished cases match your filters.');
    return;
  }
  document.getElementById('th-hist-tbody').innerHTML = TH.history.map(b => `<tr>
    <td><div style="font-weight:600">${KSI.esc(b.booking_no)}</div></td>
    <td>${thPatientCell(b)}</td>
    <td>${thSurgeryCell(b)}</td>
    <td>${KSI.esc(thEye(b.eye))}</td>
    <td>${KSI.esc(b.surgeon.name)}</td>
    <td>${b.started_at ? KSI.fmtDateTime(b.started_at) : KSI.fmtDate(b.scheduled_for)}</td>
    <td>${b.discharged_at ? KSI.fmtDateTime(b.discharged_at) : '—'}</td>
    <td>${uiBadge('theatreStatus', b.status)}</td>
    <td><div class="row-actions"><button class="action-btn action-view" onclick="thOpenCase(${b.id})">View record</button></div></td>
  </tr>`).join('');
}

async function thOpenCase(id) {
  const { booking } = await KSI.get(`theatre/bookings/${id}`);
  TH.current = booking;
  document.getElementById('th-case-title').textContent = `Case ${booking.booking_no}`;
  document.getElementById('th-case-subtitle').textContent = `${booking.patient.full_name} · ${booking.patient.reg_no}`;
  const o = booking.operation;
  const p = booking.preop;
  document.getElementById('th-case-body').innerHTML = `
    ${uiPatientCard(booking.patient)}
    <div class="diag-report-facts">
      <div><span class="pcd-label">Surgery</span><span class="pcd-value">${KSI.esc(booking.surgery?.name || 'Procedure only')}</span></div>
      <div><span class="pcd-label">Eye</span><span class="pcd-value">${KSI.esc(thEye(booking.eye))}</span></div>
      <div><span class="pcd-label">Surgeon</span><span class="pcd-value">${KSI.esc(booking.surgeon.name)}</span></div>
      <div><span class="pcd-label">Theatre</span><span class="pcd-value">${KSI.esc(booking.theatre_room || '—')}</span></div>
      <div><span class="pcd-label">Amount</span><span class="pcd-value">${thMoney(booking.total_amount)}</span></div>
      <div><span class="pcd-label">Bill</span><span class="pcd-value">${booking.bill ? `${KSI.esc(booking.bill.bill_no)} · ${KSI.esc(uiLabel('billStatus', booking.bill.status))}` : 'Not billed'}</span></div>
    </div>
    ${(booking.procedures || []).length ? `<div class="cl-note">Procedures: ${booking.procedures.map(x => `${KSI.esc(x.name)}${x.quantity > 1 ? ` ×${x.quantity}` : ''}`).join(', ')}</div>` : ''}
    ${booking.biometry ? `<div class="form-label" style="margin-top:12px">Lens and biometry</div>
      <div class="diag-report-facts">
        <div><span class="pcd-label">IOL</span><span class="pcd-value">${KSI.esc(booking.biometry.iol_model || '—')} ${booking.biometry.iol_power ?? ''}</span></div>
        <div><span class="pcd-label">Axial length</span><span class="pcd-value">${booking.biometry.axial_length ?? '—'} mm</span></div>
        <div><span class="pcd-label">Steep K</span><span class="pcd-value">${booking.biometry.steep_k ?? '—'}</span></div>
        <div><span class="pcd-label">Flat K</span><span class="pcd-value">${booking.biometry.flat_k ?? '—'}</span></div>
      </div>` : ''}

    ${p ? `<div class="form-label" style="margin-top:12px">Pre-op</div>
      <div class="diag-report-facts">
        <div><span class="pcd-label">Vision</span><span class="pcd-value">R ${KSI.esc(p.va_right || '—')} · L ${KSI.esc(p.va_left || '—')}</span></div>
        <div><span class="pcd-label">Pressure</span><span class="pcd-value">R ${p.iop_right ?? '—'} · L ${p.iop_left ?? '—'}</span></div>
        <div><span class="pcd-label">Blood pressure</span><span class="pcd-value">${p.bp_systolic ?? '—'}/${p.bp_diastolic ?? '—'}</span></div>
        <div><span class="pcd-label">Anaesthesia</span><span class="pcd-value">${KSI.esc(p.anaesthesia_type || '—')}</span></div>
        <div><span class="pcd-label">Cleared by</span><span class="pcd-value">${KSI.esc(p.cleared_by || '—')}</span></div>
      </div>` : ''}

    ${o ? `<div class="form-label" style="margin-top:12px">The operation</div>
      <div class="diag-report-facts">
        <div><span class="pcd-label">Started</span><span class="pcd-value">${KSI.fmtDateTime(o.started_at)}</span></div>
        <div><span class="pcd-label">Finished</span><span class="pcd-value">${KSI.fmtDateTime(o.ended_at)}</span></div>
        <div><span class="pcd-label">As planned</span><span class="pcd-value">${o.completed_as_planned === null ? '—' : (o.completed_as_planned ? 'Yes' : 'No')}</span></div>
        <div><span class="pcd-label">Team</span><span class="pcd-value">${KSI.esc((o.team || []).map(t => t.name).join(', ') || '—')}</span></div>
      </div>
      <div class="cl-readout-note">${KSI.esc(o.surgical_notes || 'No notes recorded.')}</div>
      <div class="muted-note">Complications: ${KSI.esc(o.complications || '—')}</div>` : ''}

    ${(booking.postop_visits || []).length ? `<div class="form-label" style="margin-top:12px">Post-op reviews</div>
      ${booking.postop_visits.map(v => `<div class="cl-addendum">
        <div class="cl-addendum-head">Review ${v.visit_number} · ${KSI.esc(v.visit_date)} · ${KSI.esc(v.created_by)}</div>
        <div>${[v.va_right ? `VA R ${KSI.esc(v.va_right)}` : '', v.iop_right ? `IOP R ${v.iop_right}` : '',
                v.wound_status ? `wound ${KSI.esc(v.wound_status)}` : '', v.follow_up_date ? `next review ${KSI.esc(v.follow_up_date)}` : ''].filter(Boolean).join(' · ')}</div>
        ${v.eye_exam ? `<div class="muted-note">${KSI.esc(v.eye_exam)}</div>` : ''}
      </div>`).join('')}` : ''}

    ${booking.discharged_at ? `<div class="alert alert-info" style="margin-top:12px">
      Discharged ${KSI.fmtDateTime(booking.discharged_at)} by ${KSI.esc(booking.discharged_by || '')}.
      ${booking.discharge_remarks ? KSI.esc(booking.discharge_remarks) : ''}</div>` : ''}`;
  openModal('modal-theatre-case');
}

function thPrintCase() {
  const b = TH.current;
  if (!b) return;
  const o = b.operation;
  optPrintWindow(`Theatre case ${b.booking_no}`, `
    <h1>Theatre Case Record</h1>
    <table class="facts">
      <tr><th>Case no.</th><td>${KSI.esc(b.booking_no)}</td><th>Status</th><td>${KSI.esc(uiLabel('theatreStatus', b.status))}</td></tr>
      <tr><th>Patient</th><td>${KSI.esc(b.patient.full_name)}</td><th>File no.</th><td>${KSI.esc(b.patient.reg_no)}</td></tr>
      <tr><th>Surgery</th><td>${KSI.esc(b.surgery?.name || 'Procedure only')}</td><th>Eye</th><td>${KSI.esc(thEye(b.eye))}</td></tr>
      <tr><th>Surgeon</th><td>${KSI.esc(b.surgeon.name)}</td><th>Theatre</th><td>${KSI.esc(b.theatre_room || '—')}</td></tr>
      <tr><th>Started</th><td>${o ? KSI.fmtDateTime(o.started_at) : '—'}</td><th>Finished</th><td>${o ? KSI.fmtDateTime(o.ended_at) : '—'}</td></tr>
      <tr><th>Discharged</th><td>${b.discharged_at ? KSI.fmtDateTime(b.discharged_at) : '—'}</td><th>Amount</th><td>${thMoney(b.total_amount)}</td></tr>
    </table>
    ${o ? `<h2>Operation notes</h2><p>${KSI.esc(o.surgical_notes || '—')}</p>
      <p><strong>Complications:</strong> ${KSI.esc(o.complications || '—')}</p>` : ''}
    ${(b.postop_visits || []).length ? `<h2>Post-op reviews</h2>
      <table><thead><tr><th>Review</th><th>Date</th><th>Vision R/L</th><th>Pressure R/L</th><th>Next review</th></tr></thead>
      <tbody>${b.postop_visits.map(v => `<tr><td>${v.visit_number}</td><td>${KSI.esc(v.visit_date)}</td>
        <td>${KSI.esc(v.va_right || '—')} / ${KSI.esc(v.va_left || '—')}</td>
        <td>${v.iop_right ?? '—'} / ${v.iop_left ?? '—'}</td>
        <td>${KSI.esc(v.follow_up_date || '—')}</td></tr>`).join('')}</tbody></table>` : ''}
    ${b.discharge_remarks ? `<h2>Discharge</h2><p>${KSI.esc(b.discharge_remarks)}</p>` : ''}`);
}

/** Prints the booking list exactly as filtered on screen, grouped by the day of surgery. */
function thPrintBookings() {
  const bookings = TH.bookings || [];
  if (!bookings.length) {
    showToast('Nothing to print', 'No cases match the filters.', 'info');
    return;
  }
  const chosen = id => {
    const el = document.getElementById(id);
    return el && el.value ? el.selectedOptions?.[0]?.textContent || el.value : '';
  };
  const from = document.getElementById('th-book-from').value;
  const to = document.getElementById('th-book-to').value;
  const search = document.getElementById('th-book-search').value.trim();
  const filters = [
    chosen('th-book-filter-status') && `Status: ${chosen('th-book-filter-status')}`,
    chosen('th-book-filter-surgeon') && `Surgeon: ${chosen('th-book-filter-surgeon')}`,
    chosen('th-book-filter-payment') && `Payment: ${chosen('th-book-filter-payment')}`,
    (from || to) && `Scheduled: ${from ? uiDate(from) : 'any date'} to ${to ? uiDate(to) : 'any date'}`,
    search && `Search: “${search}”`,
  ].filter(Boolean);
  const time = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', hour: '2-digit', minute: '2-digit' });
  const day = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const groups = [];
  bookings.forEach(b => {
    const label = b.scheduled_for ? day.format(new Date(b.scheduled_for)) : 'Not scheduled';
    if (groups[groups.length - 1]?.label !== label) groups.push({ label, rows: [] });
    groups[groups.length - 1].rows.push(b);
  });
  const by = status => bookings.filter(b => b.status === status).length;
  const surgery = b => [b.surgery?.name, ...(b.procedures || []).map(p => `${p.name}${p.quantity > 1 ? ` ×${p.quantity}` : ''}`)]
    .filter(Boolean).map(KSI.esc).join('<br>') || '—';
  const bill = b => !b.bill ? 'Not billed'
    : `${KSI.esc(uiLabel('billStatus', b.bill.status))}<div class="sub">${thMoney(b.bill.amount)}</div>${b.bill_deferred ? '<div class="sub">Emergency</div>' : ''}`;
  let n = 0;
  optPrintWindow('Theatre bookings', `
    <style>
      @page { size: A4 landscape; margin: 12mm; }
      body { margin: 0; }
      .filters { color: #555; margin: 0 0 10px; }
      .summary { display: flex; gap: 8px; margin: 10px 0 4px; flex-wrap: wrap; }
      .summary div { border: 1px solid #ddd; border-radius: 6px; padding: 6px 12px; }
      .summary strong { font-size: 15px; display: block; }
      h2 { color: #0D9488; border-bottom: 2px solid #0D9488; margin-top: 16px; }
      td, th { vertical-align: top; font-size: 12px; }
      .sub { color: #666; font-size: 11px; }
      .fast { display: inline-block; background: #FFF7ED; color: #C2410C; font-size: 10px; font-weight: bold; padding: 1px 5px; border-radius: 4px; }
      tr { page-break-inside: avoid; }
    </style>
    <h1>Theatre Bookings</h1>
    <p class="filters">${filters.length ? KSI.esc(filters.join(' · ')) : 'All open cases'}</p>
    <div class="summary">
      <div><strong>${bookings.length}</strong>Cases</div>
      <div><strong>${by('booked')}</strong>Booked</div>
      <div><strong>${bookings.filter(b => !b.may_operate).length}</strong>Awaiting payment</div>
      <div><strong>${by('cleared')}</strong>Cleared</div>
      <div><strong>${by('in_theatre')}</strong>In theatre</div>
      <div><strong>${by('post_op')}</strong>Post-op care</div>
    </div>
    ${groups.map(g => `
      <h2>${KSI.esc(g.label)} — ${g.rows.length} case${g.rows.length === 1 ? '' : 's'}</h2>
      <table>
        <thead><tr><th style="width:24px">#</th><th style="width:44px">Time</th><th>Case</th><th>Patient</th><th>Surgery</th><th>Eye</th><th>Surgeon / Theatre</th><th>Bill</th><th>Status</th></tr></thead>
        <tbody>${g.rows.map(b => `<tr>
          <td>${++n}</td>
          <td>${b.scheduled_for ? time.format(new Date(b.scheduled_for)) : '—'}</td>
          <td>${KSI.esc(b.booking_no)}</td>
          <td><strong>${KSI.esc(b.patient.full_name)}</strong> ${b.is_fast_track ? '<span class="fast">FAST TRACK</span>' : ''}
            <div class="sub">File ${KSI.esc(b.patient.reg_no)}${b.patient.age != null ? ` · ${b.patient.age} yrs` : ''}${b.patient.gender ? ` · ${KSI.esc(uiLabel('gender', b.patient.gender))}` : ''}</div></td>
          <td>${surgery(b)}</td>
          <td>${KSI.esc(thEye(b.eye))}</td>
          <td>${KSI.esc(b.surgeon.name)}<div class="sub">${KSI.esc(b.theatre_room || '')}</div></td>
          <td>${bill(b)}</td>
          <td>${KSI.esc(uiLabel('theatreStatus', b.status))}</td>
        </tr>`).join('')}</tbody>
      </table>`).join('')}`);
}

function thPrintHistory() {
  optPrintWindow('Theatre history', `
    <h1>Theatre History</h1>
    <p>${TH.history.length} case${TH.history.length === 1 ? '' : 's'}</p>
    <table>
      <thead><tr><th>Case</th><th>Patient</th><th>File no.</th><th>Surgery</th><th>Eye</th><th>Surgeon</th><th>Discharged</th></tr></thead>
      <tbody>${TH.history.map(b => `<tr>
        <td>${KSI.esc(b.booking_no)}</td><td>${KSI.esc(b.patient.full_name)}</td><td>${KSI.esc(b.patient.reg_no)}</td>
        <td>${KSI.esc(b.surgery?.name || 'Procedure')}</td><td>${KSI.esc(thEye(b.eye))}</td>
        <td>${KSI.esc(b.surgeon.name)}</td><td>${b.discharged_at ? KSI.fmtDateTime(b.discharged_at) : '—'}</td>
      </tr>`).join('')}</tbody>
    </table>`);
}

// ─── Shared ───────────────────────────────────────────────────────────────

async function thRefreshLists() {
  const open = id => !document.getElementById(id).classList.contains('hidden');
  if (open('module-theatre-booking')) { await thLoadBookings(); await thLoadRecommendations(); }
  if (open('module-theatre-preop')) await thLoadPreop();
  if (open('module-theatre-intraop')) await thLoadOperations();
  if (open('module-theatre-postop')) await thLoadPostop();
  if (open('module-theatre-history')) await thLoadHistory();
}
