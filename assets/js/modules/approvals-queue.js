'use strict';
// ─── APPROVALS ─────────────────────────────────────────────────────────────
// Discount, bill-cancellation and refund requests. Approvers may lower amounts;
// nobody can decide a request they raised (enforced by the server).

const APPR_UI = { pendingPage: 1, historyPage: 1, perPage: 25, historyTab: 'approved', current: null };
const APPR_CARDS = [
  ['', 'Total Pending', 'var(--purple)'], ['discount', 'Discounts', 'var(--blue)'],
  ['bill_cancellation', 'Cancellations', 'var(--red)'], ['refund', 'Refunds', 'var(--teal)'],
];
const APPR_TYPE_TAG = { discount: 'appr-discount', bill_cancellation: 'appr-cancel', refund: 'appr-refund', diagnostic_cancellation: 'appr-cancel', optical_cancellation: 'appr-cancel', theatre_cancellation: 'appr-cancel' };

async function updateApprovalsBadge() {
  const badge = document.getElementById('approvals-badge');
  if (!badge) return;
  if (!KSI.can('approvals.view')) {
    badge.style.display = 'none';
    return;
  }
  try {
    const { pending_counts: counts } = await KSI.get('approvals/count', null, { trackActivity: false });
    badge.textContent = counts.all;
    badge.style.display = counts.all ? '' : 'none';
  } catch {
    // the badge is informational only
  }
}

function apTypeTag(approval) {
  return `<span class="appr-type-tag ${APPR_TYPE_TAG[approval.type] || ''}">${KSI.esc(uiLabel('approvalType', approval.type))}</span>`;
}

function apDetailsText(approval) {
  const bill = approval.bill;
  if (approval.type === 'discount') {
    return `${approval.discount_type || 'Discount'}: ${uiMoney(approval.requested_amount)} off a ${uiMoney(bill?.gross_amount)} bill${approval.sponsor ? ` · Sponsor: ${approval.sponsor}` : ''}`;
  }
  if (approval.type === 'bill_cancellation') return `Cancel bill for ${uiMoney(bill?.net_amount)} — ${bill?.description || ''}`;
  if (approval.type === 'refund') return `Refund ${uiMoney(approval.requested_amount)} of ${uiMoney(bill?.paid_amount)} paid${approval.refund?.receipt_no ? ` (${approval.refund.receipt_no})` : ''}`;
  if (approval.type === 'theatre_cancellation') {
    const theatre = approval.theatre_booking;
    return `Cancel theatre case ${theatre?.booking_no || ''} — ${theatre?.surgery || 'procedure'}`;
  }
  if (approval.type === 'optical_cancellation') {
    const order = approval.optical_order;
    return `Cancel spectacle order ${order?.order_no || ''} — ${uiMoney(order?.total_amount)}`;
  }
  if (approval.type === 'diagnostic_cancellation') {
    const booking = approval.booking;
    return `Cancel ${booking?.procedure || 'a diagnostic test'} booked for ${KSI.fmtDateTime(booking?.scheduled_for)}${booking?.booking_no ? ` (${booking.booking_no})` : ''}`;
  }
  return '';
}

function apRefreshViews() {
  if (!document.getElementById('module-approvals-pending').classList.contains('hidden')) apLoadPending();
  if (!document.getElementById('module-approvals-history').classList.contains('hidden')) apLoadHistory();
  updateApprovalsBadge();
}

// ════════════════════════════════════════════════════════════════════════
// Pending
// ════════════════════════════════════════════════════════════════════════
function apRenderPending() {
  APPR_UI.pendingPage = 1;
  apLoadPending();
}

const apFilterChanged = uiDebounce(() => {
  APPR_UI.pendingPage = 1;
  apLoadPending();
});

function apSetType(type) {
  document.getElementById('ap-filter-type').value = type;
  APPR_UI.pendingPage = 1;
  apLoadPending();
}

