'use strict';
// ─── OPTICAL ROOM ─────────────────────────────────────────────────────────
// Prescriptions referred by optometry become spectacle orders. The lens comes
// from optometry; if the patient wants another lens the optician may change it
// (with a reason) while confirming, or later while the order is unpaid and not
// yet with the supplier. The prescription itself never changes. Confirming the
// order raises the cashier bill; spectacles are handed over only once it is
// settled. Discounts and cancellations are decided in Approvals.

const OPT = {
  options: null,
  incoming: [],
  active: [],
  dispensing: [],
  completed: [],
  current: null,     // order open in the detail modal
  referral: null,    // referral open on the confirm page
  rx: null,          // prescription open in the Rx modal
  frames: [],        // the Frames screen
  editingFrame: null,
  stockFrame: null,  // frame open in the stock modal
};

async function optOptions() {
  if (!OPT.options) OPT.options = await KSI.get('optical/options');
  return OPT.options;
}

function optFillSupplierFilters() {
  const suppliers = (OPT.options?.suppliers || []).map(s => [s.id, s.label]);
  ['opt-act-filter-supplier', 'opt-cmp-filter-supplier'].forEach(id => {
    const select = document.getElementById(id);
    if (!select || select.dataset.filled) return;
    admFillSelect(id, 'All suppliers', suppliers);
    select.dataset.filled = '1';
  });
}

function optFilters(prefix) {
  const value = id => document.getElementById(id)?.value || '';
  return {
    search: value(`${prefix}-search`),
    status: value(`${prefix}-filter-status`),
    supplier_id: value(`${prefix}-filter-supplier`),
    payment: value(`${prefix}-filter-payment`),
    from: value(`${prefix}-from`),
    to: value(`${prefix}-to`),
  };
}

function optMoney(amount) {
  return 'TZS ' + KSI.fmtMoney(amount);
}

function optPaymentBadge(order) {
  if (!order.bill) return '<span class="muted-note">No bill</span>';
  return uiBadge('billStatus', order.bill.status);
}

function optLensNames(order) {
  return (order.items || []).map(i => i.description).join(', ') || order.item_names || '';
}

/** The customer cell: name, file or EXT number, and what kind of order it is. */
function optCustomerCell(o) {
  const tag = o.order_type === 'frame_change' ? 'Frame change' : o.order_type === 'extra_pair' ? 'Extra pair'
    : o.customer_type === 'external' ? 'External customer' : '';
  return `<div style="font-weight:600">${KSI.esc(o.patient.full_name)}${uiFastTrackTag(o)}</div>
    <div class="muted-note">${KSI.esc(o.patient.reg_no)}${tag ? ` · ${tag}` : ''}</div>`;
}

/** Frame choices: every frame offered, with how many are free; frames with none free cannot be picked. */
function optFrameOptions(selectId, placeholder) {
  const select = document.getElementById(selectId);
  select.innerHTML = `<option value="">${KSI.esc(placeholder)}</option>` + (OPT.options?.frames || []).map(f =>
    `<option value="${f.id}"${f.available < 1 ? ' disabled' : ''}>${KSI.esc(f.label)} — ${f.available < 1 ? 'none free' : `${f.available} free`}</option>`).join('');
}

// ─── Incoming prescriptions ───────────────────────────────────────────────

async function optOpenIncoming() {
  await optOptions();
  optFillSupplierFilters();
  await optLoadIncoming();
}

async function optLoadIncoming() {
  if (!document.getElementById('opt-in-tbody')) return;
  admTableMessage('opt-in-tbody', 7, 'Loading prescriptions…');
  try {
    const { referrals } = await KSI.get('optical/incoming', optFilters('opt-in'), { trackActivity: false });
    OPT.incoming = referrals;
    optRenderIncoming();
  } catch (error) {
    admTableMessage('opt-in-tbody', 7, 'Could not load the prescriptions.');
  }
}

