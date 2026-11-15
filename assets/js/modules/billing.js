'use strict';
// ─── CASHIER · PAYMENTS, RECEIPTS & HISTORY ────────────────────────────────
// The payment queue, full payments, discount / cancellation requests (decided
// in Approvals), receipts and payment history. Amounts come from the server.

const CASH_UI = { queuePage: 1, historyPage: 1, perPage: 25, historyTab: 'paid', bill: null, receipt: null, discountTypes: null, discountFiles: [] };
const CASH_QUEUE_CARDS = [
  ['', 'All Waiting', 'var(--amber)'], ['consultation', 'Consultation', 'var(--blue)'], ['investigation', 'Investigation', 'var(--teal)'],
  ['optical', 'Optical', 'var(--purple)'], ['surgery', 'Surgery', 'var(--red)'], ['pharmacy', 'Pharmacy', 'var(--green)'],
];
const CASH_DOC_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];

// ════════════════════════════════════════════════════════════════════════
// Payment queue
// ════════════════════════════════════════════════════════════════════════
function csRenderPayments() {
  CASH_UI.queuePage = 1;
  document.getElementById('cs-fast-track-btn').style.display = KSI.can('payments.fast_track') ? '' : 'none';
  csLoadQueue();
}

const csFilterChanged = uiDebounce(() => {
  CASH_UI.queuePage = 1;
  csLoadQueue();
});

function csSetQueueType(type) {
  document.getElementById('cs-filter-type').value = type;
  CASH_UI.queuePage = 1;
  csLoadQueue();
}

function csQueueActions(bill) {
  if (bill.status === 'pending') {
    const label = KSI.can('payments.process') ? 'Pay' : 'View';
    return `<button class="action-btn action-view" onclick="csOpenPayment(${bill.id})">${label}</button>`;
  }
  const waiting = bill.status === 'discount_requested' ? 'Awaiting discount approval' : 'Awaiting cancellation approval';
  const review = KSI.can('approvals.view') ? `<button class="action-btn action-status" onclick="showModule('approvals-pending')">Review</button>` : '';
  return `<div class="muted-note" style="white-space:nowrap">${waiting}</div>${review}`;
}

async function csLoadQueue(announce = false) {
  uiTableMessage('cs-tbody', 10, 'Loading payment queue…');
  try {
    const data = await KSI.get('bills', {
      search: document.getElementById('cs-search').value.trim(),
      status: document.getElementById('cs-filter-status').value,
      bill_type: document.getElementById('cs-filter-type').value,
      from: document.getElementById('cs-from').value,
      to: document.getElementById('cs-to').value,
      page: CASH_UI.queuePage,
      per_page: CASH_UI.perPage,
    });
    const activeType = document.getElementById('cs-filter-type').value;
    document.getElementById('cs-queue-cards').innerHTML = CASH_QUEUE_CARDS.map(([type, label, color]) => `
      <div class="pay-queue-card${activeType === type ? ' active' : ''}" onclick="csSetQueueType('${type}')">
        <div class="pqc-num">${type ? data.counts[type] : data.counts.all}</div>
        <div class="pqc-label">${label}</div>
        <div class="pqc-dot" style="background:${color}"></div>
      </div>`).join('');

    if (!data.bills.length) {
      uiTableMessage('cs-tbody', 10, data.counts.all ? 'No bills match the filters.' : 'No bills are waiting for payment.');
    } else {
      document.getElementById('cs-tbody').innerHTML = data.bills.map(bill => `<tr>
        <td><span class="visit-id">${KSI.esc(bill.bill_no)}</span>${bill.visit_no ? `<div class="sub-text">Visit ${KSI.esc(bill.visit_no)}</div>` : ''}${bill.is_deferred ? '<div class="sub-text" style="color:var(--red);font-weight:600">Emergency</div>' : ''}</td>
        <td>${uiPatientCell(bill.patient)}</td>
        <td style="font-size:12px">${KSI.esc(uiLabel('billType', bill.bill_type))}</td>
        <td style="font-size:12px">${KSI.esc(bill.description)}</td>
        <td class="money" style="text-align:right">${KSI.fmtMoney(bill.gross_amount)}</td>
        <td class="money" style="text-align:right">${bill.discount_amount ? KSI.fmtMoney(bill.discount_amount) : '—'}</td>
        <td class="money" style="text-align:right;font-weight:700">${KSI.fmtMoney(bill.net_amount)}</td>
        <td>${uiBadge('billStatus', bill.status)}</td>
        <td style="font-size:12px;white-space:nowrap">${KSI.fmtDateTime(bill.created_at)}<div class="sub-text">${KSI.esc(bill.created_by)}</div></td>
        <td>${csQueueActions(bill)}</td>
      </tr>`).join('');
    }
    document.getElementById('cs-count').textContent = uiCountText(data.page, data.per_page, data.total, 'bills');
    uiPagination('cs-pagination', data.page, data.per_page, data.total, page => { CASH_UI.queuePage = page; csLoadQueue(); });
    if (announce) showToast('Queue refreshed', `${data.counts.all} bill${data.counts.all === 1 ? '' : 's'} waiting`, 'info');
  } catch (error) {
    uiTableMessage('cs-tbody', 10, error.message);
  }
}