async function apLoadPending() {
  uiTableMessage('ap-tbody', 8, 'Loading approval requests…');
  try {
    const data = await KSI.get('approvals', {
      status: 'pending',
      type: document.getElementById('ap-filter-type').value,
      search: document.getElementById('ap-search').value.trim(),
      from: document.getElementById('ap-from').value,
      to: document.getElementById('ap-to').value,
      page: APPR_UI.pendingPage,
      per_page: APPR_UI.perPage,
    });
    const activeType = document.getElementById('ap-filter-type').value;
    document.getElementById('ap-queue-cards').innerHTML = APPR_CARDS.map(([type, label, color]) => `
      <div class="pay-queue-card${activeType === type ? ' active' : ''}" onclick="apSetType('${type}')">
        <div class="pqc-num">${type ? data.pending_counts[type] : data.pending_counts.all}</div>
        <div class="pqc-label">${label}</div>
        <div class="pqc-dot" style="background:${color}"></div>
      </div>`).join('');

    const canDecide = KSI.can('approvals.decide');
    if (!data.approvals.length) {
      uiTableMessage('ap-tbody', 8, data.pending_counts.all ? 'No requests match the filters.' : 'Nothing is waiting for approval.');
    } else {
      document.getElementById('ap-tbody').innerHTML = data.approvals.map(a => `<tr>
        <td><span class="visit-id">${KSI.esc(a.approval_no)}</span></td>
        <td>${apTypeTag(a)}</td>
        <td>${uiPatientCell(a.patient)}</td>
        <td style="font-size:12px">${KSI.esc(a.bill?.bill_no || '—')}${a.bill?.visit_no ? `<div class="sub-text">${KSI.esc(a.bill.visit_no)}</div>` : ''}</td>
        <td style="font-size:12px;max-width:280px">${KSI.esc(apDetailsText(a))}${a.request_remarks ? `<div class="sub-text">“${KSI.esc(a.request_remarks.slice(0, 90))}${a.request_remarks.length > 90 ? '…' : ''}”</div>` : ''}${a.file_count ? `<div class="sub-text">📎 ${a.file_count} document${a.file_count === 1 ? '' : 's'}</div>` : ''}</td>
        <td style="font-size:12px">${KSI.esc(a.requested_by)}</td>
        <td style="font-size:12px;white-space:nowrap">${KSI.fmtDateTime(a.requested_at)}</td>
        <td><div class="row-actions">
          <button class="action-btn action-view" onclick="apOpen(${a.id})">View</button>
          ${canDecide && apMayDecide(a) ? `<button class="action-btn action-status" onclick="apOpenApprove(${a.id})">Approve</button>
            <button class="action-btn" style="background:var(--red-bg);color:#B91C1C;border-color:#FECACA" onclick="apOpenReject(${a.id})">Reject</button>` : ''}
          ${a.is_own_request ? `<span class="muted-note">Your request${apMayDecide(a) ? ' — you may decide it' : ''}</span>` : ''}
        </div></td>
      </tr>`).join('');
    }
    document.getElementById('ap-count').textContent = uiCountText(data.page, data.per_page, data.total, 'requests');
    uiPagination('ap-pagination', data.page, data.per_page, data.total, page => { APPR_UI.pendingPage = page; apLoadPending(); });
    const badge = document.getElementById('approvals-badge');
    if (badge) {
      badge.textContent = data.pending_counts.all;
      badge.style.display = data.pending_counts.all ? '' : 'none';
    }
  } catch (error) {
    uiTableMessage('ap-tbody', 8, error.message);
  }
}

// ── Detail ──
async function apLoad(id) {
  APPR_UI.current = (await KSI.get(`approvals/${id}`)).approval;
  return APPR_UI.current;
}