function optRenderIncoming() {
  const count = document.getElementById('opt-in-count');
  if (count) count.textContent = `${OPT.incoming.length} prescription${OPT.incoming.length === 1 ? '' : 's'}`;
  if (!OPT.incoming.length) {
    admTableMessage('opt-in-tbody', 7, 'Nothing waiting. Optometry sends prescriptions here when it refers a patient for spectacles.');
    return;
  }
  document.getElementById('opt-in-tbody').innerHTML = OPT.incoming.map(r => {
    const total = r.lenses.reduce((sum, l) => sum + l.unit_price, 0);
    return `<tr>
      <td>
        <div style="font-weight:600">${KSI.esc(r.patient.full_name)}${uiFastTrackTag(r)}</div>
        <div class="muted-note">${KSI.esc(r.patient.reg_no)} · ${KSI.esc(uiLabel('gender', r.patient.gender))} · ${r.patient.age} yrs</div>
      </td>
      <td>${KSI.esc(r.visit_no)}</td>
      <td>${KSI.fmtDateTime(r.prescription_date)}</td>
      <td>${KSI.esc(r.optometrist)}</td>
      <td>${r.lenses.length ? KSI.esc(optLensSummary(r.lenses)) : '<span class="muted-note">No lens recorded</span>'}
        ${r.waiting ? `<div class="muted-note" style="margin-top:4px"><span class="badge badge-pending">Waiting since ${KSI.fmtDate(r.waiting[0].since)}</span>
          Ordered earlier: ${KSI.esc((r.ordered_orders || []).join(', '))}</div>` : ''}</td>
      <td class="money">${r.lenses.length ? optMoney(total) : '—'}</td>
      <td>
        <div class="row-actions">
          <button class="action-btn action-view" onclick="optViewRx(${r.assessment_id})">View Rx</button>
          ${r.lenses.length && KSI.can('optical.orders.manage')
            ? `<button class="action-btn action-edit" onclick="optOpenConfirm(${r.assessment_id})">${r.waiting ? 'Order waiting pairs' : 'Confirm order'}</button>`
            : ''}
          ${r.waiting && KSI.can('optical.orders.manage')
            ? `<button class="action-btn action-cancel" onclick="optCloseWaiting(${r.assessment_id})">Close</button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

// ─── Prescription view ────────────────────────────────────────────────────

async function optViewRx(assessmentId) {
  const { prescription } = await KSI.get(`optical/prescriptions/${assessmentId}`);
  OPT.rx = prescription;
  document.getElementById('opt-rx-title').textContent = `Prescription — ${prescription.patient.full_name}`;
  document.getElementById('opt-rx-body').innerHTML = optRxHtml(prescription);
  openModal('modal-opt-rx');
}

/**
 * The spectacle prescriptions of a visit: the final refraction (set 1) and any further ones the
 * optometrist wrote (e.g. bifocals and reading glasses), each with its label and lenses.
 */
function optRxSets(rx) {
  if ((rx?.sets || []).length) return rx.sets;
  return [{ set_no: 1, label: null, refraction: rx?.final || {}, lenses: rx?.lenses || [] }];
}

function optSetTitle(set, index, count) {
  if (set.label) return set.label;
  return count > 1 ? `Prescription ${index + 1}` : 'Final refraction';
}

/** Lens names of a referral, grouped by prescription when there are several. */
function optLensSummary(lenses) {
  const sets = new Map();
  (lenses || []).forEach(l => {
    if (!sets.has(l.set_no)) sets.set(l.set_no, { label: l.label, names: [] });
    sets.get(l.set_no).names.push(l.name);
  });
  return [...sets.values()].map((x, i) => `${sets.size > 1 ? `${x.label || `Prescription ${i + 1}`}: ` : ''}${x.names.join(', ')}`).join(' · ');
}

function optRxHtml(rx) {
  const cell = value => (value === null || value === undefined || value === '' ? '—' : KSI.esc(String(value)));
  const sets = optRxSets(rx);
  return `
    ${uiPatientCard(rx.patient)}
    <div class="diag-report-facts">
      <div><span class="pcd-label">Visit</span><span class="pcd-value">${KSI.esc(rx.visit_no || '—')}</span></div>
      <div><span class="pcd-label">Optometrist</span><span class="pcd-value">${KSI.esc(rx.optometrist || '—')}</span></div>
      <div><span class="pcd-label">Prescribed</span><span class="pcd-value">${KSI.fmtDateTime(rx.prescription_date)}</span></div>
      <div><span class="pcd-label">VA right / left</span><span class="pcd-value">${cell(rx.va?.right)} / ${cell(rx.va?.left)}</span></div>
      <div><span class="pcd-label">IOP right / left</span><span class="pcd-value">${cell(rx.iop?.right)} / ${cell(rx.iop?.left)}</span></div>
    </div>
    ${sets.map((set, i) => {
      const r = set.refraction || {};
      return `<div class="form-label" style="margin-top:14px">${KSI.esc(optSetTitle(set, i, sets.length))}</div>
      <div class="table-wrap">
        <table class="rx-table">
          <thead><tr><th>Eye</th><th>SPH</th><th>CYL</th><th>AXIS</th><th>CVA</th><th>ADD</th></tr></thead>
          <tbody>
            <tr><td>Right (OD)</td><td>${cell(r.sph_right)}</td><td>${cell(r.cyl_right)}</td><td>${cell(r.axis_right)}</td><td>${cell(r.cva_right)}</td><td>${cell(r.add_right)}</td></tr>
            <tr><td>Left (OS)</td><td>${cell(r.sph_left)}</td><td>${cell(r.cyl_left)}</td><td>${cell(r.axis_left)}</td><td>${cell(r.cva_left)}</td><td>${cell(r.add_left)}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="muted-note">PD ${cell(r.pd_mm)} mm · NPC ${cell(r.npc)}${(set.lenses || []).length
        ? ` · Lens: ${set.lenses.map(l => `${KSI.esc(l.name)}${l.unit_price ? ` (${optMoney(l.unit_price)})` : ''}`).join(', ')}` : ''}</div>`;
    }).join('')}`;
}

/** Print: when the visit has several prescriptions, the optician picks which to print. */
function optPrintRx() {
  const rx = OPT.rx;
  if (!rx) return;
  const sets = optRxSets(rx);
  if (sets.length < 2) {
    optPrintRxSets(sets.map(x => x.set_no));
    return;
  }
  document.getElementById('opt-print-sets').innerHTML = sets.map((set, i) => `<label class="finding-cb">
    <input type="checkbox" value="${set.set_no}" checked> <span class="finding-cb-label">${KSI.esc(optSetTitle(set, i, sets.length))}</span></label>`).join('');
  openModal('modal-opt-print-choice');
}

function optConfirmPrintChoice() {
  const chosen = [...document.querySelectorAll('#opt-print-sets input:checked')].map(i => Number(i.value));
  if (!chosen.length) {
    showToast('Nothing to print', 'Tick at least one prescription.', 'error');
    return;
  }
  closeModal('modal-opt-print-choice');
  optPrintRxSets(chosen);
}

function optPrintRxSets(setNos) {
  const rx = OPT.rx;
  const all = optRxSets(rx);
  const cell = v => (v === null || v === undefined || v === '' ? '—' : String(v));
  optPrintWindow(`Prescription — ${rx.patient.reg_no}`, `
    <h1>Optometry Prescription</h1>
    <table class="facts">
      <tr><th>Patient</th><td>${KSI.esc(rx.patient.full_name)}</td><th>File no.</th><td>${KSI.esc(rx.patient.reg_no)}</td></tr>
      <tr><th>Age / Gender</th><td>${rx.patient.age} yrs · ${KSI.esc(uiLabel('gender', rx.patient.gender))}</td><th>Visit</th><td>${KSI.esc(rx.visit_no || '—')}</td></tr>
      <tr><th>Optometrist</th><td>${KSI.esc(rx.optometrist || '—')}</td><th>Date</th><td>${KSI.fmtDateTime(rx.prescription_date)}</td></tr>
    </table>
    ${all.map((set, i) => ({ set, i })).filter(({ set }) => setNos.includes(set.set_no)).map(({ set, i }) => {
      const r = set.refraction || {};
      return `<h2>${KSI.esc(all.length > 1 ? optSetTitle(set, i, all.length) : 'Refraction')}</h2>
      <table>
        <thead><tr><th>Eye</th><th>SPH</th><th>CYL</th><th>AXIS</th><th>CVA</th><th>ADD</th></tr></thead>
        <tbody>
          <tr><td>Right (OD)</td><td>${cell(r.sph_right)}</td><td>${cell(r.cyl_right)}</td><td>${cell(r.axis_right)}</td><td>${cell(r.cva_right)}</td><td>${cell(r.add_right)}</td></tr>
          <tr><td>Left (OS)</td><td>${cell(r.sph_left)}</td><td>${cell(r.cyl_left)}</td><td>${cell(r.axis_left)}</td><td>${cell(r.cva_left)}</td><td>${cell(r.add_left)}</td></tr>
        </tbody>
      </table>
      <p><strong>PD:</strong> ${cell(r.pd_mm)} mm &nbsp; <strong>NPC:</strong> ${cell(r.npc)}
        ${(set.lenses || []).length ? `&nbsp; <strong>Lens:</strong> ${set.lenses.map(l => KSI.esc(l.name)).join(', ')}` : ''}</p>`;
    }).join('')}
    <p><strong>IOP:</strong> R ${cell(rx.iop?.right)} · L ${cell(rx.iop?.left)}</p>`);
}

/** Shared print window: the clinic header, the content, then the browser print dialog. */
function optPrintWindow(title, content) {
  const win = window.open('', '_blank');
  if (!win) {
    showToast('Pop-up blocked', 'Allow pop-ups for this site to print.', 'error');
    return;
  }
  win.document.write(`<!DOCTYPE html><html><head><title>${KSI.esc(title)}</title><style>
    body { font-family: Arial, sans-serif; margin: 30px; color: #111; font-size: 13px; }
    h1 { font-size: 17px; margin: 0 0 4px; }
    h2 { font-size: 13px; border-bottom: 1px solid #eee; padding-bottom: 4px; margin: 18px 0 8px; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    th, td { border: 1px solid #ccc; padding: 6px 9px; text-align: left; }
    th { background: #f7f8fa; }
    table.facts th { width: 110px; }
    .brand { display: flex; justify-content: space-between; border-bottom: 2px solid #0D9488; padding-bottom: 10px; margin-bottom: 16px; }
    .brand div:last-child { text-align: right; color: #666; font-size: 11px; }
    .foot { margin-top: 26px; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
  </style></head><body>
    <div class="brand"><div><strong style="font-size:17px">KSI Eye Centre</strong><br>Dar es Salaam, Tanzania</div>
    <div>Printed ${new Date().toLocaleString()}</div></div>
    ${content}
    <div class="foot">Powered by Fabonic</div>
  </body></html>`);
  win.document.close();
  setTimeout(() => win.print(), 400);
}

// ─── Confirming an order ──────────────────────────────────────────────────

async function optOpenConfirm(assessmentId) {
  const referral = OPT.incoming.find(r => r.assessment_id === assessmentId);
  if (!referral) return;
  OPT.options = null;
  await optOptions();
  const { prescription } = await KSI.get(`optical/prescriptions/${assessmentId}`);
  OPT.referral = { ...referral, prescription };
  showModule('opt-create');
  document.getElementById('opt-create-rx').innerHTML = `<div class="card-head"><div class="card-title">Prescription</div></div>${optRxHtml(prescription)}`;
  // One pair per prescription, all on this order and its one bill; each pair may take a frame.
  let sets = optRxSets(prescription).filter(set => (set.lenses || []).length);
  // Partly ordered earlier: only the waiting pairs, at the lens price locked back then.
  if (referral.waiting) {
    sets = referral.waiting.map(pair => ({ ...(sets.find(s => s.set_no === pair.set_no) || {}), set_no: pair.set_no, label: pair.label, lenses: pair.lenses }));
  }
  sets.forEach(set => { set.original = set.lenses; set.now = true; });
  OPT.referral.sets = sets;
  OPT.lensEdits = {};
  document.getElementById('opt-create-order').innerHTML = `
    <div class="card-head"><div class="card-title">Order</div></div>
    <div class="diag-report-facts">
      <div><span class="pcd-label">Patient</span><span class="pcd-value">${KSI.esc(referral.patient.full_name)}</span></div>
      <div><span class="pcd-label">File no.</span><span class="pcd-value">${KSI.esc(referral.patient.reg_no)}</span></div>
      <div><span class="pcd-label">Visit</span><span class="pcd-value">${KSI.esc(referral.visit_no)}</span></div>
      <div><span class="pcd-label">Optometrist</span><span class="pcd-value">${KSI.esc(referral.optometrist)}</span></div>
    </div>
    ${referral.waiting ? `<div class="alert alert-info" style="margin-top:12px">Ordered earlier: ${KSI.esc((referral.ordered_orders || []).join(', '))}.
      These pairs were left for later; their lens price is the one locked on the first order.</div>` : ''}
    ${sets.map((set, i) => `
      <div class="form-label" style="margin-top:14px;display:flex;align-items:center;gap:10px">${KSI.esc(sets.length > 1 ? optSetTitle(set, i, sets.length) : 'Lens — chosen by the optometrist')}
        ${sets.length > 1 ? `<label class="ur-perm-check" style="margin-left:auto;font-weight:500;text-transform:none">
          <input type="checkbox" data-now="${set.set_no}" checked onchange="optTogglePairNow(${set.set_no}, this.checked)"><span>Order now</span></label>` : ''}</div>
      <div id="opt-create-lens-${set.set_no}">${optConfirmLensHtml(set)}</div>
      <div class="form-group" data-field="frames" style="margin-top:8px">
        <label class="form-label" for="opt-create-frame-${set.set_no}">Frame from us (${optMoney(OPT.options.frame_prices.patient)})</label>
        <select class="form-select" id="opt-create-frame-${set.set_no}" data-set="${set.set_no}" onchange="optConfirmTotal()"></select>
      </div>`).join('')}
    <div class="muted-note">Lens prices come from the price list. If the patient wants another lens, use "Change lens" — the prescription stays as Optometry recorded it.
      ${sets.length > 1 ? 'Untick "Order now" for a pair the patient will take later: it stays in Incoming Prescriptions, its lens price locked as of today.' : ''}</div>
    <div class="opt-price-box" style="margin-top:10px"><div id="opt-create-total-label">Total to pay</div><div class="opt-price-amount" id="opt-create-total"></div></div>
    <div class="alert alert-info" style="margin-top:12px">Confirming raises the bill and sends it to the cashier. The spectacles are handed over once it is paid.</div>
    <div class="modal-footer" style="padding:16px 0 0">
      <button class="btn btn-secondary" onclick="showModule('opt-incoming')">Cancel</button>
      <button class="btn btn-primary" onclick="optConfirmOrder(this)">Confirm order and raise the bill</button>
    </div>`;
  sets.forEach(set => optFrameOptions(`opt-create-frame-${set.set_no}`, 'No frame — the patient keeps their own'));
  optConfirmTotal();
}

function optConfirmFrames() {
  return [...document.querySelectorAll('#opt-create-order select[data-set]')]
    .filter(select => select.value && !select.disabled).map(select => ({ set_no: Number(select.dataset.set), frame_id: Number(select.value) }));
}

/** A pair the patient takes later: no lens change or frame now, and not on today's bill. */
function optTogglePairNow(setNo, now) {
  const set = OPT.referral.sets.find(x => x.set_no === setNo);
  set.now = now;
  const frame = document.getElementById(`opt-create-frame-${setNo}`);
  if (frame) frame.disabled = !now;
  const box = document.getElementById(`opt-create-lens-${setNo}`);
  if (box) box.style.opacity = now ? '' : '.45';
  box?.querySelectorAll('button').forEach(b => { b.disabled = !now; });
  optConfirmTotal();
}

async function optCloseWaiting(assessmentId) {
  const reason = prompt('Why will the waiting pairs not be ordered? (e.g. no longer wanted)');
  if (reason === null) return;
  if (reason.trim().length < 3) {
    showToast('Reason needed', 'Say why the waiting pairs are being closed.', 'error');
    return;
  }
  try {
    const { closed } = await KSI.post(`optical/referrals/${assessmentId}/close-waiting`, { reason: reason.trim() });
    showToast('Closed', `${closed} waiting pair${closed === 1 ? '' : 's'} closed.`, 'success');
    await optLoadIncoming();
  } catch (error) {
    KSI.reportError(error, null, 'Could not close the waiting pairs');
  }
}

/** A pair's lens on the confirm page: Optometry's choice, or the patient's changed choice with its reason. */
function optConfirmLensHtml(set) {
  const edit = OPT.lensEdits?.[set.set_no];
  const canChange = KSI.can('optical.orders.manage');
  return `<div class="opt-price-box">
      <div>${set.lenses.map(l => `<div>${KSI.esc(l.name)}</div>`).join('')}</div>
      <div class="opt-price-amount">${optMoney(set.lenses.reduce((sum, l) => sum + l.unit_price, 0))}</div>
    </div>
    ${edit ? `<div class="cl-note" style="margin-top:6px">Changed from Optometry's choice (${KSI.esc(set.original.map(l => l.name).join(', '))}).
      Reason: ${KSI.esc(edit.reason)} · <a href="#" onclick="event.preventDefault();optUndoLensChange(${set.set_no})">Undo</a></div>` : ''}
    ${canChange ? `<button type="button" class="btn btn-secondary btn-sm opt-lens-btn" style="margin-top:6px" onclick="optOpenLensChange('confirm', ${set.set_no})">Change lens</button>` : ''}`;
}

function optUndoLensChange(setNo) {
  const set = OPT.referral.sets.find(x => x.set_no === setNo);
  delete OPT.lensEdits[setNo];
  set.lenses = set.original;
  document.getElementById(`opt-create-lens-${setNo}`).innerHTML = optConfirmLensHtml(set);
  optConfirmTotal();
}

// ── Changing the lens (confirm page, or an unpaid order not yet with the supplier) ──
async function optOpenLensChange(mode, setNo = null, orderId = null) {
  await optOptions();
  const form = document.getElementById('opt-lens-form');
  form.reset();
  KSI.clearFieldErrors(form);
  OPT.lensChange = { mode, setNo, orderId };
  const setGroup = document.getElementById('opt-lens-set-group');
  if (mode === 'confirm') {
    const set = OPT.referral.sets.find(x => x.set_no === setNo);
    setGroup.style.display = 'none';
    document.getElementById('opt-lens-info').innerHTML = `Optometry chose: <strong>${KSI.esc(set.original.map(l => l.name).join(', '))}</strong>
      (${optMoney(set.original.reduce((sum, l) => sum + l.unit_price, 0))}).`;
    document.getElementById('opt-lens-reason').value = OPT.lensEdits[setNo]?.reason || '';
    document.getElementById('opt-lens-save').textContent = 'Use this lens';
  } else {
    const order = OPT.current;
    const pairs = [...new Map(order.items.filter(i => i.kind === 'lens').map(i => [i.set_no, i.rx_label])).entries()];
    setGroup.style.display = pairs.length > 1 ? '' : 'none';
    document.getElementById('opt-lens-set').innerHTML = pairs.map(([no, label], i) =>
      `<option value="${no ?? ''}">${KSI.esc(label || (pairs.length > 1 ? `Prescription ${i + 1}` : 'Lens'))}</option>`).join('');
    OPT.lensChange.setNo = pairs[0]?.[0] ?? null;
    document.getElementById('opt-lens-info').innerHTML = `Bill <strong>${KSI.esc(order.bill?.bill_no || '')}</strong> will be cancelled and a new bill
      raised for the new total. An approved discount does not carry over — ask for it again if needed.`;
    document.getElementById('opt-lens-save').textContent = 'Change lens and re-raise the bill';
  }
  optLensSetChanged();
  openModal('modal-opt-change-lens');
}

/** The lenses currently on the pair being changed. */
function optLensCurrentIds() {
  const { mode, setNo } = OPT.lensChange;
  if (mode === 'confirm') return OPT.referral.sets.find(x => x.set_no === setNo).lenses.map(l => l.price_item_id);
  return OPT.current.items.filter(i => i.kind === 'lens' && i.set_no === setNo).map(i => i.price_item_id);
}

function optLensSetChanged() {
  if (OPT.lensChange.mode === 'order' && document.getElementById('opt-lens-set-group').style.display !== 'none') {
    const value = document.getElementById('opt-lens-set').value;
    OPT.lensChange.setNo = value === '' ? null : Number(value);
  }
  const current = optLensCurrentIds();
  document.getElementById('opt-lens-list').innerHTML = (OPT.options.lenses || []).map(l => `<label class="finding-cb">
      <input type="checkbox" value="${l.id}"${current.includes(l.id) ? ' checked' : ''} onchange="optLensChangeTotal()">
      <span class="finding-cb-label">${KSI.esc(l.name)} — ${optMoney(l.unit_price)}</span></label>`).join('')
    || '<div class="muted-note">No lenses on the price list.</div>';
  optLensChangeTotal();
}

function optLensChosen() {
  const ids = [...document.querySelectorAll('#opt-lens-list input:checked')].map(i => Number(i.value));
  return (OPT.options.lenses || []).filter(l => ids.includes(l.id));
}

function optLensChangeTotal() {
  const chosen = optLensChosen();
  document.getElementById('opt-lens-total').innerHTML = chosen.length
    ? `<div class="opt-price-box" style="margin:8px 0 12px"><div>New lens price</div><div class="opt-price-amount">${optMoney(chosen.reduce((sum, l) => sum + l.unit_price, 0))}</div></div>`
    : '<div class="muted-note" style="margin:8px 0 12px">Choose at least one lens.</div>';
}

async function optSubmitLensChange(button) {
  const form = document.getElementById('opt-lens-form');
  KSI.clearFieldErrors(form);
  const chosen = optLensChosen();
  const reason = document.getElementById('opt-lens-reason').value.trim();
  const errors = {};
  if (!chosen.length) errors.lens_ids = 'Choose at least one lens.';
  if (reason.length < 3) errors.reason = 'Say why the lens is being changed.';
  if (Object.keys(errors).length) {
    KSI.showFieldErrors(form, errors);
    return;
  }
  const { mode, setNo, orderId } = OPT.lensChange;
  if (mode === 'confirm') {
    const set = OPT.referral.sets.find(x => x.set_no === setNo);
    const same = chosen.map(l => l.id).sort().join() === set.original.map(l => l.price_item_id).sort().join();
    if (same) {
      optUndoLensChange(setNo);
    } else {
      OPT.lensEdits[setNo] = { lens_ids: chosen.map(l => l.id), reason };
      set.lenses = chosen.map(l => ({ price_item_id: l.id, name: l.name, unit_price: l.unit_price, set_no: setNo, label: set.label }));
      document.getElementById(`opt-create-lens-${setNo}`).innerHTML = optConfirmLensHtml(set);
      optConfirmTotal();
    }
    closeModal('modal-opt-change-lens');
    return;
  }
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`optical/orders/${orderId}/lenses`, {
        set_no: setNo, lens_ids: chosen.map(l => l.id), reason, row_version: OPT.current.row_version,
      });
      closeModal('modal-opt-change-lens');
      showToast('Lens changed', `${order.order_no} — new bill ${order.bill.bill_no} sent to the cashier.`, 'success');
      await optOpenDetail(order.id, OPT.current.context);
      optRefreshLists();
    } catch (error) {
      KSI.reportError(error, form, 'Could not change the lens');
    }
  });
}