// ── Fast track a visit ──
// The patient pays the fast-track price less what the visit was charged, and moves ahead
// of the normal queue everywhere once that is paid.
function csOpenFastTrack() {
  if (!KSI.can('payments.fast_track')) return;
  CASH_UI.fastTrack = { patient: null, visitId: null };
  if (!CASH_UI.ftPicker) CASH_UI.ftPicker = uiPatientPicker('ft-patient-search', 'ft-search-results', csFastTrackPatient);
  CASH_UI.ftPicker.reset();
  csFastTrackPatient(null);
  openModal('modal-fast-track');
}

async function csFastTrackPatient(patient) {
  CASH_UI.fastTrack = { patient, visitId: null };
  const selected = document.getElementById('ft-selected-patient');
  document.getElementById('ft-picker').style.display = patient ? 'none' : '';
  selected.style.display = patient ? '' : 'none';
  selected.innerHTML = patient ? uiPatientCard(patient, '<button class="btn btn-ghost btn-sm" type="button" onclick="csFastTrackPatient(null)">Change</button>') : '';
  document.getElementById('ft-quote').style.display = 'none';
  document.getElementById('ft-confirm-btn').disabled = true;
  const box = document.getElementById('ft-visits');
  if (!patient) {
    box.innerHTML = '';
    if (CASH_UI.ftPicker) CASH_UI.ftPicker.reset();
    return;
  }
  box.innerHTML = '<div class="muted-note">Loading visits…</div>';
  try {
    const { visits } = await KSI.get('visits', { patient_id: patient.id, per_page: 10 });
    const open = visits.filter(v => v.status !== 'cancelled');
    box.innerHTML = open.length
      ? `<div class="form-label">Choose the visit</div><div class="radio-group" style="flex-direction:column">${open.map(v => `
          <label class="radio-card"><input type="radio" name="ft-visit" value="${v.id}" onchange="csFastTrackVisit(${v.id})">
            <div><div class="radio-card-label">${KSI.esc(v.visit_no)} · ${KSI.esc(uiDate(v.visit_date))}</div>
            <div class="radio-card-sub">${KSI.esc(`${uiLabel('visitType', v.visit_type)} — ${uiLabel('subType', v.sub_type)} · ${uiLabel('visitStatus', v.status)}`)}${v.is_fast_track ? ' · <strong>Fast track</strong>' : ''}${v.fast_track_pending ? ' · Fast track waiting for payment' : ''}</div></div></label>`).join('')}</div>`
      : '<div class="muted-note">This patient has no visits to fast track.</div>';
  } catch (error) {
    box.innerHTML = `<div class="field-error">${KSI.esc(error.message)}</div>`;
  }
}

