'use strict';
// ─── CASHIER · REFUNDS ─────────────────────────────────────────────────────
// Request (cashier) → decision in Approvals (may be lowered) → payout recorded
// by the cashier. A bill can have only one refund in progress at a time.

const REFUND_UI = { tab: 'pending', rows: [], patient: null, bills: [], bill: null, picker: null, payout: null,
  // Each tab keeps its own dates: In Progress is a worklist (all dates); History opens on today.
  dates: { pending: { from: '', to: '' }, history: null } };

function rfRenderModule() {
  document.getElementById('rf-new-btn').style.display = KSI.can('refunds.request') ? '' : 'none';
  rfSwitchTab(REFUND_UI.tab);
}

function rfSwitchTab(tab) {
  const from = document.getElementById('rf-from');
  const to = document.getElementById('rf-to');
  REFUND_UI.dates[REFUND_UI.tab] = { from: from.value, to: to.value };
  const saved = REFUND_UI.dates[tab] ?? { from: uiToday(), to: uiToday() };
  from.value = saved.from;
  to.value = saved.to;
  REFUND_UI.tab = tab;
  document.querySelectorAll('#rf-tabs .tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
  rfLoad();
}

const rfFilterChanged = uiDebounce(() => rfLoad());

async function rfLoad() {
  uiTableMessage('rf-tbody', 11, 'Loading refunds…');
  try {
    const { refunds } = await KSI.get('refunds', {
      tab: REFUND_UI.tab,
      search: document.getElementById('rf-search').value.trim(),
      from: document.getElementById('rf-from').value,
      to: document.getElementById('rf-to').value,
    });
    REFUND_UI.rows = refunds;
    const canPayout = KSI.can('refunds.payout');
    if (!refunds.length) {
      uiTableMessage('rf-tbody', 11, REFUND_UI.tab === 'pending' ? 'No refunds in progress.' : 'No refunds match the filters.');
    } else {
      document.getElementById('rf-tbody').innerHTML = refunds.map(r => `<tr>
        <td><span class="visit-id">${KSI.esc(r.refund_no)}</span>${r.approval_no ? `<div class="sub-text">${KSI.esc(r.approval_no)}</div>` : ''}</td>
        <td>${uiPatientCell(r.patient)}</td>
        <td style="font-size:12px">${KSI.esc(r.bill.bill_no)}<div class="sub-text">${KSI.esc(r.bill.receipt_no || '')}</div></td>
        <td style="font-size:12px">${KSI.esc(r.bill.description)}</td>
        <td class="money" style="text-align:right">${KSI.fmtMoney(r.requested_amount)}</td>
        <td class="money" style="text-align:right">${r.approved_amount === null ? '—' : KSI.fmtMoney(r.approved_amount)}</td>
        <td style="font-size:12px;max-width:240px">${KSI.esc(r.reason)}</td>
        <td>${uiBadge('refundStatus', r.status)}</td>
        <td style="font-size:12px;white-space:nowrap">${KSI.esc(r.requested_by)}<div class="sub-text">${KSI.fmtDateTime(r.requested_at)}</div></td>
        <td style="font-size:12px;white-space:nowrap">${r.decided_by ? `${KSI.esc(r.decided_by)}<div class="sub-text">${KSI.fmtDateTime(r.decided_at)}</div>` : '—'}
          ${r.paid_out_at ? `<div class="sub-text">Paid out ${KSI.fmtDateTime(r.paid_out_at)} · ${KSI.esc(r.paid_out_by || '')}</div>` : ''}</td>
        <td>${r.status === 'approved' && canPayout ? `<button class="action-btn action-status" onclick="rfOpenPayout(${r.id})">Record Payout</button>` : ''}</td>
      </tr>`).join('');
    }
    document.getElementById('rf-count').textContent = `${refunds.length} refund${refunds.length === 1 ? '' : 's'}`;
  } catch (error) {
    uiTableMessage('rf-tbody', 11, error.message);
  }
}

// ── New refund request ──
function rfOpenNew() {
  if (!KSI.can('refunds.request')) return;
  REFUND_UI.patient = null;
  REFUND_UI.bills = [];
  REFUND_UI.bill = null;
  const form = document.getElementById('nr-form');
  form.reset();
  KSI.clearFieldErrors(form);
  if (!REFUND_UI.picker) REFUND_UI.picker = uiPatientPicker('nr-patient-search', 'nr-results', rfSelectPatient);
  rfSelectPatient(null);
  openModal('modal-new-refund');
  setTimeout(() => document.getElementById('nr-patient-search').focus(), 50);
}

/** A walk-in has no patient record: find their counter-sale bill by its number instead. */
async function rfFindByNumber() {
  const form = document.getElementById('nr-form');
  KSI.clearFieldErrors(form);
  const number = document.getElementById('nr-number').value.trim();
  if (!number) {
    KSI.showFieldErrors(form, { number: 'Enter the bill, receipt or EXT number.' });
    return;
  }
  try {
    const result = await KSI.get('refunds/eligible', { number });
    await rfSelectPatient(result.customer, result.bills);
  } catch (error) {
    KSI.reportError(error, form, 'Not found');
  }
}

async function rfSelectPatient(patient, preloadedBills = null) {
  REFUND_UI.patient = patient;
  REFUND_UI.bill = null;
  document.getElementById('nr-picker').style.display = patient ? 'none' : '';
  const selected = document.getElementById('nr-selected');
  selected.style.display = patient ? '' : 'none';
  selected.innerHTML = patient ? uiPatientCard(patient, '<button class="btn btn-ghost btn-sm" type="button" onclick="rfSelectPatient(null)">Change</button>') : '';
  document.getElementById('nr-step-bill').style.display = patient ? '' : 'none';
  document.getElementById('nr-step-details').style.display = 'none';
  document.getElementById('nr-submit').disabled = true;
  if (!patient) {
    REFUND_UI.picker.reset();
    document.getElementById('nr-number').value = '';
    return;
  }
  const list = document.getElementById('nr-bills');
  list.innerHTML = '<div class="muted-note">Loading paid bills…</div>';
  try {
    REFUND_UI.bills = preloadedBills ?? (await KSI.get('refunds/eligible', { patient_id: patient.id })).bills;
    list.innerHTML = REFUND_UI.bills.length ? REFUND_UI.bills.map(b => `
      <label class="radio-card">
        <input type="radio" name="nr-bill" value="${b.id}" onchange="rfSelectBill()">
        <div style="flex:1">
          <div class="radio-card-label">${KSI.esc(b.bill_no)} · ${KSI.esc(b.description)}</div>
          <div class="radio-card-sub">${KSI.esc(b.receipt_no || '')} · paid ${KSI.fmtDateTime(b.paid_at)}${b.refunded_amount ? ` · ${uiMoney(b.refunded_amount)} already refunded` : ''}</div>
        </div>
        <strong class="money">${uiMoney(b.refundable)}</strong>
      </label>`).join('')
      : (patient.is_walk_in ? '<div class="muted-note">This walk-in has no paid bills that can be refunded (bills with a refund in progress are not listed).</div>' : '<div class="muted-note">This patient has no paid bills that can be refunded (bills with a refund in progress are not listed).</div>');
  } catch (error) {
    list.innerHTML = `<div class="muted-note">${KSI.esc(error.message)}</div>`;
  }
}

function rfSelectBill() {
  const id = Number(uiRadioValue('nr-bill'));
  REFUND_UI.bill = REFUND_UI.bills.find(b => b.id === id) || null;
  uiSyncRadioCards(document.getElementById('nr-bills'));
  if (!REFUND_UI.bill) return;
  document.getElementById('nr-step-details').style.display = '';
  const amount = document.getElementById('nr-amount');
  amount.value = REFUND_UI.bill.refundable;
  amount.max = REFUND_UI.bill.refundable;
  document.getElementById('nr-amount-hint').textContent = `Up to ${uiMoney(REFUND_UI.bill.refundable)}`;
  document.getElementById('nr-submit').disabled = false;
  setTimeout(() => document.getElementById('nr-reason').focus(), 30);
}

async function rfSubmit() {
  const form = document.getElementById('nr-form');
  KSI.clearFieldErrors(form);
  if (!REFUND_UI.bill) {
    KSI.showFieldErrors(form, { bill_id: 'Choose the bill to refund.' });
    return;
  }
  const result = await admBusy(document.getElementById('nr-submit'), async () => {
    try {
      return (await KSI.post('refunds', {
        bill_id: REFUND_UI.bill.id,
        amount: document.getElementById('nr-amount').value,
        reason: document.getElementById('nr-reason').value.trim(),
      })).refund;
    } catch (error) {
      KSI.reportError(error, form, 'Refund not requested');
      return null;
    }
  });
  if (!result) return;
  closeModal('modal-new-refund');
  showToast('Refund sent for approval', `${result.refund_no} · ${uiMoney(result.requested_amount)} (${result.approval_no})`, 'success');
  rfSwitchTab('pending');
  updateApprovalsBadge();
}

// ── Payout ──
function rfOpenPayout(refundId) {
  const refund = REFUND_UI.rows.find(r => r.id === refundId);
  if (!refund) return;
  REFUND_UI.payout = refund;
  const form = document.getElementById('po-form');
  form.reset();
  KSI.clearFieldErrors(form);
  uiSyncRadioCards(form);
  document.getElementById('po-ref-group').style.display = 'none';
  document.getElementById('po-subtitle').textContent = `${refund.refund_no} · ${refund.patient.full_name}`;
  document.getElementById('po-details').innerHTML = [
    uiField('Patient', `${KSI.esc(refund.patient.full_name)}<div class="sub-text">${KSI.esc(refund.patient.reg_no)}</div>`),
    uiField('Bill / Receipt', KSI.esc(`${refund.bill.bill_no} · ${refund.bill.receipt_no || ''}`)),
    uiField('Approved By', KSI.esc(`${refund.decided_by || '—'} · ${KSI.fmtDateTime(refund.decided_at)}`)),
    uiField('Amount to Pay Out', `<strong class="money">${uiMoney(refund.approved_amount)}</strong>${refund.approved_amount < refund.requested_amount ? `<div class="sub-text">Requested ${uiMoney(refund.requested_amount)}</div>` : ''}`),
    refund.decision_remarks ? uiField('Approver Remarks', KSI.esc(refund.decision_remarks), true) : '',
  ].join('');
  openModal('modal-refund-payout');
}

function rfOnPayoutMethod() {
  uiSyncRadioCards(document.getElementById('po-methods'));
  document.getElementById('po-ref-group').style.display = uiRadioValue('po-method') === 'lipa_number' ? '' : 'none';
}

async function rfConfirmPayout() {
  const form = document.getElementById('po-form');
  KSI.clearFieldErrors(form);
  const method = uiRadioValue('po-method');
  if (!method) {
    KSI.showFieldErrors(form, { method: 'Choose how the money was returned.' });
    return;
  }
  const refund = REFUND_UI.payout;
  const result = await admBusy(document.getElementById('po-confirm'), async () => {
    try {
      return (await KSI.post(`refunds/${refund.id}/payout`, {
        method, reference: document.getElementById('po-reference').value.trim(), row_version: refund.row_version,
      })).refund;
    } catch (error) {
      KSI.reportError(error, form, 'Payout not recorded');
      return null;
    }
  });
  if (!result) return;
  closeModal('modal-refund-payout');
  showToast('Refund paid out', `${result.refund_no} · ${uiMoney(result.approved_amount)}`, 'success');
  rfLoad();
}