function optConfirmTotal() {
  const lenses = OPT.referral.sets.filter(set => set.now).reduce((sum, set) => sum + set.lenses.reduce((s2, l) => s2 + l.unit_price, 0), 0);
  const frames = optConfirmFrames().length * OPT.options.frame_prices.patient;
  const pairs = OPT.referral.sets.filter(set => set.now).length;
  const label = document.getElementById('opt-create-total-label');
  if (label) label.textContent = `Total to pay${pairs > 1 ? ` — ${pairs} pairs, one bill` : ''}${pairs < OPT.referral.sets.length ? ` (${OPT.referral.sets.length - pairs} for later)` : ''}`;
  document.getElementById('opt-create-total').textContent = optMoney(lenses + frames);
}

async function optConfirmOrder(button) {
  const page = document.getElementById('opt-create-order');
  KSI.clearFieldErrors(page);
  await admBusy(button, async () => {
    try {
      const setNos = OPT.referral.sets.filter(set => set.now).map(set => set.set_no);
      if (!setNos.length) {
        KSI.showFieldErrors(page, { pairs: 'Tick at least one pair to order now.' });
        showToast('Nothing to order', 'Tick at least one pair to order now.', 'error');
        return;
      }
      const lenses = Object.entries(OPT.lensEdits || {}).filter(([setNo]) => setNos.includes(Number(setNo)))
        .map(([setNo, edit]) => ({ set_no: Number(setNo), lens_ids: edit.lens_ids, reason: edit.reason }));
      const { order } = await KSI.post('optical/orders', { assessment_id: OPT.referral.assessment_id, frames: optConfirmFrames(), lenses, set_nos: setNos });
      showToast('Order raised', `${order.order_no} — bill ${order.bill.bill_no} sent to the cashier.`, 'success');
      OPT.options = null;
      showModule('opt-active');
    } catch (error) {
      KSI.reportError(error, page, 'Could not raise the order');
    }
  });
}