async function csFastTrackVisit(visitId) {
  CASH_UI.fastTrack.visitId = visitId;
  uiSyncRadioCards(document.getElementById('modal-fast-track'));
  const box = document.getElementById('ft-quote');
  const button = document.getElementById('ft-confirm-btn');
  button.disabled = true;
  box.style.display = '';
  box.innerHTML = '<div class="muted-note">Calculating…</div>';
  try {
    const { quote } = await KSI.get(`visits/${visitId}/fast-track`);
    if (CASH_UI.fastTrack.visitId !== visitId) return;
    if (quote.blocked) {
      box.innerHTML = `<div class="muted-note">${KSI.esc(quote.blocked)}</div>`;
      return;
    }
    box.innerHTML = `<div>
      <div class="dcb-row"><span class="dcb-label">${KSI.esc(quote.description)}</span><span>${uiMoney(quote.fast_track_price)}</span></div>
      <div class="dcb-row"><span class="dcb-label">Already charged for this visit</span><span>− ${uiMoney(quote.charged)}</span></div>
      <div class="dcb-row"><span class="dcb-label">To pay now</span><span>${uiMoney(quote.balance)}</span></div></div>
      <div class="muted-note" style="margin-top:6px">${quote.balance <= 0
        ? 'Nothing more to pay: the visit moves to fast track straight away.'
        : quote.reprice_bill
          ? `The visit's unpaid bill ${KSI.esc(quote.reprice_bill)} is increased by this amount. Fast track starts once it is paid.`
          : 'A bill for this amount is added to the queue. Fast track starts once it is paid.'}</div>`;
    button.disabled = false;
  } catch (error) {
    box.innerHTML = `<div class="field-error">${KSI.esc(error.message)}</div>`;
  }
}

async function csConfirmFastTrack() {
  const visitId = CASH_UI.fastTrack?.visitId;
  if (!visitId) return;
  const button = document.getElementById('ft-confirm-btn');
  button.disabled = true;
  try {
    const { result } = await KSI.post(`visits/${visitId}/fast-track`);
    closeModal('modal-fast-track');
    csRefreshViews();
    if (result.activated) {
      showToast('Fast track', `Visit ${result.visit_no} is now fast track.`, 'success');
    } else {
      showToast('Fast track', `Collect ${KSI.fmtMoney(result.balance)} on bill ${result.bill_no}. The visit moves up once it is paid.`, 'success');
      if (KSI.can('payments.process')) csOpenPayment(result.bill_id);
    }
  } catch (error) {
    button.disabled = false;
    showToast('Cannot fast track', error.message, 'error');
  }
}

function csRefreshViews() {
  if (!document.getElementById('module-cashier-payments').classList.contains('hidden')) csLoadQueue();
  if (!document.getElementById('module-cashier-history').classList.contains('hidden')) csLoadHistory();
}

// ── Process payment ──
function csBillFields(bill) {
  return [
    uiField('Patient', `${KSI.esc(bill.patient.full_name)}<div class="sub-text">${KSI.esc(bill.patient.reg_no)}</div>`),
    uiField('Bill No.', `<span class="visit-id">${KSI.esc(bill.bill_no)}</span>`),
    uiField('Service', KSI.esc(bill.description)),
    uiField('Visit No.', KSI.esc(bill.visit_no || '—')),
  ].join('');
}

async function csOpenPayment(billId) {
  let bill;
  try {
    bill = (await KSI.get(`bills/${billId}`)).bill;
  } catch (error) {
    showToast('Cannot open bill', error.message, 'error');
    return;
  }
  CASH_UI.bill = bill;
  const modal = document.getElementById('modal-process-payment');
  KSI.clearFieldErrors(modal);
  document.getElementById('pp-subtitle').textContent = `${bill.bill_no} · ${bill.patient.full_name}`;

  const alerts = [];
  if (bill.is_deferred) alerts.push('<div class="alert alert-warning" style="margin-bottom:12px">Emergency: this patient was sent for treatment before paying.</div>');
  if (bill.status !== 'pending') alerts.push(`<div class="alert alert-info" style="margin-bottom:12px">Status: ${KSI.esc(uiLabel('billStatus', bill.status))}.</div>`);
  document.getElementById('pp-alerts').innerHTML = alerts.join('');
  document.getElementById('pp-details').innerHTML = csBillFields(bill)
    + uiField('Raised By', KSI.esc(`${bill.created_by} · ${KSI.fmtDateTime(bill.created_at)}`))
    + uiField('Type', KSI.esc(uiLabel('billType', bill.bill_type)));
  document.getElementById('pp-amount').innerHTML = `
    <div class="dcb-row"><span class="dcb-label">Amount</span><span class="money">${uiMoney(bill.gross_amount)}</span></div>
    ${bill.discount_amount ? `<div class="dcb-row"><span class="dcb-label">Discount${bill.discount_type ? ` (${KSI.esc(bill.discount_type)})` : ''}</span><span class="money" style="color:var(--green)">−${uiMoney(bill.discount_amount)}</span></div>` : ''}
    <div class="dcb-row"><span class="dcb-label"><strong>Amount Due</strong></span><strong class="money">${uiMoney(bill.net_amount)}</strong></div>`;

  document.querySelectorAll('input[name="pp-method"]').forEach(input => { input.checked = false; });
  uiSyncRadioCards(modal);
  document.getElementById('pp-txn-ref').value = '';
  document.getElementById('pp-ref-group').style.display = 'none';

  const pending = bill.status === 'pending';
  const canPay = pending && KSI.can('payments.process');
  document.getElementById('pp-method-panel').style.display = canPay ? '' : 'none';
  document.getElementById('pp-confirm-btn').style.display = canPay ? '' : 'none';
  document.getElementById('pp-discount-btn').style.display = pending && !bill.discount_amount && KSI.can('payments.discount.request') ? '' : 'none';
  document.getElementById('pp-cancel-btn').style.display = pending && KSI.can('payments.cancel.request') ? '' : 'none';
  openModal('modal-process-payment');
}

function csOnMethodChange() {
  uiSyncRadioCards(document.getElementById('pp-method-panel'));
  const lipa = uiRadioValue('pp-method') === 'lipa_number';
  document.getElementById('pp-ref-group').style.display = lipa ? '' : 'none';
  if (lipa) setTimeout(() => document.getElementById('pp-txn-ref').focus(), 30);
}

async function csConfirmPayment() {
  const modal = document.getElementById('modal-process-payment');
  KSI.clearFieldErrors(modal);
  const method = uiRadioValue('pp-method');
  if (!method) {
    KSI.showFieldErrors(modal, { method: 'Choose how the patient is paying.' });
    return;
  }
  const bill = CASH_UI.bill;
  const result = await admBusy(document.getElementById('pp-confirm-btn'), async () => {
    try {
      return await KSI.post(`bills/${bill.id}/pay`, {
        method, txn_ref: document.getElementById('pp-txn-ref').value.trim(), row_version: bill.row_version,
      });
    } catch (error) {
      KSI.reportError(error, modal, 'Payment not recorded');
      if (['stale_record', 'not_payable'].includes(error.code)) csLoadQueue();
      return null;
    }
  });
  if (!result) return;
  // The payment is recorded: always close, confirm and refresh, even if no receipt came back to show.
  const receipt = result.receipt;
  closeModal('modal-process-payment');
  showToast('Payment received', receipt ? `${receipt.receipt_no} · ${uiMoney(receipt.amount)}` : `${bill.bill_no} is paid.`, 'success');
  if (receipt) csShowReceipt(receipt);
  csRefreshViews();
  if (typeof vsRefreshLists === 'function') vsRefreshLists();
}

// ── Receipts ──
function csReceiptHtml(r) {
  const row = (label, value, extra = '') => `<div class="receipt-row${extra}"><span>${KSI.esc(label)}</span><span>${value}</span></div>`;
  return `
    <div class="receipt-header">
      <div class="receipt-clinic">KSI CHARITABLE EYE CENTRE</div>
      <div class="receipt-sub">Dar es Salaam, Tanzania</div>
      <div class="receipt-sub" style="margin-top:6px;font-size:11px">OFFICIAL RECEIPT</div>
    </div>
    ${r.voided ? '<div class="receipt-row" style="justify-content:center;color:#B91C1C;font-weight:700">VOIDED</div>' : ''}
    ${row('Receipt No:', KSI.esc(r.receipt_no))}
    ${row('Date:', KSI.esc(KSI.fmtDateTime(r.received_at)))}
    <div class="receipt-row divider"></div>
    ${row('Patient:', KSI.esc(r.patient.full_name))}
    ${row('File No:', KSI.esc(r.patient.reg_no))}
    ${r.visit_no ? row('Visit No:', KSI.esc(r.visit_no)) : ''}
    ${row('Bill No:', KSI.esc(r.bill_no))}
    <div class="receipt-row divider"></div>
    ${r.items.map(item => row(`${item.description}${item.quantity !== 1 ? ` × ${item.quantity}` : ''}`, KSI.esc(uiMoney(item.line_total)))).join('')}
    ${r.discount_amount > 0 ? row(`Discount${r.discount_type ? ` (${r.discount_type})` : ''}`, `−${KSI.esc(uiMoney(r.discount_amount))}`) : ''}
    ${row('AMOUNT PAID:', KSI.esc(uiMoney(r.amount)), ' total')}
    <div class="receipt-row divider"></div>
    ${row('Payment Method:', KSI.esc(uiLabel('method', r.method)))}
    ${r.txn_ref ? row('Transaction Ref:', `<span style="font-family:monospace;font-size:11px">${KSI.esc(r.txn_ref)}</span>`) : ''}
    <div class="receipt-footer">
      <div>Received by: <strong>${KSI.esc(r.cashier)}</strong></div>
      <div style="margin-top:4px">Thank you for choosing KSI Charitable Eye Centre</div>
      <div style="margin-top:4px;font-size:9px">This is a computer-generated receipt</div>
      <div style="margin-top:4px;font-size:9px">Powered by Fabonic</div>
    </div>`;
}

function csShowReceipt(receipt) {
  CASH_UI.receipt = receipt;
  document.getElementById('rc-subtitle').textContent = receipt.receipt_no;
  document.getElementById('rc-meta').textContent = `${receipt.cashier} · ${KSI.fmtDateTime(receipt.received_at)}`;
  document.getElementById('rc-content').innerHTML = csReceiptHtml(receipt);
  openModal('modal-receipt');
}

async function csOpenReceipt(paymentId) {
  try {
    csShowReceipt((await KSI.get(`receipts/${paymentId}`)).receipt);
  } catch (error) {
    showToast('Cannot open receipt', error.message, 'error');
  }
}

function csPrintReceipt() {
  const receipt = CASH_UI.receipt;
  if (!receipt) return;
  const win = window.open('', '_blank', 'width=420,height=700');
  if (!win) {
    showToast('Pop-up blocked', 'Allow pop-ups for this site to print receipts.', 'error');
    return;
  }
  win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt ${KSI.esc(receipt.receipt_no)}</title><style>
    body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:18px}
    .receipt-header{text-align:center;border-bottom:1px dashed #888;padding-bottom:8px;margin-bottom:8px}
    .receipt-clinic{font-weight:700;font-size:14px}.receipt-sub{font-size:11px;color:#444}
    .receipt-row{display:flex;justify-content:space-between;gap:12px;padding:3px 0}
    .receipt-row.divider{border-top:1px dashed #888;padding:0;margin:6px 0}
    .receipt-row.total{font-weight:700;font-size:14px}
    .receipt-footer{text-align:center;margin-top:14px;font-size:11px;color:#444}
    @media print{@page{margin:8mm}}
  </style></head><body>${csReceiptHtml(receipt)}</body></html>`);
  win.document.close();
  win.focus();
  setTimeout(() => win.print(), 300);
}

