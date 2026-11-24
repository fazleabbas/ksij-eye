'use strict';
// ─── CLINICAL WORKSPACE (Triage · Optometry · Ophthalmology) ───────────────
// Shared by the three clinical screens: options cache, form collect/fill by
// data-field path, the queue and patient workspace (open, draft, complete,
// correct, take over, return to queue), diagnosis/lens chips, prescription
// editor, follow-up block, upstream readouts, history panel and addenda.

const CL_OPTIONS = { data: null, loadedAt: 0 };
const CL_STAGES = {};
const CL_CHIPS = {};
const CL_RX = {};
const CL_ADDENDUM = { stage: null };

async function clOptions(force = false) {
  if (!force && CL_OPTIONS.data && Date.now() - CL_OPTIONS.loadedAt < 300000) return CL_OPTIONS.data;
  CL_OPTIONS.data = await KSI.get('clinical/options');
  CL_OPTIONS.loadedAt = Date.now();
  return CL_OPTIONS.data;
}

// ── Form values by dotted data-field path ──
function clGetPath(obj, path) {
  return path.split('.').reduce((o, key) => (o == null ? undefined : o[key]), obj);
}

function clSetPath(obj, path, value) {
  const keys = path.split('.');
  let node = obj;
  keys.forEach((key, i) => {
    if (i === keys.length - 1) {
      node[key] = value;
    } else {
      if (node[key] == null) node[key] = /^\d+$/.test(keys[i + 1]) ? [] : {};
      node = node[key];
    }
  });
  return obj;
}

function clInputs(root) {
  return [...root.querySelectorAll('input[data-field], select[data-field], textarea[data-field]')]
    .filter(el => !el.closest('[data-collect="skip"]'));
}

/** Every data-field input in root as a nested object; blank values become null. */
function clCollect(root) {
  const out = {};
  clInputs(root).forEach(el => {
    const path = el.dataset.field;
    if (el.type === 'radio') {
      if (el.checked) clSetPath(out, path, el.value);
      else if (clGetPath(out, path) === undefined) clSetPath(out, path, null);
      return;
    }
    const value = el.type === 'checkbox' ? el.checked : el.value.trim();
    clSetPath(out, path, value === '' ? null : value);
  });
  return out;
}

function clFill(root, data) {
  clInputs(root).forEach(el => {
    const value = clGetPath(data || {}, el.dataset.field);
    if (el.type === 'checkbox') el.checked = !!value;
    else if (el.type === 'radio') el.checked = value != null && String(value) === el.value;
    else el.value = value == null ? '' : clFormat(value, el.dataset.format);
  });
  clSyncCards(root);
}

function clFormat(value, format) {
  if (format === 'dioptre' && value !== '' && !isNaN(value)) {
    const n = Number(value);
    return (n > 0 ? '+' : '') + n.toFixed(2);
  }
  return String(value);
}

function clSelectOptions(values, placeholder = '—') {
  return `<option value="">${KSI.esc(placeholder)}</option>`
    + values.map(v => `<option value="${KSI.esc(v)}">${KSI.esc(v)}</option>`).join('');
}

/** Fills select[data-options] lists (visual acuity, emergency and follow-up reasons). */
function clPrepareSelects(root, opts) {
  const lists = { va: opts.va_values, emergency_reason: opts.emergency_reasons, follow_up_reason: opts.follow_up_reasons, rx_labels: opts.rx_labels };
  root.querySelectorAll('select[data-options]').forEach(select => {
    const list = lists[select.dataset.options];
    if (list) select.innerHTML = clSelectOptions(list, select.dataset.placeholder || '—');
  });
}

/** Keeps .route-card / .routing-opt highlighting in step with their inputs. */
function clSyncCards(root) {
  root.querySelectorAll('.route-card, .routing-opt').forEach(card => {
    const input = card.querySelector('input');
    card.classList.toggle('selected', !!input?.checked);
  });
}

/** read: everything disabled · correct: orders and routing ([data-lock="correct"]) disabled · edit: all enabled. */
function clApplyMode(root, mode) {
  root.querySelectorAll('input, select, textarea').forEach(el => {
    el.disabled = mode === 'read' || (mode === 'correct' && !!el.closest('[data-lock="correct"]'));
  });
  root.querySelectorAll('[data-lock="correct"] .cl-lock-note').forEach(note => {
    note.style.display = mode === 'correct' ? '' : 'none';
  });
}

function clMarkDirty(stage) {
  if (CL_STAGES[stage]) CL_STAGES[stage].state.dirty = true;
}

function clWaitText(iso) {
  if (!iso) return '';
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  return hours >= 24 ? `${Math.floor(hours / 24)} d` : `${hours} h ${minutes % 60} min`;
}

