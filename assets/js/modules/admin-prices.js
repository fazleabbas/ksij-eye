'use strict';
// ─── ADMINISTRATION · PRICE LIST ───────────────────────────────────────────
// Prices live in the database; every change needs a reason and is kept in the
// price history. Bills copy the price when raised, so old bills never change.
const PRICE_UI = { items: [], editingId: null, timer: null };
const PRICE_CATEGORIES = {
  consultation: 'Consultation',
  investigation: 'Investigation',
  surgery: 'Surgery',
  procedure: 'Procedure',
  lens: 'Spectacle Lens',
  other: 'Other',
};

async function renderPriceListModule() {
  document.getElementById('pl-add-btn').style.display = KSI.can('prices.manage') ? '' : 'none';
  admFillSelect('pl-category-filter', 'All Categories', Object.entries(PRICE_CATEGORIES));
  await loadPriceList();
}

function onPriceFilter(debounce) {
  clearTimeout(PRICE_UI.timer);
  PRICE_UI.timer = setTimeout(loadPriceList, debounce ? 300 : 0);
}

async function loadPriceList() {
  admTableMessage('pl-tbody', 7, 'Loading price list…');
  try {
    const { items } = await KSI.get('prices', {
      search: document.getElementById('pl-search').value.trim(),
      category: document.getElementById('pl-category-filter').value,
    });
    PRICE_UI.items = items;
    admStatCards('pl-stats-grid', [
      { label: 'Price Items', value: items.length, color: 'teal', icon: 'tag' },
      { label: 'Awaiting Finance Confirmation', value: items.filter(i => !i.is_confirmed).length, color: 'amber', icon: 'alert' },
      { label: 'Inactive', value: items.filter(i => !i.is_active).length, color: 'purple', icon: 'lock' },
    ]);
    const canManage = KSI.can('prices.manage');
    if (!items.length) {
      admTableMessage('pl-tbody', 7, 'No price items match the filters.');
    } else {
      document.getElementById('pl-tbody').innerHTML = items.map(item => `<tr${item.is_active ? '' : ' style="opacity:.6"'}>
        <td><span class="reg-num">${KSI.esc(item.code)}</span></td>
        <td class="patient-name">${KSI.esc(item.name)}</td>
        <td style="font-size:12px">${KSI.esc(PRICE_CATEGORIES[item.category] || item.category)}</td>
        <td style="text-align:right;font-family:var(--font-mono);font-weight:600;white-space:nowrap">${KSI.fmtMoney(item.unit_price)}</td>
        <td><div class="badge-list">
          ${item.is_confirmed ? '<span class="badge badge-complete">Confirmed</span>' : '<span class="badge badge-warn">Unconfirmed</span>'}
          ${item.is_active ? '' : '<span class="badge badge-cancelled">Inactive</span>'}
        </div></td>
        <td style="font-size:12px;color:var(--text-3);white-space:nowrap">
          ${item.updated_at ? KSI.fmtDateTime(item.updated_at) : '—'}
          ${item.updated_by ? `<div class="sub-text">${KSI.esc(item.updated_by)}</div>` : ''}
        </td>
        <td><div class="row-actions">
          ${canManage ? `<button class="btn btn-secondary btn-sm" onclick="openPriceModal(${item.id})">Edit</button>` : ''}
          <button class="btn btn-ghost btn-sm" onclick="openPriceHistory(${item.id})">History</button>
        </div></td>
      </tr>`).join('');
    }
    document.getElementById('pl-count').textContent = `${items.length} item${items.length === 1 ? '' : 's'}`;
  } catch (error) {
    admTableMessage('pl-tbody', 7, error.message);
  }
}

function openPriceModal(itemId = null) {
  if (!KSI.can('prices.manage')) return;
  const item = itemId ? PRICE_UI.items.find(i => i.id === itemId) : null;
  PRICE_UI.editingId = item ? item.id : null;
  const form = document.getElementById('form-price-item');
  form.reset();
  KSI.clearFieldErrors(form);
  const field = name => form.querySelector(`[data-field="${name}"]`);

  document.getElementById('price-item-title').textContent = item ? `Edit Price — ${item.code}` : 'Add Price Item';
  admFillSelect('price-item-category', 'Select category…', Object.entries(PRICE_CATEGORIES));
  field('code').value = item?.code || '';
  field('code').disabled = !!item;
  field('category').value = item?.category || '';
  field('name').value = item?.name || '';
  field('unit_price').value = item ? item.unit_price : '';
  field('is_confirmed').checked = !!item?.is_confirmed;
  field('is_active').checked = item ? item.is_active : true;
  field('reason').value = '';
  document.getElementById('price-item-active-wrap').style.display = item ? '' : 'none';
  document.getElementById('price-item-reason-group').style.display = item ? '' : 'none';
  openModal('modal-price-item');
  setTimeout(() => (item ? field('unit_price') : field('code')).focus(), 50);
}

async function savePrice() {
  const form = document.getElementById('form-price-item');
  KSI.clearFieldErrors(form);
  const item = PRICE_UI.editingId ? PRICE_UI.items.find(i => i.id === PRICE_UI.editingId) : null;
  const field = name => form.querySelector(`[data-field="${name}"]`);
  const body = {
    name: field('name').value.trim(),
    unit_price: field('unit_price').value.trim(),
    category: field('category').value,
    is_confirmed: field('is_confirmed').checked,
  };
  if (item) {
    Object.assign(body, { is_active: field('is_active').checked, reason: field('reason').value.trim(), row_version: item.row_version });
  } else {
    body.code = field('code').value.trim().toUpperCase();
  }

  await admBusy(document.getElementById('price-item-save'), async () => {
    try {
      const result = item ? await KSI.put(`prices/${item.id}`, body) : await KSI.post('prices', body);
      closeModal('modal-price-item');
      showToast(item ? 'Price updated' : 'Price item added', `${result.item.name}: TZS ${KSI.fmtMoney(result.item.unit_price)}`, 'success');
      await loadPriceList();
    } catch (error) {
      KSI.reportError(error, form, 'Price not saved');
    }
  });
}

async function openPriceHistory(itemId) {
  const item = PRICE_UI.items.find(i => i.id === itemId);
  document.getElementById('price-history-subtitle').textContent = item ? `${item.code} — ${item.name}` : '';
  admTableMessage('price-history-tbody', 5, 'Loading…');
  openModal('modal-price-history');
  try {
    const { history } = await KSI.get(`prices/${itemId}/history`);
    document.getElementById('price-history-tbody').innerHTML = history.map(h => `<tr>
      <td style="white-space:nowrap;font-size:12px">${KSI.fmtDateTime(h.changed_at)}</td>
      <td style="text-align:right;font-family:var(--font-mono)">${h.old_price === null ? '—' : KSI.fmtMoney(h.old_price)}</td>
      <td style="text-align:right;font-family:var(--font-mono);font-weight:600">${KSI.fmtMoney(h.new_price)}</td>
      <td style="font-size:12px">${KSI.esc(h.changed_by)}</td>
      <td style="font-size:12px;color:var(--text-2)">${KSI.esc(h.reason || '—')}</td>
    </tr>`).join('') || '<tr class="table-state"><td colspan="5">No history.</td></tr>';
  } catch (error) {
    admTableMessage('price-history-tbody', 5, error.message);
  }
}