async function apOpen(id) {
  let a;
  try {
    a = await apLoad(id);
  } catch (error) {
    showToast('Cannot open request', error.message, 'error');
    return;
  }
  document.getElementById('va-subtitle').textContent = `${a.approval_no} · ${uiLabel('approvalType', a.type)}`;
  const alerts = [];
  if (a.status === 'pending' && a.is_own_request) {
    alerts.push(apMayDecide(a)
      ? '<div class="alert alert-warning" style="margin-bottom:12px">You raised this request. You may decide it yourself; it will be marked <strong>self-approved</strong> in the records and reports.</div>'
      : '<div class="alert alert-info" style="margin-bottom:12px">You raised this request, so another approver must decide it.</div>');
  }
  document.getElementById('va-alerts').innerHTML = alerts.join('');
  document.getElementById('va-info').innerHTML = [
    uiField('Status', uiBadge('approvalStatus', a.status)),
    uiField('Type', apTypeTag(a)),
    uiField('Requested By', KSI.esc(`${a.requested_by} · ${KSI.fmtDateTime(a.requested_at)}`)),
    a.decided_by ? uiField('Decided By', KSI.esc(`${a.decided_by}${a.is_self_approved ? ' (self-approved)' : ''} · ${KSI.fmtDateTime(a.decided_at)}`)) : '',
    a.patient ? uiField('Patient', `${KSI.esc(a.patient.full_name)}<div class="sub-text">${KSI.esc(a.patient.reg_no)} · ${a.patient.age} yrs</div>`) : '',
    a.bill ? uiField('Bill', `${KSI.esc(a.bill.bill_no)}<div class="sub-text">${KSI.esc(a.bill.description)}${a.bill.visit_no ? ` · ${KSI.esc(a.bill.visit_no)}` : ''}</div>`) : '',
    a.theatre_booking ? uiField('Theatre Case', `${KSI.esc(a.theatre_booking.booking_no)}<div class="sub-text">${KSI.esc(a.theatre_booking.surgery || '')} · ${KSI.esc(uiLabel('theatreStatus', a.theatre_booking.status))}</div>`) : '',
    a.optical_order ? uiField('Spectacle Order', `${KSI.esc(a.optical_order.order_no)}<div class="sub-text">${KSI.esc(uiLabel('opticalStatus', a.optical_order.status))}</div>`) : '',
    a.booking ? uiField('Diagnostic Booking', `${KSI.esc(a.booking.booking_no)}<div class="sub-text">${KSI.esc(a.booking.procedure)} · ${KSI.esc(uiLabel('eye', a.booking.eye))}</div>`) : '',
  ].join('');
  document.getElementById('va-details').innerHTML = [
    uiField('Request', KSI.esc(apDetailsText(a)), true),
    a.requested_amount !== null ? uiField('Requested Amount', `<span class="money">${uiMoney(a.requested_amount)}</span>`) : '',
    a.approved_amount !== null ? uiField('Approved Amount', `<strong class="money">${uiMoney(a.approved_amount)}</strong>`) : '',
    a.bill ? uiField('Bill Status', uiBadge('billStatus', a.bill.status)) : '',
    a.refund ? uiField('Refund', `${KSI.esc(a.refund.refund_no)} ${uiBadge('refundStatus', a.refund.status)}`) : '',
    uiField(a.type === 'refund' ? 'Reason for Refund' : 'Requester Remarks', KSI.esc(a.request_remarks || '—'), true),
    a.decision_remarks ? uiField('Decision Remarks', KSI.esc(a.decision_remarks), true) : '',
  ].join('');
  document.getElementById('va-files-panel').style.display = a.files.length ? '' : 'none';
  document.getElementById('va-files').innerHTML = a.files.map(f => `
    <div class="file-item">
      <div class="file-info"><div class="file-name">${KSI.esc(f.name)}</div><div class="file-meta">${uiFileSize(f.size_bytes)} · ${KSI.fmtDateTime(f.uploaded_at)}</div></div>
      <div class="file-actions">
        <button class="btn btn-secondary btn-sm" type="button" onclick="apViewFile(${a.id}, ${f.id})">View</button>
        <button class="btn btn-secondary btn-sm" type="button" onclick="apDownloadFile(${a.id}, ${f.id})">Download</button>
      </div>
    </div>`).join('');
  const canDecide = a.status === 'pending' && KSI.can('approvals.decide') && apMayDecide(a);
  document.getElementById('va-actions').innerHTML = canDecide
    ? `<button class="btn btn-danger" type="button" onclick="apOpenReject(${a.id})">Reject</button>
       <button class="btn btn-primary" type="button" style="background:var(--green);border-color:var(--green)" onclick="apOpenApprove(${a.id})">Approve</button>`
    : '';
  openModal('modal-view-approval');
}

