'use strict';
// ─── ADMINISTRATION · CLINICAL LISTS ───────────────────────────────────────
// ICD-10 codes, medicines, examination findings and the editable dropdown
// lists used by the clinical and reception screens. Entries are switched off
// rather than deleted.

const CAT_UI = { kind: 'icd10', data: {}, editing: null };

const CAT_KINDS = {
  icd10: { path: 'catalogues/icd10', columns: ['Code', 'Description', 'Category', 'Used', 'Status', ''] },
  drugs: { path: 'catalogues/drugs', columns: ['Medicine', 'Category', 'Form', 'Default dose', 'Frequency', 'Duration', 'Price', 'Status', ''] },
  findings: { path: 'catalogues/findings', columns: ['Section', 'Finding', 'Normal', 'Status', ''] },
  lists: { path: 'catalogues/lists', columns: ['List', 'Entry', 'Order', 'Status', ''] },
  templates: { path: 'catalogues/operation-templates', columns: ['Surgery / procedure', 'Type', 'Operation notes', 'Post-op medicines', 'First review', 'Status', ''] },
};

function catSwitch(kind) {
  CAT_UI.kind = kind;
  document.querySelectorAll('#cat-tabs .tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.cat === kind));
  document.getElementById('cat-search').value = '';
  catLoad();
}

async function catLoad(force = false) {
  const kind = CAT_UI.kind;
  const def = CAT_KINDS[kind];
  document.getElementById('cat-thead').innerHTML = `<tr>${def.columns.map(c => `<th>${c}</th>`).join('')}</tr>`;
  if (!CAT_UI.data[kind] || force) {
    admTableMessage('cat-tbody', def.columns.length, 'Loading…');
    try {
      CAT_UI.data[kind] = await KSI.get(def.path);
    } catch (error) {
      admTableMessage('cat-tbody', def.columns.length, error.message);
      return;
    }
  }
  const filter = document.getElementById('cat-filter');
  if (kind === 'findings') {
    admFillSelect('cat-filter', 'All sections', CAT_UI.data.findings.sections.map(s => [s.code, s.label]));
  } else if (kind === 'lists') {
    admFillSelect('cat-filter', 'All lists', CAT_UI.data.lists.lists.map(l => [l.code, l.name]));
  }
  filter.style.display = kind === 'findings' || kind === 'lists' ? '' : 'none';
  // Every surgery and procedure is listed; there is nothing to "add" on this tab.
  const add = document.getElementById('su-add-btn');
  if (add && SETTINGS_UI.tab === 'clinical') add.style.display = kind === 'templates' || !KSI.can('catalogues.manage') ? 'none' : '';
  catRenderTable();
}

function catRows() {
  const kind = CAT_UI.kind;
  const data = CAT_UI.data[kind];
  if (!data) return [];
  const filter = document.getElementById('cat-filter').value;
  const q = document.getElementById('cat-search').value.trim().toLowerCase();
  let rows;
  if (kind === 'templates') rows = data.items.map(i => ({ ...i, label: i.name }));
  else if (kind === 'icd10') rows = data.codes;
  else if (kind === 'drugs') rows = data.drugs;
  else if (kind === 'findings') {
    const labels = Object.fromEntries(data.sections.map(s => [s.code, s.label]));
    rows = data.findings.filter(f => !filter || f.section === filter).map(f => ({ ...f, section_label: labels[f.section] }));
  } else {
    rows = data.lists.filter(l => !filter || l.code === filter).flatMap(l => l.values.map(v => ({ ...v, list_code: l.code, list_name: l.name })));
  }
  return q ? rows.filter(r => Object.values(r).some(v => typeof v === 'string' && v.toLowerCase().includes(q))) : rows;
}

function catRenderTable() {
  const kind = CAT_UI.kind;
  const rows = catRows();
  const columns = CAT_KINDS[kind].columns.length;
  const edit = id => `<button class="btn btn-secondary btn-sm" onclick="catOpenItem(${id})">Edit</button>`;
  const status = active => settingsStatusBadge(active);
  const cells = {
    icd10: r => `<td><span class="reg-num">${KSI.esc(r.code)}</span></td><td class="patient-name">${KSI.esc(r.description)}</td><td style="font-size:12px">${KSI.esc(r.category || '—')}</td><td>${r.usage}</td>`,
    drugs: r => `<td class="patient-name">${KSI.esc(r.name)}</td><td style="font-size:12px">${KSI.esc(r.category)}</td><td style="font-size:12px">${KSI.esc(r.dosage_form || '—')}</td>
      <td style="font-size:12px">${KSI.esc(r.default_dose || '—')}</td><td style="font-size:12px">${KSI.esc(r.default_frequency || '—')}</td><td style="font-size:12px">${KSI.esc(r.default_duration || '—')}</td>
      <td class="money">${r.unit_price ? KSI.fmtMoney(r.unit_price) : '<span class="muted-note">Not priced</span>'}</td>`,
    findings: r => `<td style="font-size:12px">${KSI.esc(r.section_label)}</td><td class="patient-name">${KSI.esc(r.label)}</td><td>${r.is_normal ? 'Yes' : '—'}</td>`,
    lists: r => `<td style="font-size:12px">${KSI.esc(r.list_name)}</td><td class="patient-name">${KSI.esc(r.label)}</td><td>${r.sort_order}</td>`,
  }[kind];
  if (kind === 'templates') {
    if (!rows.length) admTableMessage('cat-tbody', columns, 'No surgeries or procedures on the price list.');
    else document.getElementById('cat-tbody').innerHTML = rows.map(r => {
      const t = r.template;
      return `<tr><td class="patient-name">${KSI.esc(r.name)}${r.item_active ? '' : ' <span class="muted-note">(off the price list)</span>'}</td>
        <td style="font-size:12px">${r.category === 'surgery' ? 'Surgery' : 'Procedure'}</td>
        <td style="font-size:12px">${t?.operation_notes ? KSI.esc(t.operation_notes.slice(0, 70)) + (t.operation_notes.length > 70 ? '…' : '') : '<span class="muted-note">None</span>'}</td>
        <td style="font-size:12px">${t?.medicines?.length ? t.medicines.map(m => KSI.esc(m.name)).join(', ') : '<span class="muted-note">None</span>'}</td>
        <td>${t?.review_days != null ? `Day ${t.review_days}` : '—'}</td>
        <td>${t ? settingsStatusBadge(t.is_active) : '<span class="muted-note">No template</span>'}</td>
        <td>${KSI.can('catalogues.manage') ? `<button class="btn btn-secondary btn-sm" onclick="opTplOpen(${r.id})">${t ? 'Edit' : 'Create'}</button>` : ''}</td></tr>`;
    }).join('');
    document.getElementById('cat-count').textContent = `${rows.length} surger${rows.length === 1 ? 'y or procedure' : 'ies and procedures'}`;
    return;
  }
  if (!rows.length) admTableMessage('cat-tbody', columns, 'No entries match.');
  else document.getElementById('cat-tbody').innerHTML = rows.map(r => `<tr>${cells(r)}<td>${status(r.is_active)}</td><td>${edit(r.id)}</td></tr>`).join('');
  document.getElementById('cat-count').textContent = `${rows.length} entr${rows.length === 1 ? 'y' : 'ies'}`;
}

function catField(label, html, required = false) {
  return `<div class="form-group"><label class="form-label">${label}${required ? ' <span class="required">*</span>' : ''}</label>${html}</div>`;
}

function catInput(field, value, max, placeholder = '') {
  return `<input class="form-input" data-field="${field}" maxlength="${max}" value="${KSI.esc(value ?? '')}" placeholder="${KSI.esc(placeholder)}">`;
}

function catCheck(field, checked, label) {
  return `<div class="form-group"><label class="ur-perm-check"><input type="checkbox" data-field="${field}"${checked ? ' checked' : ''}> <span>${label}</span></label></div>`;
}

function catOpenItem(id = null) {
  if (!KSI.can('catalogues.manage')) return;
  const kind = CAT_UI.kind;
  const item = id ? catRows().find(r => r.id === id) || null : null;
  CAT_UI.editing = item;
  const titles = { icd10: 'ICD-10 Code', drugs: 'Medicine', findings: 'Examination Finding', lists: 'Dropdown Entry' };
  document.getElementById('cat-item-title').textContent = `${item ? 'Edit' : 'Add'} ${titles[kind]}`;
  let html = '';
  if (kind === 'icd10') {
    html = catField('Code', catInput('code', item?.code, 12, 'e.g. H40.1'), true)
      + catField('Description', catInput('description', item?.description, 255), true)
      + catField('Category', catInput('category', item?.category, 80, 'e.g. Glaucoma'));
  } else if (kind === 'drugs') {
    html = catField('Name', catInput('name', item?.name, 150, 'e.g. Timolol 0.5% Eye Drops'), true)
      + catField('Category', catInput('category', item?.category, 60, 'e.g. Glaucoma'), true)
      + catField('Form', catInput('dosage_form', item?.dosage_form, 40, 'e.g. Eye Drops'))
      + catField('Default dose', catInput('default_dose', item?.default_dose, 40, 'e.g. 1 Drop'))
      + catField('Default frequency', catInput('default_frequency', item?.default_frequency, 20, 'e.g. BD'))
      + catField('Default duration', catInput('default_duration', item?.default_duration, 40, 'e.g. 1 Month'))
      + catField('Price per unit (TZS)', `<input type="number" class="form-input" data-field="unit_price" min="0" step="50" value="${item?.unit_price ?? 0}">`);
  } else if (kind === 'findings') {
    const sections = CAT_UI.data.findings.sections;
    const current = item?.section || document.getElementById('cat-filter').value;
    html = catField('Section', `<select class="form-select" data-field="section">${sections.map(s => `<option value="${s.code}"${s.code === current ? ' selected' : ''}>${KSI.esc(s.label)}</option>`).join('')}</select>`, true)
      + catField('Finding', catInput('label', item?.label, 100), true)
      + catCheck('is_normal', item?.is_normal, 'This is a normal finding (not listed as abnormal in summaries)');
  } else {
    const lists = CAT_UI.data.lists.lists;
    const current = item?.list_code || document.getElementById('cat-filter').value;
    html = (item
      ? catField('List', `<input class="form-input" value="${KSI.esc(item.list_name)}" disabled>`)
      : catField('List', `<select class="form-select" data-field="list_code">${lists.map(l => `<option value="${l.code}"${l.code === current ? ' selected' : ''}>${KSI.esc(l.name)}</option>`).join('')}</select>`, true))
      + catField('Entry', catInput('label', item?.label, 100), true)
      + (item ? catField('Order', `<input class="form-input" type="number" min="0" max="60000" data-field="sort_order" value="${item.sort_order}">`, true) : '');
  }
  if (item) html += catCheck('is_active', item.is_active, 'Active (available for selection)');
  const form = document.getElementById('cat-form');
  document.getElementById('cat-fields').innerHTML = html;
  KSI.clearFieldErrors(form);
  openModal('modal-catalogue-item');
  setTimeout(() => form.querySelector('input:not([disabled]), select')?.focus(), 50);
}

// ── Operation templates ──
const OP_TPL = { itemId: null, meds: [] };

function opTplOpen(itemId) {
  const item = CAT_UI.data.templates.items.find(i => i.id === itemId);
  if (!item) return;
  const t = item.template;
  OP_TPL.itemId = itemId;
  OP_TPL.meds = (t?.medicines || []).map(m => ({ ...m }));
  document.getElementById('opt-tpl-title').textContent = `Operation template — ${item.name}`;
  document.getElementById('op-tpl-notes').value = t?.operation_notes || '';
  document.getElementById('op-tpl-instructions').value = t?.postop_instructions || '';
  document.getElementById('op-tpl-review').value = t?.review_days ?? '';
  document.getElementById('op-tpl-active').checked = t ? t.is_active : true;
  KSI.clearFieldErrors(document.getElementById('op-tpl-form'));
  opTplRenderMeds();
  openModal('modal-op-template');
}

function opTplRenderMeds() {
  const drugs = CAT_UI.data.templates.drugs;
  const chosen = OP_TPL.meds.map(m => m.drug_id);
  admFillSelect('op-tpl-drug', 'Add a medicine from the catalogue', drugs.filter(d => !chosen.includes(d.id)).map(d => [d.id, d.name]));
  document.getElementById('op-tpl-meds').innerHTML = OP_TPL.meds.length
    ? OP_TPL.meds.map((m, i) => `<tr data-field="medicines.${i}.drug_id">
        <td>${KSI.esc(m.name)}</td>
        <td><input type="number" class="form-input" min="1" max="999" value="${m.quantity}" style="width:70px" onchange="OP_TPL.meds[${i}].quantity = Number(this.value) || 1"></td>
        <td><input class="form-input" maxlength="40" value="${KSI.esc(m.dose || '')}" style="width:90px" onchange="OP_TPL.meds[${i}].dose = this.value"></td>
        <td><input class="form-input" maxlength="20" value="${KSI.esc(m.frequency || '')}" style="width:90px" onchange="OP_TPL.meds[${i}].frequency = this.value"></td>
        <td><input class="form-input" maxlength="40" value="${KSI.esc(m.duration || '')}" style="width:100px" onchange="OP_TPL.meds[${i}].duration = this.value"></td>
        <td><button type="button" class="action-btn action-cancel" onclick="OP_TPL.meds.splice(${i}, 1); opTplRenderMeds()">Remove</button></td></tr>`).join('')
    : '<tr class="table-state"><td colspan="6">No medicine on this template.</td></tr>';
}

function opTplAddMed(drugId) {
  const drug = CAT_UI.data.templates.drugs.find(d => d.id === Number(drugId));
  if (!drug) return;
  OP_TPL.meds.push({ drug_id: drug.id, name: drug.name, quantity: 1, dose: '', frequency: '', duration: '' });
  opTplRenderMeds();
}

async function opTplSave() {
  const form = document.getElementById('op-tpl-form');
  KSI.clearFieldErrors(form);
  const review = document.getElementById('op-tpl-review').value.trim();
  const body = {
    operation_notes: document.getElementById('op-tpl-notes').value.trim(),
    postop_instructions: document.getElementById('op-tpl-instructions').value.trim(),
    review_days: review === '' ? null : Number(review),
    is_active: document.getElementById('op-tpl-active').checked,
    medicines: OP_TPL.meds.map(m => ({ drug_id: m.drug_id, quantity: m.quantity, dose: m.dose || null, frequency: m.frequency || null, duration: m.duration || null })),
  };
  await admBusy(document.getElementById('op-tpl-save'), async () => {
    try {
      await KSI.put(`catalogues/operation-templates/${OP_TPL.itemId}`, body);
      closeModal('modal-op-template');
      showToast('Saved', 'Operation template saved.', 'success');
      await catLoad(true);
    } catch (error) {
      KSI.reportError(error, form);
    }
  });
}

async function catSaveItem() {
  const form = document.getElementById('cat-form');
  KSI.clearFieldErrors(form);
  const body = {};
  form.querySelectorAll('[data-field]').forEach(el => {
    body[el.dataset.field] = el.type === 'checkbox' ? el.checked : el.value.trim();
  });
  const kind = CAT_UI.kind;
  const item = CAT_UI.editing;
  if (kind === 'findings' && body.is_normal === undefined) body.is_normal = false;
  await admBusy(document.getElementById('cat-save'), async () => {
    try {
      if (item) await KSI.put(`${CAT_KINDS[kind].path}/${item.id}`, body);
      else await KSI.post(CAT_KINDS[kind].path, body);
      closeModal('modal-catalogue-item');
      showToast('Saved', body.label || body.name || body.code || 'Entry saved', 'success');
      await catLoad(true);
    } catch (error) {
      KSI.reportError(error, form);
    }
  });
}