function clInitials(name) {
  const words = String(name || '').split(' ').filter(Boolean);
  return ((words[0]?.[0] || '') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
}

function clEyePair(right, left, unit = '') {
  const show = v => (v == null || v === '' ? '—' : `${v}${unit}`);
  return `${show(right)} / ${show(left)}`;
}

// ── Chips (ICD-10 diagnoses, lens items) ──
/** items(): [{ id, label, group? }] available for selection. */
function clChipsInit(id, { container, field, placeholder, items, stage, onChange }) {
  CL_CHIPS[id] = { container, field, placeholder, items, stage, onChange, ids: [], labels: {}, disabled: false };
}

function clChipsSet(id, selected, disabled) {
  const chips = CL_CHIPS[id];
  chips.ids = [];
  chips.labels = {};
  (selected || []).forEach(item => {
    chips.ids.push(item.id);
    chips.labels[item.id] = item.label;
  });
  chips.disabled = !!disabled;
  clChipsRender(id);
}

function clChipsRender(id) {
  const chips = CL_CHIPS[id];
  const el = document.getElementById(chips.container);
  const available = chips.items().filter(item => !chips.ids.includes(item.id));
  const groups = {};
  available.forEach(item => (groups[item.group || ''] ||= []).push(item));
  const optionHtml = Object.entries(groups).map(([group, list]) => {
    const options = list.map(item => `<option value="${item.id}">${KSI.esc(item.label)}</option>`).join('');
    return group ? `<optgroup label="${KSI.esc(group)}">${options}</optgroup>` : options;
  }).join('');
  el.innerHTML = `${chips.disabled ? '' : `<select class="form-select" data-field="${KSI.esc(chips.field)}" onchange="clChipsAdd('${id}', this)">
      <option value="">${KSI.esc(chips.placeholder)}</option>${optionHtml}</select>`}
    <div class="ms-chip-wrap">${chips.ids.length
      ? chips.ids.map(value => `<span class="ms-chip">${KSI.esc(chips.labels[value] || value)}${chips.disabled ? '' : `<button type="button" title="Remove" onclick="clChipsRemove('${id}', ${value})">&times;</button>`}</span>`).join('')
      : '<span class="text-xs text-muted">None selected</span>'}</div>`;
}

function clChipsAdd(id, select) {
  const value = Number(select.value);
  if (!value) return;
  const chips = CL_CHIPS[id];
  if (!chips.ids.includes(value)) {
    chips.ids.push(value);
    chips.labels[value] = select.options[select.selectedIndex].text;
  }
  clChipsRender(id);
  clMarkDirty(chips.stage);
  chips.onChange?.();
}

function clChipsRemove(id, value) {
  const chips = CL_CHIPS[id];
  chips.ids = chips.ids.filter(v => v !== value);
  clChipsRender(id);
  clMarkDirty(chips.stage);
  chips.onChange?.();
}

function clIcdItems() {
  return (CL_OPTIONS.data?.icd10 || []).map(c => ({ id: c.id, label: `${c.code} — ${c.description}`, group: c.category || 'Other' }));
}

function clIcdSelected(codes) {
  return (codes || []).map(c => ({ id: c.id, label: `${c.code} — ${c.description}` }));
}

// ── Prescription editor ──
function clRxInit(id, container, stage) {
  CL_RX[id] = {
    container, stage, items: [], instructions: '', status: null, number: null, disabled: false,
    // An issued prescription may be changed by the doctor who issued it, for a while (amending).
    rx: null, amendableUntil: null, amending: false, reason: '',
  };
}

function clRxSet(id, rx, disabled) {
  const state = CL_RX[id];
  state.items = (rx?.items || []).map(item => ({ ...item }));
  state.instructions = rx?.pharmacy_instructions || '';
  state.status = rx?.status || null;
  state.number = rx?.prescription_no || null;
  state.disabled = !!disabled || (rx != null && rx.status !== 'draft');
  state.rx = rx || null;
  state.amendableUntil = rx?.status === 'issued' ? rx.amendable_until || null : null;
  state.amending = false;
  state.reason = '';
  clRxRender(id);
}

function clRxRender(id) {
  const state = CL_RX[id];
  const opts = CL_OPTIONS.data;
  const el = document.getElementById(state.container);
  const locked = state.disabled && !state.amending;
  const dis = locked ? ' disabled' : '';
  const rows = state.items.map((item, i) => `<tr>
      <td class="med-td-name" data-field="prescription.items.${i}.drug_id">${KSI.esc(item.name)}<div class="med-td-name-sub">${item.outside
        ? '<span style="color:var(--amber)">Not stocked — buy outside</span>' : KSI.esc(item.dosage_form || '')}</div></td>
      <td><input class="med-row-input" type="number" min="1" max="999" value="${KSI.esc(item.quantity ?? 1)}" data-field="prescription.items.${i}.quantity" oninput="clRxUpdate('${id}', ${i}, 'quantity', this.value)"${dis}></td>
      <td><input class="med-row-input" maxlength="40" value="${KSI.esc(item.dose || '')}" placeholder="1 Drop" data-field="prescription.items.${i}.dose" oninput="clRxUpdate('${id}', ${i}, 'dose', this.value)"${dis}></td>
      <td><input class="med-row-input" maxlength="20" list="cl-frequencies" value="${KSI.esc(item.frequency || '')}" placeholder="BD" data-field="prescription.items.${i}.frequency" oninput="clRxUpdate('${id}', ${i}, 'frequency', this.value)"${dis}></td>
      <td><input class="med-row-input" maxlength="40" value="${KSI.esc(item.duration || '')}" placeholder="1 Month" data-field="prescription.items.${i}.duration" oninput="clRxUpdate('${id}', ${i}, 'duration', this.value)"${dis}></td>
      <td><input class="med-row-input" maxlength="200" value="${KSI.esc(item.instructions || '')}" placeholder="Both eyes" data-field="prescription.items.${i}.instructions" oninput="clRxUpdate('${id}', ${i}, 'instructions', this.value)"${dis}></td>
      <td>${locked ? '' : `<button type="button" class="med-row-remove" title="Remove" onclick="clRxRemove('${id}', ${i})">&times;</button>`}</td>
    </tr>`).join('');
  el.innerHTML = `
    ${clRxIssuedNote(id)}
    ${locked ? '' : `<div class="med-search-wrap">
      <input class="med-search-input" list="cl-drugs-${id}" data-field="prescription.items" autocomplete="off"
        placeholder="Type the medicine name and pick it — or type one the pharmacy does not stock" onchange="clRxAdd('${id}', this)">
      <datalist id="cl-drugs-${id}">${(opts?.drugs || []).map(d =>
        `<option value="${KSI.esc(d.name)}">${KSI.esc([d.dosage_form, d.category].filter(Boolean).join(' · '))}</option>`).join('')}</datalist>
    </div>`}
    <div class="med-table-wrap"><table class="med-table">
      <thead><tr><th>Medicine</th><th style="min-width:56px">Qty</th><th style="min-width:80px">Dose</th><th style="min-width:70px">Frequency</th><th style="min-width:80px">Duration</th><th style="min-width:120px">Instructions</th><th></th></tr></thead>
      <tbody>${rows || '<tr><td colspan="7"><div class="med-empty-state">No medicines prescribed.</div></td></tr>'}</tbody>
    </table></div>
    <label class="med-pharm-instr-label">Instructions for the pharmacy <span style="font-weight:400;color:var(--text-3)">(optional)</span></label>
    <textarea class="med-pharm-instr-textarea" maxlength="500" data-field="prescription.pharmacy_instructions" oninput="clRxUpdate('${id}', null, 'instructions', this.value)"${dis}>${KSI.esc(state.instructions)}</textarea>
    ${state.amending ? `<div class="cl-note" style="margin-top:10px">
      <label class="med-pharm-instr-label" for="${id}-amend-reason">Reason for the change <span style="color:var(--red)">*</span></label>
      <input class="form-input" style="width:100%" id="${id}-amend-reason" maxlength="300" placeholder="e.g. wrong strength, patient allergic, changed frequency"
        value="${KSI.esc(state.reason)}" oninput="CL_RX['${id}'].reason = this.value">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="clRxCancelAmend('${id}')">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="${id}-amend-save" onclick="clRxSaveAmend('${id}')">Save prescription changes</button>
      </div>
    </div>` : ''}`;
}

/** The notice above an issued prescription: number, last change, and whether it can still be changed. */
function clRxIssuedNote(id) {
  const state = CL_RX[id];
  if (!state.number) return '';
  const rx = state.rx || {};
  const a = rx.amendment;
  const changed = a ? `<div style="margin-top:4px">Changed${a.count > 1 ? ` ${a.count} times` : ''} — last by ${KSI.esc(a.by || '—')} on ${KSI.fmtDateTime(a.at)}: <em>${KSI.esc(a.reason || '')}</em></div>` : '';
  const settled = ['paid', 'partially_dispensed', 'completed'].includes(rx.pharmacy_status);
  let tail;
  if (state.amending) {
    tail = `<div style="margin-top:4px"><strong>Changing the prescription.</strong> ${settled
      ? 'The pharmacy has already been paid, so only medicines bought outside can change.'
      : rx.pharmacy_status === 'awaiting_payment'
        ? 'The pharmacy has billed it but it is not paid: changing a stocked medicine cancels that bill and sends the order back to the pharmacy.'
        : 'The pharmacy will see the changed prescription.'}</div>`;
  } else if (state.amendableUntil) {
    tail = `<div style="display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:6px;flex-wrap:wrap">
      <span>You can change it until ${KSI.fmtDateTime(state.amendableUntil)}${settled ? ' (the pharmacy has been paid: only medicines bought outside)' : ''}.</span>
      <button type="button" class="btn btn-secondary btn-sm" onclick="clRxStartAmend('${id}')">Edit prescription</button></div>`;
  } else {
    tail = ' — it can no longer be changed here.';
  }
  return `<div class="alert alert-info" style="margin-bottom:10px">Prescription <strong>${KSI.esc(state.number)}</strong> issued${tail}${changed}</div>`;
}

function clRxStartAmend(id) {
  const state = CL_RX[id];
  const stageObj = CL_STAGES[state.stage];
  if (stageObj?.state.dirty && !confirm('This record has other unsaved changes. They will be lost when the prescription is saved. Continue?')) return;
  state.amending = true;
  state.reason = '';
  clRxRender(id);
}

function clRxCancelAmend(id) {
  const state = CL_RX[id];
  clRxSet(id, state.rx, true);
}

async function clRxSaveAmend(id) {
  const state = CL_RX[id];
  const stageObj = CL_STAGES[state.stage];
  const ws = stageObj.state.ws;
  const el = document.getElementById(state.container);
  KSI.clearFieldErrors(el);
  if (state.reason.trim().length < 3) {
    showToast('Reason needed', 'Say briefly why the prescription is being changed.', 'error');
    document.getElementById(`${id}-amend-reason`).focus();
    return;
  }
  const body = { prescription: clRxValue(id), reason: state.reason.trim(), row_version: state.rx?.row_version ?? null };
  const saved = await admBusy(document.getElementById(`${id}-amend-save`), async () => {
    try {
      return await KSI.put(`${stageObj.stage}/visits/${ws.visit.id}/prescription`, body);
    } catch (error) {
      if (error.status === 422) {
        KSI.showFieldErrors(el, error.fields);
        showToast('Prescription not changed', Object.values(error.fields || {})[0] || error.message, 'error');
      } else {
        showToast('Prescription not changed', error.message, 'error');
        if (['stale_record', 'record_locked', 'not_issued'].includes(error.code)) stageObj.reload();
      }
      return null;
    }
  });
  if (!saved) return;
  const effect = {
    order_rebuilt: 'The pharmacy order has been updated.',
    bill_cancelled: 'The unpaid pharmacy bill was cancelled; the pharmacy will review the order again.',
    order_cancelled: 'No stocked medicine is left, so the pharmacy order was cancelled.',
  }[saved.result.effect] || 'The pharmacy will see the change.';
  showToast(`Prescription ${state.number} changed`, effect, 'success');
  stageObj.showWorkspace(saved);
}

function clRxAdd(id, input) {
  const typed = input.value.trim();
  if (!typed) return;
  const drugs = CL_OPTIONS.data.drugs || [];
  const drug = drugs.find(d => d.name.toLowerCase() === typed.toLowerCase());
  const state = CL_RX[id];
  if (drug) {
    state.items.push({
      drug_id: drug.id, name: drug.name, dosage_form: drug.dosage_form, quantity: 1,
      dose: drug.default_dose || '', frequency: drug.default_frequency || '', duration: drug.default_duration || '', instructions: '',
    });
  } else {
    // Not a catalogue name: the doctor may still prescribe it, and the patient buys it outside.
    if (!confirm(`"${typed}" is not stocked by the pharmacy.\n\nPrescribe it anyway? The patient will buy it outside.`)) {
      input.value = '';
      return;
    }
    state.items.push({
      drug_id: null, outside: true, name: typed.slice(0, 150), quantity: 1, dose: '', frequency: '', duration: '', instructions: '',
    });
  }
  clRxRender(id);
  if (!state.amending) clMarkDirty(state.stage);
}

function clRxUpdate(id, index, field, value) {
  const state = CL_RX[id];
  if (index === null) state.instructions = value;
  else state.items[index][field] = value;
  if (!state.amending) clMarkDirty(state.stage);
}

function clRxRemove(id, index) {
  CL_RX[id].items.splice(index, 1);
  clRxRender(id);
  if (!CL_RX[id].amending) clMarkDirty(CL_RX[id].stage);
}

function clRxValue(id) {
  const state = CL_RX[id];
  return {
    items: state.items.map(i => ({
      drug_id: i.drug_id, outside_name: i.drug_id ? null : i.name,
      quantity: i.quantity, dose: i.dose, frequency: i.frequency, duration: i.duration, instructions: i.instructions,
    })),
    pharmacy_instructions: state.instructions,
  };
}

// ── Follow-up block ──
function clToggleFollowUp(prefix) {
  const on = document.querySelector(`#${prefix}-form [data-field="follow_up.required"]`).checked;
  document.getElementById(`${prefix}-fu-fields`).classList.toggle('hidden', !on);
}

function clFollowUpData(followUp) {
  return followUp ? { follow_up: { ...followUp, required: true } } : { follow_up: { required: false, priority: 'routine' } };
}

function clFollowUpNote(prefix, followUp) {
  const note = document.getElementById(`${prefix}-fu-status`);
  if (!note) return;
  note.textContent = followUp && followUp.status !== 'draft'
    ? `Follow-up ${followUp.status === 'booked' ? `booked as visit ${followUp.booked_visit_no}` : followUp.status === 'cancelled' ? 'cancelled by reception' : 'sent to reception'}.`
    : '';
}

// ── Readouts ──
function clTriageReadout(t) {
  if (!t) return '';
  const fields = [
    ['Blood pressure', t.bp_systolic ? `${t.bp_systolic}/${t.bp_diastolic} mmHg` : '—'],
    ['Blood sugar', t.blood_sugar_mmol != null ? `${t.blood_sugar_mmol} mmol/L (${t.blood_sugar_type})` : '—'],
    ['Weight / height', `${t.weight_kg ?? '—'} kg / ${t.height_m ?? '—'} m`],
    ['BMI', t.bmi ?? '—'],
    ['Unaided VA R / L', clEyePair(t.va_unaided_right, t.va_unaided_left)],
    ['Aided VA R / L', clEyePair(t.va_aided_right, t.va_aided_left)],
    ['IOP R / L', clEyePair(t.iop_right, t.iop_left, ' mmHg')],
  ];
  return `<div class="triage-readout">
    <div class="triage-readout-title">Triage — ${KSI.esc(t.completed_by || '')} · ${KSI.fmtDateTime(t.completed_at)}</div>
    <div class="triage-readout-grid">${fields.map(([label, value]) => `<div class="tro-item"><div class="tro-label">${KSI.esc(label)}</div><div class="tro-value">${KSI.esc(value)}</div></div>`).join('')}</div>
    ${t.remarks ? `<div class="cl-readout-note"><strong>Remarks:</strong> ${KSI.esc(t.remarks)}</div>` : ''}
    ${t.emergency_notes ? `<div class="cl-readout-note"><strong>Emergency notes:</strong> ${KSI.esc(t.emergency_notes)}</div>` : ''}
  </div>`;
}

const CL_REFRACTION_KINDS = [['final', 'Final'], ['dry_ark', 'Dry / ARK'], ['cyclo', 'Cyclo'], ['pmt', 'PMT']];

/** Refractions in a small table; further spectacle prescriptions (extra) follow the final one, by label. */
function clRefractionTable(refractions, extra = [], finalLabel = null) {
  const d = v => (v == null ? '—' : clFormat(v, 'dioptre'));
  const rows = CL_REFRACTION_KINDS
    .filter(([kind]) => Object.values(refractions?.[kind] || {}).some(v => v != null))
    .map(([kind, label]) => [kind === 'final' && finalLabel ? `${label} — ${finalLabel}` : label, refractions[kind]]);
  (extra || []).forEach((x, i) => rows.splice(i + 1, 0, [x.label || `Prescription ${i + 2}`, x.refraction]));
  if (!rows.length) return '<div class="text-xs text-muted">No refraction recorded.</div>';
  return `<div class="table-wrap"><table class="hist-mini-table">
    <thead><tr><th></th><th>SPH R/L</th><th>CYL R/L</th><th>AXIS R/L</th><th>CVA R/L</th><th>ADD R/L</th><th>PD</th></tr></thead>
    <tbody>${rows.map(([label, r]) => `<tr><td>${KSI.esc(label)}</td><td>${d(r.sph_right)} / ${d(r.sph_left)}</td><td>${d(r.cyl_right)} / ${d(r.cyl_left)}</td>
        <td>${clEyePair(r.axis_right, r.axis_left)}</td><td>${KSI.esc(clEyePair(r.cva_right, r.cva_left))}</td><td>${d(r.add_right)} / ${d(r.add_left)}</td><td>${r.pd_mm ?? '—'}</td></tr>`).join('')}</tbody></table></div>`;
}

/** The lenses of every spectacle prescription in an optometry record, by label. */
function clLensText(o) {
  const sets = [[o.final_label, o.lens_items || []], ...(o.extra_rx || []).map((x, i) => [x.label || `Prescription ${i + 2}`, x.lens_items || []])];
  const several = (o.extra_rx || []).length > 0;
  return sets.filter(([, items]) => items.length)
    .map(([label, items]) => `${several && label ? `${label}: ` : ''}${items.map(l => l.name).join(', ')}`).join(' · ') || '—';
}

function clDxText(diagnoses, notes = {}) {
  const line = (kind, label) => {
    const codes = (diagnoses?.[kind] || []).map(c => `${c.code} ${c.description}`);
    if (notes[kind]) codes.push(notes[kind]);
    return codes.length ? `<div class="hist-field"><div class="hist-field-lbl">${label}</div><div class="hist-field-val">${KSI.esc(codes.join('; '))}</div></div>` : '';
  };
  return line('provisional', 'Provisional diagnosis') + line('final', 'Final diagnosis');
}

function clRxText(rx) {
  if (!rx?.items?.length) return '';
  return `<div class="hist-field"><div class="hist-field-lbl">Prescription ${KSI.esc(rx.prescription_no || '')}</div><div class="hist-field-val">${rx.items.map(i =>
    `<div><strong>${KSI.esc(i.name)}</strong>${i.outside ? ' <em>(buy outside)</em>' : ''} — ${KSI.esc([i.dose, i.frequency, i.duration].filter(Boolean).join(' · '))}${i.instructions ? ` (${KSI.esc(i.instructions)})` : ''} × ${i.quantity}</div>`).join('')}</div></div>`;
}

/** Cataract biometry per eye, as one line each. */
function clBiometryText(biometry) {
  const line = b => [b.axial_length != null && `AL ${b.axial_length} mm`, b.steep_k != null && `K1 ${b.steep_k}`, b.flat_k != null && `K2 ${b.flat_k}`,
    b.acd != null && `ACD ${b.acd}`, b.corneal_thickness != null && `CCT ${b.corneal_thickness}`, b.lens_thickness != null && `LT ${b.lens_thickness}`,
    b.white_to_white != null && `WTW ${b.white_to_white}`, b.a_constant != null && `A ${b.a_constant}`, b.iol_model && `IOL ${b.iol_model}`,
    b.iol_power != null && `${b.iol_power} D`, b.remarks].filter(Boolean).join(' · ');
  const eyes = [['right', 'Right'], ['left', 'Left']].map(([eye, label]) => [label, biometry?.[eye] ? line(biometry[eye]) : '']).filter(([, text]) => text);
  if (!eyes.length) return '';
  return `<div class="hist-field"><div class="hist-field-lbl">Biometry</div><div class="hist-field-val">${eyes.map(([label, text]) => `${label}: ${KSI.esc(text)}`).join('<br>')}</div></div>`;
}

function clOptometryReadout(o, title = 'Optometry') {
  if (!o) return '';
  const field = (label, value) => (value ? `<div class="hist-field"><div class="hist-field-lbl">${label}</div><div class="hist-field-val">${KSI.esc(value)}</div></div>` : '');
  return `<div class="triage-readout">
    <div class="triage-readout-title">${KSI.esc(title)} — ${KSI.esc(o.completed_by || '')} · ${KSI.fmtDateTime(o.completed_at)}</div>
    <div class="triage-readout-grid" style="margin-bottom:8px">
      <div class="tro-item"><div class="tro-label">VA R / L</div><div class="tro-value">${KSI.esc(clEyePair(o.va_right, o.va_left))}</div></div>
      <div class="tro-item"><div class="tro-label">IOP R / L</div><div class="tro-value">${KSI.esc(clEyePair(o.iop_right, o.iop_left, ' mmHg'))}</div></div>
      <div class="tro-item"><div class="tro-label">Lenses</div><div class="tro-value normal">${KSI.esc(clLensText(o))}</div></div>
    </div>
    ${clRefractionTable(o.refractions, o.extra_rx, o.final_label)}
    ${clDxText(o.diagnoses)}
    ${clBiometryText(o.biometry)}
    ${field('Management plan', o.management_plan)}${field('Anterior segment', o.anterior_segment)}${field('Posterior segment', o.posterior_segment)}${field('Remarks', o.remarks)}
    ${clRxText(o.prescription)}
  </div>`;
}

function clOphthalmologyReadout(c) {
  if (!c) return '';
  const abnormal = { right: [], left: [] };
  (c.findings || []).filter(f => !f.is_normal).forEach(f => {
    (f.eye === 'both' ? ['right', 'left'] : [f.eye]).forEach(eye => abnormal[eye].push(f.label));
  });
  return `<div class="triage-readout">
    <div class="triage-readout-title">Ophthalmology — ${KSI.esc(c.completed_by || '')} · ${KSI.fmtDateTime(c.completed_at)}</div>
    ${c.clinical_history ? `<div class="hist-field"><div class="hist-field-lbl">Clinical history</div><div class="hist-field-val">${KSI.esc(c.clinical_history)}</div></div>` : ''}
    ${clDxText(c.diagnoses, { provisional: c.provisional_dx_notes, final: c.final_dx_notes })}
    ${abnormal.right.length || abnormal.left.length ? `<div class="hist-field"><div class="hist-field-lbl">Abnormal findings</div><div class="hist-field-val">Right: ${KSI.esc(abnormal.right.join(', ') || '—')}<br>Left: ${KSI.esc(abnormal.left.join(', ') || '—')}</div></div>` : ''}
    ${c.clinical_notes ? `<div class="hist-field"><div class="hist-field-lbl">Clinical notes</div><div class="hist-field-val">${KSI.esc(c.clinical_notes)}</div></div>` : ''}
    ${clRxText(c.prescription)}
    ${c.refer_optometry ? '<div class="hist-field"><div class="hist-field-lbl">Referral</div><div class="hist-field-val">Referred to Optometry for spectacles</div></div>' : ''}
  </div>`;
}

function clAuditStrip(record) {
  const item = (label, value) => `<div class="audit-item"><div class="audit-lbl">${label}</div><div class="audit-val">${KSI.esc(value)}</div></div>`;
  if (!record) return item('Record', 'Not saved yet');
  return [
    item('Status', record.status === 'completed' ? 'Completed' : 'Draft'),
    item('Started', `${record.created_by} · ${KSI.fmtDateTime(record.created_at)}`),
    record.updated_at ? item('Last saved', `${record.updated_by || '—'} · ${KSI.fmtDateTime(record.updated_at)}`) : '',
    record.completed_at ? item('Completed', `${record.completed_by} · ${KSI.fmtDateTime(record.completed_at)}`) : '',
  ].join('');
}

function clBanner(visit) {
  const p = visit.patient;
  return `<div class="pb-avatar">${KSI.esc(clInitials(p.full_name))}</div>
    <div class="pb-info">
      <div class="pb-name">${KSI.esc(p.full_name)}</div>
      <div class="pb-meta">${KSI.esc(p.reg_no)} · ${KSI.esc(uiLabel('gender', p.gender))} · ${p.age} years</div>
      <div class="pb-visit">Visit ${KSI.esc(visit.visit_no)} · ${KSI.esc(uiLabel('visitType', visit.visit_type))} — ${KSI.esc(uiLabel('subType', visit.sub_type))}${visit.room ? ` · ${KSI.esc(visit.room)}` : ''}</div>
      <div class="pb-tags">
        ${visit.is_fast_track ? '<span class="badge badge-fasttrack">Fast track</span>' : ''}
        ${visit.is_emergency ? `<span class="emg-badge">EMERGENCY${visit.emergency_reason ? ` · ${KSI.esc(visit.emergency_reason)}` : ''}</span>` : ''}
        ${uiBadge('visitStatus', visit.status)}
        ${visit.readmitted ? `<span class="badge badge-pending" title="${KSI.esc(visit.readmitted.reason || '')}">Re-admitted ${KSI.fmtDateTime(visit.readmitted.at)}${visit.readmitted.reason ? ` · ${KSI.esc(visit.readmitted.reason)}` : ''}</span>` : ''}
      </div>
    </div>`;
}

// ── History panel ──
async function clLoadHistory(containerId, patientId, exceptVisitId, allVisits = false) {
  const el = document.getElementById(containerId);
  el.innerHTML = '<div class="cl-queue-empty">Loading history…</div>';
  try {
    const data = await KSI.get(`clinical/patients/${patientId}/history`, { except_visit: exceptVisitId, all: allVisits ? '1' : '' });
    return data;
  } catch (error) {
    el.innerHTML = `<div class="cl-queue-empty">${KSI.esc(error.message)}</div>`;
    return null;
  }
}

function clHistoryHtml(data) {
  if (!data?.visits?.length) return '<div class="cl-queue-empty">No earlier clinical records for this patient.</div>';
  return data.visits.map((v, i) => `<div class="hist-visit-card">
      <div class="hist-visit-header" onclick="this.nextElementSibling.classList.toggle('open')">
        <span class="hist-visit-id">${KSI.esc(v.visit_no)}</span><span class="hist-visit-date">${uiDate(v.visit_date)}</span>
      </div>
      <div class="hist-visit-body${i === 0 ? ' open' : ''}">
        ${clTriageReadout(v.triage)}${clOptometryReadout(v.optometry)}${clOphthalmologyReadout(v.ophthalmology)}
        ${clVisitTestsHtml(data, v.id)}
      </div>
    </div>`).join('');
}

/** Tests done in a visit, under its clinical records. */
function clVisitTestsHtml(data, visitId) {
  const tests = (data.diagnostics || []).filter(t => t.visit_id === visitId && t.status !== 'cancelled');
  if (!tests.length) return '';
  return `<div class="triage-readout"><div class="triage-readout-title">Tests</div>${tests.map(t => clTestCard(t, data.can_view_results)).join('')}</div>`;
}

const CL_TEAM_ROLES = { surgeon: 'Surgeon', assistant: 'Assistant', anaesthetist: 'Anaesthetist', scrub_nurse: 'Scrub nurse', anaesthesia_nurse: 'Anaesthesia nurse', runner: 'Runner' };
const CL_OPTICAL_TYPES = { referral: 'Spectacles', external: 'Outside customer', frame_change: 'Frame change', extra_pair: 'Extra pair' };

/** A diagnostic test: status for everyone; doctors can open the result. */
function clTestCard(t, canView) {
  const ready = t.result_ready;
  return `<div class="inv-result-card"><div class="inv-result-header" style="cursor:default">
    <div><div style="font-size:11px;font-weight:700">${KSI.esc(t.name)} · ${KSI.esc(uiLabel('eye', t.eye))}</div>
      <div class="text-xs text-muted">${KSI.esc(t.booking_no)}${t.visit_no ? ` · ${KSI.esc(t.visit_no)}` : ''} · ${KSI.esc(uiDate(String(t.scheduled_for).slice(0, 10)))}${t.referring ? ` · ${KSI.esc(t.referring)}` : ''}</div></div>
    <div style="display:flex;gap:6px;align-items:center">${ready ? '<span class="badge badge-complete">Result ready</span>' : uiBadge('diagnosticStatus', t.status)}
      ${ready && canView ? `<button type="button" class="btn btn-secondary btn-sm" onclick="clResultOpen(${t.booking_id})">View result</button>` : ''}</div>
  </div></div>`;
}

function clOrdersHtml(data) {
  const investigations = data?.investigations || [];
  const surgery = data?.surgery || [];
  const tests = data?.diagnostics || [];
  const theatre = data?.theatre || [];
  const medicines = data?.medicines || [];
  const optical = data?.optical || [];
  if (![investigations, surgery, tests, theatre, medicines, optical].some(list => list.length)) {
    return '<div class="cl-queue-empty">No tests, surgery, medicines or spectacles on record yet.</div>';
  }
  const heading = (label, first) => `<div class="hist-field-lbl" style="margin:${first ? 4 : 12}px 0 6px">${label}</div>`;
  const card = (title, meta, badge, extra = '') => `<div class="inv-result-card"><div class="inv-result-header" style="cursor:default">
      <div><div style="font-size:11px;font-weight:700">${title}</div><div class="text-xs text-muted">${meta}</div></div>${badge}</div>${extra}</div>`;
  // Tests the doctor asked for that have not reached Diagnostics yet; the rest are listed as tests.
  const waiting = investigations.filter(inv => !inv.booking_id && inv.status !== 'cancelled');
  const parts = [];
  if (tests.length || waiting.length) {
    parts.push(heading('Tests and results', !parts.length)
      + waiting.map(inv => card(`${KSI.esc(inv.name)} · ${KSI.esc(uiLabel('eye', inv.eye))}`,
        `${KSI.esc(inv.request_no || '')} · ${KSI.esc(inv.visit_no)} · ${KSI.fmtDate(inv.requested_at)}`, uiBadge('investigationStatus', inv.status))).join('')
      + tests.map(t => clTestCard(t, data.can_view_results)).join(''));
  }
  if (surgery.length) {
    parts.push(heading('Surgery and procedures recommended', !parts.length) + surgery.map(s => card(`${KSI.esc(s.name)} · ${KSI.esc(uiLabel('eye', s.eye))}`,
      `${KSI.esc(s.recommendation_no || '')} · ${KSI.esc(uiLabel('urgency', s.urgency))} · ${KSI.esc(s.recommended_by || '')}`, uiBadge('surgeryStatus', s.status))).join(''));
  }
  if (theatre.length) {
    parts.push(heading('Theatre', !parts.length) + theatre.map(c => card(`${KSI.esc(c.procedures.join(', ') || '—')} · ${KSI.esc(uiLabel('eye', c.eye))}`,
      `${KSI.esc(c.booking_no)} · ${KSI.esc(uiDate(String(c.scheduled_for).slice(0, 10)))} · ${KSI.esc(c.surgeon)}${c.room ? ` · ${KSI.esc(c.room)}` : ''}`,
      uiBadge('theatreStatus', c.status), data.can_view_results ? clTheatreDetail(c) : '')).join(''));
  }
  if (medicines.length) {
    parts.push(heading('Medicines handed over', !parts.length) + medicines.map(m => card(
      m.items.map(i => `${KSI.esc(i.name)} × ${i.quantity}`).join(', ') || '—',
      `${KSI.esc(m.dispensing_no)} · ${KSI.fmtDateTime(m.dispensed_at)} · ${KSI.esc(m.dispensed_by)}${m.kind === 'refill' ? ' · Refill' : ''}`, '')).join(''));
  }
  if (optical.length) {
    parts.push(heading('Spectacle orders', !parts.length) + optical.map(o => card(
      `${KSI.esc(CL_OPTICAL_TYPES[o.order_type] || o.order_type)} — ${KSI.esc(o.items.join(', ') || '—')}`,
      `${KSI.esc(o.order_no)} · ordered ${KSI.fmtDate(o.ordered_at)}${o.dispensed_at ? ` · handed over ${KSI.fmtDate(o.dispensed_at)}` : ''}`,
      uiBadge('opticalStatus', o.status))).join(''));
  }
  return parts.join('');
}

/** What happened in theatre (doctors only). */
function clTheatreDetail(c) {
  const field = (label, value) => (value ? `<div class="hist-field"><div class="hist-field-lbl">${label}</div><div class="hist-field-val">${KSI.esc(value)}</div></div>` : '');
  const op = c.operation;
  if (!op && !(c.postop || []).length && !c.discharge_remarks) return '';
  return `<div style="padding:8px 12px 10px">
    ${op ? `${field('Operation', [op.started_at && `Started ${KSI.fmtDateTime(op.started_at)}`, op.ended_at && `ended ${KSI.fmtDateTime(op.ended_at)}`,
        op.anaesthesia && `${op.anaesthesia} anaesthesia`, op.as_planned === false ? 'not as planned' : ''].filter(Boolean).join(' · '))}
      ${field('Procedure done', op.actual_procedure)}${field('Team', op.team.map(t => `${CL_TEAM_ROLES[t.role] || t.role}: ${t.name}`).join(' · '))}
      ${field('Surgical notes', op.notes)}${field('Complications', op.complications)}${field('Remarks', op.remarks)}` : ''}
    ${(c.postop || []).map(p => field(`Post-op review ${p.visit_number} · ${uiDate(p.visit_date)}`, [
      `VA ${clEyePair(p.va_right, p.va_left)}`, `IOP ${clEyePair(p.iop_right, p.iop_left, ' mmHg')}`, p.wound && `wound ${p.wound}`,
      p.pain_score != null && `pain ${p.pain_score}/10`, p.complications, p.follow_up_notes].filter(Boolean).join(' · '))).join('')}
    ${c.discharged_at ? field(`Discharged ${KSI.fmtDate(c.discharged_at)}`, c.discharge_remarks || '—') : ''}
  </div>`;
}

// ── Test results (doctors) ──
async function clResultOpen(bookingId) {
  try {
    const { result: r } = await KSI.get(`clinical/results/${bookingId}`);
    document.getElementById('clr-title').textContent = `${r.name} · ${uiLabel('eye', r.eye)}`;
    document.getElementById('clr-subtitle').textContent =
      `${r.patient.full_name} · ${r.patient.reg_no} · ${r.booking_no}${r.visit_no ? ` · Visit ${r.visit_no}` : ''}`;
    const rep = r.report;
    const field = (label, value) => `<div class="hist-field"><div class="hist-field-lbl">${label}</div><div class="hist-field-val" style="white-space:pre-wrap">${KSI.esc(value || '—')}</div></div>`;
    document.getElementById('clr-body').innerHTML = rep ? `
      ${field('Result', rep.result)}${field('Comments', rep.comments)}
      <div class="triage-readout-grid" style="margin:10px 0">
        <div class="tro-item"><div class="tro-label">Reporting doctor</div><div class="tro-value normal">${KSI.esc(rep.reporting_doctor.name)}</div></div>
        <div class="tro-item"><div class="tro-label">Technician</div><div class="tro-value normal">${KSI.esc(rep.technician?.name || '—')}</div></div>
        <div class="tro-item"><div class="tro-label">Reported</div><div class="tro-value normal">${KSI.fmtDateTime(rep.completed_at)}</div></div>
        <div class="tro-item"><div class="tro-label">Referred by</div><div class="tro-value normal">${KSI.esc(r.referring || '—')}</div></div>
      </div>
      ${rep.files.length ? `<div class="hist-field-lbl">Attached files</div>${rep.files.map(f =>
        `<div class="scan-file-item" onclick="clResultFile(${r.booking_id}, ${f.id})"><span class="scan-file-name">${KSI.esc(f.name)}</span><span class="text-xs text-muted">${KSI.fmtDate(f.uploaded_at)}</span></div>`).join('')}` : ''}
      ${rep.addenda.length ? `<div class="hist-field-lbl" style="margin-top:10px">Addenda</div>${rep.addenda.map(a =>
        `<div class="cl-addendum"><strong>${KSI.esc(a.reason)}</strong><div>${KSI.esc(a.note)}</div><div class="cl-addendum-meta">${KSI.esc(a.created_by)} · ${KSI.fmtDateTime(a.created_at)}</div></div>`).join('')}` : ''}`
      : `<div class="muted-note">This test has not been reported yet (${KSI.esc(uiLabel('diagnosticStatus', r.status))}).</div>`;
    openModal('modal-cl-result');
    clResultsBell();
  } catch (error) {
    KSI.reportError(error, null, 'Cannot open the result');
  }
}

function clResultFile(bookingId, fileId) {
  KSI.openFile(`clinical/results/${bookingId}/files/${fileId}`).catch(error => showToast('Cannot open file', error.message, 'error'));
}

/** The bell: results waiting to be opened by this doctor. */
async function clResultsBell() {
  const badge = document.getElementById('notif-count');
  const list = document.getElementById('notif-list');
  if (!badge || !list || !KSI.can('ophthalmology.results.view')) return;
  try {
    const { results } = await KSI.get('clinical/results/unseen', null, { trackActivity: false });
    badge.textContent = results.length > 99 ? '99+' : String(results.length);
    badge.style.display = results.length ? '' : 'none';
    list.innerHTML = results.length ? results.map(r => `<div class="notif-item" style="cursor:pointer" onclick="clResultOpen(${r.booking_id})">
        <div class="notif-dot"></div>
        <div><div class="notif-item-text"><strong>Result ready:</strong> ${KSI.esc(r.name)} — ${KSI.esc(r.patient.full_name)} (${KSI.esc(r.patient.reg_no)})${r.mine ? '' : ' · no referring doctor'}</div>
          <div class="notif-item-time">${KSI.esc(r.reported_by)} · ${KSI.fmtDateTime(r.reported_at)}</div></div></div>`).join('')
      : '<div class="notif-item"><div class="notif-item-text">No new results.</div></div>';
  } catch {
    // the bell is informational only
  }
}

async function clFilesHtml(patientId) {
  if (!KSI.can('patients.files.view')) return '<div class="cl-queue-empty">You do not have access to scanned records.</div>';
  try {
    const { files } = await KSI.get(`patients/${patientId}/files`);
    const active = files.filter(f => !f.voided);
    if (!active.length) return '<div class="cl-queue-empty">No scanned records on file.</div>';
    return active.map(f => `<div class="scan-file-item" onclick="clOpenPatientFile(${patientId}, ${f.id})">
      <span class="scan-file-name">${KSI.esc(f.name)}</span><span class="text-xs text-muted">${KSI.fmtDate(f.uploaded_at)}</span></div>`).join('');
  } catch (error) {
    return `<div class="cl-queue-empty">${KSI.esc(error.message)}</div>`;
  }
}

function clOpenPatientFile(patientId, fileId) {
  KSI.openFile(`patients/${patientId}/files/${fileId}`).catch(error => showToast('Cannot open file', error.message, 'error'));
}

// ── Addenda ──
function clAddendaHtml(ws, stage) {
  const list = ws.addenda || [];
  const canAdd = ws.record?.status === 'completed' && KSI.can(`${stage}.record`);
  if (!list.length && !canAdd) return '';
  return `<div class="cl-addenda">
    <div class="vitals-card-title" style="display:flex;align-items:center;gap:8px">Addenda
      ${canAdd ? `<button type="button" class="btn btn-secondary btn-sm" style="margin-left:auto" onclick="clOpenAddendum('${stage}')">Add addendum</button>` : ''}</div>
    ${list.length ? list.map(a => `<div class="cl-addendum"><strong>${KSI.esc(a.reason)}</strong><div>${KSI.esc(a.note)}</div>
      <div class="cl-addendum-meta">${KSI.esc(a.created_by)} · ${KSI.fmtDateTime(a.created_at)}</div></div>`).join('')
      : '<div class="text-xs text-muted">No addenda.</div>'}
  </div>`;
}

function clOpenAddendum(stage) {
  CL_ADDENDUM.stage = stage;
  const form = document.getElementById('ad-form');
  form.reset();
  KSI.clearFieldErrors(form);
  const ws = CL_STAGES[stage].state.ws;
  document.getElementById('ad-subtitle').textContent = `${ws.visit.patient.full_name} · Visit ${ws.visit.visit_no}`;
  openModal('modal-clinical-addendum');
  setTimeout(() => form.querySelector('[data-field="reason"]').focus(), 50);
}

async function clSaveAddendum() {
  const stageObj = CL_STAGES[CL_ADDENDUM.stage];
  const form = document.getElementById('ad-form');
  KSI.clearFieldErrors(form);
  const body = clCollect(form);
  const saved = await admBusy(document.getElementById('ad-save'), async () => {
    try {
      return await KSI.post(`${stageObj.stage}/visits/${stageObj.state.ws.visit.id}/addenda`, body);
    } catch (error) {
      KSI.reportError(error, form, 'Addendum not saved');
      return null;
    }
  });
  if (!saved) return;
  closeModal('modal-clinical-addendum');
  showToast('Addendum added', 'It is shown with the record.', 'success');
  stageObj.state.ws.addenda = saved.addenda;
  document.getElementById(`${stageObj.p}-addenda`).innerHTML = clAddendaHtml(stageObj.state.ws, stageObj.stage);
}

// ── Queue + workspace controller ──
class ClinicalStage {
  /**
   * cfg: stage, prefix, label, global (the JS name used in onclick handlers), completeLabel,
   *      prepare(opts), render(ws), collect(form, ws), onPatient(ws), onClear()
   */
  constructor(cfg) {
    this.cfg = cfg;
    this.stage = cfg.stage;
    this.p = cfg.prefix;
    this.label = cfg.label;
    this.state = { view: 'queue', filter: 'all', visitId: null, ws: null, dirty: false, timer: null, queue: [], recent: [], wired: false };
    this.searchChanged = uiDebounce(() => (this.state.view === 'queue' ? this.loadQueue() : this.renderRecent()), 300);
    CL_STAGES[cfg.stage] = this;
  }

  el(suffix) {
    return document.getElementById(`${this.p}-${suffix}`);
  }

  async open() {
    if (!this.state.wired) {
      this.state.wired = true;
      this.el('form').addEventListener('input', () => { this.state.dirty = true; });
      this.el('form').addEventListener('change', event => {
        this.state.dirty = true;
        if (event.target.closest('.route-card, .routing-opt')) clSyncCards(this.el('form'));
      });
      document.querySelector(`#${this.p}-q-tabs [data-view="recent"]`).style.display = KSI.can(`${this.stage}.record`) ? '' : 'none';
    }
    try {
      const opts = await clOptions();
      this.cfg.prepare?.(opts);
      // The queue opens on today's patients; staff can widen the dates to see earlier days.
      if (!this.state.datesSet) {
        this.state.datesSet = true;
        this.el('q-from').value = opts.today;
        this.el('q-to').value = opts.today;
      }
    } catch (error) {
      showToast('Cannot load clinical lists', error.message, 'error');
      return;
    }
    this.setView(this.state.view);
    clearInterval(this.state.timer);
    this.state.timer = setInterval(() => {
      if (document.getElementById(`module-${this.stage}`).classList.contains('hidden')) {
        clearInterval(this.state.timer);
        return;
      }
      if (this.state.view === 'queue') this.loadQueue(true);
    }, 30000);
  }

  setView(view) {
    this.state.view = view;
    document.querySelectorAll(`#${this.p}-q-tabs .cl-qtab`).forEach(tab => tab.classList.toggle('active', tab.dataset.view === view));
    this.el('q-filters').style.display = view === 'queue' ? '' : 'none';
    if (view === 'queue') this.loadQueue();
    else this.loadRecent();
  }

  setFilter(filter) {
    this.state.filter = filter;
    this.loadQueue();
  }

  async loadQueue(background = false) {
    const list = this.el('q-list');
    if (!background) list.innerHTML = '<div class="cl-queue-empty">Loading…</div>';
    try {
      const data = await KSI.get(`${this.stage}/queue`, {
        search: this.el('q-search').value.trim(), from: this.el('q-from').value, to: this.el('q-to').value, filter: this.state.filter,
      }, { trackActivity: false });
      if (this.state.view !== 'queue') return;
      this.state.queue = data.queue;
      const c = data.counts;
      this.el('q-stats').innerHTML = [['all', 'Total', c.total], ['emergency', 'Emergency', c.emergency], ['draft', 'Drafts', c.drafts], ['mine', 'Mine', c.mine]]
        .map(([key, label, n]) => `<div class="tq-stat${this.state.filter === key ? ' active' : ''}" title="${label}" onclick="${this.cfg.global}.setFilter('${key}')">
          <div class="tq-stat-num">${n}</div><div class="tq-stat-lbl">${label}</div></div>`).join('');
      list.innerHTML = data.queue.length
        ? data.queue.map(item => this.queueCard(item)).join('')
        : this.el('q-search').value.trim()
          // The box only searches this queue; anyone else is found in Patient History.
          ? `<div class="cl-queue-empty">Not in this queue.${KSI.canModule('patient-history')
            ? `<br><a href="#" onclick="clOpenPatientHistory(${KSI.esc(JSON.stringify(this.el('q-search').value.trim()))}); return false;">Look them up in Patient History</a>` : ''}</div>`
          : `<div class="cl-queue-empty">${this.state.filter === 'all' ? 'No patients waiting.' : 'No patients match this filter.'}</div>`;
    } catch (error) {
      if (!background) list.innerHTML = `<div class="cl-queue-empty">${KSI.esc(error.message)}</div>`;
    }
  }

  queueCard(item) {
    const classes = ['tq-card'];
    if (item.is_emergency) classes.push('emergency');
    else if (item.has_draft) classes.push('draft');
    if (item.visit_id === this.state.visitId) classes.push('active');
    const badge = item.is_emergency ? '<div class="tq-card-badge emergency">● Emergency</div>'
      : item.has_draft ? '<div class="tq-card-badge draft">● Draft saved</div>' : '';
    const attending = item.attending
      ? `<div class="cl-attending${item.is_mine ? ' mine' : ''}">${item.is_mine ? 'You are seeing this patient' : `With ${KSI.esc(item.attending.name)}`}</div>` : '';
    return `<div class="${classes.join(' ')}" data-visit="${item.visit_id}" onclick="${this.cfg.global}.select(${item.visit_id})">
      ${badge}
      <div class="tq-card-name">${KSI.esc(item.patient.full_name)}</div>
      <div class="tq-card-reg">${KSI.esc(item.patient.reg_no)} · ${item.patient.age} yrs · ${KSI.esc(uiLabel('gender', item.patient.gender))}</div>
      <div class="cl-card-meta">
        <span class="tq-card-tag ${item.is_fast_track ? 'fast-track' : 'normal'}">${KSI.esc(item.is_fast_track ? 'Fast track' : uiLabel('subType', item.sub_type))}</span>
        ${this.stage !== 'triage' ? `<span class="oq-source ${KSI.esc(item.source.toLowerCase())}">From ${KSI.esc(item.source)}</span>` : ''}
        <span class="cl-wait" title="Waiting time">${clWaitText(item.queued_at)}</span>
      </div>
      ${attending}
    </div>`;
  }

  async loadRecent() {
    const list = this.el('q-list');
    list.innerHTML = '<div class="cl-queue-empty">Loading…</div>';
    try {
      const data = await KSI.get(`${this.stage}/recent`);
      this.state.recent = data.records;
      this.state.editHours = data.edit_window_hours;
      this.renderRecent();
    } catch (error) {
      list.innerHTML = `<div class="cl-queue-empty">${KSI.esc(error.message)}</div>`;
    }
  }

  renderRecent() {
    const q = this.el('q-search').value.trim().toLowerCase();
    const items = this.state.recent.filter(r => !q || r.patient.full_name.toLowerCase().includes(q) || r.patient.reg_no.toLowerCase().includes(q));
    this.el('q-list').innerHTML = items.length
      ? `<div class="cl-queue-empty" style="padding:6px 8px 10px;text-align:left">Records you completed in the last ${this.state.editHours} hours — you can still correct them.</div>`
        + items.map(r => `<div class="tq-card${r.visit_id === this.state.visitId ? ' active' : ''}" onclick="${this.cfg.global}.select(${r.visit_id}, true)">
          <div class="tq-card-name">${KSI.esc(r.patient.full_name)}</div>
          <div class="tq-card-reg">${KSI.esc(r.patient.reg_no)} · Visit ${KSI.esc(r.visit_no)}</div>
          <div class="cl-card-meta"><span class="cl-wait" style="margin-left:0">Completed ${KSI.fmtDateTime(r.completed_at)}</span></div>
        </div>`).join('')
      : '<div class="cl-queue-empty">No records you can still correct.</div>';
  }

  async select(visitId, fromRecent = false) {
    if (this.state.dirty && this.state.visitId && this.state.visitId !== visitId
      && !confirm('You have unsaved changes for the current patient. Discard them?')) return;
    const item = this.state.queue.find(q => q.visit_id === visitId);
    const claim = !fromRecent && KSI.can(`${this.stage}.record`) && item && (!item.attending || item.is_mine);
    try {
      const ws = claim ? await KSI.post(`${this.stage}/visits/${visitId}/claim`, {}) : await KSI.get(`${this.stage}/visits/${visitId}`);
      this.showWorkspace(ws);
    } catch (error) {
      showToast('Cannot open patient', error.message, 'error');
    }
    if (this.state.view === 'queue') this.loadQueue(true);
    else this.renderRecent();
  }

  showWorkspace(ws) {
    this.state.ws = ws;
    this.state.visitId = ws.visit.id;
    this.state.dirty = false;
    const form = this.el('form');
    this.el('empty').style.display = 'none';
    form.style.display = '';
    this.el('emg-bar').style.display = ws.visit.is_emergency ? '' : 'none';
    this.el('banner').innerHTML = clBanner(ws.visit);
    this.el('mode').innerHTML = this.modeStrip(ws);
    KSI.clearFieldErrors(form);
    this.cfg.render(ws, CL_OPTIONS.data);
    clApplyMode(form, ws.mode);
    this.el('audit').innerHTML = clAuditStrip(ws.record);
    this.el('addenda').innerHTML = clAddendaHtml(ws, this.stage);
    this.el('actions').innerHTML = this.actionsHtml(ws);
    this.cfg.onPatient?.(ws);
    document.querySelectorAll(`#${this.p}-q-list .tq-card`).forEach(card => card.classList.toggle('active', Number(card.dataset.visit) === ws.visit.id));
    this.el('form-panel').scrollTop = 0;
  }

  modeStrip(ws) {
    const g = this.cfg.global;
    const v = ws.visit;
    if (ws.mode === 'edit') {
      return `<div class="cl-mode edit"><span><strong>You are seeing this patient.</strong> Save a draft at any time and complete when finished.</span>
        <button type="button" class="btn btn-secondary btn-sm" onclick="${g}.release()">Return to queue</button></div>`;
    }
    if (ws.mode === 'correct') {
      return `<div class="cl-mode correct"><span><strong>Completed.</strong> You can correct this record until ${KSI.fmtDateTime(ws.correctable_until)}; orders and routing can no longer change. After that, add an addendum.</span></div>`;
    }
    if (ws.record?.status === 'completed') {
      return `<div class="cl-mode read"><span><strong>Completed</strong> by ${KSI.esc(ws.record.completed_by || '—')} on ${KSI.fmtDateTime(ws.record.completed_at)}. Read only${KSI.can(`${this.stage}.record`) ? ' — add an addendum to record anything further' : ''}.</span></div>`;
    }
    if (v.status === 'in_progress' && v.clinical_stage === this.stage && v.attending && !v.is_mine) {
      return `<div class="cl-mode read"><span><strong>${KSI.esc(v.attending.name)}</strong> has been seeing this patient since ${KSI.fmtDateTime(v.attending.since)}.</span>
        ${KSI.can(`${this.stage}.record`) ? `<button type="button" class="btn btn-secondary btn-sm" onclick="${g}.takeOver()">Take over</button>` : ''}</div>`;
    }
    return `<div class="cl-mode read"><span>Read only.</span></div>`;
  }

  actionsHtml(ws) {
    const g = this.cfg.global;
    if (ws.mode === 'edit') {
      return `<button type="button" class="btn btn-secondary" id="${this.p}-save-btn" onclick="${g}.save(false)">Save Draft</button>
        <button type="button" class="btn btn-primary" id="${this.p}-complete-btn" onclick="${g}.save(true)">${KSI.esc(this.cfg.completeLabel)}</button>`;
    }
    if (ws.mode === 'correct') {
      return `<button type="button" class="btn btn-primary" id="${this.p}-save-btn" onclick="${g}.save(false)">Save Correction</button>`;
    }
    return '';
  }

  async save(complete) {
    const ws = this.state.ws;
    if (!ws) return;
    const form = this.el('form');
    KSI.clearFieldErrors(form);
    const body = { ...this.cfg.collect(form, ws), row_version: ws.record?.row_version ?? null };
    const button = this.el(complete ? 'complete-btn' : 'save-btn');
    const saved = await admBusy(button, async () => {
      try {
        return complete
          ? await KSI.post(`${this.stage}/visits/${ws.visit.id}/complete`, body)
          : await KSI.put(`${this.stage}/visits/${ws.visit.id}`, body);
      } catch (error) {
        if (error.status === 422) {
          const shown = KSI.showFieldErrors(form, error.fields);
          showToast('Please check the form', shown ? 'The highlighted fields need attention.' : (Object.values(error.fields || {})[0] || error.message), 'error');
        } else {
          showToast(complete ? 'Not completed' : 'Not saved', error.message, 'error');
          if (['stale_record', 'attended_by_other', 'not_attending', 'record_locked', 'already_completed'].includes(error.code)) this.reload();
        }
        return null;
      }
    });
    if (!saved) return;
    this.state.dirty = false;
    if (saved.result.action === 'completed') {
      showToast(`${this.label} complete`, saved.result.note, 'success');
      this.clear();
    } else {
      showToast(saved.result.action === 'corrected' ? 'Correction saved' : 'Draft saved', `${ws.visit.patient.full_name} · Visit ${ws.visit.visit_no}`, 'success');
      this.showWorkspace(saved);
    }
    if (this.state.view === 'queue') this.loadQueue(true);
  }

  async release() {
    const ws = this.state.ws;
    if (!ws) return;
    if (this.state.dirty && !confirm('Unsaved changes will be lost. Return the patient to the queue anyway?')) return;
    try {
      await KSI.post(`${this.stage}/visits/${ws.visit.id}/release`, {});
      showToast('Returned to queue', `${ws.visit.patient.full_name} is waiting again.`, 'info');
      this.clear();
      this.loadQueue();
    } catch (error) {
      showToast('Not returned to queue', error.message, 'error');
    }
  }

  async takeOver() {
    const ws = this.state.ws;
    if (!ws || !confirm(`${ws.visit.attending?.name || 'Another clinician'} is seeing this patient. Take over? Their unsaved changes will be lost.`)) return;
    try {
      this.showWorkspace(await KSI.post(`${this.stage}/visits/${ws.visit.id}/claim`, { take_over: true }));
      this.loadQueue(true);
    } catch (error) {
      showToast('Cannot take over', error.message, 'error');
    }
  }

  async reload() {
    if (!this.state.visitId) return;
    try {
      this.showWorkspace(await KSI.get(`${this.stage}/visits/${this.state.visitId}`));
    } catch {
      this.clear();
    }
  }

  clear() {
    this.state.ws = null;
    this.state.visitId = null;
    this.state.dirty = false;
    this.el('form').style.display = 'none';
    this.el('empty').style.display = '';
    this.el('emg-bar').style.display = 'none';
    this.cfg.onClear?.();
    document.querySelectorAll(`#${this.p}-q-list .tq-card.active`).forEach(card => card.classList.remove('active'));
  }
}

function clToggleSidePanel(layoutId, className) {
  document.getElementById(layoutId).classList.toggle(className);
}

/** From a queue search that found nobody: open Patient History with the same search. */
function clOpenPatientHistory(query) {
  showModule('patient-history');
  const input = document.getElementById('ph-search');
  input.value = query;
  input.dispatchEvent(new Event('input'));
}
