'use strict';
// ─── STORES ───────────────────────────────────────────────────────────────
// The store room: what is held, what departments ask for and receive, and
// how it is bought. Quantities only move through deliveries, issues and
// recorded corrections. Medicine issued to the pharmacy tops up its shelf.

const ST = {
  options: null,
  stock: [],
  mine: [],
  requests: [],
  history: [],
  quotations: [],
  orders: [],
  deliveries: [],
  current: null,       // record open in a modal
  editing: null,       // requisition being written on the form page
  reqItems: [],        // lines on the requisition form
  quoteItems: [],      // lines on the quotation form
  editingQuote: null,
  editingSupplier: null,
  receiveOrder: null,
};

async function stOptions(force = false) {
  if (force) ST.options = null;
  // Departments that only raise requisitions get the shorter list, without costs or suppliers.
  if (!ST.options) ST.options = await KSI.get(KSI.can('stores.stock.view') ? 'stores/options' : 'stores/requisition-options');
  return ST.options;
}

const stMoney = amount => 'TZS ' + KSI.fmtMoney(amount);

function stFillFilters() {
  const fill = (id, placeholder, pairs) => {
    const select = document.getElementById(id);
    if (!select || select.dataset.filled) return;
    admFillSelect(id, placeholder, pairs);
    select.dataset.filled = '1';
  };
  const departments = (ST.options?.departments || []).map(d => [d.id, d.name]);
  const suppliers = (ST.options?.suppliers || []).map(s => [s.id, s.name]);
  fill('st-req-filter-department', 'All departments', departments);
  fill('st-hist-filter-department', 'All departments', departments);
  fill('st-stock-filter-category', 'All categories', (ST.options?.categories || []).map(c => [c, c]));
  fill('st-quote-filter-supplier', 'All suppliers', suppliers);
  fill('st-order-filter-supplier', 'All suppliers', suppliers);
}

function stItemsCell(record) {
  const items = record.items || [];
  if (!items.length) return '<span class="muted-note">—</span>';
  const shown = items.slice(0, 2).map(i => `${KSI.esc(i.name)} ×${i.qty_requested ?? i.quantity}`).join('<br>');
  return shown + (items.length > 2 ? `<div class="muted-note">and ${items.length - 2} more</div>` : '');
}

// ─── Requisition form ─────────────────────────────────────────────────────

async function stOpenRequisitionForm() {
  await stOptions();
  stFillFilters();
  if (!ST.editing) stResetRequisitionForm();
}

function stNewRequisition() {
  ST.editing = null;
  ST.reqItems = [];
  showModule('stores-requisition-form');
  stResetRequisitionForm();
}

function stResetRequisitionForm() {
  document.getElementById('st-req-form-title').textContent = ST.editing
    ? `Requisition ${ST.editing.requisition_no}` : 'What does your department need?';
  admFillSelect('st-req-department', 'Choose a department', (ST.options?.departments || []).map(d => [d.id, d.name]));
  const mine = (ST.options?.departments || []).find(d => d.name === KSI.user?.department);
  document.getElementById('st-req-department').value = ST.editing?.department_id || mine?.id || '';
  document.getElementById('st-req-priority').value = ST.editing?.priority || 'normal';
  document.getElementById('st-req-remarks').value = ST.editing?.remarks || '';
  stRenderRequisitionItems();
}

function stRequisitionItemPicker() {
  const chosen = ST.reqItems.map(i => i.item_id);
  admFillSelect('st-req-item-pick', 'Add an item', (ST.options?.items || [])
    .filter(i => !chosen.includes(i.id))
    .map(i => [i.id, i.name]));
}

function stReqAddItem(itemId) {
  const item = (ST.options?.items || []).find(i => i.id === Number(itemId));
  if (!item) return;
  ST.reqItems.push({ item_id: item.id, name: item.name, unit: item.unit, in_store: item.quantity, quantity: 1, remarks: '' });
  stRenderRequisitionItems();
}

function stReqRemoveItem(itemId) {
  ST.reqItems = ST.reqItems.filter(i => i.item_id !== itemId);
  stRenderRequisitionItems();
}

function stReqSetItem(itemId, field, value) {
  const line = ST.reqItems.find(i => i.item_id === itemId);
  if (!line) return;
  line[field] = field === 'quantity' ? Math.max(1, Number(value) || 1) : value;
}

function stRenderRequisitionItems() {
  const body = document.getElementById('st-req-items');
  if (!body) return;
  body.innerHTML = ST.reqItems.length
    ? ST.reqItems.map(i => `<tr>
        <td>${KSI.esc(i.name)}<div class="muted-note">${KSI.esc(i.unit)}</div></td>
        <td>${i.in_store}</td>
        <td><input type="number" class="form-input" min="1" value="${i.quantity}" style="width:90px"
             onchange="stReqSetItem(${i.item_id}, 'quantity', this.value)"></td>
        <td><input class="form-input" maxlength="190" value="${KSI.esc(i.remarks || '')}"
             onchange="stReqSetItem(${i.item_id}, 'remarks', this.value)"></td>
        <td><button class="action-btn action-cancel" onclick="stReqRemoveItem(${i.item_id})">Remove</button></td>
      </tr>`).join('')
    : '<tr class="table-state"><td colspan="5">No items added yet.</td></tr>';
  stRequisitionItemPicker();
}

async function stSaveRequisition(button, submit) {
  const page = document.getElementById('module-stores-requisition-form');
  KSI.clearFieldErrors(page);
  if (!ST.reqItems.length) {
    KSI.showFieldErrors(page, { items: 'Add at least one item.' });
    return;
  }
  const payload = {
    department_id: Number(document.getElementById('st-req-department').value) || 0,
    priority: document.getElementById('st-req-priority').value,
    remarks: document.getElementById('st-req-remarks').value,
    items: ST.reqItems.map(i => ({ item_id: i.item_id, quantity: i.quantity, remarks: i.remarks })),
    submit,
  };
  await admBusy(button, async () => {
    try {
      const { requisition } = ST.editing
        ? await KSI.put(`stores/requisitions/${ST.editing.id}`, { ...payload, row_version: ST.editing.row_version })
        : await KSI.post('stores/requisitions', payload);
      ST.editing = null;
      ST.reqItems = [];
      showToast(submit ? 'Sent to the store' : 'Draft saved', requisition.requisition_no, 'success');
      showModule('stores-my-requisitions');
    } catch (error) {
      KSI.reportError(error, page, 'Could not save the requisition');
    }
  });
}

// ─── My requisitions ──────────────────────────────────────────────────────

async function stOpenMine() {
  await stOptions();
  stFillFilters();
  await stLoadMine();
}

async function stLoadMine() {
  if (!document.getElementById('st-mine-tbody')) return;
  admTableMessage('st-mine-tbody', 7, 'Loading requisitions…');
  try {
    const { requisitions } = await KSI.get('stores/requisitions', {
      scope: 'mine',
      search: document.getElementById('st-mine-search')?.value || '',
      status: document.getElementById('st-mine-filter-status')?.value || '',
    }, { trackActivity: false });
    ST.mine = requisitions;
    stRenderMine();
  } catch (error) {
    admTableMessage('st-mine-tbody', 7, 'Could not load your requisitions.');
  }
}