// ── Discount request ──
async function csOpenDiscount() {
  const bill = CASH_UI.bill;
  if (!bill) return;
  try {
    if (!CASH_UI.discountTypes) CASH_UI.discountTypes = (await KSI.get('reference/lookups', { list: 'discount_type' })).values;
  } catch (error) {
    showToast('Cannot open form', error.message, 'error');
    return;
  }
  const form = document.getElementById('dr-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('dr-type').innerHTML = '<option value="">Select type…</option>'
    + CASH_UI.discountTypes.map(t => `<option value="${KSI.esc(t.value)}">${KSI.esc(t.label)}</option>`).join('');
  document.getElementById('dr-details').innerHTML = csBillFields(bill);
  document.getElementById('dr-amount').max = bill.net_amount;
  CASH_UI.discountFiles = [];
  csRenderDiscountFiles();
  csRecalcDiscount();
  closeModal('modal-process-payment');
  openModal('modal-discount');
}

function csRecalcDiscount() {
  const due = CASH_UI.bill?.net_amount || 0;
  const amount = Math.min(due, Math.max(0, Number(document.getElementById('dr-amount').value) || 0));
  document.getElementById('dr-orig').textContent = uiMoney(due);
  document.getElementById('dr-disc').textContent = uiMoney(amount);
  document.getElementById('dr-pct').textContent = due ? `${(amount / due * 100).toFixed(1)}%` : '0%';
  document.getElementById('dr-final').textContent = uiMoney(due - amount);
}

function csOnDiscountFiles(input) {
  for (const file of input.files) {
    const extension = file.name.split('.').pop().toLowerCase();
    if (!CASH_DOC_EXTENSIONS.includes(extension)) {
      showToast('File not accepted', `${file.name}: use PDF, JPG, PNG or DOCX.`, 'error');
    } else if (file.size > 10 * 1048576) {
      showToast('File too large', `${file.name} is over 10 MB.`, 'error');
    } else if (CASH_UI.discountFiles.length >= 5) {
      showToast('Too many files', 'Attach at most 5 documents.', 'error');
      break;
    } else if (!CASH_UI.discountFiles.some(f => f.name === file.name && f.size === file.size)) {
      CASH_UI.discountFiles.push(file);
    }
  }
  input.value = '';
  csRenderDiscountFiles();
}

function csRemoveDiscountFile(index) {
  CASH_UI.discountFiles.splice(index, 1);
  csRenderDiscountFiles();
}

function csRenderDiscountFiles() {
  document.getElementById('dr-file-list').innerHTML = CASH_UI.discountFiles.map((file, index) => `
    <div class="file-item" style="margin-top:6px;padding:8px 10px">
      <div class="file-info"><div class="file-name">${KSI.esc(file.name)}</div><div class="file-meta">${uiFileSize(file.size)}</div></div>
      <button class="btn btn-ghost btn-sm" type="button" onclick="csRemoveDiscountFile(${index})">Remove</button>
    </div>`).join('');
}

async function csSubmitDiscount() {
  const form = document.getElementById('dr-form');
  KSI.clearFieldErrors(form);
  const bill = CASH_UI.bill;
  const body = {
    discount_type: document.getElementById('dr-type').value,
    amount: document.getElementById('dr-amount').value,
    sponsor: document.getElementById('dr-sponsor').value.trim(),
    remarks: document.getElementById('dr-remarks').value.trim(),
    row_version: bill.row_version,
  };
  const button = document.getElementById('dr-submit');
  const result = await admBusy(button, async () => {
    try {
      const response = await KSI.post(`bills/${bill.id}/discount-request`, body);
      let failed = 0;
      for (const file of CASH_UI.discountFiles) {
        const data = new FormData();
        data.append('file', file, file.name);
        try {
          await KSI.upload(`approvals/${response.approval.id}/files`, data);
        } catch {
          failed++;
        }
      }
      return { ...response, failed };
    } catch (error) {
      KSI.reportError(error, form, 'Request not sent');
      return null;
    }
  });
  if (!result) return;
  closeModal('modal-discount');
  showToast('Discount sent for approval',
    `${result.approval.approval_no}${result.failed ? ` — ${result.failed} document(s) could not be attached` : ''}`,
    result.failed ? 'error' : 'success');
  csLoadQueue();
  updateApprovalsBadge();
}

// ── Bill cancellation request ──
function csOpenCancelBill() {
  const bill = CASH_UI.bill;
  if (!bill) return;
  const form = document.getElementById('cb-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('cb-details').innerHTML = csBillFields(bill) + uiField('Amount Due', `<strong class="money">${uiMoney(bill.net_amount)}</strong>`);
  closeModal('modal-process-payment');
  openModal('modal-cancel-bill');
  setTimeout(() => document.getElementById('cb-reason').focus(), 50);
}

async function csSubmitCancelBill() {
  const form = document.getElementById('cb-form');
  KSI.clearFieldErrors(form);
  const bill = CASH_UI.bill;
  const result = await admBusy(document.getElementById('cb-submit'), async () => {
    try {
      return await KSI.post(`bills/${bill.id}/cancel-request`, { reason: document.getElementById('cb-reason').value.trim(), row_version: bill.row_version });
    } catch (error) {
      KSI.reportError(error, form, 'Request not sent');
      return null;
    }
  });
  if (!result) return;
  closeModal('modal-cancel-bill');
  showToast('Cancellation sent for approval', result.approval.approval_no, 'success');
  csLoadQueue();
  updateApprovalsBadge();
}

// ════════════════════════════════════════════════════════════════════════
// Payment history
// ════════════════════════════════════════════════════════════════════════
function csRenderHistory() {
  csSwitchHistoryTab(CASH_UI.historyTab);
}

function csSwitchHistoryTab(tab) {
  CASH_UI.historyTab = tab;
  CASH_UI.historyPage = 1;
  document.querySelectorAll('#ch-tabs .tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
  csLoadHistory();
}

const csHistoryFilterChanged = uiDebounce(() => {
  CASH_UI.historyPage = 1;
  csLoadHistory();
});

function csClearHistoryDates() {
  document.getElementById('ch-from').value = '';
  document.getElementById('ch-to').value = '';
  csHistoryFilterChanged();
}

async function csLoadHistory() {
  uiTableMessage('ch-tbody', 12, 'Loading payment history…');
  try {
    const data = await KSI.get('bills/history', {
      tab: CASH_UI.historyTab,
      search: document.getElementById('ch-search').value.trim(),
      from: document.getElementById('ch-from').value,
      to: document.getElementById('ch-to').value,
      page: CASH_UI.historyPage,
      per_page: CASH_UI.perPage,
    });
    if (!data.bills.length) {
      uiTableMessage('ch-tbody', 12, 'No bills match the filters.');
    } else {
      document.getElementById('ch-tbody').innerHTML = data.bills.map(bill => {
        const paid = bill.paid_amount > 0;
        return `<tr>
          <td><span class="visit-id">${KSI.esc(bill.bill_no)}</span>${bill.visit_no ? `<div class="sub-text">${KSI.esc(bill.visit_no)}</div>` : ''}</td>
          <td>${uiPatientCell(bill.patient)}</td>
          <td style="font-size:12px">${KSI.esc(uiLabel('billType', bill.bill_type))}</td>
          <td class="money" style="text-align:right">${KSI.fmtMoney(bill.gross_amount)}</td>
          <td class="money" style="text-align:right">${bill.discount_amount ? KSI.fmtMoney(bill.discount_amount) : '—'}</td>
          <td class="money" style="text-align:right;font-weight:700">${KSI.fmtMoney(paid ? bill.paid_amount : bill.net_amount)}${bill.refunded_amount ? `<div class="sub-text">−${KSI.fmtMoney(bill.refunded_amount)} refunded</div>` : ''}</td>
          <td>${uiBadge('billStatus', bill.status)}</td>
          <td style="font-size:12px">${bill.payment ? KSI.esc(uiLabel('method', bill.payment.method)) : '—'}</td>
          <td style="font-family:var(--font-mono);font-size:11px">${KSI.esc(bill.payment?.txn_ref || '—')}</td>
          <td style="font-size:12px">${KSI.esc(bill.payment?.cashier || '—')}</td>
          <td style="font-size:12px;white-space:nowrap">${bill.paid_at ? KSI.fmtDateTime(bill.paid_at) : '—'}</td>
          <td><div class="row-actions">
            ${bill.payment ? `<button class="action-btn action-view" onclick="csOpenReceipt(${bill.payment.id})">Receipt</button>` : ''}
            <button class="action-btn action-edit" onclick="csOpenBillDetail(${bill.id})">Details</button>
          </div></td>
        </tr>`;
      }).join('');
    }
    document.getElementById('ch-count').textContent = uiCountText(data.page, data.per_page, data.total, 'bills');
    uiPagination('ch-pagination', data.page, data.per_page, data.total, page => { CASH_UI.historyPage = page; csLoadHistory(); });
  } catch (error) {
    uiTableMessage('ch-tbody', 12, error.message);
  }
}

async function csOpenBillDetail(billId) {
  const body = document.getElementById('bd-body');
  body.innerHTML = '<div class="muted-note" style="padding:20px;text-align:center">Loading…</div>';
  document.getElementById('bd-receipt-btn').style.display = 'none';
  openModal('modal-bill-detail');
  try {
    const { bill } = await KSI.get(`bills/${billId}`);
    document.getElementById('bd-title').textContent = `Bill ${bill.bill_no}`;
    document.getElementById('bd-subtitle').textContent = `${bill.patient.full_name} · ${bill.patient.reg_no}`;
    const table = (headers, rows) => rows.length
      ? `<div class="table-wrap"><table class="lines-table"><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`
      : '<div class="muted-note">None</div>';
    body.innerHTML = `
      <div class="detail-grid">
        ${uiField('Status', uiBadge('billStatus', bill.status))}
        ${uiField('Type', KSI.esc(uiLabel('billType', bill.bill_type)))}
        ${uiField('Raised', KSI.esc(`${KSI.fmtDateTime(bill.created_at)} · ${bill.created_by}`))}
        ${uiField('Visit No.', KSI.esc(bill.visit_no || '—'))}
        ${uiField('Amount', `<span class="money">${uiMoney(bill.gross_amount)}</span>`)}
        ${uiField('Discount', bill.discount_amount ? `<span class="money">${uiMoney(bill.discount_amount)}</span> ${KSI.esc(bill.discount_type || '')}${bill.discount_sponsor ? ` · ${KSI.esc(bill.discount_sponsor)}` : ''}` : '—')}
        ${uiField('Paid', `<span class="money">${uiMoney(bill.paid_amount)}</span>`)}
        ${uiField('Refunded', bill.refunded_amount ? `<span class="money">${uiMoney(bill.refunded_amount)}</span>` : '—')}
        ${bill.exemption_reason ? uiField('Exemption', KSI.esc(bill.exemption_reason), true) : ''}
      </div>
      <div class="form-section-title">Items</div>
      ${table(['Description', 'Qty', 'Unit Price', 'Total'], bill.items.map(i => `<tr><td>${KSI.esc(i.description)}</td><td>${i.quantity}</td><td class="money">${KSI.fmtMoney(i.unit_price)}</td><td class="money">${KSI.fmtMoney(i.line_total)}</td></tr>`))}
      <div class="form-section-title">Payments</div>
      ${table(['Receipt', 'Amount', 'Method', 'Reference', 'Cashier', 'Date'], bill.payments.map(p => `<tr><td>${KSI.esc(p.receipt_no)}${p.voided ? ' (voided)' : ''}</td><td class="money">${KSI.fmtMoney(p.amount)}</td><td>${KSI.esc(uiLabel('method', p.method))}</td><td>${KSI.esc(p.txn_ref || '—')}</td><td>${KSI.esc(p.cashier)}</td><td>${KSI.fmtDateTime(p.received_at)}</td></tr>`))}
      <div class="form-section-title">Approval Requests</div>
      ${table(['No.', 'Type', 'Status', 'Requested', 'Approved', 'By'], bill.approvals.map(a => `<tr><td>${KSI.esc(a.approval_no)}</td><td>${KSI.esc(uiLabel('approvalType', a.type))}</td><td>${uiBadge('approvalStatus', a.status)}</td><td class="money">${a.requested_amount === null ? '—' : KSI.fmtMoney(a.requested_amount)}</td><td class="money">${a.approved_amount === null ? '—' : KSI.fmtMoney(a.approved_amount)}</td><td>${KSI.esc(a.requested_by)}${a.decided_by ? ` → ${KSI.esc(a.decided_by)}` : ''}</td></tr>`))}
      <div class="form-section-title">Refunds</div>
      ${table(['No.', 'Status', 'Requested', 'Approved', 'Paid Out'], bill.refunds.map(r => `<tr><td>${KSI.esc(r.refund_no)}</td><td>${uiBadge('refundStatus', r.status)}</td><td class="money">${KSI.fmtMoney(r.requested_amount)}</td><td class="money">${r.approved_amount === null ? '—' : KSI.fmtMoney(r.approved_amount)}</td><td>${r.paid_out_at ? KSI.fmtDateTime(r.paid_out_at) : '—'}</td></tr>`))}`;
    const payment = bill.payments.find(p => !p.voided);
    const receiptButton = document.getElementById('bd-receipt-btn');
    if (payment) {
      receiptButton.style.display = '';
      receiptButton.onclick = () => csOpenReceipt(payment.id);
    }
  } catch (error) {
    body.innerHTML = `<div class="muted-note" style="padding:20px;text-align:center">${KSI.esc(error.message)}</div>`;
  }
}