// ─── Active orders ────────────────────────────────────────────────────────

async function optOpenActive() {
  await optOptions();
  optFillSupplierFilters();
  await optLoadActive();
}

async function optLoadActive() {
  if (!document.getElementById('opt-act-tbody')) return;
  admTableMessage('opt-act-tbody', 8, 'Loading orders…');
  try {
    const { orders } = await KSI.get('optical/orders', { scope: 'active', ...optFilters('opt-act') }, { trackActivity: false });
    OPT.active = orders;
    optRenderActive();
  } catch (error) {
    admTableMessage('opt-act-tbody', 8, 'Could not load the orders.');
  }
}

function optRenderActive() {
  const count = document.getElementById('opt-act-count');
  if (count) count.textContent = `${OPT.active.length} order${OPT.active.length === 1 ? '' : 's'}`;
  const stats = document.getElementById('opt-act-stats');
  if (stats) {
    const by = status => OPT.active.filter(o => o.status === status).length;
    stats.innerHTML = [
      ['Active', OPT.active.length],
      ['Awaiting payment', OPT.active.filter(o => !o.is_paid).length],
      ['At the supplier', by('sent_to_supplier')],
      ['Ready to hand over', by('ready_for_dispensing')],
    ].map(([label, value]) => `<div class="stat-card"><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div>`).join('');
  }
  if (!OPT.active.length) {
    admTableMessage('opt-act-tbody', 8, 'No active orders match your filters.');
    return;
  }
  document.getElementById('opt-act-tbody').innerHTML = OPT.active.map(o => `<tr>
    <td>
      <div style="font-weight:600">${KSI.esc(o.order_no)}</div>
      <div class="muted-note">${KSI.fmtDate(o.created_at)}</div>
    </td>
    <td>${optCustomerCell(o)}</td>
    <td>${KSI.esc(o.item_names || '—')}<div class="muted-note">${optMoney(o.total_amount)}</div></td>
    <td>${uiBadge('opticalStatus', o.status)}</td>
    <td>${o.supplier ? KSI.esc(o.supplier) : '<span class="muted-note">Not assigned</span>'}</td>
    <td>${o.lens_received_date ? KSI.esc(o.lens_received_date) : '—'}</td>
    <td>${optPaymentBadge(o)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="optOpenDetail(${o.id}, 'active')">Manage</button>
      </div>
    </td>
  </tr>`).join('');
}

// ─── Order detail and tracking ────────────────────────────────────────────