function apViewFile(approvalId, fileId) {
  KSI.openFile(`approvals/${approvalId}/files/${fileId}`).catch(error => showToast('Cannot open document', error.message, 'error'));
}

function apDownloadFile(approvalId, fileId) {
  KSI.download(`approvals/${approvalId}/files/${fileId}`).catch(error => showToast('Cannot download document', error.message, 'error'));
}

// ── Approve ──
async function apOpenApprove(id) {
  let a;
  try {
    a = APPR_UI.current?.id === id ? APPR_UI.current : await apLoad(id);
  } catch (error) {
    showToast('Cannot open request', error.message, 'error');
    return;
  }
  const form = document.getElementById('apv-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('apv-subtitle').textContent = `${a.approval_no} · ${a.patient?.full_name || ''}`;
  document.getElementById('apv-summary').textContent = apDetailsText(a);
  const negotiable = a.type === 'discount' || a.type === 'refund';
  document.getElementById('apv-amount-section').style.display = negotiable ? '' : 'none';
  if (negotiable) {
    document.getElementById('apv-base-label').textContent = a.type === 'discount' ? 'Amount due now' : 'Amount paid';
    document.getElementById('apv-base').textContent = uiMoney(a.type === 'discount' ? a.bill.net_amount : a.bill.paid_amount);
    document.getElementById('apv-requested').textContent = uiMoney(a.requested_amount);
    document.getElementById('apv-amount-label').firstChild.textContent = a.type === 'discount' ? 'Approved Discount (TZS) ' : 'Approved Refund (TZS) ';
    const input = document.getElementById('apv-amount');
    input.value = a.requested_amount;
    input.max = apApproveCeiling(a);
    document.getElementById('apv-amount-hint').textContent = apApproveCeiling(a) > a.requested_amount
      ? `You may approve less or more than requested, up to ${uiMoney(apApproveCeiling(a))} (the amount owed).`
      : 'You may approve less than requested, never more.';
    apRecalcApprove();
  }
  closeModal('modal-view-approval');
  openModal('modal-approve');
}

function apRecalcApprove() {
  const a = APPR_UI.current;
  if (!a || !(a.type === 'discount' || a.type === 'refund')) return;
  const amount = Math.max(0, Math.min(apApproveCeiling(a), Number(document.getElementById('apv-amount').value) || 0));
  if (a.type === 'discount') {
    document.getElementById('apv-result-label').textContent = 'Patient will pay';
    document.getElementById('apv-result').textContent = uiMoney(Math.max(0, a.bill.net_amount - amount));
  } else {
    document.getElementById('apv-result-label').textContent = 'Patient receives back';
    document.getElementById('apv-result').textContent = uiMoney(amount);
  }
}

async function apConfirmApprove() {
  const a = APPR_UI.current;
  const form = document.getElementById('apv-form');
  KSI.clearFieldErrors(form);
  const body = { remarks: document.getElementById('apv-remarks').value.trim(), row_version: a.row_version };
  if (a.type === 'discount' || a.type === 'refund') body.approved_amount = document.getElementById('apv-amount').value;
  const result = await admBusy(document.getElementById('apv-confirm'), async () => {
    try {
      return (await KSI.post(`approvals/${a.id}/approve`, body)).approval;
    } catch (error) {
      KSI.reportError(error, form, 'Not approved');
      if (['already_decided', 'stale_record'].includes(error.code)) apRefreshViews();
      return null;
    }
  });
  if (!result) return;
  closeModal('modal-approve');
  showToast('Request approved', `${result.approval_no}${result.approved_amount !== null ? ` · ${uiMoney(result.approved_amount)}` : ''}`, 'success');
  APPR_UI.current = null;
  apRefreshViews();
}

// ── Reject ──
async function apOpenReject(id) {
  let a;
  try {
    a = APPR_UI.current?.id === id ? APPR_UI.current : await apLoad(id);
  } catch (error) {
    showToast('Cannot open request', error.message, 'error');
    return;
  }
  const form = document.getElementById('rej-form');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('rej-subtitle').textContent = `${a.approval_no} · ${apDetailsText(a)}`;
  closeModal('modal-view-approval');
  openModal('modal-reject');
  setTimeout(() => document.getElementById('rej-remarks').focus(), 50);
}

async function apConfirmReject() {
  const a = APPR_UI.current;
  const form = document.getElementById('rej-form');
  KSI.clearFieldErrors(form);
  const result = await admBusy(document.getElementById('rej-confirm'), async () => {
    try {
      return (await KSI.post(`approvals/${a.id}/reject`, { remarks: document.getElementById('rej-remarks').value.trim(), row_version: a.row_version })).approval;
    } catch (error) {
      KSI.reportError(error, form, 'Not rejected');
      if (['already_decided', 'stale_record'].includes(error.code)) apRefreshViews();
      return null;
    }
  });
  if (!result) return;
  closeModal('modal-reject');
  showToast('Request rejected', result.approval_no, 'success');
  APPR_UI.current = null;
  apRefreshViews();
}

// ════════════════════════════════════════════════════════════════════════
// History
// ════════════════════════════════════════════════════════════════════════
function apRenderHistory() {
  apSwitchHistoryTab(APPR_UI.historyTab);
}

function apSwitchHistoryTab(tab) {
  APPR_UI.historyTab = tab;
  APPR_UI.historyPage = 1;
  document.querySelectorAll('#ah-tabs .tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
  apLoadHistory();
}

const apHistoryFilterChanged = uiDebounce(() => {
  APPR_UI.historyPage = 1;
  apLoadHistory();
});

async function apLoadHistory() {
  uiTableMessage('ah-tbody', 9, 'Loading decisions…');
  try {
    const data = await KSI.get('approvals', {
      status: APPR_UI.historyTab,
      type: document.getElementById('ah-filter-type').value,
      search: document.getElementById('ah-search').value.trim(),
      from: document.getElementById('ah-from').value,
      to: document.getElementById('ah-to').value,
      page: APPR_UI.historyPage,
      per_page: APPR_UI.perPage,
    });
    if (!data.approvals.length) {
      uiTableMessage('ah-tbody', 9, 'No decisions match the filters.');
    } else {
      document.getElementById('ah-tbody').innerHTML = data.approvals.map(a => `<tr>
        <td><button class="btn btn-ghost btn-sm visit-id" type="button" onclick="apOpen(${a.id})">${KSI.esc(a.approval_no)}</button></td>
        <td>${apTypeTag(a)}</td>
        <td>${uiPatientCell(a.patient)}</td>
        <td style="font-size:12px;max-width:280px">${KSI.esc(apDetailsText(a))}${a.approved_amount !== null && a.approved_amount !== a.requested_amount ? `<div class="sub-text">Approved ${uiMoney(a.approved_amount)}</div>` : ''}</td>
        <td style="font-size:12px">${KSI.esc(a.requested_by)}</td>
        <td style="font-size:12px;white-space:nowrap">${KSI.fmtDateTime(a.requested_at)}</td>
        <td style="font-size:12px;white-space:nowrap">${KSI.fmtDateTime(a.decided_at)}</td>
        <td style="font-size:12px">${KSI.esc(a.decided_by || '—')}${a.is_self_approved ? '<div class="sub-text" style="color:var(--amber)">Self-approved</div>' : ''}</td>
        <td style="font-size:12px;max-width:220px">${KSI.esc(a.decision_remarks || '—')}</td>
      </tr>`).join('');
    }
    document.getElementById('ah-count').textContent = uiCountText(data.page, data.per_page, data.total, 'decisions');
    uiPagination('ah-pagination', data.page, data.per_page, data.total, page => { APPR_UI.historyPage = page; apLoadHistory(); });
  } catch (error) {
    uiTableMessage('ah-tbody', 9, error.message);
  }
}

/** Two people decide money matters, unless this person has been trusted to decide their own requests. */
function apMayDecide(a) {
  return !a.is_own_request || KSI.can('approvals.decide_own');
}

/** The most that may be approved: what was asked, or for some approvers a discount up to what is still owed. */
function apApproveCeiling(a) {
  if (a.type === 'discount' && KSI.can('approvals.increase') && a.bill) {
    return Math.max(a.requested_amount, a.bill.gross_amount - a.bill.discount_amount);
  }
  return a.requested_amount;
}
