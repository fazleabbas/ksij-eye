'use strict';
// ─── OPHTHALMOLOGY ─────────────────────────────────────────────────────────
// Consultation: examination findings per section and eye, pupils, eye
// diagrams, diagnoses and notes, prescription, investigations (billed at the
// cashier on completion), surgery recommendations and follow-up.
// The queue and the draft/complete/correct workflow live in shared/clinical.js.

const OPH_EYES = [['right', 'Right Eye'], ['left', 'Left Eye'], ['both', 'Both Eyes']];
const OPH_PUPIL = {
  shape: ['round', 'irregular', 'oval', 'keyhole'],
  light: ['brisk', 'sluggish', 'non_reactive', 'rapd'],
  consensual: ['present', 'absent'],
};
const OPH_PUPIL_LABELS = {
  round: 'Round', irregular: 'Irregular', oval: 'Oval', keyhole: 'Keyhole', brisk: 'Brisk', sluggish: 'Sluggish',
  non_reactive: 'Non-reactive', rapd: 'RAPD', present: 'Present', absent: 'Absent',
};

const OPH_FORM = {
  prepared: false,
  mode: 'read',
  findings: new Set(),
  laterality: {},
  openSections: new Set(['face', 'eyelids']),
  orders: { investigations: [], surgery: [], procedures: [] },
  submitted: { investigations: [], surgery: [], procedures: [] },
  patientId: null,
  visitId: null,
  history: null,
  tabName: 'visits',

  form() {
    return document.getElementById('oph-form');
  },

  prepare(opts) {
    if (this.prepared) return;
    document.getElementById('cl-frequencies').innerHTML = opts.frequencies.map(f => `<option value="${KSI.esc(f)}">`).join('');
    clChipsInit('oph-dx-provisional', { container: 'oph-dx-provisional', field: 'provisional_icd_ids', placeholder: '— Add ICD-10 diagnosis —', items: clIcdItems, stage: 'ophthalmology' });
    clChipsInit('oph-dx-final', { container: 'oph-dx-final', field: 'final_icd_ids', placeholder: '— Add ICD-10 diagnosis —', items: clIcdItems, stage: 'ophthalmology' });
    clRxInit('oph-rx', 'oph-rx', 'ophthalmology');
    OPH_DRAW.init(opts.diagram_colors);
    this.prepared = true;
  },

  render(ws) {
    const record = ws.record;
    const opts = CL_OPTIONS.data;
    this.mode = ws.mode;
    const known = new Set(opts.exam_sections.flatMap(s => s.findings.map(f => f.id)));
    this.findings = new Set((record?.findings || []).filter(f => ws.mode === 'read' || known.has(f.finding_id)).map(f => `${f.finding_id}|${f.eye}`));
    this.laterality = {};
    this.renderExam();
    clFill(this.form(), { ...(record || {}), ...clFollowUpData(record?.follow_up) });
    clToggleFollowUp('oph');
    clFollowUpNote('oph', record?.follow_up);
    this.toggleReferral();

    const readOnly = ws.mode === 'read';
    const locked = ws.mode !== 'edit';
    clChipsSet('oph-dx-provisional', clIcdSelected(record?.diagnoses?.provisional), readOnly);
    clChipsSet('oph-dx-final', clIcdSelected(record?.diagnoses?.final), readOnly);
    clRxSet('oph-rx', record?.prescription, locked);

    const drafts = kind => (record?.[kind] || []).filter(x => x.status === 'draft');
    this.orders.investigations = drafts('investigations').map(x => ({ price_item_id: x.price_item_id, eye: x.eye, priority: x.priority, preferred_date: x.preferred_date || '', reason: x.reason || '' }));
    // Surgery and procedures are saved as one list; the doctor sees them on separate cards.
    const isProcedure = x => opts.surgery_items.find(it => it.id === Number(x.price_item_id))?.category === 'procedure';
    const recommended = record?.surgery || [];
    const surgeryDraft = x => ({ price_item_id: x.price_item_id, eye: x.eye, urgency: x.urgency, indication: x.indication || '' });
    this.orders.surgery = drafts('surgery').filter(x => !isProcedure(x)).map(surgeryDraft);
    this.orders.procedures = drafts('surgery').filter(isProcedure).map(surgeryDraft);
    this.submitted.investigations = (record?.investigations || []).filter(x => x.status !== 'draft');
    this.submitted.surgery = recommended.filter(x => x.status !== 'draft' && !isProcedure(x));
    this.submitted.procedures = recommended.filter(x => x.status !== 'draft' && isProcedure(x));
    document.getElementById('oph-inv-card').style.display = opts.can.request_investigations || record?.investigations?.length ? '' : 'none';
    document.getElementById('oph-surg-card').style.display = opts.can.recommend_surgery || recommended.some(x => !isProcedure(x)) ? '' : 'none';
    document.getElementById('oph-proc-card').style.display = opts.can.recommend_surgery || recommended.some(isProcedure) ? '' : 'none';
    this.renderOrders('investigations');
    this.renderOrders('surgery');
    this.renderOrders('procedures');

    OPH_DRAW.load(record?.diagrams, ws.mode !== 'read');
    document.getElementById('oph-upstream').innerHTML = clTriageReadout(ws.upstream.triage) + clOptometryReadout(ws.upstream.optometry, 'Optometry referral');
    this.summary();
  },

  /** Where the patient was referred to is only asked once the box is ticked. */
  toggleReferral() {
    const on = this.form().querySelector('[data-field="referral.referred"]').checked;
    document.getElementById('oph-ref-fields').classList.toggle('hidden', !on);
  },

  // ── Examination ──
  renderExam() {
    document.getElementById('oph-exam').innerHTML = CL_OPTIONS.data.exam_sections.map(section => this.sectionHtml(section)).join('');
  },

  sectionHtml(section) {
    const lat = this.laterality[section.code] || 'both';
    const count = this.sectionCount(section);
    const isOpen = this.openSections.has(section.code);
    const disabled = this.mode === 'read' ? ' disabled' : '';
    const findings = section.findings.length ? `
      <div class="lat-toggle">${OPH_EYES.map(([eye, label]) => `<button type="button" class="lat-btn ${eye}${lat === eye ? ' active' : ''}" data-eye="${eye}" onclick="OPH_FORM.setLaterality('${section.code}', '${eye}')">${label}</button>`).join('')}</div>
      <div class="findings-grid" data-collect="skip">${section.findings.map(f => `<label class="finding-cb">
        <input type="checkbox" data-finding="${f.id}"${this.findings.has(`${f.id}|${lat}`) ? ' checked' : ''}${disabled} onchange="OPH_FORM.toggleFinding('${section.code}', ${f.id}, this.checked)">
        <span class="finding-cb-label">${KSI.esc(f.label)}</span></label>`).join('')}</div>` : '';
    return `<div class="acc-section" id="oph-sec-${section.code}">
      <div class="acc-header${count ? ' has-data' : ''}" onclick="OPH_FORM.toggleSection('${section.code}')">
        <svg class="acc-chevron${isOpen ? ' open' : ''}" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
        <span class="acc-title">${KSI.esc(section.label)}</span>
        <span class="acc-count">${count ? `${count} abnormal` : ''}</span>
      </div>
      <div class="acc-body${isOpen ? ' open' : ''}">
        ${section.code === 'pupil' ? this.pupilTable() : ''}
        ${findings}
        <div class="finding-other form-group">
          <span style="font-size:11px;color:var(--text-3);white-space:nowrap">Other:</span>
          <input type="text" maxlength="200" placeholder="Additional finding…" data-field="section_notes.${section.code}">
        </div>
      </div>
    </div>`;
  },

  pupilTable() {
    const select = (aspect, eye) => `<select class="pupil-select" data-field="pupil.${aspect}_${eye}"><option value="">—</option>${OPH_PUPIL[aspect].map(v => `<option value="${v}">${OPH_PUPIL_LABELS[v]}</option>`).join('')}</select>`;
    const row = (label, aspect) => `<tr><td>${label}</td><td>${select(aspect, 'right')}</td><td>${select(aspect, 'left')}</td></tr>`;
    return `<table class="pupil-table"><thead><tr><th>Measurement</th><th>Right Eye</th><th>Left Eye</th></tr></thead><tbody>
      <tr><td>Size (mm)</td>${['right', 'left'].map(eye => `<td><input class="pupil-input" type="number" min="0.5" max="12" step="0.5" placeholder="3.5" data-field="pupil.size_${eye}"></td>`).join('')}</tr>
      ${row('Shape', 'shape')}${row('Reaction to light', 'light')}${row('Consensual reflex', 'consensual')}
    </tbody></table>`;
  },

  sectionCount(section) {
    const abnormal = new Set(section.findings.filter(f => !f.is_normal).map(f => f.id));
    return [...this.findings].filter(key => abnormal.has(Number(key.split('|')[0]))).length;
  },

  toggleSection(code) {
    if (this.openSections.has(code)) this.openSections.delete(code);
    else this.openSections.add(code);
    const el = document.getElementById(`oph-sec-${code}`);
    el.querySelector('.acc-body').classList.toggle('open');
    el.querySelector('.acc-chevron').classList.toggle('open');
  },

  setLaterality(code, eye) {
    this.laterality[code] = eye;
    const el = document.getElementById(`oph-sec-${code}`);
    el.querySelectorAll('.lat-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.eye === eye));
    el.querySelectorAll('input[data-finding]').forEach(cb => { cb.checked = this.findings.has(`${cb.dataset.finding}|${eye}`); });
  },

  toggleFinding(code, findingId, checked) {
    const key = `${findingId}|${this.laterality[code] || 'both'}`;
    if (checked) this.findings.add(key);
    else this.findings.delete(key);
    const section = CL_OPTIONS.data.exam_sections.find(s => s.code === code);
    const count = this.sectionCount(section);
    const header = document.querySelector(`#oph-sec-${code} .acc-header`);
    header.classList.toggle('has-data', count > 0);
    header.querySelector('.acc-count').textContent = count ? `${count} abnormal` : '';
    this.summary();
    clMarkDirty('ophthalmology');
  },

  summary() {
    const byId = new Map();
    CL_OPTIONS.data.exam_sections.forEach(s => s.findings.forEach(f => byId.set(f.id, { ...f, section: s.label })));
    const eyes = { right: {}, left: {} };
    this.findings.forEach(key => {
      const [id, eye] = key.split('|');
      const finding = byId.get(Number(id));
      if (!finding || finding.is_normal) return;
      (eye === 'both' ? ['right', 'left'] : [eye]).forEach(e => (eyes[e][finding.section] ||= new Set()).add(finding.label));
    });
    const column = (eye, label) => `<div>
      <div class="summary-eye-header"><span class="summary-eye-badge ${eye}">${label}</span></div>
      ${Object.keys(eyes[eye]).length
        ? Object.entries(eyes[eye]).map(([section, labels]) => `<div class="summary-section-name">${KSI.esc(section)}</div>
          <div class="summary-finding-tags">${[...labels].map(l => `<span class="summary-finding-tag abnormal">${KSI.esc(l)}</span>`).join('')}</div>`).join('')
        : '<div class="text-xs text-muted">No abnormal findings ticked.</div>'}
    </div>`;
    document.getElementById('oph-summary').innerHTML = `<div class="vitals-card-title">Abnormal Findings</div>
      <div class="clinical-grid">${column('right', 'Right Eye (OD)')}${column('left', 'Left Eye (OS)')}</div>`;
  },

  // ── Investigations & surgery ──
  renderOrders(kind) {
    const opts = CL_OPTIONS.data;
    const isInv = kind === 'investigations';
    const prefix = { investigations: 'oph-inv', surgery: 'oph-surg', procedures: 'oph-proc' }[kind];
    const editable = this.mode === 'edit' && (isInv ? opts.can.request_investigations : opts.can.recommend_surgery);
    document.getElementById(`${prefix}-add`).style.display = editable ? '' : 'none';
    const items = isInv ? opts.investigation_items
      : opts.surgery_items.filter(it => (it.category === 'procedure') === (kind === 'procedures'));
    const dis = editable ? '' : ' disabled';

    const submitted = this.submitted[kind].map(x => `<div class="inv-result-card"><div class="inv-result-header" style="cursor:default">
      <div><div style="font-size:12px;font-weight:700">${KSI.esc(x.name)} · ${KSI.esc(uiLabel('eye', x.eye))}</div>
        <div class="text-xs text-muted">${KSI.esc(x.request_no || x.recommendation_no || '')} · ${KSI.esc(isInv ? uiLabel('priority', x.priority) : uiLabel('urgency', x.urgency))}${x.bill_no ? ` · Bill ${KSI.esc(x.bill_no)}` : ''}</div>
        <div class="text-xs">${KSI.esc(isInv ? x.reason : x.indication)}</div></div>
      ${isInv && x.result_ready
        ? `<div style="display:flex;gap:6px;align-items:center"><span class="badge badge-complete">Result ready</span>${KSI.can('ophthalmology.results.view')
          ? `<button type="button" class="btn btn-secondary btn-sm" onclick="clResultOpen(${x.booking_id})">View result</button>` : ''}</div>`
        : uiBadge(isInv ? 'investigationStatus' : 'surgeryStatus', x.status)}</div></div>`).join('');

    const rows = this.orders[kind].map((row, i) => {
      // Procedures follow the surgery rows in the saved list, so errors come back numbered that way.
      const field = name => kind === 'procedures' ? `surgery.${this.orders.surgery.length + i}.${name}` : `${kind}.${i}.${name}`;
      const update = name => `OPH_FORM.updateOrder('${kind}', ${i}, '${name}', this.value)`;
      const options = (values, current, labels) => values.map(v => `<option value="${v}"${current === v ? ' selected' : ''}>${KSI.esc(labels(v))}</option>`).join('');
      const cells = [
        [{ investigations: 'Investigation', surgery: 'Surgery', procedures: 'Procedure' }[kind], `<select class="form-select" data-field="${field('price_item_id')}" onchange="${update('price_item_id')}"${dis}>
          <option value="">Choose…</option>${items.map(it => `<option value="${it.id}"${Number(row.price_item_id) === it.id ? ' selected' : ''}>${KSI.esc(it.name)}${isInv ? ` — ${uiMoney(it.price)}` : ''}</option>`).join('')}</select>`],
        ['Eye', `<select class="form-select" data-field="${field('eye')}" onchange="${update('eye')}"${dis}><option value="">Choose…</option>${options(['right', 'left', 'both'], row.eye, v => uiLabel('eye', v))}</select>`],
        isInv
          ? ['Priority', `<select class="form-select" data-field="${field('priority')}" onchange="${update('priority')}"${dis}>${options(['routine', 'urgent'], row.priority, v => uiLabel('priority', v))}</select>`]
          : ['Urgency', `<select class="form-select" data-field="${field('urgency')}" onchange="${update('urgency')}"${dis}>${options(['elective', 'urgent', 'emergency'], row.urgency, v => uiLabel('urgency', v))}</select>`],
        isInv
          ? ['Preferred date', `<input type="date" class="form-input" data-field="${field('preferred_date')}" value="${KSI.esc(row.preferred_date || '')}" onchange="${update('preferred_date')}"${dis}>`]
          : ['', ''],
        [isInv ? 'Clinical reason' : 'Indication', `<input class="form-input" maxlength="500" data-field="${field(isInv ? 'reason' : 'indication')}" value="${KSI.esc((isInv ? row.reason : row.indication) || '')}" oninput="${update(isInv ? 'reason' : 'indication')}"${dis}>`],
      ];
      return `<div class="cl-order-row">
        ${cells.map(([label, html]) => `<div class="form-group">${label ? `<label class="form-label">${label}</label>` : ''}${html}</div>`).join('')}
        <div>${editable ? `<button type="button" class="btn btn-ghost btn-sm" style="margin-top:18px" onclick="OPH_FORM.removeOrder('${kind}', ${i})">Remove</button>` : ''}</div>
      </div>`;
    }).join('');

    document.getElementById(prefix).innerHTML = submitted + rows
      || `<div class="text-xs text-muted">${{ investigations: 'No investigations requested.', surgery: 'No surgery recommended.',
        procedures: 'No procedure recommended.' }[kind]}</div>`;
  },

  addOrder(kind) {
    this.orders[kind].push(kind === 'investigations'
      ? { price_item_id: '', eye: '', priority: 'routine', preferred_date: '', reason: '' }
      : { price_item_id: '', eye: '', urgency: 'elective', indication: '' });
    this.renderOrders(kind);
    if (kind === 'surgery') this.renderOrders('procedures');
    clMarkDirty('ophthalmology');
  },

  updateOrder(kind, index, field, value) {
    this.orders[kind][index][field] = value;
    clMarkDirty('ophthalmology');
  },

  removeOrder(kind, index) {
    this.orders[kind].splice(index, 1);
    this.renderOrders(kind);
    if (kind === 'surgery') this.renderOrders('procedures');
    clMarkDirty('ophthalmology');
  },

  collect(form) {
    const blank = v => (v === '' || v == null ? null : v);
    return {
      ...clCollect(form),
      findings: [...this.findings].map(key => {
        const [id, eye] = key.split('|');
        return { finding_id: Number(id), eye };
      }),
      provisional_icd_ids: CL_CHIPS['oph-dx-provisional'].ids,
      final_icd_ids: CL_CHIPS['oph-dx-final'].ids,
      diagrams: OPH_DRAW.value(),
      prescription: clRxValue('oph-rx'),
      investigations: this.orders.investigations.map(o => ({
        price_item_id: blank(o.price_item_id), eye: blank(o.eye), priority: o.priority, preferred_date: blank(o.preferred_date), reason: blank(o.reason),
      })),
      surgery: [...this.orders.surgery, ...this.orders.procedures]
        .map(o => ({ price_item_id: blank(o.price_item_id), eye: blank(o.eye), urgency: o.urgency, indication: blank(o.indication) })),
    };
  },

  // ── History panel ──
  onPatient(ws) {
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
    document.getElementById('oph-hist-body').innerHTML = '<div class="cl-queue-empty">Select a patient to see their history.</div>';
  },

  async historyTab(tab) {
    this.tabName = tab;
    document.querySelectorAll('#oph-right-tabs .ophtho-right-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    const body = document.getElementById('oph-hist-body');
    const patientId = this.patientId;
    if (!patientId) return;
    if (tab === 'files') {
      body.innerHTML = '<div class="cl-queue-empty">Loading…</div>';
      const html = await clFilesHtml(patientId);
      if (this.patientId === patientId && this.tabName === 'files') body.innerHTML = html;
      return;
    }
    if (!this.history) {
      const data = await clLoadHistory('oph-hist-body', patientId, this.visitId);
      if (this.patientId !== patientId) return;
      this.history = data;
    }
    if (!this.history || this.tabName !== tab) return;
    body.innerHTML = tab === 'orders' ? clOrdersHtml(this.history) : clHistoryHtml(this.history);
  },
};

// ── Eye diagrams (vector marks, saved with the consultation) ──
const OPH_DRAW = {
  canvas: null,
  ctx: null,
  current: 'iris',
  toolName: 'pen',
  color: '#EF4444',
  marks: { iris: [], cross_section: [] },
  enabled: false,
  drawing: null,

  init(colors) {
    this.canvas = document.getElementById('oph-canvas');
    this.ctx = this.canvas.getContext('2d');
    this.color = colors[0];
    document.getElementById('oph-draw-colors').innerHTML = colors.map((c, i) =>
      `<div class="dcolor${i === 0 ? ' active' : ''}" style="background:${c}" data-color="${c}" onclick="OPH_DRAW.setColor('${c}')"></div>`).join('');
    this.canvas.addEventListener('pointerdown', e => this.down(e));
    this.canvas.addEventListener('pointermove', e => this.move(e));
    this.canvas.addEventListener('pointerup', e => this.up(e));
    this.canvas.addEventListener('pointercancel', () => { this.drawing = null; this.redraw(); });
  },

  load(diagrams, enabled) {
    this.marks = { iris: [...(diagrams?.iris || [])], cross_section: [...(diagrams?.cross_section || [])] };
    this.enabled = enabled;
    this.drawing = null;
    document.getElementById('oph-draw-tools').style.display = enabled ? '' : 'none';
    this.redraw();
  },

  value() {
    return this.marks.iris.length || this.marks.cross_section.length ? this.marks : null;
  },

  point(e) {
    const rect = this.canvas.getBoundingClientRect();
    const round = v => Math.round(v * 10) / 10;
    return [
      round(Math.min(560, Math.max(0, (e.clientX - rect.left) * this.canvas.width / rect.width))),
      round(Math.min(320, Math.max(0, (e.clientY - rect.top) * this.canvas.height / rect.height))),
    ];
  },

  changed() {
    this.redraw();
    clMarkDirty('ophthalmology');
  },

  down(e) {
    if (!this.enabled) return;
    const [x, y] = this.point(e);
    if (this.toolName === 'text') {
      const text = document.getElementById('oph-draw-text').value.trim().slice(0, 60) || 'Note';
      this.marks[this.current].push({ type: 'text', color: this.color, x, y, text });
      this.changed();
      return;
    }
    if (this.toolName === 'marker') {
      this.marks[this.current].push({ type: 'marker', color: this.color, x, y });
      this.changed();
      return;
    }
    this.drawing = { start: [x, y], path: [[x, y]], end: [x, y] };
    this.canvas.setPointerCapture?.(e.pointerId);
  },

  move(e) {
    if (!this.drawing) return;
    const p = this.point(e);
    this.drawing.end = p;
    if (this.toolName === 'pen' && this.drawing.path.length < 1999) this.drawing.path.push(p);
    this.redraw(this.pending());
  },

  up() {
    if (!this.drawing) return;
    const mark = this.pending();
    this.drawing = null;
    if (mark) this.marks[this.current].push(mark);
    this.changed();
  },

  pending() {
    const d = this.drawing;
    if (!d) return null;
    const [x1, y1] = d.start;
    const [x2, y2] = d.end;
    if (this.toolName === 'pen') return { type: 'pen', color: this.color, path: d.path };
    if (this.toolName === 'arrow') return { type: 'arrow', color: this.color, x1, y1, x2, y2 };
    const round = v => Math.round(v * 10) / 10;
    return { type: 'circle', color: this.color, cx: round((x1 + x2) / 2), cy: round((y1 + y2) / 2), rx: round(Math.abs(x2 - x1) / 2), ry: round(Math.abs(y2 - y1) / 2) };
  },

  tab(name) {
    this.current = name;
    document.querySelectorAll('.diagram-tab').forEach(b => b.classList.toggle('active', b.dataset.diagram === name));
    this.redraw();
  },

  tool(name) {
    this.toolName = name;
    document.querySelectorAll('#oph-draw-tools .dtool[data-tool]').forEach(b => b.classList.toggle('active', b.dataset.tool === name));
    document.getElementById('oph-draw-text').style.display = name === 'text' ? '' : 'none';
  },

  setColor(color) {
    this.color = color;
    document.querySelectorAll('#oph-draw-colors .dcolor').forEach(d => d.classList.toggle('active', d.dataset.color === color));
  },

  undo() {
    if (this.marks[this.current].pop()) this.changed();
  },

  clear() {
    if (!this.marks[this.current].length || !confirm('Clear all marks on this diagram?')) return;
    this.marks[this.current] = [];
    this.changed();
  },

  redraw(preview = null) {
    const { ctx, canvas } = this;
    if (!ctx) return;
    this.background(ctx, canvas.width, canvas.height);
    [...this.marks[this.current], ...(preview ? [preview] : [])].forEach(mark => this.drawMark(mark));
  },

  drawMark(m) {
    const ctx = this.ctx;
    ctx.strokeStyle = m.color;
    ctx.fillStyle = m.color;
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    if (m.type === 'pen' && m.path?.length) {
      ctx.beginPath();
      ctx.moveTo(m.path[0][0], m.path[0][1]);
      m.path.forEach(([x, y]) => ctx.lineTo(x, y));
      ctx.stroke();
    } else if (m.type === 'arrow') {
      const angle = Math.atan2(m.y2 - m.y1, m.x2 - m.x1);
      ctx.beginPath(); ctx.moveTo(m.x1, m.y1); ctx.lineTo(m.x2, m.y2); ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(m.x2, m.y2);
      ctx.lineTo(m.x2 - 10 * Math.cos(angle - Math.PI / 6), m.y2 - 10 * Math.sin(angle - Math.PI / 6));
      ctx.lineTo(m.x2 - 10 * Math.cos(angle + Math.PI / 6), m.y2 - 10 * Math.sin(angle + Math.PI / 6));
      ctx.closePath(); ctx.fill();
    } else if (m.type === 'circle') {
      ctx.beginPath(); ctx.ellipse(m.cx, m.cy, Math.max(m.rx, 1), Math.max(m.ry, 1), 0, 0, Math.PI * 2); ctx.stroke();
    } else if (m.type === 'text') {
      ctx.font = '13px Inter, sans-serif';
      ctx.textAlign = 'left';
      ctx.fillText(m.text, m.x, m.y);
    } else if (m.type === 'marker') {
      ctx.beginPath(); ctx.arc(m.x, m.y, 5, 0, Math.PI * 2); ctx.fill();
    }
  },

  background(ctx, w, h) {
    ctx.setLineDash([]);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(0, 0, w, h);
    if (this.current === 'iris') {
      const cx = w / 2, cy = h / 2;
      ctx.strokeStyle = '#CBD5E1'; ctx.lineWidth = 1.5;
      ctx.beginPath(); ctx.arc(cx, cy, h * 0.42, 0, Math.PI * 2); ctx.stroke();
      ctx.fillStyle = '#E0F9F7'; ctx.beginPath(); ctx.arc(cx, cy, h * 0.38, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
      ctx.fillStyle = '#1E293B'; ctx.beginPath(); ctx.arc(cx, cy, h * 0.18, 0, Math.PI * 2); ctx.fill();
      ctx.strokeStyle = '#94A3B8'; ctx.lineWidth = 1;
      for (let i = 0; i < 12; i++) {
        const a = i * (Math.PI * 2 / 12);
        ctx.beginPath(); ctx.moveTo(cx + h * 0.18 * Math.cos(a), cy + h * 0.18 * Math.sin(a)); ctx.lineTo(cx + h * 0.36 * Math.cos(a), cy + h * 0.36 * Math.sin(a)); ctx.stroke();
      }
      ctx.strokeStyle = '#CBD5E1'; ctx.setLineDash([4, 4]);
      ctx.beginPath(); ctx.moveTo(cx - h * 0.5, cy); ctx.lineTo(cx + h * 0.5, cy); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(cx, cy - h * 0.5); ctx.lineTo(cx, cy + h * 0.5); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = '#64748B'; ctx.font = '10px Inter, sans-serif'; ctx.textAlign = 'center';
      ctx.fillText('S', cx, cy - h * 0.44); ctx.fillText('T', cx + h * 0.46, cy + 4); ctx.fillText('I', cx, cy + h * 0.48); ctx.fillText('N', cx - h * 0.46, cy + 4);
    } else {
      const cx = w / 2, cy = h / 2, ew = w * 0.35, eh = h * 0.45;
      ctx.strokeStyle = '#CBD5E1'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.ellipse(cx, cy, ew, eh, 0, 0, Math.PI * 2); ctx.stroke();
      ctx.beginPath(); ctx.ellipse(cx - ew * 0.9, cy, ew * 0.25, eh * 0.35, Math.PI * 0.1, Math.PI * 0.6, Math.PI * 1.4); ctx.strokeStyle = '#7DD3FC'; ctx.stroke();
      ctx.beginPath(); ctx.ellipse(cx - ew * 0.35, cy, ew * 0.2, eh * 0.3, 0, 0, Math.PI * 2); ctx.strokeStyle = '#A5B4FC'; ctx.stroke();
      ctx.beginPath(); ctx.moveTo(cx + ew * 0.8, cy); ctx.lineTo(cx + ew * 1.15, cy); ctx.strokeStyle = '#94A3B8'; ctx.lineWidth = 4; ctx.stroke();
      ctx.fillStyle = '#64748B'; ctx.font = '9px Inter, sans-serif'; ctx.textAlign = 'center'; ctx.lineWidth = 1;
      ctx.fillText('Cornea', cx - ew * 1.05, cy - eh * 0.5); ctx.fillText('Lens', cx - ew * 0.35, cy - eh * 0.38);
      ctx.fillText('Retina', cx + ew * 0.75, cy - eh * 0.55); ctx.fillText('Optic N.', cx + ew * 1.1, cy - 14); ctx.fillText('Macula', cx + ew * 0.6, cy + eh * 0.6);
      ctx.beginPath(); ctx.arc(cx + ew * 0.55, cy, 4, 0, Math.PI * 2); ctx.fillStyle = '#F59E0B'; ctx.fill();
    }
  },
};

const OPH = new ClinicalStage({
  stage: 'ophthalmology',
  prefix: 'oph',
  label: 'Consultation',
  global: 'OPH',
  completeLabel: 'Complete Consultation',
  prepare: opts => OPH_FORM.prepare(opts),
  render: ws => OPH_FORM.render(ws),
  collect: form => OPH_FORM.collect(form),
  onPatient: ws => OPH_FORM.onPatient(ws),
  onClear: () => OPH_FORM.onClear(),
});