async function optOpenDetail(id, context) {
  const { order } = await KSI.get(`optical/orders/${id}`);
  OPT.current = { ...order, context };
  const editable = context === 'active' && ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing'].includes(order.status)
    && KSI.can('optical.orders.manage');
  document.getElementById('opt-detail-title').textContent = `Order ${order.order_no}`;
  document.getElementById('opt-detail-subtitle').textContent = `${order.patient.full_name} · ${order.patient.reg_no}`;
  const suppliers = (OPT.options?.suppliers || []);

  const hasLens = order.items.some(i => i.kind === 'lens');
  document.getElementById('opt-detail-body').innerHTML = `
    ${hasLens ? `<div class="opt-lifecycle">${optLifecycleHtml(order)}</div>` : ''}
    <div style="display:flex;gap:8px;align-items:center;margin:12px 0">
      ${uiBadge('opticalStatus', order.status)} ${optPaymentBadge(order)}
      <span class="muted-note">${KSI.esc(order.visit_no || { external: 'External customer', frame_change: 'Frame change' }[order.order_type] || '')}</span>
    </div>
    ${order.order_type === 'frame_change' ? `<div class="cl-note" style="margin-bottom:10px">
      <div class="pcd-label">Frame change on ${KSI.esc(order.original_order_no || '')}</div>
      <div>Taken off: ${KSI.esc(order.old_frame || 'not recorded')}</div>
      <div>Reason: ${KSI.esc(order.change_reason || '—')}</div></div>` : ''}
    ${order.order_type === 'extra_pair' ? `<div class="cl-note" style="margin-bottom:10px">
      Another pair on the same prescription as ${KSI.esc(order.original_order_no || '')}.</div>` : ''}

    <div class="diag-report-facts">
      <div><span class="pcd-label">${hasLens ? 'Lens and frame' : 'Frame'}</span><span class="pcd-value">${KSI.esc(optLensNames(order))}</span></div>
      <div><span class="pcd-label">Amount</span><span class="pcd-value">${optMoney(order.total_amount)}</span></div>
      <div><span class="pcd-label">Bill</span><span class="pcd-value">${order.bill ? KSI.esc(order.bill.bill_no) : '—'}</span></div>
      ${order.patient.phone ? `<div><span class="pcd-label">Phone</span><span class="pcd-value">${KSI.esc(order.patient.phone)}</span></div>` : ''}
      ${order.assessment_id ? `<div><span class="pcd-label">Optometrist</span><span class="pcd-value">${KSI.esc(order.optometrist)}</span></div>` : ''}
      <div><span class="pcd-label">Ordered</span><span class="pcd-value">${KSI.fmtDateTime(order.created_at)}</span></div>
      ${order.dispensed_at ? `<div><span class="pcd-label">Handed over</span><span class="pcd-value">${KSI.fmtDateTime(order.dispensed_at)} · ${KSI.esc(order.dispensed_by || '')}</span></div>` : ''}
    </div>

    ${!hasLens ? '' : `<div class="form-label" style="margin-top:14px">Supplier tracking</div>
    ${editable ? `
      <div class="form-grid">
        <div class="form-group" data-field="supplier_id">
          <label class="form-label" for="opt-d-supplier">Supplier</label>
          <select class="form-select" id="opt-d-supplier">
            <option value="">Not assigned</option>
            ${suppliers.map(s => `<option value="${s.id}" ${order.supplier_id === s.id ? 'selected' : ''}>${KSI.esc(s.label)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" data-field="supplier_order_date">
          <label class="form-label" for="opt-d-supplier-date">Sent to supplier on</label>
          <div style="display:flex;gap:6px;align-items:center"><input type="date" class="form-input" id="opt-d-supplier-date" value="${order.supplier_order_date || ''}">${uiTodayButton('opt-d-supplier-date')}</div>
        </div>
      </div>
      <div class="form-group" data-field="supplier_ref">
        <label class="form-label" for="opt-d-supplier-ref">Supplier reference</label>
        <input class="form-input" id="opt-d-supplier-ref" maxlength="190" value="${KSI.esc(order.supplier_ref || '')}" placeholder="Order reference, WhatsApp note…">
      </div>
      <div class="form-label" style="margin-top:8px">Production</div>
      <div class="form-grid">
        <div class="form-group" data-field="lens_received_date">
          <label class="form-label" for="opt-d-received">Lens received on</label>
          <div style="display:flex;gap:6px;align-items:center"><input type="date" class="form-input" id="opt-d-received" value="${order.lens_received_date || ''}">${uiTodayButton('opt-d-received')}</div>
        </div>
        <div class="form-group" data-field="ready_date">
          <label class="form-label" for="opt-d-ready">Work finished on</label>
          <div style="display:flex;gap:6px;align-items:center"><input type="date" class="form-input" id="opt-d-ready" value="${order.ready_date || ''}">${uiTodayButton('opt-d-ready')}</div>
        </div>
      </div>
      ${order.is_paid ? '' : '<div class="alert alert-warning">The bill is not settled, so the order cannot become ready to hand over yet.</div>'}
    ` : `
      <div class="diag-report-facts">
        <div><span class="pcd-label">Supplier</span><span class="pcd-value">${KSI.esc(order.supplier || '—')}</span></div>
        <div><span class="pcd-label">Sent on</span><span class="pcd-value">${KSI.esc(order.supplier_order_date || '—')}</span></div>
        <div><span class="pcd-label">Reference</span><span class="pcd-value">${KSI.esc(order.supplier_ref || '—')}</span></div>
        <div><span class="pcd-label">Lens received</span><span class="pcd-value">${KSI.esc(order.lens_received_date || '—')}</span></div>
        <div><span class="pcd-label">Work finished</span><span class="pcd-value">${KSI.esc(order.ready_date || '—')}</span></div>
      </div>`}`}
    ${!hasLens && !order.is_paid && order.status !== 'cancelled' ? '<div class="alert alert-warning" style="margin-top:12px">Ready to hand over as soon as the bill is paid.</div>' : ''}

    ${(order.lens_changes || []).length ? `<div class="form-label" style="margin-top:14px">Lens changes</div>
      ${order.lens_changes.map(c => `<div class="cl-note" style="margin-bottom:6px">
        <div><strong>${KSI.esc(c.rx_label ? `${c.rx_label}: ` : '')}${KSI.esc(c.from)} → ${KSI.esc(c.to)}</strong> (${optMoney(c.from_amount)} → ${optMoney(c.to_amount)})</div>
        <div>Reason: ${KSI.esc(c.reason || '—')}</div>
        <div class="text-xs text-muted">${c.stage === 'confirm' ? 'When the order was confirmed' : `On the order${c.old_bill_no ? ` — bill ${KSI.esc(c.old_bill_no)} replaced` : ''}`}
          · ${KSI.esc(c.changed_by)} · ${KSI.fmtDateTime(c.changed_at)}</div></div>`).join('')}` : ''}

    ${order.prescription ? `<div class="form-label" style="margin-top:14px">Prescription</div>
    ${optRxHtml({ ...order.prescription, patient: order.patient, visit_no: order.visit_no, optometrist: order.optometrist, lenses: order.items.map(i => ({ name: i.description, unit_price: i.unit_price })) })}` : ''}`;

  document.getElementById('opt-detail-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-opt-detail')">Close</button>
    <button class="btn btn-secondary" onclick="optPrintOrder()">Print order</button>
    ${order.prescription ? `<button class="btn btn-secondary" onclick="optPrintOrderRx()">Print prescription</button>` : ''}
    ${editable && !order.is_paid && KSI.can('optical.discount.request') && order.bill?.status === 'pending'
      ? `<button class="btn btn-secondary" onclick="optOpenDiscount(${order.id})">Ask for a discount</button>` : ''}
    ${editable && KSI.can('optical.cancel.request')
      ? `<button class="btn btn-danger" onclick="optOpenCancel(${order.id})">Request cancellation</button>` : ''}
    ${editable && hasLens && !order.lens_change_blocked
      ? `<button class="btn btn-secondary opt-lens-btn" onclick="optOpenLensChange('order', null, ${order.id})">Change lens</button>` : ''}
    ${editable && hasLens ? `<button class="btn btn-primary" onclick="optSaveTracking(this)">Save</button>` : ''}
    ${order.status === 'dispensed' && KSI.can('optical.orders.manage')
      ? `<button class="btn btn-secondary" onclick="optOpenChangeFrame(${order.id})">Change frame</button>` : ''}
    ${order.assessment_id && order.customer_type === 'patient' && !['cancelled', 'cancellation_requested'].includes(order.status)
      && KSI.can('optical.orders.manage')
      ? `<button class="btn btn-secondary" onclick="optOpenExtraPair(${order.id})">Add another pair</button>` : ''}
    ${order.status === 'ready_for_dispensing' && KSI.can('optical.dispense')
      ? `<button class="btn btn-primary" onclick="optOpenDispense(${order.id})">Hand over</button>` : ''}`;

  openModal('modal-opt-detail');
}

function optLifecycleHtml(order) {
  const steps = [
    ['ordered', 'Ordered'],
    ['sent_to_supplier', 'At supplier'],
    ['lens_received', 'Lens received'],
    ['ready_for_dispensing', 'Ready'],
    ['dispensed', 'Handed over'],
  ];
  const order_ = ['ordered', 'sent_to_supplier', 'lens_received', 'ready_for_dispensing', 'dispensed'];
  const current = order_.indexOf(order.status);
  return steps.map(([key, label], index) => {
    const done = current > index;
    const active = current === index;
    return `${index ? `<div class="opt-lc-line ${done ? 'done' : ''}"></div>` : ''}
      <div class="opt-lc-step">
        <div class="opt-lc-dot ${done ? 'done' : active ? 'active' : ''}">${done ? '✓' : index + 1}</div>
        <div class="opt-lc-label">${label}</div>
      </div>`;
  }).join('');
}

async function optSaveTracking(button) {
  const modal = document.getElementById('modal-opt-detail');
  KSI.clearFieldErrors(modal);
  const value = id => document.getElementById(id)?.value || '';
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.put(`optical/orders/${OPT.current.id}`, {
        supplier_id: value('opt-d-supplier'),
        supplier_order_date: value('opt-d-supplier-date'),
        supplier_ref: value('opt-d-supplier-ref'),
        lens_received_date: value('opt-d-received'),
        ready_date: value('opt-d-ready'),
        row_version: OPT.current.row_version,
      });
      closeModal('modal-opt-detail');
      showToast('Saved', `${order.order_no} — ${uiLabel('opticalStatus', order.status)}`, 'success');
      await optRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not save the order');
    }
  });
}

async function optRefreshLists() {
  if (!document.getElementById('module-opt-active').classList.contains('hidden')) await optLoadActive();
  if (!document.getElementById('module-opt-dispensing').classList.contains('hidden')) await optLoadDispensing();
  if (!document.getElementById('module-opt-completed').classList.contains('hidden')) await optLoadCompleted();
}

/** The prescription behind an order, printed the same way as from Incoming Prescriptions. */
function optPrintOrderRx() {
  const o = OPT.current;
  if (!o?.prescription) return;
  OPT.rx = { ...o.prescription, patient: o.patient, visit_no: o.visit_no, optometrist: o.optometrist };
  optPrintRx();
}

