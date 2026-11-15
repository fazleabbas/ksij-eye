'use strict';
// ─── PHARMACY ─────────────────────────────────────────────────────────────
// Prescriptions arrive from the clinicians, the pharmacy says what it can
// supply (which raises the bill), the cashier takes the money and the
// medicine is handed over — in full or in part. Refills repeat a completed
// order free of charge. Counter sales serve people who are not patients.

const PH = {
  options: null,
  orders: [],
  dispensing: [],
  partial: [],
  balance: [],        // prescriptions with medicine still to collect
  refills: [],
  history: [],
  stock: [],
  current: null,      // order open in a modal
  mode: 'view',       // view | review | balance
  dispenseMode: 'dispense',
  walkIn: [],         // lines on the counter sale
  stockItem: null,
};

async function phOptions() {
  if (!PH.options) PH.options = await KSI.get('pharmacy/options');
  return PH.options;
}

const phMoney = amount => 'TZS ' + KSI.fmtMoney(amount);

function phFilters(prefix) {
  const value = id => document.getElementById(id)?.value || '';
  return {
    search: value(`${prefix}-search`),
    status: value(`${prefix}-filter-status`),
    source: value(`${prefix}-filter-source`),
    from: value(`${prefix}-from`),
    to: value(`${prefix}-to`),
  };
}

function phCustomerCell(order) {
  return `<div style="font-weight:600">${KSI.esc(order.customer.name)}${uiFastTrackTag(order)}</div>
    <div class="muted-note">${KSI.esc(order.customer.reference)}${order.source === 'walk_in' ? ' · counter sale' : ''}</div>`;
}

function phMedicinesCell(order) {
  const items = order.items || [];
  if (!items.length) return '<span class="muted-note">—</span>';
  const first = items.slice(0, 2).map(i => `${KSI.esc(i.description)} ×${i.quantity}`).join('<br>');
  return first + (items.length > 2 ? `<div class="muted-note">and ${items.length - 2} more</div>` : '');
}

// ─── Order queue ──────────────────────────────────────────────────────────

async function phOpenOrders() {
  await phOptions();
  await phLoadOrders();
}

async function phLoadOrders() {
  if (!document.getElementById('ph-ord-tbody')) return;
  admTableMessage('ph-ord-tbody', 7, 'Loading orders…');
  try {
    const { orders } = await KSI.get('pharmacy/orders', { scope: 'queue', ...phFilters('ph-ord') }, { trackActivity: false });
    PH.orders = await phWithItems(orders);
    phRenderOrders();
  } catch (error) {
    admTableMessage('ph-ord-tbody', 7, 'Could not load the orders.');
  }
}

/** The list endpoints return orders without their lines; fetch them for the few shown. */
async function phWithItems(orders) {
  const detailed = await Promise.all(orders.slice(0, 60).map(async order => {
    try {
      const { order: full } = await KSI.get(`pharmacy/orders/${order.id}`, {}, { trackActivity: false });
      return full;
    } catch {
      return order;
    }
  }));
  return detailed.concat(orders.slice(60));
}