function stRenderMine() {
  const count = document.getElementById('st-mine-count');
  if (count) count.textContent = `${ST.mine.length} requisition${ST.mine.length === 1 ? '' : 's'}`;
  if (!ST.mine.length) {
    admTableMessage('st-mine-tbody', 7, 'You have not raised any requisitions yet.');
    return;
  }
  document.getElementById('st-mine-tbody').innerHTML = ST.mine.map(r => `<tr>
    <td><div style="font-weight:600">${KSI.esc(r.requisition_no)}</div></td>
    <td>${KSI.esc(r.department)}</td>
    <td>${stItemsCell(r)}</td>
    <td>${uiBadge('requisitionPriority', r.priority)}</td>
    <td>${KSI.fmtDate(r.created_at)}</td>
    <td>${uiBadge('requisitionStatus', r.status)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="stOpenRequisition(${r.id})">View</button>
        ${r.status === 'draft' ? `<button class="action-btn action-edit" onclick="stEditRequisition(${r.id})">Edit</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

async function stEditRequisition(id) {
  const { requisition } = await KSI.get(`stores/requisitions/${id}`);
  ST.editing = requisition;
  ST.reqItems = requisition.items.map(i => ({
    item_id: i.item_id, name: i.name, unit: i.unit, in_store: i.in_store, quantity: i.qty_requested, remarks: i.remarks,
  }));
  showModule('stores-requisition-form');
  stResetRequisitionForm();
}

// ─── Requests to the store ────────────────────────────────────────────────

async function stOpenRequests() {
  await stOptions();
  stFillFilters();
  await stLoadRequests();
}

async function stLoadRequests() {
  if (!document.getElementById('st-req-tbody')) return;
  admTableMessage('st-req-tbody', 7, 'Loading requests…');
  try {
    const status = document.getElementById('st-req-filter-status')?.value || '';
    const { requisitions } = await KSI.get('stores/requisitions', {
      scope: status === '' ? 'open' : '',
      status,
      search: document.getElementById('st-req-search')?.value || '',
      department_id: document.getElementById('st-req-filter-department')?.value || '',
    }, { trackActivity: false });
    ST.requests = requisitions;
    stRenderRequests();
  } catch (error) {
    admTableMessage('st-req-tbody', 7, 'Could not load the requests.');
  }
}

function stRenderRequests() {
  const count = document.getElementById('st-req-count');
  if (count) count.textContent = `${ST.requests.length} request${ST.requests.length === 1 ? '' : 's'}`;
  if (!ST.requests.length) {
    admTableMessage('st-req-tbody', 7, 'Nothing waiting at the moment.');
    return;
  }
  document.getElementById('st-req-tbody').innerHTML = ST.requests.map(r => `<tr>
    <td><div style="font-weight:600">${KSI.esc(r.requisition_no)}</div><div class="muted-note">${KSI.fmtDate(r.created_at)}</div></td>
    <td>${KSI.esc(r.department)}</td>
    <td>${stItemsCell(r)}</td>
    <td>${uiBadge('requisitionPriority', r.priority)}</td>
    <td>${KSI.esc(r.created_by)}</td>
    <td>${uiBadge('requisitionStatus', r.status)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="stOpenRequisition(${r.id})">${r.status === 'submitted' ? 'Decide' : 'Issue'}</button>
      </div>
    </td>
  </tr>`).join('');
}

async function stOpenRequisition(id) {
  const { requisition } = await KSI.get(`stores/requisitions/${id}`);
  ST.current = requisition;
  const deciding = requisition.status === 'submitted' && KSI.can('requisitions.manage');
  const issuing = ['approved', 'partly_issued'].includes(requisition.status) && KSI.can('requisitions.manage');
  document.getElementById('st-reqd-title').textContent = `Requisition ${requisition.requisition_no}`;
  document.getElementById('st-reqd-subtitle').textContent =
    `${requisition.department} · raised by ${requisition.created_by} · ${uiLabel('requisitionPriority', requisition.priority)}`;

  document.getElementById('st-reqd-body').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      ${uiBadge('requisitionStatus', requisition.status)}
      ${uiBadge('requisitionPriority', requisition.priority)}
      <span class="muted-note">${KSI.fmtDateTime(requisition.created_at)}</span>
    </div>
    ${requisition.remarks ? `<div class="cl-note">${KSI.esc(requisition.remarks)}</div>` : ''}
    ${requisition.decision_reason ? `<div class="alert alert-warning">Note from the store: ${KSI.esc(requisition.decision_reason)}</div>` : ''}

    <div class="table-wrap" style="margin-top:12px">
      <table>
        <thead>
          <tr><th>Item</th><th>In the store</th><th>Asked for</th>
            ${deciding ? '<th>Approve</th>' : '<th>Approved</th>'}
            <th>Issued</th>${issuing ? '<th>Issue now</th>' : ''}</tr>
        </thead>
        <tbody>
          ${requisition.items.map(i => `<tr>
            <td>${KSI.esc(i.name)}<div class="muted-note">${KSI.esc(i.unit)}</div></td>
            <td>${uiBadge('stockLevel', i.in_store > 0 ? 'in_stock' : 'out_of_stock', String(i.in_store))}</td>
            <td>${i.qty_requested}</td>
            ${deciding
              ? `<td><input type="number" class="form-input st-approve" data-line="${i.id}" min="0" max="${i.qty_requested}"
                   value="${Math.min(i.qty_requested, i.in_store)}" style="width:80px"></td>`
              : `<td>${i.qty_approved ?? '—'}</td>`}
            <td>${i.qty_issued}${i.outstanding ? ` <span class="muted-note">(${i.outstanding} left)</span>` : ''}</td>
            ${issuing
              ? `<td><input type="number" class="form-input st-issue" data-line="${i.id}" min="0" max="${Math.min(i.outstanding, i.in_store)}"
                   value="${Math.min(i.outstanding, i.in_store)}" style="width:80px"></td>` : ''}
          </tr>`).join('')}
        </tbody>
      </table>
    </div>

    ${deciding ? `<div class="form-group" data-field="reason" style="margin-top:12px">
      <label class="form-label" for="st-reqd-reason">Reason <span class="muted-note">(required if you approve less than asked, or refuse)</span></label>
      <textarea class="form-input" rows="2" id="st-reqd-reason" maxlength="500"></textarea>
    </div>` : ''}
    ${issuing ? `<div class="form-group" style="margin-top:12px">
      <label class="form-label" for="st-reqd-remarks">Note on this issue</label>
      <input class="form-input" id="st-reqd-remarks" maxlength="500">
    </div>` : ''}

    ${requisition.issues?.length ? `<div class="form-label" style="margin-top:14px">Issued so far</div>
      ${requisition.issues.map(issue => `<div class="cl-addendum">
        <div class="cl-addendum-head">${KSI.esc(issue.issue_no)} · ${KSI.esc(issue.issued_by)} · ${KSI.fmtDateTime(issue.issued_at)}</div>
        <div>${issue.items.map(i => `${KSI.esc(i.name)} ×${i.quantity}`).join(', ')}</div>
        ${issue.remarks ? `<div class="muted-note">${KSI.esc(issue.remarks)}</div>` : ''}
      </div>`).join('')}` : ''}`;

  document.getElementById('st-reqd-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-store-requisition')">Close</button>
    <button class="btn btn-secondary" onclick="stPrintRequisition()">Print</button>
    ${deciding ? `
      <button class="btn btn-danger" onclick="stDecideRequisition(this, 'rejected')">Refuse</button>
      <button class="btn btn-primary" onclick="stDecideRequisition(this, 'approved')">Approve</button>` : ''}
    ${issuing ? `<button class="btn btn-primary" onclick="stIssueRequisition(this)">Issue the goods</button>` : ''}`;
  openModal('modal-store-requisition');
}

async function stDecideRequisition(button, decision) {
  const modal = document.getElementById('modal-store-requisition');
  KSI.clearFieldErrors(modal);
  const items = [...document.querySelectorAll('#st-reqd-body .st-approve')].map(input => ({
    item_id: Number(input.dataset.line), qty_approved: Math.max(0, Number(input.value) || 0),
  }));
  await admBusy(button, async () => {
    try {
      const { requisition } = await KSI.post(`stores/requisitions/${ST.current.id}/decide`, {
        decision, reason: document.getElementById('st-reqd-reason')?.value || '', items,
      });
      closeModal('modal-store-requisition');
      showToast(decision === 'approved' ? 'Approved' : 'Refused', requisition.requisition_no, 'success');
      await stRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not record the decision');
    }
  });
}

async function stIssueRequisition(button) {
  const modal = document.getElementById('modal-store-requisition');
  KSI.clearFieldErrors(modal);
  const items = [...document.querySelectorAll('#st-reqd-body .st-issue')].map(input => ({
    item_id: Number(input.dataset.line), quantity: Math.max(0, Number(input.value) || 0),
  }));
  await admBusy(button, async () => {
    try {
      const { requisition } = await KSI.post(`stores/requisitions/${ST.current.id}/issue`, {
        items, remarks: document.getElementById('st-reqd-remarks')?.value || '',
      });
      closeModal('modal-store-requisition');
      showToast('Issued', `${requisition.requisition_no} — ${uiLabel('requisitionStatus', requisition.status)}`, 'success');
      ST.options = null;
      await stRefreshLists();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not issue the goods');
    }
  });
}

function stPrintRequisition() {
  const r = ST.current;
  if (!r) return;
  optPrintWindow(`Requisition ${r.requisition_no}`, `
    <h1>Store Requisition</h1>
    <table class="facts">
      <tr><th>Number</th><td>${KSI.esc(r.requisition_no)}</td><th>Status</th><td>${KSI.esc(uiLabel('requisitionStatus', r.status))}</td></tr>
      <tr><th>Department</th><td>${KSI.esc(r.department)}</td><th>Priority</th><td>${KSI.esc(uiLabel('requisitionPriority', r.priority))}</td></tr>
      <tr><th>Raised by</th><td>${KSI.esc(r.created_by)}</td><th>Date</th><td>${KSI.fmtDateTime(r.created_at)}</td></tr>
    </table>
    <h2>Items</h2>
    <table>
      <thead><tr><th>Item</th><th>Unit</th><th>Asked for</th><th>Approved</th><th>Issued</th></tr></thead>
      <tbody>${r.items.map(i => `<tr><td>${KSI.esc(i.name)}</td><td>${KSI.esc(i.unit)}</td>
        <td>${i.qty_requested}</td><td>${i.qty_approved ?? '—'}</td><td>${i.qty_issued}</td></tr>`).join('')}</tbody>
    </table>
    <p style="margin-top:30px">Issued by: ____________________ &nbsp;&nbsp; Received by: ____________________ &nbsp;&nbsp; Date: __________</p>`);
}

// ─── Requisition history ──────────────────────────────────────────────────

async function stOpenRequisitionHistory() {
  await stOptions();
  stFillFilters();
  await stLoadRequisitionHistory();
}

async function stLoadRequisitionHistory() {
  if (!document.getElementById('st-hist-tbody')) return;
  admTableMessage('st-hist-tbody', 7, 'Loading history…');
  try {
    const value = id => document.getElementById(id)?.value || '';
    const all = [];
    for (const status of ['issued', 'rejected']) {
      const { requisitions } = await KSI.get('stores/requisitions', {
        status, search: value('st-hist-search'), department_id: value('st-hist-filter-department'),
        from: value('st-hist-from'), to: value('st-hist-to'),
      }, { trackActivity: false });
      all.push(...requisitions);
    }
    ST.history = all.sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
    stRenderRequisitionHistory();
  } catch (error) {
    admTableMessage('st-hist-tbody', 7, 'Could not load the history.');
  }
}

function stRenderRequisitionHistory() {
  const count = document.getElementById('st-hist-count');
  if (count) count.textContent = `${ST.history.length} requisition${ST.history.length === 1 ? '' : 's'}`;
  if (!ST.history.length) {
    admTableMessage('st-hist-tbody', 7, 'Nothing finished yet.');
    return;
  }
  document.getElementById('st-hist-tbody').innerHTML = ST.history.map(r => `<tr>
    <td><div style="font-weight:600">${KSI.esc(r.requisition_no)}</div></td>
    <td>${KSI.esc(r.department)}</td>
    <td>${stItemsCell(r)}</td>
    <td>${KSI.fmtDate(r.created_at)}</td>
    <td>${KSI.esc(r.decided_by || '—')}</td>
    <td>${uiBadge('requisitionStatus', r.status)}</td>
    <td><div class="row-actions"><button class="action-btn action-view" onclick="stOpenRequisition(${r.id})">View</button></div></td>
  </tr>`).join('');
}

// ─── Stock ────────────────────────────────────────────────────────────────

async function stOpenStock() {
  await stOptions();
  stFillFilters();
  await stLoadStock();
}

async function stLoadStock() {
  if (!document.getElementById('st-stock-tbody')) return;
  admTableMessage('st-stock-tbody', 9, 'Loading stock…');
  try {
    const value = id => document.getElementById(id)?.value || '';
    const data = await KSI.get('stores/stock', {
      search: value('st-stock-search'), category: value('st-stock-filter-category'), status: value('st-stock-filter-status'),
    }, { trackActivity: false });
    ST.stock = data.items;
    stRenderStock(data);
  } catch (error) {
    admTableMessage('st-stock-tbody', 9, 'Could not load the stock.');
  }
}

function stRenderStock(data) {
  const count = document.getElementById('st-stock-count');
  if (count) count.textContent = `${ST.stock.length} item${ST.stock.length === 1 ? '' : 's'}`;
  const stats = document.getElementById('st-stock-stats');
  if (stats) {
    stats.innerHTML = [
      ['Items', ST.stock.length],
      ['Low stock', data.counts.low_stock],
      ['Out of stock', data.counts.out_of_stock],
      ['Expiring or expired', data.expiring],
      ['Stock value', stMoney(data.stock_value)],
    ].map(([label, value]) => `<div class="stat-card"><div class="stat-num" style="font-size:${typeof value === 'string' ? '15px' : '20px'}">${value}</div><div class="stat-label">${label}</div></div>`).join('');
  }
  if (!ST.stock.length) {
    admTableMessage('st-stock-tbody', 9, 'No items match your filters.');
    return;
  }
  document.getElementById('st-stock-tbody').innerHTML = ST.stock.map(i => `<tr>
    <td>
      <div style="font-weight:600">${KSI.esc(i.name)}</div>
      <div class="muted-note">${KSI.esc(i.code)}${i.drug_name ? ` · pharmacy: ${KSI.esc(i.drug_name)}` : ''}${i.frame_name ? ` · frame: ${KSI.esc(i.frame_name)}` : ''}</div>
    </td>
    <td>${KSI.esc(i.category)}</td>
    <td>${KSI.esc(i.unit)}</td>
    <td style="font-weight:600">${i.quantity}</td>
    <td>${i.reorder_level}</td>
    <td>${i.earliest_expiry ? `${KSI.esc(i.earliest_expiry)} ${uiBadge('expiryStatus', i.expiry_status)}` : '—'}</td>
    <td class="money">${stMoney(i.stock_value)}</td>
    <td>${uiBadge('stockLevel', i.stock_status)}</td>
    <td>
      <div class="row-actions">
        <button class="action-btn action-view" onclick="stOpenStockItem(${i.id})">Open</button>
        ${KSI.can('stores.stock.manage') ? `<button class="action-btn action-edit" onclick="stOpenItem(${i.id})">Edit</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

async function stOpenItem(itemId = null) {
  await stOptions();
  const modal = document.getElementById('modal-store-item');
  KSI.clearFieldErrors(modal);
  ST.editingItem = itemId ? (await KSI.get(`stores/stock/${itemId}`)).item : null;
  const item = ST.editingItem;
  modal.querySelector('.modal-title').textContent = item ? `Edit ${item.name}` : 'New store item';
  admFillSelect('st-item-category', 'Choose a category', (ST.options.categories || []).map(c => [c, c]));
  admFillSelect('st-item-unit', 'Choose how it is counted', (ST.options.units || []).map(u => [u, u]));
  admFillSelect('st-item-supplier', 'No usual supplier', (ST.options.suppliers || []).map(s => [s.id, s.name]));
  admFillSelect('st-item-drug', 'Not a pharmacy medicine',
    [['new', '+ Add to the pharmacy as a new medicine'], ...(ST.options.drugs || []).map(d => [d.id, d.name])]);
  document.getElementById('st-item-name').value = item?.name || '';
  document.getElementById('st-item-category').value = item?.category || '';
  document.getElementById('st-item-unit').value = item?.unit || '';
  document.getElementById('st-item-supplier').value = item?.supplier_id || '';
  document.getElementById('st-item-drug').value = item?.drug_id || '';
  admFillSelect('st-item-frame', 'Not a frame', (ST.options.frames || []).map(f => [f.id, f.name]));
  document.getElementById('st-item-frame').value = item?.frame_id || '';
  document.getElementById('st-item-reorder').value = item?.reorder_level ?? 0;
  document.getElementById('st-item-cost').value = item?.cost_price ?? 0;
  document.getElementById('st-item-active-row').style.display = item ? '' : 'none';
  document.getElementById('st-item-active').checked = item ? item.is_active : true;
  document.getElementById('st-item-drug-form').value = '';
  stToggleNewDrug();
  openModal('modal-store-item');
}

/**
 * Shows the pharmacy selling price once a medicine is chosen. A new medicine needs one and a
 * form; an existing one shows its current price, which only catalogue managers may change.
 */
function stToggleNewDrug() {
  const choice = document.getElementById('st-item-drug').value;
  const existing = (ST.options.drugs || []).find(d => String(d.id) === choice);
  const isNew = choice === 'new';
  const locked = !!existing && !KSI.can('catalogues.manage');
  const price = document.getElementById('st-item-drug-price');
  document.getElementById('st-item-new-drug').style.display = isNew || existing ? '' : 'none';
  document.getElementById('st-item-drug-form-group').style.display = isNew ? '' : 'none';
  price.closest('.form-group').querySelector('.required').style.display = isNew ? '' : 'none';
  price.closest('.form-group').dataset.field = isNew ? 'new_drug_price' : 'drug_price';
  price.value = existing ? existing.unit_price || '' : '';
  price.readOnly = locked;
  document.getElementById('st-item-drug-price-note').style.display = locked ? '' : 'none';
}

async function stSaveItem(button) {
  const modal = document.getElementById('modal-store-item');
  KSI.clearFieldErrors(modal);
  const value = id => document.getElementById(id)?.value || '';
  const payload = {
    name: value('st-item-name'), category: value('st-item-category'), unit: value('st-item-unit'),
    supplier_id: value('st-item-supplier'), drug_id: value('st-item-drug'), frame_id: value('st-item-frame'),
    reorder_level: value('st-item-reorder'), cost_price: value('st-item-cost'),
  };
  if (payload.drug_id === 'new') {
    Object.assign(payload, { new_drug_price: value('st-item-drug-price'), new_drug_form: value('st-item-drug-form') });
  } else if (payload.drug_id !== '' && KSI.can('catalogues.manage')) {
    payload.drug_price = value('st-item-drug-price');
  }
  await admBusy(button, async () => {
    try {
      if (ST.editingItem) {
        await KSI.put(`stores/stock/${ST.editingItem.id}`, {
          ...payload, is_active: document.getElementById('st-item-active').checked, row_version: ST.editingItem.row_version,
        });
      } else {
        await KSI.post('stores/stock', payload);
      }
      closeModal('modal-store-item');
      showToast('Saved', payload.name, 'success');
      ST.options = null;
      await stOptions();
      await stLoadStock();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not save the item');
    }
  });
}

async function stOpenStockItem(itemId) {
  const { item } = await KSI.get(`stores/stock/${itemId}`);
  ST.current = item;
  document.getElementById('st-stock-title').textContent = item.name;
  document.getElementById('st-stock-body').innerHTML = `
    <div class="diag-report-facts">
      <div><span class="pcd-label">Code</span><span class="pcd-value">${KSI.esc(item.code)}</span></div>
      <div><span class="pcd-label">In the store</span><span class="pcd-value">${item.quantity} ${KSI.esc(item.unit)}</span></div>
      <div><span class="pcd-label">Reorder at</span><span class="pcd-value">${item.reorder_level}</span></div>
      <div><span class="pcd-label">Cost each</span><span class="pcd-value">${stMoney(item.cost_price)}</span></div>
      <div><span class="pcd-label">Supplier</span><span class="pcd-value">${KSI.esc(item.supplier_name || '—')}</span></div>
      <div><span class="pcd-label">Pharmacy medicine</span><span class="pcd-value">${KSI.esc(item.drug_name || 'Not linked')}</span></div>
    </div>

    <div class="form-label" style="margin-top:14px">Batches on the shelf</div>
    ${item.batches.length ? `<div class="table-wrap"><table>
        <thead><tr><th>Batch</th><th>Expiry</th><th>Quantity</th></tr></thead>
        <tbody>${item.batches.map(b => `<tr>
          <td>${KSI.esc(b.batch_no || '—')}</td>
          <td>${b.expiry_date ? `${KSI.esc(b.expiry_date)} ${uiBadge('expiryStatus', b.expiry_status)}` : '—'}</td>
          <td>${b.quantity}</td>
        </tr>`).join('')}</tbody></table></div>`
      : '<div class="muted-note">Nothing on the shelf.</div>'}

    ${KSI.can('stores.stock.manage') ? `
      <div class="form-label" style="margin-top:14px">Correct the count</div>
      <div class="form-grid">
        <div class="form-group" data-field="reason">
          <label class="form-label" for="st-adjust-reason">What happened</label>
          <select class="form-select" id="st-adjust-reason">
            <option value="adjusted">Correcting the count</option>
            <option value="returned">Returned to the store</option>
            <option value="written_off">Written off (damaged or expired)</option>
          </select>
        </div>
        <div class="form-group" data-field="change">
          <label class="form-label" for="st-adjust-change">Change</label>
          <input type="number" class="form-input" id="st-adjust-change" step="1" placeholder="e.g. 20, or -5 to take off">
        </div>
      </div>
      <div class="form-group" data-field="note">
        <label class="form-label" for="st-adjust-note">Reason <span class="required">*</span></label>
        <input class="form-input" id="st-adjust-note" maxlength="190" placeholder="Why the count is changing">
      </div>` : ''}

    <div class="form-label" style="margin-top:14px">Recent movements</div>
    ${item.movements.length ? `<div class="cl-file-list">${item.movements.map(m => `<div class="cl-file">
        <div>
          <div>${m.change_qty > 0 ? '+' : ''}${m.change_qty} · ${KSI.esc(uiLabel('storeMovement', m.reason))}${m.reference ? ` · ${KSI.esc(m.reference)}` : ''}</div>
          ${m.note ? `<div class="muted-note">${KSI.esc(m.note)}</div>` : ''}
        </div>
        <span class="muted-note">left ${m.balance_after} · ${KSI.esc(m.created_by)} · ${KSI.fmtDateTime(m.created_at)}</span>
      </div>`).join('')}</div>`
      : '<div class="muted-note">No movements yet.</div>'}`;
  document.getElementById('st-stock-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-store-stock')">Close</button>
    ${KSI.can('stores.stock.manage') ? `<button class="btn btn-primary" onclick="stSaveAdjustment(this)">Save the correction</button>` : ''}`;
  openModal('modal-store-stock');
}

async function stSaveAdjustment(button) {
  const modal = document.getElementById('modal-store-stock');
  KSI.clearFieldErrors(modal);
  await admBusy(button, async () => {
    try {
      const { item } = await KSI.post(`stores/stock/${ST.current.id}/adjust`, {
        change: Number(document.getElementById('st-adjust-change').value) || 0,
        reason: document.getElementById('st-adjust-reason').value,
        note: document.getElementById('st-adjust-note').value,
      });
      closeModal('modal-store-stock');
      showToast('Stock updated', `${item.name} — ${item.quantity} in the store`, 'success');
      ST.options = null;
      await stLoadStock();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not correct the count');
    }
  });
}

// ─── Suppliers ────────────────────────────────────────────────────────────

async function stOpenSuppliers() {
  await stOptions();
  ST.editingSupplier = null;
  const modal = document.getElementById('modal-store-suppliers');
  KSI.clearFieldErrors(modal);
  await stRenderSuppliers();
  stClearSupplierForm();
  openModal('modal-store-suppliers');
}

async function stRenderSuppliers() {
  const { suppliers } = await KSI.get('stores/suppliers', { include_inactive: '1' });
  ST.suppliers = suppliers;
  document.getElementById('st-supplier-rows').innerHTML = suppliers.length
    ? suppliers.map(s => `<tr>
        <td>${KSI.esc(s.name)}</td>
        <td>${KSI.esc(s.contact_name || '—')}</td>
        <td>${KSI.esc(s.phone || '—')}</td>
        <td>${s.is_preferred ? '★' : ''}</td>
        <td>${s.is_active ? 'In use' : 'Not in use'}</td>
        <td><button class="action-btn action-edit" onclick="stEditSupplier(${s.id})">Edit</button></td>
      </tr>`).join('')
    : '<tr class="table-state"><td colspan="6">No suppliers yet.</td></tr>';
}

function stClearSupplierForm() {
  ST.editingSupplier = null;
  document.getElementById('st-supplier-form-title').textContent = 'Add a supplier';
  ['st-supplier-name', 'st-supplier-contact', 'st-supplier-phone', 'st-supplier-email', 'st-supplier-address']
    .forEach(id => { document.getElementById(id).value = ''; });
  document.getElementById('st-supplier-preferred').checked = false;
}

function stEditSupplier(id) {
  const supplier = (ST.suppliers || []).find(s => s.id === id);
  if (!supplier) return;
  ST.editingSupplier = supplier;
  document.getElementById('st-supplier-form-title').textContent = `Edit ${supplier.name}`;
  document.getElementById('st-supplier-name').value = supplier.name;
  document.getElementById('st-supplier-contact').value = supplier.contact_name || '';
  document.getElementById('st-supplier-phone').value = supplier.phone || '';
  document.getElementById('st-supplier-email').value = supplier.email || '';
  document.getElementById('st-supplier-address').value = supplier.address || '';
  document.getElementById('st-supplier-preferred').checked = supplier.is_preferred;
}

async function stSaveSupplier(button) {
  const modal = document.getElementById('modal-store-suppliers');
  KSI.clearFieldErrors(modal);
  const value = id => document.getElementById(id).value;
  const payload = {
    name: value('st-supplier-name'), contact_name: value('st-supplier-contact'), phone: value('st-supplier-phone'),
    email: value('st-supplier-email'), address: value('st-supplier-address'),
    is_preferred: document.getElementById('st-supplier-preferred').checked, is_active: true,
  };
  await admBusy(button, async () => {
    try {
      if (ST.editingSupplier) {
        await KSI.put(`stores/suppliers/${ST.editingSupplier.id}`, { ...payload, row_version: ST.editingSupplier.row_version });
      } else {
        await KSI.post('stores/suppliers', payload);
      }
      showToast('Saved', payload.name, 'success');
      ST.options = null;
      await stOptions();
      await stRenderSuppliers();
      stClearSupplierForm();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not save the supplier');
    }
  });
}

// ─── Quotations ───────────────────────────────────────────────────────────

async function stOpenQuotations() {
  await stOptions();
  stFillFilters();
  await stLoadQuotations();
}

async function stLoadQuotations() {
  if (!document.getElementById('st-quote-tbody')) return;
  admTableMessage('st-quote-tbody', 7, 'Loading quotations…');
  try {
    const { quotations } = await KSI.get('stores/quotations', {
      status: document.getElementById('st-quote-filter-status')?.value || '',
      supplier_id: document.getElementById('st-quote-filter-supplier')?.value || '',
    }, { trackActivity: false });
    ST.quotations = quotations;
    stRenderQuotations();
  } catch (error) {
    admTableMessage('st-quote-tbody', 7, 'Could not load the quotations.');
  }
}

function stRenderQuotations() {
  const count = document.getElementById('st-quote-count');
  if (count) count.textContent = `${ST.quotations.length} quotation${ST.quotations.length === 1 ? '' : 's'}`;
  if (!ST.quotations.length) {
    admTableMessage('st-quote-tbody', 7, 'No quotations recorded yet.');
    return;
  }
  document.getElementById('st-quote-tbody').innerHTML = ST.quotations.map(q => `<tr>
    <td><div style="font-weight:600">${KSI.esc(q.quotation_no)}</div><div class="muted-note">${KSI.fmtDate(q.created_at)}</div></td>
    <td>${KSI.esc(q.supplier.name)}</td>
    <td>${q.items.length} item${q.items.length === 1 ? '' : 's'}</td>
    <td class="money">${stMoney(q.total_amount)}</td>
    <td>${KSI.esc(q.valid_until || '—')}</td>
    <td>${uiBadge('quotationStatus', q.status)}</td>
    <td>
      <div class="row-actions">
        ${q.status === 'draft' && KSI.can('stores.quotations.manage')
          ? `<button class="action-btn action-edit" onclick="stOpenQuotation(${q.id})">Edit</button>` : ''}
        ${q.status === 'under_review' && KSI.can('stores.quotations.manage')
          ? `<button class="action-btn action-status" onclick="stSelectQuotation(${q.id}, this)">Choose</button>` : ''}
        ${q.status === 'selected' && !q.has_order && KSI.can('stores.lpo.manage')
          ? `<button class="action-btn action-edit" onclick="stCreateOrder(${q.id}, this)">Raise order</button>` : ''}
      </div>
    </td>
  </tr>`).join('');
}

async function stOpenQuotation(quotationId = null) {
  await stOptions();
  const modal = document.getElementById('modal-store-quotation');
  KSI.clearFieldErrors(modal);
  ST.editingQuote = quotationId ? (await KSI.get(`stores/quotations/${quotationId}`)).quotation : null;
  ST.quoteItems = ST.editingQuote
    ? ST.editingQuote.items.map(i => ({ ...i }))
    : [];
  modal.querySelector('.modal-title').textContent = ST.editingQuote ? `Edit ${ST.editingQuote.quotation_no}` : 'New quotation';
  admFillSelect('st-quote-supplier', 'Choose a supplier', (ST.options.suppliers || []).map(s => [s.id, s.name]));
  document.getElementById('st-quote-supplier').value = ST.editingQuote?.supplier.id || '';
  document.getElementById('st-quote-valid').value = ST.editingQuote?.valid_until || '';
  document.getElementById('st-quote-remarks').value = ST.editingQuote?.remarks || '';
  stRenderQuoteItems();
  openModal('modal-store-quotation');
}

function stQuoteItemPicker() {
  const chosen = ST.quoteItems.map(i => i.item_id);
  admFillSelect('st-quote-item-pick', 'Add an item', (ST.options?.items || [])
    .filter(i => !chosen.includes(i.id)).map(i => [i.id, i.name]));
}

function stQuoteAddItem(itemId) {
  const item = (ST.options?.items || []).find(i => i.id === Number(itemId));
  if (!item) return;
  ST.quoteItems.push({ item_id: item.id, name: item.name, quantity: 1, unit_price: item.cost_price, tax_percent: 0 });
  stRenderQuoteItems();
}

function stQuoteRemoveItem(itemId) {
  ST.quoteItems = ST.quoteItems.filter(i => i.item_id !== itemId);
  stRenderQuoteItems();
}

function stQuoteSetItem(itemId, field, value) {
  const line = ST.quoteItems.find(i => i.item_id === itemId);
  if (!line) return;
  line[field] = Math.max(0, Number(value) || 0);
  if (field === 'quantity') line.quantity = Math.max(1, line.quantity);
  stRenderQuoteItems();
}

function stRenderQuoteItems() {
  const body = document.getElementById('st-quote-items');
  if (!body) return;
  body.innerHTML = ST.quoteItems.length
    ? ST.quoteItems.map(i => {
        const total = Math.round(i.quantity * i.unit_price * (1 + (i.tax_percent || 0) / 100));
        return `<tr>
          <td>${KSI.esc(i.name)}</td>
          <td><input type="number" class="form-input" min="1" value="${i.quantity}" style="width:80px"
               onchange="stQuoteSetItem(${i.item_id}, 'quantity', this.value)"></td>
          <td><input type="number" class="form-input" min="0" step="100" value="${i.unit_price}" style="width:110px"
               onchange="stQuoteSetItem(${i.item_id}, 'unit_price', this.value)"></td>
          <td><input type="number" class="form-input" min="0" step="1" value="${i.tax_percent || 0}" style="width:70px"
               onchange="stQuoteSetItem(${i.item_id}, 'tax_percent', this.value)"></td>
          <td class="money">${stMoney(total)}</td>
          <td><button class="action-btn action-cancel" onclick="stQuoteRemoveItem(${i.item_id})">Remove</button></td>
        </tr>`;
      }).join('')
    : '<tr class="table-state"><td colspan="6">No items added.</td></tr>';
  stQuoteItemPicker();
  const total = ST.quoteItems.reduce((sum, i) => sum + Math.round(i.quantity * i.unit_price * (1 + (i.tax_percent || 0) / 100)), 0);
  document.getElementById('st-quote-total').textContent = stMoney(total);
}

async function stSaveQuotation(button, submit) {
  const modal = document.getElementById('modal-store-quotation');
  KSI.clearFieldErrors(modal);
  if (!ST.quoteItems.length) {
    KSI.showFieldErrors(modal, { items: 'Add at least one item.' });
    return;
  }
  const payload = {
    supplier_id: Number(document.getElementById('st-quote-supplier').value) || 0,
    valid_until: document.getElementById('st-quote-valid').value,
    quoted_on: uiToday(),
    remarks: document.getElementById('st-quote-remarks').value,
    items: ST.quoteItems.map(i => ({
      item_id: i.item_id, quantity: i.quantity, unit_price: i.unit_price, tax_percent: i.tax_percent || 0,
    })),
    submit,
  };
  await admBusy(button, async () => {
    try {
      const { quotation } = ST.editingQuote
        ? await KSI.put(`stores/quotations/${ST.editingQuote.id}`, { ...payload, row_version: ST.editingQuote.row_version })
        : await KSI.post('stores/quotations', payload);
      closeModal('modal-store-quotation');
      showToast(submit ? 'Sent for review' : 'Draft saved', quotation.quotation_no, 'success');
      await stLoadQuotations();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not save the quotation');
    }
  });
}

async function stSelectQuotation(id, button) {
  await admBusy(button, async () => {
    try {
      const { quotation } = await KSI.post(`stores/quotations/${id}/select`, {});
      showToast('Supplier chosen', `${quotation.supplier.name} — ${stMoney(quotation.total_amount)}`, 'success');
      await stLoadQuotations();
    } catch (error) {
      KSI.reportError(error, null, 'Could not choose this quotation');
    }
  });
}

async function stOpenComparison() {
  const data = await KSI.get('stores/quotations/compare');
  const body = document.getElementById('st-compare-body');
  if (!data.quotations.length) {
    body.innerHTML = '<div class="muted-note">No quotations are under review.</div>';
  } else {
    body.innerHTML = `<div class="table-wrap"><table>
      <thead><tr><th>Item</th>${data.quotations.map(q => `<th>${KSI.esc(q.supplier)}<div class="muted-note">${KSI.esc(q.quotation_no)}</div></th>`).join('')}</tr></thead>
      <tbody>
        ${data.items.map(item => `<tr>
          <td>${KSI.esc(item.name)}<div class="muted-note">${KSI.esc(item.unit)}</div></td>
          ${data.quotations.map(q => {
            const price = item.prices[q.id];
            if (!price) return '<td class="muted-note">Not quoted</td>';
            const cheapest = item.cheapest !== null && Math.abs(price.unit_price - item.cheapest) < 0.001;
            return `<td class="${cheapest ? 'st-cheapest' : ''}">${stMoney(price.unit_price)}<div class="muted-note">×${price.quantity}</div></td>`;
          }).join('')}
        </tr>`).join('')}
        <tr>
          <td style="font-weight:700">Total</td>
          ${data.quotations.map(q => `<td class="${data.cheapest_total !== null && Math.abs(q.total_amount - data.cheapest_total) < 0.001 ? 'st-cheapest' : ''}"
            style="font-weight:700">${stMoney(q.total_amount)}</td>`).join('')}
        </tr>
      </tbody></table></div>
      <div class="muted-note" style="margin-top:8px">The cheapest price in each row is highlighted. Choosing a supplier is still your decision.</div>`;
  }
  openModal('modal-store-compare');
}

// ─── Purchase orders ──────────────────────────────────────────────────────

async function stCreateOrder(quotationId, button) {
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post('stores/orders', { quotation_id: quotationId });
      showToast('Order raised', `${order.order_no} — waiting for approval`, 'success');
      showModule('stores-lpos');
    } catch (error) {
      KSI.reportError(error, null, 'Could not raise the order');
    }
  });
}

async function stOpenOrders() {
  await stOptions();
  stFillFilters();
  await stLoadOrders();
}

async function stLoadOrders() {
  if (!document.getElementById('st-order-tbody')) return;
  admTableMessage('st-order-tbody', 7, 'Loading orders…');
  try {
    const { orders } = await KSI.get('stores/orders', {
      status: document.getElementById('st-order-filter-status')?.value || '',
      supplier_id: document.getElementById('st-order-filter-supplier')?.value || '',
    }, { trackActivity: false });
    ST.orders = orders;
    stRenderOrders();
  } catch (error) {
    admTableMessage('st-order-tbody', 7, 'Could not load the orders.');
  }
}

function stRenderOrders() {
  const count = document.getElementById('st-order-count');
  if (count) count.textContent = `${ST.orders.length} order${ST.orders.length === 1 ? '' : 's'}`;
  const stats = document.getElementById('st-order-stats');
  if (stats) {
    const waiting = ST.orders.filter(o => ['pending_admin', 'pending_store'].includes(o.status)).length;
    const open = ST.orders.filter(o => ['approved', 'sent'].includes(o.status)).length;
    const value = ST.orders.filter(o => o.status !== 'cancelled').reduce((sum, o) => sum + o.total_amount, 0);
    stats.innerHTML = [
      ['Orders', ST.orders.length],
      ['Waiting for approval', waiting],
      ['With the supplier', open],
      ['Value', stMoney(value)],
    ].map(([label, v]) => `<div class="stat-card"><div class="stat-num" style="font-size:${typeof v === 'string' ? '15px' : '20px'}">${v}</div><div class="stat-label">${label}</div></div>`).join('');
  }
  if (!ST.orders.length) {
    admTableMessage('st-order-tbody', 7, 'No purchase orders yet. Choose a quotation to raise one.');
    return;
  }
  document.getElementById('st-order-tbody').innerHTML = ST.orders.map(o => `<tr>
    <td><div style="font-weight:600">${KSI.esc(o.order_no)}</div><div class="muted-note">${KSI.fmtDate(o.created_at)}</div></td>
    <td>${KSI.esc(o.supplier.name)}</td>
    <td>${o.items.length} item${o.items.length === 1 ? '' : 's'}</td>
    <td class="money">${stMoney(o.total_amount)}</td>
    <td>${KSI.esc(o.expected_delivery || '—')}</td>
    <td>${uiBadge('orderStatus', o.status)}</td>
    <td><div class="row-actions"><button class="action-btn action-view" onclick="stOpenOrder(${o.id})">Open</button></div></td>
  </tr>`).join('');
}

async function stOpenOrder(id) {
  const { order } = await KSI.get(`stores/orders/${id}`);
  ST.current = order;
  document.getElementById('st-order-title').textContent = `Purchase order ${order.order_no}`;
  document.getElementById('st-order-subtitle').textContent = `${order.supplier.name} · ${stMoney(order.total_amount)}`;
  document.getElementById('st-order-body').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      ${uiBadge('orderStatus', order.status)}
      ${order.quotation_no ? `<span class="muted-note">from ${KSI.esc(order.quotation_no)}</span>` : ''}
    </div>
    <div class="diag-report-facts">
      <div><span class="pcd-label">Expected</span><span class="pcd-value">${KSI.esc(order.expected_delivery || '—')}</span></div>
      <div><span class="pcd-label">Raised by</span><span class="pcd-value">${KSI.esc(order.created_by)}</span></div>
      <div><span class="pcd-label">Administration</span><span class="pcd-value">${order.admin_approved_by ? `${KSI.esc(order.admin_approved_by)} · ${KSI.fmtDate(order.admin_approved_at)}` : 'Not yet'}</span></div>
      <div><span class="pcd-label">Store manager</span><span class="pcd-value">${order.store_approved_by ? `${KSI.esc(order.store_approved_by)} · ${KSI.fmtDate(order.store_approved_at)}` : 'Not yet'}</span></div>
    </div>
    <div class="table-wrap" style="margin-top:12px">
      <table>
        <thead><tr><th>Item</th><th>Ordered</th><th>Price each</th><th>Tax %</th><th>Line total</th><th>Received</th></tr></thead>
        <tbody>${order.items.map(i => `<tr>
          <td>${KSI.esc(i.name)}<div class="muted-note">${KSI.esc(i.unit)}</div></td>
          <td>${i.quantity}</td>
          <td class="money">${stMoney(i.unit_price)}</td>
          <td>${i.tax_percent}</td>
          <td class="money">${stMoney(i.line_total)}</td>
          <td>${i.qty_received}${i.outstanding ? ` <span class="muted-note">(${i.outstanding} due)</span>` : ''}</td>
        </tr>`).join('')}</tbody>
      </table>
    </div>
    ${order.deliveries?.length ? `<div class="form-label" style="margin-top:14px">Deliveries</div>
      ${order.deliveries.map(d => `<div class="cl-addendum">
        <div class="cl-addendum-head">${KSI.esc(d.grn_no)} · ${KSI.esc(d.received_on)} · ${KSI.esc(uiLabel('deliveryStatus', d.status))}</div>
        <div>${d.items.map(i => `${KSI.esc(i.name)} ×${i.quantity}`).join(', ')}</div>
      </div>`).join('')}` : ''}`;

  document.getElementById('st-order-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-store-order')">Close</button>
    <button class="btn btn-secondary" onclick="stPrintOrder()">Print</button>
    ${order.status === 'pending_admin' && KSI.can('stores.lpo.approve_admin')
      ? `<button class="btn btn-primary" onclick="stApproveOrder(this, 'admin')">Approve (administration)</button>` : ''}
    ${order.status === 'pending_store' && KSI.can('stores.lpo.approve_store')
      ? `<button class="btn btn-primary" onclick="stApproveOrder(this, 'store')">Approve (store manager)</button>` : ''}
    ${order.status === 'approved' && KSI.can('stores.lpo.manage')
      ? `<button class="btn btn-primary" onclick="stSendOrder(this)">Mark as sent</button>` : ''}`;
  openModal('modal-store-order');
}

async function stApproveOrder(button, step) {
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`stores/orders/${ST.current.id}/approve`, { step });
      closeModal('modal-store-order');
      showToast('Approved', `${order.order_no} — ${uiLabel('orderStatus', order.status)}`, 'success');
      await stLoadOrders();
    } catch (error) {
      KSI.reportError(error, null, 'Could not approve the order');
    }
  });
}

async function stSendOrder(button) {
  await admBusy(button, async () => {
    try {
      const { order } = await KSI.post(`stores/orders/${ST.current.id}/send`, {});
      closeModal('modal-store-order');
      showToast('Sent', `${order.order_no} marked as sent to ${order.supplier.name}`, 'success');
      await stLoadOrders();
    } catch (error) {
      KSI.reportError(error, null, 'Could not mark the order as sent');
    }
  });
}

function stPrintOrder() {
  const o = ST.current;
  if (!o) return;
  optPrintWindow(`Purchase order ${o.order_no}`, `
    <h1>Local Purchase Order</h1>
    <table class="facts">
      <tr><th>Order no.</th><td>${KSI.esc(o.order_no)}</td><th>Date</th><td>${KSI.fmtDate(o.created_at)}</td></tr>
      <tr><th>Supplier</th><td>${KSI.esc(o.supplier.name)}</td><th>Expected</th><td>${KSI.esc(o.expected_delivery || '—')}</td></tr>
    </table>
    <h2>Items</h2>
    <table>
      <thead><tr><th>Item</th><th>Unit</th><th>Quantity</th><th>Price each</th><th>Tax %</th><th>Total</th></tr></thead>
      <tbody>${o.items.map(i => `<tr><td>${KSI.esc(i.name)}</td><td>${KSI.esc(i.unit)}</td><td>${i.quantity}</td>
        <td>${stMoney(i.unit_price)}</td><td>${i.tax_percent}</td><td>${stMoney(i.line_total)}</td></tr>`).join('')}
        <tr><td colspan="5" style="text-align:right"><strong>Order total</strong></td><td><strong>${stMoney(o.total_amount)}</strong></td></tr>
      </tbody>
    </table>
    <p style="margin-top:26px">Approved (administration): ${KSI.esc(o.admin_approved_by || '____________________')}</p>
    <p>Approved (store manager): ${KSI.esc(o.store_approved_by || '____________________')}</p>`);
}

// ─── Goods received ───────────────────────────────────────────────────────

async function stOpenDeliveries() {
  await stOptions();
  await stLoadDeliveries();
}

async function stLoadDeliveries() {
  if (!document.getElementById('st-grn-tbody')) return;
  admTableMessage('st-grn-tbody', 7, 'Loading deliveries…');
  try {
    const { deliveries } = await KSI.get('stores/deliveries', {
      status: document.getElementById('st-grn-filter-status')?.value || '',
    }, { trackActivity: false });
    ST.deliveries = deliveries;
    stRenderDeliveries();
  } catch (error) {
    admTableMessage('st-grn-tbody', 7, 'Could not load the deliveries.');
  }
}

function stRenderDeliveries() {
  const count = document.getElementById('st-grn-count');
  if (count) count.textContent = `${ST.deliveries.length} deliver${ST.deliveries.length === 1 ? 'y' : 'ies'}`;
  if (!ST.deliveries.length) {
    admTableMessage('st-grn-tbody', 7, 'Nothing received yet.');
    return;
  }
  document.getElementById('st-grn-tbody').innerHTML = ST.deliveries.map(d => `<tr>
    <td><div style="font-weight:600">${KSI.esc(d.grn_no)}</div><div class="muted-note">${KSI.esc(d.delivery_note || '')}</div></td>
    <td>${KSI.esc(d.order_no)}</td>
    <td>${KSI.esc(d.supplier)}</td>
    <td>${KSI.esc(d.received_on)}</td>
    <td>${d.items.length} item${d.items.length === 1 ? '' : 's'}</td>
    <td>${uiBadge('deliveryStatus', d.status)}</td>
    <td><div class="row-actions"><button class="action-btn action-view" onclick="stOpenDelivery(${d.id})">Open</button></div></td>
  </tr>`).join('');
}

async function stOpenReceive() {
  await stOptions();
  const modal = document.getElementById('modal-store-receive');
  KSI.clearFieldErrors(modal);
  const { orders } = await KSI.get('stores/orders', {});
  const open = orders.filter(o => ['approved', 'sent'].includes(o.status));
  admFillSelect('st-grn-order', open.length ? 'Choose the order' : 'No approved orders are waiting',
    open.map(o => [o.id, `${o.order_no} — ${o.supplier.name}`]));
  ST.openOrders = open;
  ST.receiveOrder = null;
  document.getElementById('st-grn-date').value = uiToday();
  document.getElementById('st-grn-note').value = '';
  document.getElementById('st-grn-remarks').value = '';
  document.getElementById('st-grn-items').innerHTML = '<tr class="table-state"><td colspan="8">Choose an order first.</td></tr>';
  openModal('modal-store-receive');
}

function stReceiveOrderChanged(orderId) {
  const order = (ST.openOrders || []).find(o => o.id === Number(orderId));
  ST.receiveOrder = order || null;
  const body = document.getElementById('st-grn-items');
  if (!order) {
    body.innerHTML = '<tr class="table-state"><td colspan="8">Choose an order first.</td></tr>';
    return;
  }
  body.innerHTML = order.items.map(i => `<tr>
    <td>${KSI.esc(i.name)}<div class="muted-note">${KSI.esc(i.unit)}</div></td>
    <td>${i.quantity}</td>
    <td>${i.outstanding}</td>
    <td><input type="number" class="form-input st-receive" data-item="${i.item_id}" min="0" max="${i.outstanding}" value="${i.outstanding}" style="width:80px"></td>
    <td><input type="number" class="form-input st-rejected" data-item="${i.item_id}" min="0" value="0" style="width:80px"></td>
    <td><input class="form-input st-batch" data-item="${i.item_id}" maxlength="60" style="width:110px" placeholder="Batch"></td>
    <td><input type="date" class="form-input st-expiry" data-item="${i.item_id}" style="width:150px"></td>
    <td><input type="number" class="form-input st-price" data-item="${i.item_id}" min="0" step="100" value="${i.unit_price}" style="width:110px"></td>
  </tr>`).join('');
}

async function stSaveDelivery(button) {
  const modal = document.getElementById('modal-store-receive');
  KSI.clearFieldErrors(modal);
  if (!ST.receiveOrder) {
    KSI.showFieldErrors(modal, { order_id: 'Choose the order this delivery is against.' });
    return;
  }
  const field = (cls, itemId) => document.querySelector(`#st-grn-items .${cls}[data-item="${itemId}"]`)?.value || '';
  const items = ST.receiveOrder.items.map(i => ({
    item_id: i.item_id,
    quantity: Number(field('st-receive', i.item_id)) || 0,
    rejected_qty: Number(field('st-rejected', i.item_id)) || 0,
    batch_no: field('st-batch', i.item_id),
    expiry_date: field('st-expiry', i.item_id),
    unit_price: field('st-price', i.item_id),
  })).filter(i => i.quantity > 0);
  await admBusy(button, async () => {
    try {
      const { delivery } = await KSI.post('stores/deliveries', {
        order_id: ST.receiveOrder.id,
        received_on: document.getElementById('st-grn-date').value,
        delivery_note: document.getElementById('st-grn-note').value,
        remarks: document.getElementById('st-grn-remarks').value,
        items,
      });
      closeModal('modal-store-receive');
      showToast('Delivery recorded', `${delivery.grn_no} — check it, then approve to take it into stock.`, 'success');
      await stLoadDeliveries();
    } catch (error) {
      KSI.reportError(error, modal, 'Could not record the delivery');
    }
  });
}

async function stOpenDelivery(id) {
  const delivery = ST.deliveries.find(d => d.id === id);
  if (!delivery) return;
  ST.current = delivery;
  document.getElementById('st-grnd-title').textContent = `Goods received ${delivery.grn_no}`;
  document.getElementById('st-grnd-subtitle').textContent = `${delivery.supplier} · against ${delivery.order_no}`;
  document.getElementById('st-grnd-body').innerHTML = `
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      ${uiBadge('deliveryStatus', delivery.status)}
      <span class="muted-note">received ${KSI.esc(delivery.received_on)}${delivery.delivery_note ? ` · note ${KSI.esc(delivery.delivery_note)}` : ''}</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Item</th><th>Accepted</th><th>Rejected</th><th>Batch</th><th>Expiry</th><th>Price each</th></tr></thead>
        <tbody>${delivery.items.map(i => `<tr>
          <td>${KSI.esc(i.name)}</td>
          <td>${i.quantity}</td>
          <td>${i.rejected_qty || '—'}</td>
          <td>${KSI.esc(i.batch_no || '—')}</td>
          <td>${KSI.esc(i.expiry_date || '—')}</td>
          <td class="money">${stMoney(i.unit_price)}</td>
        </tr>`).join('')}</tbody>
      </table>
    </div>
    ${delivery.remarks ? `<div class="cl-note" style="margin-top:10px">${KSI.esc(delivery.remarks)}</div>` : ''}
    <div class="diag-report-facts">
      <div><span class="pcd-label">Recorded by</span><span class="pcd-value">${KSI.esc(delivery.created_by)}</span></div>
      <div><span class="pcd-label">Checked by</span><span class="pcd-value">${delivery.verified_by ? `${KSI.esc(delivery.verified_by)} · ${KSI.fmtDate(delivery.verified_at)}` : 'Not yet'}</span></div>
      <div><span class="pcd-label">Approved by</span><span class="pcd-value">${delivery.approved_by ? `${KSI.esc(delivery.approved_by)} · ${KSI.fmtDate(delivery.approved_at)}` : 'Not yet'}</span></div>
    </div>`;
  document.getElementById('st-grnd-footer').innerHTML = `
    <button class="btn btn-secondary" onclick="closeModal('modal-store-delivery')">Close</button>
    <button class="btn btn-secondary" onclick="stPrintDelivery()">Print</button>
    ${delivery.status === 'recorded' && KSI.can('stores.grn.approve')
      ? `<button class="btn btn-primary" onclick="stDecideDelivery(this, 'verify')">Mark as checked</button>` : ''}
    ${delivery.status === 'verified' && KSI.can('stores.grn.approve')
      ? `<button class="btn btn-primary" onclick="stDecideDelivery(this, 'approve')">Approve and take into stock</button>` : ''}`;
  openModal('modal-store-delivery');
}

async function stDecideDelivery(button, step) {
  await admBusy(button, async () => {
    try {
      const { delivery } = await KSI.post(`stores/deliveries/${ST.current.id}/decide`, { step });
      closeModal('modal-store-delivery');
      showToast(step === 'verify' ? 'Checked' : 'Taken into stock',
        `${delivery.grn_no} — ${uiLabel('deliveryStatus', delivery.status)}`, 'success');
      ST.options = null;
      await stLoadDeliveries();
    } catch (error) {
      KSI.reportError(error, null, 'Could not update the delivery');
    }
  });
}

function stPrintDelivery() {
  const d = ST.current;
  if (!d) return;
  optPrintWindow(`Goods received ${d.grn_no}`, `
    <h1>Goods Received Note</h1>
    <table class="facts">
      <tr><th>Number</th><td>${KSI.esc(d.grn_no)}</td><th>Date</th><td>${KSI.esc(d.received_on)}</td></tr>
      <tr><th>Order</th><td>${KSI.esc(d.order_no)}</td><th>Supplier</th><td>${KSI.esc(d.supplier)}</td></tr>
      <tr><th>Delivery note</th><td>${KSI.esc(d.delivery_note || '—')}</td><th>Status</th><td>${KSI.esc(uiLabel('deliveryStatus', d.status))}</td></tr>
    </table>
    <h2>Items</h2>
    <table>
      <thead><tr><th>Item</th><th>Accepted</th><th>Rejected</th><th>Batch</th><th>Expiry</th></tr></thead>
      <tbody>${d.items.map(i => `<tr><td>${KSI.esc(i.name)}</td><td>${i.quantity}</td><td>${i.rejected_qty}</td>
        <td>${KSI.esc(i.batch_no || '—')}</td><td>${KSI.esc(i.expiry_date || '—')}</td></tr>`).join('')}</tbody>
    </table>
    <p style="margin-top:26px">Checked by: ${KSI.esc(d.verified_by || '____________________')} &nbsp;&nbsp;
       Approved by: ${KSI.esc(d.approved_by || '____________________')}</p>`);
}

// ─── Procurement history ──────────────────────────────────────────────────

async function stOpenProcurementHistory() {
  await stOptions();
  await stLoadProcurementHistory();
}

async function stLoadProcurementHistory() {
  if (!document.getElementById('st-pr-hist-tbody')) return;
  admTableMessage('st-pr-hist-tbody', 7, 'Loading history…');
  try {
    const type = document.getElementById('st-pr-hist-type')?.value || '';
    const rows = [];
    if (type === '' || type === 'quotation') {
      const { quotations } = await KSI.get('stores/quotations', {}, { trackActivity: false });
      quotations.forEach(q => rows.push({
        reference: q.quotation_no, type: 'Quotation', supplier: q.supplier.name, date: q.created_at,
        value: q.total_amount, status: uiLabel('quotationStatus', q.status), open: `stOpenQuotation(${q.id})`,
      }));
    }
    if (type === '' || type === 'order') {
      const { orders } = await KSI.get('stores/orders', {}, { trackActivity: false });
      orders.forEach(o => rows.push({
        reference: o.order_no, type: 'Purchase order', supplier: o.supplier.name, date: o.created_at,
        value: o.total_amount, status: uiLabel('orderStatus', o.status), open: `stOpenOrder(${o.id})`,
      }));
    }
    if (type === '' || type === 'delivery') {
      const { deliveries } = await KSI.get('stores/deliveries', {}, { trackActivity: false });
      ST.deliveries = deliveries;
      deliveries.forEach(d => rows.push({
        reference: d.grn_no, type: 'Goods received', supplier: d.supplier, date: d.created_at,
        value: null, status: uiLabel('deliveryStatus', d.status), open: `stOpenDelivery(${d.id})`,
      }));
    }
    rows.sort((a, b) => (a.date < b.date ? 1 : -1));
    const count = document.getElementById('st-pr-hist-count');
    if (count) count.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'}`;
    if (!rows.length) {
      admTableMessage('st-pr-hist-tbody', 7, 'Nothing recorded yet.');
      return;
    }
    document.getElementById('st-pr-hist-tbody').innerHTML = rows.map(r => `<tr>
      <td><div style="font-weight:600">${KSI.esc(r.reference)}</div></td>
      <td>${KSI.esc(r.type)}</td>
      <td>${KSI.esc(r.supplier)}</td>
      <td>${KSI.fmtDate(r.date)}</td>
      <td class="money">${r.value === null ? '—' : stMoney(r.value)}</td>
      <td>${KSI.esc(r.status)}</td>
      <td><div class="row-actions"><button class="action-btn action-view" onclick="${r.open}">Open</button></div></td>
    </tr>`).join('');
  } catch (error) {
    admTableMessage('st-pr-hist-tbody', 7, 'Could not load the history.');
  }
}

// ─── Shared ───────────────────────────────────────────────────────────────

async function stRefreshLists() {
  const open = id => !document.getElementById(id).classList.contains('hidden');
  if (open('module-stores-my-requisitions')) await stLoadMine();
  if (open('module-stores-requisition-requests')) await stLoadRequests();
  if (open('module-stores-requisition-history')) await stLoadRequisitionHistory();
  if (open('module-stores-stock')) await stLoadStock();
  if (open('module-stores-quotations')) await stLoadQuotations();
  if (open('module-stores-lpos')) await stLoadOrders();
  if (open('module-stores-grns')) await stLoadDeliveries();
  if (open('module-stores-history')) await stLoadProcurementHistory();
}