function optPrintOrder() {
  const o = OPT.current;
  if (!o) return;
  optPrintWindow(`Spectacle order ${o.order_no}`, `
    <h1>Spectacle Order</h1>
    <table class="facts">
      <tr><th>Order no.</th><td>${KSI.esc(o.order_no)}</td><th>Status</th><td>${KSI.esc(uiLabel('opticalStatus', o.status))}</td></tr>
      <tr><th>Patient</th><td>${KSI.esc(o.patient.full_name)}</td><th>File no.</th><td>${KSI.esc(o.patient.reg_no)}</td></tr>
      <tr><th>Visit</th><td>${KSI.esc(o.visit_no || '—')}</td><th>${o.assessment_id ? 'Optometrist' : 'Order'}</th><td>${KSI.esc(o.assessment_id ? o.optometrist : { external: 'External customer', frame_change: `Frame change on ${o.original_order_no}` }[o.order_type] || '—')}</td></tr>
      <tr><th>Lens</th><td>${KSI.esc(optLensNames(o))}</td><th>Amount</th><td>${optMoney(o.total_amount)}</td></tr>
      <tr><th>Supplier</th><td>${KSI.esc(o.supplier || '—')}</td><th>Sent on</th><td>${KSI.esc(o.supplier_order_date || '—')}</td></tr>
      <tr><th>Lens received</th><td>${KSI.esc(o.lens_received_date || '—')}</td><th>Work finished</th><td>${KSI.esc(o.ready_date || '—')}</td></tr>
      <tr><th>Bill</th><td>${o.bill ? KSI.esc(o.bill.bill_no) : '—'}</td><th>Payment</th><td>${o.bill ? KSI.esc(uiLabel('billStatus', o.bill.status)) : '—'}</td></tr>
    </table>
    ${o.dispensed_at ? `<p><strong>Handed over:</strong> ${KSI.fmtDateTime(o.dispensed_at)} by ${KSI.esc(o.dispensed_by || '')}</p>` : ''}
    <p style="margin-top:30px">Received by: ______________________________ &nbsp;&nbsp; Date: ______________</p>`);
}

// ─── Dispensing ───────────────────────────────────────────────────────────

async function optOpenDispensing() {
  await optOptions();
  await optLoadDispensing();
}

async function optLoadDispensing() {
  if (!document.getElementById('opt-disp-tbody')) return;
  admTableMessage('opt-disp-tbody', 7, 'Loading orders…');
  try {
    const { orders } = await KSI.get('optical/orders', { scope: 'dispensing', ...optFilters('opt-disp') }, { trackActivity: false });
    OPT.dispensing = orders;
    optRenderDispensing();
  } catch (error) {
    admTableMessage('opt-disp-tbody', 7, 'Could not load the orders.');
  }
}

function optRenderDispensing() {
  const count = document.getElementById('opt-disp-count');
  if (count) count.textContent = `${OPT.dispensing.length} order${OPT.dispensing.length === 1 ? '' : 's'}`;
  if (!OPT.dispensing.length) {
    admTableMessage('opt-disp-tbody', 7, 'Nothing ready. An order appears here once the lens is in, the work is finished and the bill is settled.');
    return;
  }
  document.getElementById('opt-disp-tbody').innerHTML = OPT.dispensing.map(o => `<tr>
    <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div></td>
    <td>${optCustomerCell(o)}</td>
    <td>${KSI.esc(o.patient.phone || '—')}</td>
    <td>${KSI.esc(optLensNames(o) || '—')}</td>
    <td class="money">${optMoney(o.total_amount)}</td>
    <td>${optPaymentBadge(o)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="optOpenDetail(${o.id}, 'dispensing')">View</button>
        ${KSI.can('optical.dispense') ? `<button class="action-btn action-edit" onclick="optOpenDispense(${o.id})">Hand over</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

async function optOpenDispense(id) {
  const order = OPT.dispensing.find(o => o.id === id) || OPT.current
    || (await KSI.get(`optical/orders/${id}`)).order;
  OPT.current = order;
  document.getElementById('opt-dispense-info').innerHTML = `
    <div><strong>${KSI.esc(order.patient.full_name)}</strong> · ${KSI.esc(order.patient.reg_no)}</div>
    <div class="muted-note">${KSI.esc(order.order_no)} · ${KSI.esc(optLensNames(order))} · ${optMoney(order.total_amount)}</div>`;
  openModal('modal-opt-dispense');
}

async function optConfirmDispense(button) {
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`optical/orders/${OPT.current.id}/dispense`, {});
      closeModal('modal-opt-dispense');
      closeModal('modal-opt-detail');
      showToast('Handed over', `${order.order_no} — ${order.patient.full_name}`, 'success');
      await optRefreshLists();
    } catch (error) {
      KSI.reportError(error, null, 'Could not hand over the spectacles');
    }
  });
}

// ─── Discount and cancellation ────────────────────────────────────────────

async function optOpenDiscount(id) {
  await optOptions();
  const order = OPT.current?.id === id ? OPT.current : (await KSI.get(`optical/orders/${id}`)).order;
  OPT.current = order;
  const modal = document.getElementById('modal-opt-discount');
  KSI.clearFieldErrors(modal);
  document.getElementById('opt-discount-info').innerHTML = `
    <div><strong>${KSI.esc(order.patient.full_name)}</strong> · ${KSI.esc(order.order_no)}</div>
    <div class="muted-note">Bill ${KSI.esc(order.bill?.bill_no || '—')} · ${optMoney(order.total_amount)}</div>`;
  admFillSelect('opt-discount-type', 'Select a type', (OPT.options.discount_types || []).map(t => [t, t]));
  document.getElementById('opt-discount-amount').value = '';
  document.getElementById('opt-discount-amount').max = order.total_amount;
  document.getElementById('opt-discount-sponsor').value = '';
  document.getElementById('opt-discount-remarks').value = '';
  uiDocsMount('opt-discount-doc', 'opt-discount-docs', order.patient?.id);
  openModal('modal-opt-discount');
}

async function optSubmitDiscount(button) {
  const modal = document.getElementById('modal-opt-discount');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`optical/orders/${OPT.current.id}/discount`, {
        amount: Number(document.getElementById('opt-discount-amount').value) || 0,
        discount_type: document.getElementById('opt-discount-type').value,
        sponsor: document.getElementById('opt-discount-sponsor').value,
        remarks: document.getElementById('opt-discount-remarks').value,
      });
      const failed = await uiDocsUpload('opt-discount-doc', order.approval_id);
      closeModal('modal-opt-discount');
      closeModal('modal-opt-detail');
      showToast('Sent for approval', `${order.order_no} — an administrator will decide.`
        + (failed ? ` ${failed} document(s) could not be attached.` : ''), failed ? 'error' : 'info');
      await optRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not ask for the discount');
    }
  });
}

async function optOpenCancel(id) {
  const order = OPT.current?.id === id ? OPT.current : (await KSI.get(`optical/orders/${id}`)).order;
  OPT.current = order;
  const modal = document.getElementById('modal-opt-cancel');
  KSI.clearFieldErrors(modal);
  document.getElementById('opt-cancel-info').innerHTML = `
    <div><strong>${KSI.esc(order.patient.full_name)}</strong> · ${KSI.esc(order.order_no)}</div>
    <div class="muted-note">${KSI.esc(optLensNames(order))} · ${optMoney(order.total_amount)}</div>`;
  document.getElementById('opt-cancel-reason').value = '';
  openModal('modal-opt-cancel');
}

async function optSubmitCancel(button) {
  const modal = document.getElementById('modal-opt-cancel');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`optical/orders/${OPT.current.id}/cancel`, {
        reason: document.getElementById('opt-cancel-reason').value,
      });
      closeModal('modal-opt-cancel');
      closeModal('modal-opt-detail');
      showToast('Sent for approval', `${order.order_no} will be cancelled once an administrator agrees.`, 'info');
      await optRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not request the cancellation');
    }
  });
}

// ─── Completed orders ─────────────────────────────────────────────────────

async function optOpenCompleted() {
  await optOptions();
  optFillSupplierFilters();
  await optLoadCompleted();
}

async function optLoadCompleted() {
  if (!document.getElementById('opt-cmp-tbody')) return;
  admTableMessage('opt-cmp-tbody', 8, 'Loading orders…');
  try {
    const { orders } = await KSI.get('optical/orders', { scope: 'completed', ...optFilters('opt-cmp') }, { trackActivity: false });
    OPT.completed = orders;
    optRenderCompleted();
  } catch (error) {
    admTableMessage('opt-cmp-tbody', 8, 'Could not load the orders.');
  }
}