function phRenderOrders() {
  const count = document.getElementById('ph-ord-count');
  if (count) count.textContent = `${PH.orders.length} order${PH.orders.length === 1 ? '' : 's'}`;
  const stats = document.getElementById('ph-ord-stats');
  if (stats) {
    const by = status => PH.orders.filter(o => o.status === status).length;
    stats.innerHTML = [
      ['Waiting for review', by('pending_review')],
      ['Waiting for payment', by('awaiting_payment')],
      ['Ready to hand over', by('paid')],
      ['Partly handed over', by('partially_dispensed')],
    ].map(([label, value]) => `<div class="stat-card"><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div>`).join('');
  }
  if (!PH.orders.length) {
    admTableMessage('ph-ord-tbody', 7, 'Nothing waiting. Prescriptions appear here as soon as a clinician issues one.');
    return;
  }
  document.getElementById('ph-ord-tbody').innerHTML = PH.orders.map(o => `<tr>
    <td>
      <div style="font-weight:600">${KSI.esc(o.order_no)}</div>
      <div class="muted-note">${KSI.fmtDate(o.created_at)}</div>
      ${o.amendment ? `<div class="muted-note" style="color:var(--amber);font-weight:600" title="${KSI.esc(o.amendment.reason || '')}">Changed by doctor</div>` : ''}
    </td>
    <td>${phCustomerCell(o)}</td>
    <td>${KSI.esc(o.prescribed_by || '—')}</td>
    <td>${phMedicinesCell(o)}</td>
    <td class="money">${o.total_amount ? phMoney(o.total_amount) : '<span class="muted-note">Not priced yet</span>'}</td>
    <td>${uiBadge('pharmacyStatus', o.status)}</td>
    <td>
      <div class="row-actions">
        ${o.status === 'pending_review' && KSI.can('pharmacy.orders.review')
          ? `<button class="action-btn action-edit" onclick="phOpenOrder(${o.id}, 'review')">Review</button>`
          : `<button class="action-btn action-view" onclick="phOpenOrder(${o.id}, 'view')">View</button>`}
        ${['paid', 'partially_dispensed'].includes(o.status) && KSI.can('pharmacy.dispense')
          ? `<button class="action-btn action-status" onclick="phOpenDispense(${o.id}, 'dispense')">Hand over</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

// ─── Order detail and review ──────────────────────────────────────────────

async function phOpenOrder(id, mode) {
  const { order } = await KSI.get(`pharmacy/orders/${id}`);
  PH.current = order;
  PH.mode = mode;
  document.getElementById('ph-order-title').textContent = `${order.source === 'walk_in' ? 'Counter sale' : 'Medication order'} ${order.order_no}`;
  document.getElementById('ph-order-subtitle').textContent =
    `${order.customer.name} · ${order.customer.reference}${order.prescribed_by ? ' · prescribed by ' + order.prescribed_by : ''}`;

  const review = mode === 'review';
  const balance = mode === 'balance';
  document.getElementById('ph-order-body').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      ${uiBadge('pharmacyStatus', order.status)}
      ${order.bill ? uiBadge('billStatus', order.bill.status) : ''}
      ${order.visit_no ? `<span class="muted-note">${KSI.esc(order.visit_no)}</span>` : ''}
    </div>
    <div class="diag-report-facts">
      <div><span class="pcd-label">Patient / buyer</span><span class="pcd-value">${KSI.esc(order.customer.name)}</span></div>
      <div><span class="pcd-label">Reference</span><span class="pcd-value">${KSI.esc(order.customer.reference)}</span></div>
      <div><span class="pcd-label">Phone</span><span class="pcd-value">${KSI.esc(order.customer.phone || '—')}</span></div>
      ${order.prescription_no ? `<div><span class="pcd-label">Prescription</span><span class="pcd-value">${KSI.esc(order.prescription_no)}</span></div>` : ''}
      ${order.bill ? `<div><span class="pcd-label">Bill</span><span class="pcd-value">${KSI.esc(order.bill.bill_no)}</span></div>` : ''}
      ${order.reviewed_by ? `<div><span class="pcd-label">Reviewed by</span><span class="pcd-value">${KSI.esc(order.reviewed_by)}</span></div>` : ''}
    </div>
    ${order.amendment ? `<div class="alert alert-warning" style="margin-top:10px">
      <strong>Prescription changed by the doctor</strong> — ${KSI.esc(order.amendment.by || '—')}, ${KSI.fmtDateTime(order.amendment.at)}
      <div>Reason: ${KSI.esc(order.amendment.reason || '—')}</div></div>` : ''}
    ${order.pharmacy_instructions ? `<div class="cl-note" style="margin-top:10px">
      <div class="pcd-label">Instructions from the clinician</div><div>${KSI.esc(order.pharmacy_instructions)}</div></div>` : ''}
    ${(order.outside_items || []).length ? `<div class="cl-note" style="margin-top:10px">
      <div class="pcd-label">Not stocked — the patient buys these outside</div>
      ${order.outside_items.map(i => `<div>${KSI.esc(i.name)}${i.directions ? ` — ${KSI.esc(i.directions)}` : ''} × ${i.quantity}</div>`).join('')}</div>` : ''}

    <div class="form-label" style="margin-top:14px">Medicines</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Medicine</th><th>Dose</th><th>How often</th><th>For how long</th>
            <th>On the shelf</th><th>Price each</th>${review ? '<th>Prescribed</th><th>Supply now</th><th>Line total</th>'
              : balance ? '<th>Taken</th><th>Still to collect</th><th>Supply now</th><th>Line total</th>'
              : `<th>Billed</th><th>Handed over</th>${order.balance_qty ? '<th>Still to collect</th>' : ''}`}
          </tr>
        </thead>
        <tbody>
          ${order.items.map(item => `<tr>
            <td>
              <div style="font-weight:600">${KSI.esc(item.description)}</div>
              ${item.instructions ? `<div class="muted-note">${KSI.esc(item.instructions)}</div>` : ''}
            </td>
            <td>${KSI.esc(item.dose || '—')}</td>
            <td>${KSI.esc(item.frequency || '—')}</td>
            <td>${KSI.esc(item.duration || '—')}</td>
            <td>${uiBadge('stockLevel', item.in_stock > 0 ? 'in_stock' : 'out_of_stock', String(item.in_stock))}</td>
            <td class="money">${phMoney(item.unit_price)}</td>
            ${review ? `
              <td>${item.prescribed_qty}</td>
              <td><input type="number" class="form-input ph-qty" data-item="${item.id}" data-price="${item.unit_price}" data-prescribed="${item.prescribed_qty}"
                   min="0" max="${Math.max(item.quantity, item.in_stock)}" value="${Math.min(item.quantity, item.in_stock)}"
                   oninput="phReviewTotal()" style="width:80px"></td>
              <td class="money" id="ph-line-${item.id}">${phMoney(Math.min(item.quantity, item.in_stock) * item.unit_price)}</td>`
            : balance ? `
              <td>${item.quantity}</td>
              <td>${item.balance}</td>
              <td>${item.balance ? `<input type="number" class="form-input ph-qty" data-item="${item.id}" data-price="${item.unit_price}"
                   min="0" max="${item.balance}" value="${Math.min(item.balance, item.in_stock)}" oninput="phReviewTotal()" style="width:80px">` : '—'}</td>
              <td class="money" id="ph-line-${item.id}">${phMoney(Math.min(item.balance, item.in_stock) * item.unit_price)}</td>`
            : `<td>${item.quantity}</td><td>${item.dispensed_qty}${item.remaining ? ` <span class="muted-note">(${item.remaining} left)</span>` : ''}</td>
               ${order.balance_qty ? `<td>${item.balance || '—'}</td>` : ''}`}
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
    ${review || balance ? `<div class="opt-price-box" style="margin-top:12px">
      <div><div class="pcd-label">Bill to raise</div><div class="muted-note">${review
        ? 'If the patient can only take part now, enter less (or 0): the rest is kept as a balance they collect on a later day, at the price shown here.'
        : 'Only what the patient takes today is billed; anything left stays as the balance.'}</div>
        ${review ? '<div class="muted-note" id="ph-review-balance"></div>' : ''}</div>
      <div class="opt-price-amount" id="ph-review-total">TZS 0</div>
    </div>` : ''}
    ${!review && !balance && order.balance_qty ? `<div class="alert alert-info" style="margin-top:12px">
      <strong>${order.balance_qty} still to collect.</strong> The patient can come back to the pharmacy for the rest (Balance to Collect).
      ${order.status === 'completed' ? '' : `<div style="margin-top:4px">"Supply balance" becomes available once ${order.bill
        ? `bill ${KSI.esc(order.bill.bill_no)} is ${order.status === 'awaiting_payment' ? 'paid and the medicine' : 'settled and everything paid for is'}`
        : 'the current part is'} handed over.</div>`}</div>` : ''}
    ${order.balance_closed ? `<div class="cl-note" style="margin-top:10px">Balance closed by ${KSI.esc(order.balance_closed.by || '—')},
      ${KSI.fmtDateTime(order.balance_closed.at)}: ${KSI.esc(order.balance_closed.reason || '—')}</div>` : ''}
    ${(order.balance_supplies || []).length ? `
      <div class="form-label" style="margin-top:14px">Balance collected later</div>
      ${order.balance_supplies.map(s => `<div class="cl-addendum">
        <div class="cl-addendum-head">Bill ${KSI.esc(s.bill_no)} · ${s.status === 'cancelled' ? 'Cancelled' : KSI.esc(uiLabel('billStatus', s.bill_status))} · ${phMoney(s.total_amount)} · ${KSI.esc(s.created_by)} · ${KSI.fmtDateTime(s.created_at)}</div>
        <div>${s.items.map(i => `${KSI.esc(i.description)} ×${i.quantity}`).join(', ')}</div>
      </div>`).join('')}` : ''}
    ${order.dispensings?.length ? `
      <div class="form-label" style="margin-top:14px">Handed over</div>
      ${order.dispensings.map(d => `<div class="cl-addendum">
        <div class="cl-addendum-head">${KSI.esc(d.dispensing_no)} · ${d.kind === 'refill' ? 'Refill' : 'Hand-over'} · ${KSI.esc(d.dispensed_by)} · ${KSI.fmtDateTime(d.dispensed_at)}</div>
        <div>${d.items.map(i => `${KSI.esc(i.description)} ×${i.quantity}`).join(', ')}</div>
        ${d.notes ? `<div class="muted-note">${KSI.esc(d.notes)}</div>` : ''}
      </div>`).join('')}` : ''}
    ${order.cancel_reason ? `<div class="alert alert-warning" style="margin-top:12px">Cancelled: ${KSI.esc(order.cancel_reason)}${order.refund ? ` · refund ${KSI.esc(order.refund.refund_no)} (${KSI.esc(uiLabel('refundStatus', order.refund.status))})` : ''}</div>` : ''}`;

  document.getElementById('ph-order-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-pharm-order')">Close</button>
    ${review ? `<button class="btn btn-primary" onclick="phConfirmReview(this)">Confirm and raise the bill</button>` : ''}
    ${balance ? `<button class="btn btn-primary" onclick="phConfirmBalance(this)">Raise the bill for these</button>` : ''}
    ${!balance && order.balance_qty && order.status === 'completed' && KSI.can('pharmacy.orders.review')
      ? `<button class="btn btn-primary" onclick="phOpenOrder(${order.id}, 'balance')">Supply balance</button>` : ''}
    ${order.balance_qty && KSI.can('pharmacy.cancel')
      ? `<button class="btn btn-secondary" onclick="phCloseBalance(${order.id})">Close balance</button>` : ''}
    ${!review && ['paid', 'partially_dispensed'].includes(order.status) && KSI.can('pharmacy.dispense')
      ? `<button class="btn btn-primary" onclick="phOpenDispense(${order.id}, 'dispense')">Hand over</button>` : ''}
    ${!review && ['awaiting_payment', 'paid', 'partially_dispensed'].includes(order.status) && KSI.can('pharmacy.cancel')
      ? `<button class="btn btn-danger" onclick="phOpenCancel(${order.id})">Cancel what is left</button>` : ''}`;

  if (review || balance) phReviewTotal();
  openModal('modal-pharm-order');
}

function phReviewTotal() {
  let total = 0;
  document.querySelectorAll('#ph-order-body .ph-qty').forEach(input => {
    const quantity = Math.max(0, Number(input.value) || 0);
    const line = quantity * Number(input.dataset.price);
    total += line;
    const cell = document.getElementById(`ph-line-${input.dataset.item}`);
    if (cell) cell.textContent = phMoney(line);
  });
  const totalEl = document.getElementById('ph-review-total');
  if (totalEl) totalEl.textContent = phMoney(total);
  // At review, say what will be left for a later day.
  const left = document.getElementById('ph-review-balance');
  if (left) {
    const lines = [...document.querySelectorAll('#ph-order-body .ph-qty')].map(input => {
      const rest = Math.max(0, Number(input.dataset.prescribed) - Math.max(0, Number(input.value) || 0));
      const name = input.closest('tr').querySelector('td div')?.textContent || '';
      return rest ? `${name} ×${rest}` : '';
    }).filter(Boolean);
    left.innerHTML = lines.length ? `<strong>Balance to collect later:</strong> ${KSI.esc(lines.join(', '))}` : '';
  }
}

async function phConfirmBalance(button) {
  const modal = document.getElementById('modal-pharm-order');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`pharmacy/orders/${PH.current.id}/balance`, { items: phQuantityLines('#ph-order-body') });
      closeModal('modal-pharm-order');
      showToast('Bill raised', `${order.order_no} — bill ${order.bill.bill_no} sent to the cashier.`, 'success');
      await phRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not raise the bill');
    }
  });
}

async function phCloseBalance(id) {
  const reason = prompt('Why will the rest not be collected? (e.g. bought elsewhere, no longer needed)');
  if (reason === null) return;
  if (reason.trim().length < 3) {
    showToast('Reason needed', 'Say why the balance is being closed.', 'error');
    return;
  }
  try {
    const { order } = await KSI.post(`pharmacy/orders/${id}/balance/close`, { reason: reason.trim() });
    closeModal('modal-pharm-order');
    showToast('Balance closed', `${order.order_no} — nothing more to collect.`, 'success');
    await phRefreshLists();
  } catch (error) {
    KSI.reportError(error, null, 'Could not close the balance');
  }
}

// ─── Balance to collect ───────────────────────────────────────────────────

async function phOpenBalance() {
  await phOptions();
  await phLoadBalance();
}

async function phLoadBalance() {
  if (!document.getElementById('ph-bal-tbody')) return;
  admTableMessage('ph-bal-tbody', 6, 'Loading orders…');
  try {
    const { orders } = await KSI.get('pharmacy/orders', { scope: 'balance', search: document.getElementById('ph-bal-search')?.value || '' }, { trackActivity: false });
    PH.balance = await phWithItems(orders);
    phRenderBalance();
  } catch (error) {
    admTableMessage('ph-bal-tbody', 6, 'Could not load the orders.');
  }
}

function phRenderBalance() {
  const count = document.getElementById('ph-bal-count');
  if (count) count.textContent = `${PH.balance.length} order${PH.balance.length === 1 ? '' : 's'}`;
  if (!PH.balance.length) {
    admTableMessage('ph-bal-tbody', 6, 'No balances waiting to be collected.');
    return;
  }
  document.getElementById('ph-bal-tbody').innerHTML = PH.balance.map(o => {
    const owed = (o.items || []).filter(i => i.balance > 0);
    const taken = (o.items || []).filter(i => i.quantity > 0);
    const ready = o.status === 'completed';
    return `<tr>
      <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div><div class="muted-note">${KSI.esc(o.prescription_no || '')}</div></td>
      <td>${phCustomerCell(o)}</td>
      <td>${owed.map(i => `${KSI.esc(i.description)} ×${i.balance}`).join('<br>') || '—'}</td>
      <td>${taken.map(i => `${KSI.esc(i.description)} ×${i.quantity}`).join('<br>') || '—'}</td>
      <td>${KSI.fmtDate(o.reviewed_at || o.created_at)}</td>
      <td>
        <div class="row-actions">
          ${ready && KSI.can('pharmacy.orders.review') ? `<button class="action-btn action-edit" onclick="phOpenOrder(${o.id}, 'balance')">Supply balance</button>`
            : `<span class="muted-note">${KSI.esc(uiLabel('pharmacyStatus', o.status))} — finish the current part first</span>`}
          <button class="action-btn action-view" onclick="phOpenOrder(${o.id}, 'view')">View</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function phQuantityLines(root) {
  return [...document.querySelectorAll(`${root} .ph-qty`)].map(input => ({
    item_id: Number(input.dataset.item),
    quantity: Math.max(0, Number(input.value) || 0),
  }));
}

async function phConfirmReview(button) {
  const modal = document.getElementById('modal-pharm-order');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`pharmacy/orders/${PH.current.id}/review`, { items: phQuantityLines('#ph-order-body') });
      closeModal('modal-pharm-order');
      showToast('Bill raised', `${order.order_no} — ${phMoney(order.total_amount)} sent to the cashier.`, 'success');
      await phRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not raise the bill');
    }
  });
}

// ─── Dispensing and refills ───────────────────────────────────────────────

async function phOpenDispense(id, mode) {
  const { order } = await KSI.get(`pharmacy/orders/${id}`);
  PH.current = order;
  PH.dispenseMode = mode;
  const refill = mode === 'refill';
  document.getElementById('ph-dispense-title').textContent = refill ? `Refill ${order.order_no}` : `Hand over ${order.order_no}`;
  document.getElementById('ph-dispense-subtitle').textContent = `${order.customer.name} · ${order.customer.reference}`;
  document.getElementById('ph-dispense-save').textContent = refill ? 'Record the refill' : 'Hand over';

  document.getElementById('ph-dispense-body').innerHTML = `
    ${refill ? '<div class="alert alert-info">A refill repeats the whole prescription at no charge. The stock still comes off the shelf.</div>' : ''}
    <div class="table-wrap">
      <table>
        <thead><tr><th>Medicine</th><th>Ordered</th><th>Still owed</th><th>On the shelf</th><th>Hand over now</th></tr></thead>
        <tbody>
          ${order.items.map(item => {
            const owed = refill ? item.quantity : item.remaining;
            const suggested = Math.min(owed, item.in_stock);
            return `<tr>
              <td>
                <div style="font-weight:600">${KSI.esc(item.description)}</div>
                <div class="muted-note">${KSI.esc([item.dose, item.frequency, item.duration].filter(Boolean).join(' · '))}</div>
              </td>
              <td>${item.quantity}</td>
              <td>${owed}</td>
              <td>${uiBadge('stockLevel', item.in_stock > 0 ? 'in_stock' : 'out_of_stock', String(item.in_stock))}</td>
              <td><input type="number" class="form-input ph-qty" data-item="${item.id}" min="0" max="${Math.min(owed, item.in_stock)}"
                   value="${suggested}" style="width:80px"></td>
            </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>
    <div class="form-group" data-field="notes" style="margin-top:12px">
      <label class="form-label" for="ph-dispense-notes">Notes</label>
      <textarea class="form-input" rows="2" id="ph-dispense-notes" maxlength="500" placeholder="Anything worth recording about this hand-over"></textarea>
    </div>`;
  openModal('modal-pharm-dispense');
}

async function phConfirmDispense(button) {
  const modal = document.getElementById('modal-pharm-dispense');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`pharmacy/orders/${PH.current.id}/dispense`, {
        items: phQuantityLines('#ph-dispense-body'),
        notes: document.getElementById('ph-dispense-notes').value,
        refill: PH.dispenseMode === 'refill',
      });
      closeModal('modal-pharm-dispense');
      closeModal('modal-pharm-order');
      showToast(PH.dispenseMode === 'refill' ? 'Refill recorded' : 'Handed over',
        `${order.order_no} — ${uiLabel('pharmacyStatus', order.status)}`, 'success');
      await phRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not hand the medicine over');
    }
  });
}

// ─── Cancel the remainder ─────────────────────────────────────────────────

async function phOpenCancel(id) {
  const { order } = await KSI.get(`pharmacy/orders/${id}`);
  PH.current = order;
  const owed = order.items.reduce((sum, i) => sum + i.remaining * i.unit_price, 0);
  const modal = document.getElementById('modal-pharm-cancel');
  KSI.clearFieldErrors(modal);
  document.getElementById('ph-cancel-info').innerHTML = `
    <div><strong>${KSI.esc(order.customer.name)}</strong> · ${KSI.esc(order.order_no)}</div>
    <div class="muted-note">${order.items.filter(i => i.remaining > 0).map(i => `${KSI.esc(i.description)} ×${i.remaining}`).join(', ') || 'Nothing outstanding'}</div>`;
  document.getElementById('ph-cancel-refund').textContent = order.is_paid && owed > 0
    ? `${phMoney(owed)} has been paid for medicine not handed over. A refund request will go to an administrator.`
    : 'Nothing has been paid for the outstanding medicine, so no refund is needed.';
  document.getElementById('ph-cancel-reason').value = '';
  openModal('modal-pharm-cancel');
}

async function phConfirmCancel(button) {
  const modal = document.getElementById('modal-pharm-cancel');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`pharmacy/orders/${PH.current.id}/cancel`, {
        reason: document.getElementById('ph-cancel-reason').value,
      });
      closeModal('modal-pharm-cancel');
      closeModal('modal-pharm-order');
      showToast('Cancelled', order.refund
        ? `${order.order_no} — refund ${order.refund.refund_no} sent for approval.`
        : `${order.order_no} — nothing further will be supplied.`, 'success');
      await phRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not cancel the rest');
    }
  });
}

// ─── Dispensing list ──────────────────────────────────────────────────────

async function phOpenDispenseList() {
  await phOptions();
  await phLoadDispense();
}

async function phLoadDispense() {
  if (!document.getElementById('ph-disp-tbody')) return;
  admTableMessage('ph-disp-tbody', 7, 'Loading orders…');
  try {
    const { orders } = await KSI.get('pharmacy/orders', { scope: 'dispensing', ...phFilters('ph-disp') }, { trackActivity: false });
    PH.dispensing = await phWithItems(orders);
    phRenderDispense();
  } catch (error) {
    admTableMessage('ph-disp-tbody', 7, 'Could not load the orders.');
  }
}

function phRenderDispense() {
  const count = document.getElementById('ph-disp-count');
  if (count) count.textContent = `${PH.dispensing.length} order${PH.dispensing.length === 1 ? '' : 's'}`;
  if (!PH.dispensing.length) {
    admTableMessage('ph-disp-tbody', 7, 'Nothing to hand over. An order appears here once its bill is settled.');
    return;
  }
  document.getElementById('ph-disp-tbody').innerHTML = PH.dispensing.map(o => `<tr>
    <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div></td>
    <td>${phCustomerCell(o)}</td>
    <td>${KSI.esc(o.customer.phone || '—')}</td>
    <td>${phMedicinesCell(o)}</td>
    <td class="money">${phMoney(o.total_amount)}</td>
    <td>${uiBadge('pharmacyStatus', o.status)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="phOpenOrder(${o.id}, 'view')">View</button>
        ${KSI.can('pharmacy.dispense') ? `<button class="action-btn action-edit" onclick="phOpenDispense(${o.id}, 'dispense')">Hand over</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

// ─── Partly handed over ───────────────────────────────────────────────────

async function phOpenPartial() {
  await phOptions();
  await phLoadPartial();
}

async function phLoadPartial() {
  if (!document.getElementById('ph-part-tbody')) return;
  admTableMessage('ph-part-tbody', 6, 'Loading orders…');
  try {
    const { orders } = await KSI.get('pharmacy/orders', { scope: 'partial', ...phFilters('ph-part') }, { trackActivity: false });
    PH.partial = await phWithItems(orders);
    phRenderPartial();
  } catch (error) {
    admTableMessage('ph-part-tbody', 6, 'Could not load the orders.');
  }
}

function phRenderPartial() {
  const count = document.getElementById('ph-part-count');
  if (count) count.textContent = `${PH.partial.length} order${PH.partial.length === 1 ? '' : 's'}`;
  if (!PH.partial.length) {
    admTableMessage('ph-part-tbody', 6, 'Nothing part-supplied at the moment.');
    return;
  }
  document.getElementById('ph-part-tbody').innerHTML = PH.partial.map(o => {
    const owed = (o.items || []).filter(i => i.remaining > 0);
    const given = (o.items || []).filter(i => i.dispensed_qty > 0);
    return `<tr>
      <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div><div class="muted-note">${KSI.fmtDate(o.created_at)}</div></td>
      <td>${phCustomerCell(o)}</td>
      <td>${owed.map(i => `${KSI.esc(i.description)} ×${i.remaining}`).join('<br>') || '—'}</td>
      <td>${given.map(i => `${KSI.esc(i.description)} ×${i.dispensed_qty}`).join('<br>') || '—'}</td>
      <td class="money">${phMoney(o.total_amount)}</td>
      <td>
        <div class="row-actions">
          ${KSI.can('pharmacy.dispense') ? `<button class="action-btn action-edit" onclick="phOpenDispense(${o.id}, 'dispense')">Hand over the rest</button>` : ''}
          ${KSI.can('pharmacy.cancel') ? `<button class="action-btn action-cancel" onclick="phOpenCancel(${o.id})">Cancel the rest</button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

// ─── Refills ──────────────────────────────────────────────────────────────

async function phOpenRefills() {
  await phOptions();
  await phLoadRefills();
}

async function phLoadRefills() {
  if (!document.getElementById('ph-ref-tbody')) return;
  admTableMessage('ph-ref-tbody', 6, 'Loading orders…');
  try {
    const { orders } = await KSI.get('pharmacy/orders', { scope: 'completed', ...phFilters('ph-ref') }, { trackActivity: false });
    PH.refills = await phWithItems(orders.filter(o => o.status === 'completed' && o.source === 'prescription'));
    phRenderRefills();
  } catch (error) {
    admTableMessage('ph-ref-tbody', 6, 'Could not load the orders.');
  }
}

function phRenderRefills() {
  const count = document.getElementById('ph-ref-count');
  if (count) count.textContent = `${PH.refills.length} order${PH.refills.length === 1 ? '' : 's'}`;
  if (!PH.refills.length) {
    admTableMessage('ph-ref-tbody', 6, 'No completed prescriptions to repeat yet.');
    return;
  }
  document.getElementById('ph-ref-tbody').innerHTML = PH.refills.map(o => `<tr>
    <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div></td>
    <td>${phCustomerCell(o)}</td>
    <td>${phMedicinesCell(o)}</td>
    <td>${o.completed_at ? KSI.fmtDateTime(o.completed_at) : '—'}</td>
    <td>${o.refill_count}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="phOpenOrder(${o.id}, 'view')">View</button>
        ${KSI.can('pharmacy.dispense') ? `<button class="action-btn action-edit" onclick="phOpenDispense(${o.id}, 'refill')">Refill</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

// ─── History ──────────────────────────────────────────────────────────────

async function phOpenHistory() {
  await phOptions();
  await phLoadHistory();
}

async function phLoadHistory() {
  if (!document.getElementById('ph-hist-tbody')) return;
  admTableMessage('ph-hist-tbody', 7, 'Loading history…');
  try {
    const { orders } = await KSI.get('pharmacy/orders', { scope: 'completed', ...phFilters('ph-hist') }, { trackActivity: false });
    PH.history = await phWithItems(orders);
    phRenderHistory();
  } catch (error) {
    admTableMessage('ph-hist-tbody', 7, 'Could not load the history.');
  }
}

function phRenderHistory() {
  const count = document.getElementById('ph-hist-count');
  if (count) count.textContent = `${PH.history.length} order${PH.history.length === 1 ? '' : 's'}`;
  if (!PH.history.length) {
    admTableMessage('ph-hist-tbody', 7, 'No finished orders match your filters.');
    return;
  }
  document.getElementById('ph-hist-tbody').innerHTML = PH.history.map(o => `<tr>
    <td>${o.completed_at ? KSI.fmtDateTime(o.completed_at) : KSI.fmtDateTime(o.cancelled_at)}</td>
    <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div></td>
    <td>${phCustomerCell(o)}</td>
    <td>${phMedicinesCell(o)}</td>
    <td class="money">${phMoney(o.total_amount)}</td>
    <td>${uiBadge('pharmacyStatus', o.status)}</td>
    <td><div class="row-actions"><button class="action-btn action-view" onclick="phOpenOrder(${o.id}, 'view')">View</button></div></td>
  </tr>`).join('');
}

// ─── Counter sale ─────────────────────────────────────────────────────────

async function phOpenWalkIn() {
  await phOptions();
  PH.walkIn = [];
  const modal = document.getElementById('modal-pharm-walkin');
  KSI.clearFieldErrors(modal);
  ['ph-wi-name', 'ph-wi-phone', 'ph-wi-age'].forEach(id => { document.getElementById(id).value = ''; });
  document.getElementById('ph-wi-gender').value = '';
  phWalkInDrugList();
  phWalkInRender();
  openModal('modal-pharm-walkin');
}

function phWalkInDrugList() {
  const chosen = PH.walkIn.map(l => l.drug_id);
  const available = (PH.options?.drugs || []).filter(d => d.in_stock > 0 && !chosen.includes(d.id));
  admFillSelect('ph-wi-drug', 'Choose a medicine to add', available.map(d => [d.id, `${d.name} — ${phMoney(d.unit_price)} (${d.in_stock} on the shelf)`]));
}

function phWalkInAdd(drugId) {
  const drug = (PH.options?.drugs || []).find(d => d.id === Number(drugId));
  if (!drug) return;
  PH.walkIn.push({ drug_id: drug.id, name: drug.name, in_stock: drug.in_stock, quantity: 1, unit_price: drug.unit_price, catalogue_price: drug.unit_price });
  phWalkInDrugList();
  phWalkInRender();
}

function phWalkInRemove(drugId) {
  PH.walkIn = PH.walkIn.filter(l => l.drug_id !== drugId);
  phWalkInDrugList();
  phWalkInRender();
}

function phWalkInSet(drugId, field, value) {
  const line = PH.walkIn.find(l => l.drug_id === drugId);
  if (!line) return;
  line[field] = Math.max(0, Number(value) || 0);
  phWalkInTotal();
}

function phWalkInRender() {
  const body = document.getElementById('ph-wi-items');
  if (!body) return;
  body.innerHTML = PH.walkIn.length
    ? PH.walkIn.map(l => `<tr>
        <td>
          <div style="font-weight:600">${KSI.esc(l.name)}</div>
          ${l.unit_price !== l.catalogue_price ? `<div class="muted-note">Catalogue price ${phMoney(l.catalogue_price)}</div>` : ''}
        </td>
        <td>${l.in_stock}</td>
        <td><input type="number" class="form-input" min="1" max="${l.in_stock}" value="${l.quantity}" style="width:80px"
             oninput="phWalkInSet(${l.drug_id}, 'quantity', this.value); phWalkInRenderPrices()"></td>
        <td><input type="number" class="form-input" min="0" step="50" value="${l.unit_price}" style="width:110px"
             oninput="phWalkInSet(${l.drug_id}, 'unit_price', this.value); phWalkInRenderPrices()"></td>
        <td class="money" id="ph-wi-line-${l.drug_id}">${phMoney(l.quantity * l.unit_price)}</td>
        <td><button class="action-btn action-cancel" onclick="phWalkInRemove(${l.drug_id})">Remove</button></td>
      </tr>`).join('')
    : '<tr class="table-state"><td colspan="6">No medicine added yet.</td></tr>';
  phWalkInTotal();
}

function phWalkInRenderPrices() {
  PH.walkIn.forEach(l => {
    const cell = document.getElementById(`ph-wi-line-${l.drug_id}`);
    if (cell) cell.textContent = phMoney(l.quantity * l.unit_price);
  });
  phWalkInTotal();
}

function phWalkInTotal() {
  const total = PH.walkIn.reduce((sum, l) => sum + l.quantity * l.unit_price, 0);
  const el = document.getElementById('ph-wi-total');
  if (el) el.textContent = phMoney(total);
}

async function phSubmitWalkIn(button) {
  const modal = document.getElementById('modal-pharm-walkin');
  KSI.clearFieldErrors(modal);
  if (!PH.walkIn.length) {
    KSI.showFieldErrors(modal, { items: 'Add at least one medicine.' });
    return;
  }
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post('pharmacy/walk-in-sales', {
        name: document.getElementById('ph-wi-name').value,
        phone: document.getElementById('ph-wi-phone').value,
        gender: document.getElementById('ph-wi-gender').value,
        age: document.getElementById('ph-wi-age').value || null,
        items: PH.walkIn.map(l => ({ drug_id: l.drug_id, quantity: l.quantity, unit_price: l.unit_price })),
      });
      closeModal('modal-pharm-walkin');
      showToast('Bill raised', `${order.order_no} — ${phMoney(order.total_amount)} waiting at the cashier.`, 'success');
      PH.options = null;
      await phRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not record the sale');
    }
  });
}

// ─── Stock ────────────────────────────────────────────────────────────────

async function phOpenStock() {
  await phOptions();
  const select = document.getElementById('ph-stock-filter-category');
  if (select && !select.dataset.filled) {
    admFillSelect('ph-stock-filter-category', 'All categories', (PH.options.categories || []).map(c => [c, c]));
    select.dataset.filled = '1';
  }
  await phLoadStock();
}

async function phLoadStock() {
  if (!document.getElementById('ph-stock-tbody')) return;
  admTableMessage('ph-stock-tbody', 8, 'Loading stock…');
  try {
    const value = id => document.getElementById(id)?.value || '';
    const data = await KSI.get('pharmacy/stock', {
      search: value('ph-stock-search'), category: value('ph-stock-filter-category'), status: value('ph-stock-filter-status'),
    }, { trackActivity: false });
    PH.stock = data.items;
    phRenderStock(data.counts);
  } catch (error) {
    admTableMessage('ph-stock-tbody', 8, 'Could not load the stock.');
  }
}

function phRenderStock(counts) {
  const count = document.getElementById('ph-stock-count');
  if (count) count.textContent = `${PH.stock.length} medicine${PH.stock.length === 1 ? '' : 's'}`;
  const stats = document.getElementById('ph-stock-stats');
  if (stats && counts) {
    stats.innerHTML = [
      ['In stock', counts.in_stock],
      ['Low stock', counts.low_stock],
      ['Out of stock', counts.out_of_stock],
    ].map(([label, value]) => `<div class="stat-card"><div class="stat-num">${value}</div><div class="stat-label">${label}</div></div>`).join('');
  }
  if (!PH.stock.length) {
    admTableMessage('ph-stock-tbody', 8, 'No medicines match your filters.');
    return;
  }
  document.getElementById('ph-stock-tbody').innerHTML = PH.stock.map(i => `<tr>
    <td><div style="font-weight:600">${KSI.esc(i.name)}</div><div class="muted-note">${KSI.esc(i.dosage_form || '')}</div></td>
    <td>${KSI.esc(i.category || '—')}</td>
    <td class="money">${i.unit_price ? phMoney(i.unit_price) : '<span class="muted-note">Not priced</span>'}</td>
    <td style="font-weight:600">${i.quantity}</td>
    <td>${i.reorder_level}</td>
    <td>${i.expiry_date ? KSI.esc(i.expiry_date) : '—'}</td>
    <td>${uiBadge('stockLevel', i.stock_status)}</td>
    <td>
      <div class="row-actions">
        ${KSI.can('pharmacy.stock.manage')
          ? `<button class="action-btn action-edit" onclick="phOpenStockItem(${i.drug_id})">Record stock</button>`
          : `<button class="action-btn action-view" onclick="phOpenStockItem(${i.drug_id})">Movements</button>`}
      </div>
    </td>
  </tr>`).join('');
}

async function phOpenStockItem(drugId) {
  const item = PH.stock.find(i => i.drug_id === drugId);
  if (!item) return;
  PH.stockItem = item;
  const modal = document.getElementById('modal-pharm-stock');
  KSI.clearFieldErrors(modal);
  document.getElementById('ph-stock-title').textContent = item.name;
  document.getElementById('ph-stock-info').innerHTML = `
    <div><strong>${KSI.esc(item.name)}</strong> · ${KSI.esc(item.category || '')}</div>
    <div class="muted-note">${item.quantity} on the shelf · reorder at ${item.reorder_level} · ${uiLabel('stockLevel', item.stock_status)}</div>`;
  document.getElementById('ph-stock-change').value = '';
  document.getElementById('ph-stock-note').value = '';
  document.getElementById('ph-stock-reorder').value = item.reorder_level;
  document.getElementById('ph-stock-expiry').value = item.expiry_date || '';
  document.getElementById('ph-stock-reason').value = 'received';
  const { movements } = await KSI.get(`pharmacy/stock/${drugId}/movements`);
  document.getElementById('ph-stock-movements').innerHTML = movements.length
    ? `<div class="cl-file-list">${movements.map(m => `<div class="cl-file">
        <div>
          <div>${m.change_qty > 0 ? '+' : ''}${m.change_qty} · ${KSI.esc(uiLabel('stockReason', m.reason))}${m.order_no ? ` · ${KSI.esc(m.order_no)}` : ''}</div>
          ${m.note ? `<div class="muted-note">${KSI.esc(m.note)}</div>` : ''}
        </div>
        <span class="muted-note">left ${m.balance_after} · ${KSI.esc(m.created_by)} · ${KSI.fmtDateTime(m.created_at)}</span>
      </div>`).join('')}</div>`
    : '<div class="muted-note">No movements yet.</div>';
  openModal('modal-pharm-stock');
}

async function phSaveStock(button) {
  const modal = document.getElementById('modal-pharm-stock');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { item } = await KSI.post(`pharmacy/stock/${PH.stockItem.drug_id}`, {
        change: Number(document.getElementById('ph-stock-change').value) || 0,
        reason: document.getElementById('ph-stock-reason').value,
        note: document.getElementById('ph-stock-note').value,
        reorder_level: document.getElementById('ph-stock-reorder').value,
        expiry_date: document.getElementById('ph-stock-expiry').value,
      });
      closeModal('modal-pharm-stock');
      showToast('Stock updated', `${item.name} — ${item.quantity} on the shelf`, 'success');
      PH.options = null;
      await phLoadStock();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not update the stock');
    }
  });
}

// ─── Shared ───────────────────────────────────────────────────────────────

async function phRefreshLists() {
  const open = id => !document.getElementById(id).classList.contains('hidden');
  if (open('module-pharm-orders')) await phLoadOrders();
  if (open('module-pharm-dispense')) await phLoadDispense();
  if (open('module-pharm-partial')) await phLoadPartial();
  if (open('module-pharm-balance')) await phLoadBalance();
  if (open('module-pharm-refills')) await phLoadRefills();
  if (open('module-pharm-history')) await phLoadHistory();
  if (open('module-pharm-inventory')) await phLoadStock();
}
