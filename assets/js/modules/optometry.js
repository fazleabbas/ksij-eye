'use strict';
// ─── OPTOMETRY ─────────────────────────────────────────────────────────────
// Vision and eye pressure, four refraction sets, lens recommendation, clinical
// assessment with ICD-10 diagnoses, prescription, follow-up and referrals.
// The queue and the draft/complete/correct workflow live in shared/clinical.js.

const OPTO_FORM = {
  prepared: false,
  patientId: null,
  visitId: null,
  history: null,
  tabName: 'visits',

  form() {
    return document.getElementById('op-form');
  },

  prepare(opts) {
    if (this.prepared) return;
    clPrepareSelects(this.form(), opts);
    document.getElementById('cl-frequencies').innerHTML = opts.frequencies.map(f => `<option value="${KSI.esc(f)}">`).join('');
    clChipsInit('op-dx-provisional', { container: 'op-dx-provisional', field: 'provisional_icd_ids', placeholder: '— Add ICD-10 diagnosis —', items: clIcdItems, stage: 'optometry' });
    clChipsInit('op-dx-final', { container: 'op-dx-final', field: 'final_icd_ids', placeholder: '— Add ICD-10 diagnosis —', items: clIcdItems, stage: 'optometry' });
    clChipsInit('op-lens', {
      container: 'op-lens', field: 'lens_item_ids', placeholder: '— Add lens type —', stage: 'optometry',
      items: () => CL_OPTIONS.data.lens_items.map(l => ({ id: l.id, label: `${l.name} — ${uiMoney(l.price)}` })),
      onChange: () => this.lensTotal(),
    });
    clRxInit('op-rx', 'op-rx', 'optometry');
    this.prepared = true;
  },

  render(ws) {
    const record = ws.record;
    const readOnly = ws.mode === 'read';
    const locked = ws.mode !== 'edit';
    clFill(this.form(), { ...(record || {}), ...clFollowUpData(record?.follow_up) });
    clToggleFollowUp('op');
    clFollowUpNote('op', record?.follow_up);
    clChipsSet('op-dx-provisional', clIcdSelected(record?.diagnoses?.provisional), readOnly);
    clChipsSet('op-dx-final', clIcdSelected(record?.diagnoses?.final), readOnly);
    clChipsSet('op-lens', (record?.lens_items || []).map(l => ({ id: l.id, label: `${l.name} — ${uiMoney(l.price)}` })), locked);
    this.lensTotal();
    this.mode = ws.mode;
    this.extra = (record?.extra_rx || []).map(x => ({
      label: x.label || '', refraction: { ...x.refraction },
      lenses: (x.lens_items || []).map(l => ({ id: l.id, label: `${l.name} — ${uiMoney(l.price)}` })),
    }));
    this.renderExtra();
    clRxSet('op-rx', record?.prescription, locked);
    // Sent back by the doctor for spectacles: show why, and the visit ends here (or at Optical).
    const fromDoctor = ws.upstream.ophthalmology;
    document.getElementById('op-upstream').innerHTML = (fromDoctor
      ? `<div class="alert alert-info" style="margin-bottom:10px"><div><strong>Referred by the doctor for spectacles.</strong>
          ${record?.reopened_at ? `This optometry record was reopened on ${KSI.fmtDateTime(record.reopened_at)} — add or change the refraction and lenses.` : ''}
          The consultation is complete, so the visit ends at Optometry or the Optical Room.</div></div>`
        + clOphthalmologyReadout(fromDoctor)
      : '') + clTriageReadout(ws.upstream.triage);
    this.doctorReferral = !!fromDoctor;
  },

  /** After the form mode is applied: a doctor's referral leaves no referral to Ophthalmology to make. */
  lockDoctorReferral() {
    const box = this.form().querySelector('[data-field="refer_ophthalmology"]');
    const note = document.getElementById('op-route-note');
    if (this.doctorReferral) box.disabled = true;
    note.textContent = this.doctorReferral
      ? 'Referred by the doctor: the consultation is complete, so the visit ends when you finish.'
      : 'Without a referral to Ophthalmology the visit is complete when you finish.';
  },

  lensTotal() {
    const prices = new Map(CL_OPTIONS.data.lens_items.map(l => [l.id, l.price]));
    const ids = CL_CHIPS['op-lens'].ids;
    const total = ids.reduce((sum, id) => sum + (prices.get(id) || 0), 0);
    document.getElementById('op-lens-total').textContent = ids.length ? `Price list total: ${uiMoney(total)}` : '';
  },

  collect(form) {
    this.keepExtraLenses();
    return {
      ...clCollect(form),
      provisional_icd_ids: CL_CHIPS['op-dx-provisional'].ids,
      final_icd_ids: CL_CHIPS['op-dx-final'].ids,
      lens_item_ids: CL_CHIPS['op-lens'].ids,
      prescription: clRxValue('op-rx'),
      extra_rx: this.extra.map(x => ({
        label: x.label || null,
        refraction: Object.fromEntries(Object.entries(x.refraction).map(([k, v]) => [k, v === '' || v == null ? null : v])),
        lens_item_ids: x.lenses.map(l => l.id),
      })),
    };
  },

  // ── Further spectacle prescriptions ──
  // Each is a final refraction with its own label and lenses (e.g. reading glasses beside bifocals).
  // Values can be corrected later like the first; adding or removing one is only possible while editing.
  extra: [],
  mode: 'edit',

  /** The lens chips are the source of truth while editing; copy them back before redrawing. */
  keepExtraLenses() {
    this.extra.forEach((x, i) => {
      const chips = CL_CHIPS[`op-lens-x${i}`];
      if (chips) x.lenses = chips.ids.map(id => ({ id, label: chips.labels[id] }));
    });
  },

  addRx() {
    this.keepExtraLenses();
    this.extra.push({ label: '', refraction: {}, lenses: [] });
    this.renderExtra();
    clMarkDirty('optometry');
  },

  removeRx(index) {
    this.keepExtraLenses();
    this.extra.splice(index, 1);
    this.renderExtra();
    clMarkDirty('optometry');
  },

  setExtra(index, key, value) {
    if (key === 'label') this.extra[index].label = value;
    else this.extra[index].refraction[key] = value;
  },

  renderExtra() {
    const opts = CL_OPTIONS.data;
    const readOnly = this.mode === 'read';
    const structural = this.mode === 'edit';
    const dis = readOnly ? ' disabled' : '';
    const rows = [['sph', 'SPH', '+0.00'], ['cyl', 'CYL', '-0.00'], ['axis', 'AXIS', '°'], ['cva', 'Corrected VA', ''], ['add', 'ADD', '+0.00']];
    const value = (x, key) => {
      const v = x.refraction[key];
      return v == null || v === '' ? '' : (['sph', 'cyl', 'add'].some(k => key.startsWith(k)) && !isNaN(v) ? clFormat(v, 'dioptre') : String(v));
    };
    document.getElementById('op-extra-rx').innerHTML = this.extra.map((x, i) => {
      const field = key => `extra_rx.${i}.refraction.${key}`;
      const input = key => `oninput="OPTO_FORM.setExtra(${i}, '${key}', this.value.trim())"`;
      const cell = (key, placeholder) => key.startsWith('cva')
        ? `<select class="refr-input" data-field="${field(key)}" onchange="OPTO_FORM.setExtra(${i}, '${key}', this.value)"${dis}>
            <option value="">—</option>${opts.va_values.map(v => `<option${x.refraction[key] === v ? ' selected' : ''}>${KSI.esc(v)}</option>`).join('')}</select>`
        : `<input class="refr-input" inputmode="decimal" data-field="${field(key)}" placeholder="${placeholder}" value="${KSI.esc(value(x, key))}" ${input(key)}${dis}>`;
      return `<div class="vitals-card">
        <div class="vitals-card-title" style="display:flex;align-items:center;gap:10px">
          <span>Prescription ${i + 2} <span class="required">*</span></span>
          <select class="form-select" data-field="extra_rx.${i}.label" style="width:auto;margin-left:auto;text-transform:none"
            onchange="OPTO_FORM.setExtra(${i}, 'label', this.value)"${dis}>
            <option value="">Label (optional)</option>${(opts.rx_labels || []).map(l => `<option${x.label === l ? ' selected' : ''}>${KSI.esc(l)}</option>`).join('')}</select>
          ${structural ? `<button type="button" class="btn btn-ghost btn-sm" onclick="OPTO_FORM.removeRx(${i})">Remove</button>` : ''}
        </div>
        <div class="table-wrap"><table class="refr-table">
          <thead><tr><th>Measurement</th><th>Right Eye (OD)</th><th>Left Eye (OS)</th></tr></thead>
          <tbody>${rows.map(([key, label, ph]) => `<tr><td>${label}</td><td>${cell(`${key}_right`, ph)}</td><td>${cell(`${key}_left`, ph)}</td></tr>`).join('')}</tbody>
        </table></div>
        <div class="refr-below">
          <div class="refr-field form-group" style="margin:0"><label>PD (mm)</label><input inputmode="decimal" data-field="${field('pd_mm')}" placeholder="63" value="${KSI.esc(value(x, 'pd_mm'))}" ${input('pd_mm')}${dis}></div>
          <div class="refr-field form-group" style="margin:0"><label>NPC</label><input maxlength="20" data-field="${field('npc')}" placeholder="5/7 cm" value="${KSI.esc(value(x, 'npc'))}" ${input('npc')}${dis}></div>
        </div>
        <div class="form-label" style="margin-top:10px">Lenses for this prescription</div>
        <div class="form-group" style="margin:0" data-field="extra_rx.${i}.lens_item_ids" id="op-lens-x${i}"></div>
      </div>`;
    }).join('');
    this.extra.forEach((x, i) => {
      clChipsInit(`op-lens-x${i}`, {
        container: `op-lens-x${i}`, field: `extra_rx.${i}.lens_item_ids`, placeholder: '— Add lens type —', stage: 'optometry',
        items: () => CL_OPTIONS.data.lens_items.map(l => ({ id: l.id, label: `${l.name} — ${uiMoney(l.price)}` })),
      });
      clChipsSet(`op-lens-x${i}`, x.lenses, !structural);
    });
    document.getElementById('op-extra-add-row').style.display = structural ? '' : 'none';
  },

  onPatient(ws) {
    this.lockDoctorReferral();
    if (this.patientId === ws.visit.patient.id && this.visitId === ws.visit.id) return;
    this.patientId = ws.visit.patient.id;
    this.visitId = ws.visit.id;
    this.history = null;
    this.historyTab(this.tabName);
  },

  onClear() {
    this.patientId = null;
    this.visitId = null;
    this.history = null;
    document.getElementById('op-hist-body').innerHTML = '<div class="cl-queue-empty">Select a patient to see their history.</div>';
  },

  async historyTab(tab) {
    this.tabName = tab;
    document.querySelectorAll('#op-hist-tabs .hist-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    const body = document.getElementById('op-hist-body');
    const patientId = this.patientId;
    if (!patientId) return;
    if (tab === 'files') {
      body.innerHTML = '<div class="cl-queue-empty">Loading…</div>';
      const html = await clFilesHtml(patientId);
      if (this.patientId === patientId && this.tabName === 'files') body.innerHTML = html;
      return;
    }
    if (!this.history) {
      const data = await clLoadHistory('op-hist-body', patientId, this.visitId);
      if (this.patientId !== patientId) return;
      this.history = data;
    }
    if (this.history && this.tabName === 'visits') body.innerHTML = clHistoryHtml(this.history);
  },
};

const OPTO = new ClinicalStage({
  stage: 'optometry',
  prefix: 'op',
  label: 'Optometry',
  global: 'OPTO',
  completeLabel: 'Complete Assessment',
  prepare: opts => OPTO_FORM.prepare(opts),
  render: ws => OPTO_FORM.render(ws),
  collect: form => OPTO_FORM.collect(form),
  onPatient: ws => OPTO_FORM.onPatient(ws),
  onClear: () => OPTO_FORM.onClear(),
});