function optRenderCompleted() {
  const count = document.getElementById('opt-cmp-count');
  if (count) count.textContent = `${OPT.completed.length} order${OPT.completed.length === 1 ? '' : 's'}`;
  if (!OPT.completed.length) {
    admTableMessage('opt-cmp-tbody', 8, 'No completed orders match your filters.');
    return;
  }
  document.getElementById('opt-cmp-tbody').innerHTML = OPT.completed.map(o => `<tr>
    <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div></td>
    <td>${optCustomerCell(o)}</td>
    <td>${KSI.esc(optLensNames(o) || '—')}</td>
    <td>${KSI.esc(o.supplier || '—')}</td>
    <td>${o.dispensed_at ? KSI.fmtDateTime(o.dispensed_at) : '—'}</td>
    <td class="money">${optMoney(o.total_amount)}</td>
    <td>${uiBadge('opticalStatus', o.status)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="optOpenDetail(${o.id}, 'completed')">View</button>
      </div>
    </td>
  </tr>`).join('');
}

// ─── External customer ────────────────────────────────────────────────────
// Someone who is not a patient buys a frame, lenses or both; the bill goes to the cashier.

async function optOpenExternal() {
  OPT.options = null;
  await optOptions();
  optResetExternal();
}

function optResetExternal() {
  const form = document.getElementById('opt-ext-form');
  form.reset();
  KSI.clearFieldErrors(form);
  optFrameOptions('opt-ext-frame', 'No frame');
  document.getElementById('opt-ext-lenses').innerHTML = (OPT.options.lenses || []).map(l => `<label class="finding-cb">
      <input type="checkbox" value="${l.id}" onchange="optExternalTotal()">
      <span class="finding-cb-label">${KSI.esc(l.name)} — ${optMoney(l.unit_price)}</span></label>`).join('')
    || '<div class="muted-note">No lenses on the price list.</div>';
  optExternalTotal();
}

function optExternalChoice() {
  const frameId = document.getElementById('opt-ext-frame').value;
  const lensIds = [...document.querySelectorAll('#opt-ext-lenses input:checked')].map(i => Number(i.value));
  return {
    frame: (OPT.options.frames || []).find(f => String(f.id) === frameId) || null,
    lenses: (OPT.options.lenses || []).filter(l => lensIds.includes(l.id)),
  };
}

function optExternalTotal() {
  const { frame, lenses } = optExternalChoice();
  const framePrice = OPT.options.frame_prices.external;
  const lines = [...(frame ? [[`Frame: ${frame.label}`, framePrice]] : []), ...lenses.map(l => [l.name, l.unit_price])];
  const total = lines.reduce((sum, [, price]) => sum + price, 0);
  document.getElementById('opt-ext-summary').innerHTML = lines.length
    ? `${lines.map(([name, price]) => `<div class="dcb-row"><span class="dcb-label">${KSI.esc(name)}</span><span>${optMoney(price)}</span></div>`).join('')}
       <div class="opt-price-box" style="margin-top:10px"><div>Total</div><div class="opt-price-amount">${optMoney(total)}</div></div>`
    : `<div class="muted-note">Choose a frame (${optMoney(framePrice)}) and/or lenses.</div>`;
}

async function optSubmitExternal(button) {
  const form = document.getElementById('opt-ext-form');
  KSI.clearFieldErrors(form);
  const value = id => document.getElementById(id).value.trim();
  const { frame, lenses } = optExternalChoice();
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post('optical/external', {
        name: value('opt-ext-name'), phone: value('opt-ext-phone'), gender: value('opt-ext-gender') || null,
        age: value('opt-ext-age') || null, frame_id: frame?.id || null, lens_ids: lenses.map(l => l.id),
      });
      showToast('Bill raised', `${order.order_no} for ${order.patient.full_name} — ${optMoney(order.total_amount)} at the cashier.`, 'success');
      OPT.options = null;
      showModule('opt-active');
    } catch (error) {
      KSI.reportError(error, form, 'Could not raise the bill');
    }
  });
}

// ─── Frames ───────────────────────────────────────────────────────────────

async function optOpenFrames() {
  document.getElementById('opt-frame-add').style.display = KSI.can('optical.frames.manage') ? '' : 'none';
  await optLoadFrames();
}

async function optLoadFrames() {
  if (!document.getElementById('opt-frames-tbody')) return;
  admTableMessage('opt-frames-tbody', 7, 'Loading frames…');
  try {
    const inactive = document.getElementById('opt-frames-inactive').checked ? '1' : '0';
    OPT.frames = (await KSI.get('optical/frames', { include_inactive: inactive }, { trackActivity: false })).frames;
    optRenderFrames();
  } catch (error) {
    admTableMessage('opt-frames-tbody', 7, 'Could not load the frames.');
  }
}

function optRenderFrames() {
  const needle = (document.getElementById('opt-frames-search').value || '').toLowerCase();
  const all = OPT.frames || [];
  const frames = all.filter(f => !needle || `${f.label} ${f.code}`.toLowerCase().includes(needle));
  document.getElementById('opt-frames-count').textContent = `${frames.length} frame${frames.length === 1 ? '' : 's'}`;
  document.getElementById('opt-frames-stats').innerHTML = [
    ['Frames', all.length],
    ['In stock', all.reduce((sum, f) => sum + f.quantity, 0)],
    ['Set aside for orders', all.reduce((sum, f) => sum + f.set_aside, 0)],
    ['Low or out', all.filter(f => f.available <= f.reorder_level).length],
  ].map(([label, value]) => `<div class="stat-card"><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div>`).join('');
  if (!frames.length) {
    admTableMessage('opt-frames-tbody', 7, all.length ? 'No frame matches your search.' : 'No frames yet. Add the frames the Optical Room sells.');
    return;
  }
  const manage = KSI.can('optical.frames.manage');
  document.getElementById('opt-frames-tbody').innerHTML = frames.map(f => `<tr>
    <td><div style="font-weight:600">${KSI.esc(f.name)}</div>
      <div class="muted-note">${KSI.esc([f.brand, f.colour, f.size].filter(Boolean).join(' · ') || '—')}</div></td>
    <td>${KSI.esc(f.code)}</td>
    <td>${f.quantity}</td>
    <td>${f.set_aside}</td>
    <td><strong style="color:${f.available <= f.reorder_level ? 'var(--red)' : 'inherit'}">${f.available}</strong></td>
    <td>${f.is_active ? 'Offered' : '<span class="muted-note">No longer offered</span>'}</td>
    <td><div class="row-actions">
      <button class="action-btn action-view" onclick="optOpenFrameStock(${f.id})">Stock</button>
      ${manage ? `<button class="action-btn action-edit" onclick="optOpenFrame(${f.id})">Edit</button>` : ''}
    </div></td>
  </tr>`).join('');
}

function optOpenFrame(id = null) {
  const frame = id ? (OPT.frames || []).find(f => f.id === id) : null;
  OPT.editingFrame = frame;
  const form = document.getElementById('opt-frame-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('opt-frame-title').textContent = frame ? `Edit ${frame.name}` : 'Add frame';
  document.getElementById('opt-frame-name').value = frame?.name || '';
  document.getElementById('opt-frame-brand').value = frame?.brand || '';
  document.getElementById('opt-frame-colour').value = frame?.colour || '';
  document.getElementById('opt-frame-size').value = frame?.size || '';
  document.getElementById('opt-frame-reorder').value = frame?.reorder_level ?? 0;
  document.getElementById('opt-frame-active-row').style.display = frame ? '' : 'none';
  document.getElementById('opt-frame-active').checked = frame ? frame.is_active : true;
  openModal('modal-opt-frame');
}

async function optSaveFrame(button) {
  const form = document.getElementById('opt-frame-form');
  KSI.clearFieldErrors(form);
  const value = id => document.getElementById(id).value.trim();
  const body = {
    name: value('opt-frame-name'), brand: value('opt-frame-brand'), colour: value('opt-frame-colour'),
    size: value('opt-frame-size'), reorder_level: value('opt-frame-reorder') || 0,
  };
  await admBusy(button, async () => {
    try {
      const frame = OPT.editingFrame;
      const { frame: saved } = frame
        ? await KSI.put(`optical/frames/${frame.id}`, { ...body, is_active: document.getElementById('opt-frame-active').checked, row_version: frame.row_version })
        : await KSI.post('optical/frames', body);
      closeModal('modal-opt-frame');
      showToast('Saved', saved.label, 'success');
      OPT.options = null;
      await optLoadFrames();
    } catch (error) {
      KSI.reportError(error, form, 'Could not save the frame');
    }
  });
}

const OPT_FRAME_REASONS = { received: 'From the store', sold: 'Handed over', adjusted: 'Count corrected', returned: 'Returned' };

async function optOpenFrameStock(id) {
  const { frame, movements } = await KSI.get(`optical/frames/${id}/movements`);
  OPT.stockFrame = frame;
  document.getElementById('opt-frame-stock-title').textContent = frame.label;
  const manage = KSI.can('optical.frames.manage');
  const rows = movements.map(m => `<tr><td>${KSI.fmtDateTime(m.created_at)}</td><td>${m.change_qty > 0 ? '+' : ''}${m.change_qty}</td>
      <td>${m.balance_after}</td><td>${KSI.esc(OPT_FRAME_REASONS[m.reason] || m.reason)}${m.note ? `<div class="muted-note">${KSI.esc(m.note)}</div>` : ''}</td>
      <td>${KSI.esc(m.reference || '—')}</td><td>${KSI.esc(m.created_by)}</td></tr>`).join('');
  document.getElementById('opt-frame-stock-body').innerHTML = `
    <div class="diag-report-facts">
      <div><span class="pcd-label">In stock</span><span class="pcd-value">${frame.quantity}</span></div>
      <div><span class="pcd-label">Set aside for orders</span><span class="pcd-value">${frame.set_aside}</span></div>
      <div><span class="pcd-label">Free to sell</span><span class="pcd-value">${frame.available}</span></div>
    </div>
    ${manage ? `<form id="opt-frame-adjust-form" novalidate onsubmit="event.preventDefault()" class="card" style="margin:12px 0;padding:12px">
      <div class="form-label">Correct the count</div>
      <div class="form-grid">
        <div class="form-group" data-field="change"><label class="form-label" for="opt-adj-change">Add (+) or take off (−)</label>
          <input type="number" class="form-input" id="opt-adj-change" step="1"></div>
        <div class="form-group" data-field="reason"><label class="form-label" for="opt-adj-reason">Why</label>
          <select class="form-select" id="opt-adj-reason"><option value="adjusted">Count corrected</option><option value="returned">Returned</option></select></div>
      </div>
      <div class="form-group" data-field="note"><label class="form-label" for="opt-adj-note">Note <span class="required">*</span></label>
        <input class="form-input" id="opt-adj-note" maxlength="190" placeholder="e.g. Counted on the shelf"></div>
      <div class="muted-note">New frames come from the store: ask the store to issue them to the Optical Room.</div>
    </form>` : ''}
    <div class="form-label" style="margin-top:12px">Recent changes</div>
    <div class="table-wrap"><table>
      <thead><tr><th>When</th><th>Change</th><th>Balance</th><th>Why</th><th>Reference</th><th>By</th></tr></thead>
      <tbody>${rows || '<tr class="table-state"><td colspan="6">No changes yet.</td></tr>'}</tbody>
    </table></div>`;
  document.getElementById('opt-frame-stock-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-opt-frame-stock')">Close</button>
    ${manage ? '<button class="btn btn-primary" onclick="optSaveFrameAdjust(this)">Save correction</button>' : ''}`;
  openModal('modal-opt-frame-stock');
}

async function optSaveFrameAdjust(button) {
  const form = document.getElementById('opt-frame-adjust-form');
  KSI.clearFieldErrors(form);
  await admBusy(button, async () => {
    try {
      const { frame } = await KSI.post(`optical/frames/${OPT.stockFrame.id}/adjust`, {
        change: Number(document.getElementById('opt-adj-change').value) || 0,
        reason: document.getElementById('opt-adj-reason').value,
        note: document.getElementById('opt-adj-note').value.trim(),
      });
      showToast('Count corrected', `${frame.label}: ${frame.quantity} in stock`, 'success');
      OPT.options = null;
      await optOpenFrameStock(frame.id);
      await optLoadFrames();
    } catch (error) {
      KSI.reportError(error, form, 'Could not correct the count');
    }
  });
}

// ─── Change frame ─────────────────────────────────────────────────────────
// Spectacles already handed over get a new frame; the lenses stay.

async function optOpenChangeFrame(id) {
  OPT.options = null;
  await optOptions();
  const order = OPT.current?.id === id ? OPT.current : (await KSI.get(`optical/orders/${id}`)).order;
  OPT.current = order;
  const form = document.getElementById('opt-change-form');
  form.reset();
  KSI.clearFieldErrors(form);
  const price = order.customer_type === 'external' ? OPT.options.frame_prices.external : OPT.options.frame_prices.patient;
  const oldFrame = (order.items || []).filter(i => i.kind === 'frame').map(i => i.description.replace(/^Frame: /, '')).pop() || '';
  document.getElementById('opt-change-info').innerHTML = `
    <div><strong>${KSI.esc(order.patient.full_name)}</strong> · ${KSI.esc(order.patient.reg_no)} · ${KSI.esc(order.order_no)}</div>
    <div class="muted-note">New frame charged at ${optMoney(price)}.</div>`;
  optFrameOptions('opt-change-frame', 'Choose the new frame');
  document.getElementById('opt-change-old').value = oldFrame;
  openModal('modal-opt-change-frame');
}

async function optSubmitChangeFrame(button) {
  const form = document.getElementById('opt-change-form');
  KSI.clearFieldErrors(form);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`optical/orders/${OPT.current.id}/change-frame`, {
        frame_id: Number(document.getElementById('opt-change-frame').value) || null,
        old_frame: document.getElementById('opt-change-old').value.trim(),
        reason: document.getElementById('opt-change-reason').value.trim(),
      });
      closeModal('modal-opt-change-frame');
      closeModal('modal-opt-detail');
      showToast('Bill raised', `${order.order_no} — ${optMoney(order.total_amount)} at the cashier.`, 'success');
      OPT.options = null;
      await optRefreshLists();
    } catch (error) {
      KSI.reportError(error, form, 'Could not change the frame');
    }
  });
}

// ─── Another pair on the same prescription ────────────────────────────────

async function optOpenExtraPair(id) {
  OPT.options = null;
  await optOptions();
  const order = OPT.current?.id === id && OPT.current.items ? OPT.current : (await KSI.get(`optical/orders/${id}`)).order;
  OPT.current = order;
  const form = document.getElementById('opt-extra-form');
  form.reset();
  KSI.clearFieldErrors(form);
  // With several prescriptions on the visit, the optician says which one the extra pair is for.
  const sets = optRxSets(order.prescription).filter(set => (set.lenses || []).length);
  OPT.extraSets = sets;
  document.getElementById('opt-extra-set-group').style.display = sets.length > 1 ? '' : 'none';
  document.getElementById('opt-extra-set').innerHTML = sets.map((set, i) =>
    `<option value="${set.set_no}">${KSI.esc(optSetTitle(set, i, sets.length))} — ${KSI.esc(set.lenses.map(l => l.name).join(', '))}</option>`).join('');
  document.getElementById('opt-extra-info').innerHTML = `
    <div><strong>${KSI.esc(order.patient.full_name)}</strong> · ${KSI.esc(order.patient.reg_no)} · with ${KSI.esc(order.order_no)}</div>
    <div class="muted-note" id="opt-extra-lenses"></div>`;
  optFrameOptions('opt-extra-frame', 'No frame — the patient brings their own');
  optExtraPairTotal();
  openModal('modal-opt-extra-pair');
}

function optExtraPairTotal() {
  const setNo = Number(document.getElementById('opt-extra-set').value) || 1;
  const set = (OPT.extraSets || []).find(x => x.set_no === setNo) || OPT.extraSets?.[0];
  const lenses = set?.lenses || [];
  document.getElementById('opt-extra-lenses').textContent = `Lenses: ${lenses.map(l => l.name).join(', ')}`;
  // Lenses are charged again at today's price-list rate; the server sets the final figure.
  const frame = document.getElementById('opt-extra-frame').value ? OPT.options.frame_prices.patient : 0;
  const total = lenses.reduce((sum, l) => sum + l.unit_price, 0) + frame;
  document.getElementById('opt-extra-summary').innerHTML =
    `<div class="opt-price-box" style="margin-top:10px"><div>Total for the extra pair</div><div class="opt-price-amount">${optMoney(total)}</div></div>`;
}

async function optSubmitExtraPair(button) {
  const form = document.getElementById('opt-extra-form');
  KSI.clearFieldErrors(form);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`optical/orders/${OPT.current.id}/extra-pair`, {
        frame_id: Number(document.getElementById('opt-extra-frame').value) || null,
        set_no: Number(document.getElementById('opt-extra-set').value) || 1,
      });
      closeModal('modal-opt-extra-pair');
      closeModal('modal-opt-detail');
      showToast('Bill raised', `${order.order_no} — extra pair, ${optMoney(order.total_amount)} at the cashier.`, 'success');
      OPT.options = null;
      await optRefreshLists();
    } catch (error) {
      KSI.reportError(error, form, 'Could not add the pair');
    }
  });
}
