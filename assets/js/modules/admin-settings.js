'use strict';
// ─── ADMINISTRATION · SETTINGS ─────────────────────────────────────────────
// Staff register, departments, designations and system rules (session timeout,
// sign-in lockout, password length, free revisit window).
const SETTINGS_UI = { tab: 'staff', staff: [], departments: [], designations: [], settings: [], refKind: null, refId: null, staffTimer: null };

const SETTING_META = {
  'security.session_idle_minutes':    { label: 'Sign out after inactivity', unit: 'minutes', group: 'Sessions & sign-in' },
  'security.session_warning_seconds': { label: 'Warn before signing out', unit: 'seconds', group: 'Sessions & sign-in' },
  'security.session_absolute_hours':  { label: 'Maximum session length', unit: 'hours', group: 'Sessions & sign-in' },
  'security.login_max_attempts':      { label: 'Failed sign-ins before locking', unit: 'attempts', group: 'Sessions & sign-in' },
  'security.lockout_minutes':         { label: 'Account lock duration', unit: 'minutes', group: 'Sessions & sign-in' },
  'security.password_min_length':     { label: 'Minimum password length', unit: 'characters', group: 'Passwords' },
  'visits.revisit_exemption_days':    { label: 'Free return-visit window', unit: 'days', group: 'Visits & billing' },
  'clinical.record_edit_hours':       { label: 'Author may correct a completed clinical record for', unit: 'hours', group: 'Clinical records' },
};

function renderSettingsUsersModule() {
  const allowed = { staff: KSI.can('users.view'), departments: true, designations: true, security: true, clinical: KSI.can('catalogues.manage') };
  document.querySelectorAll('#module-settings-users .tab-btn[data-tab]').forEach(btn => {
    btn.style.display = allowed[btn.dataset.tab] ? '' : 'none';
  });
  switchSettingsTab(allowed[SETTINGS_UI.tab] ? SETTINGS_UI.tab : 'departments');
}

function switchSettingsTab(tab) {
  SETTINGS_UI.tab = tab;
  document.querySelectorAll('#module-settings-users .tab-btn[data-tab]').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
  ['staff', 'departments', 'designations', 'security', 'clinical'].forEach(name => {
    document.getElementById(`su-panel-${name}`).style.display = name === tab ? '' : 'none';
  });
  const [label, permission] = {
    staff: ['Register Staff', 'users.manage'],
    departments: ['Add Department', 'settings.manage'],
    designations: ['Add Designation', 'settings.manage'],
    security: [null, null],
    clinical: ['Add Entry', 'catalogues.manage'],
  }[tab];
  document.getElementById('su-add-label').textContent = label || '';
  document.getElementById('su-add-btn').style.display = label && KSI.can(permission) ? '' : 'none';
  ({ staff: loadStaffRegister, departments: loadDepartments, designations: loadDesignations, security: loadSystemSettings, clinical: () => catLoad() })[tab]();
}

function settingsAddClicked() {
  if (SETTINGS_UI.tab === 'staff') openUserModal();
  else if (SETTINGS_UI.tab === 'departments') openRefItemModal('department');
  else if (SETTINGS_UI.tab === 'designations') openRefItemModal('designation');
  else if (SETTINGS_UI.tab === 'clinical') catOpenItem();
}

function settingsStatusBadge(active) {
  return active ? '<span class="badge badge-complete">Active</span>' : '<span class="badge badge-cancelled">Inactive</span>';
}

// ── Staff register ──
function onStaffFilter(debounce) {
  clearTimeout(SETTINGS_UI.staffTimer);
  SETTINGS_UI.staffTimer = setTimeout(loadStaffRegister, debounce ? 300 : 0);
}

async function loadStaffRegister() {
  admTableMessage('su-users-tbody', 9, 'Loading staff…');
  try {
    if (!SETTINGS_UI.departments.length) {
      SETTINGS_UI.departments = (await KSI.get('reference/departments')).departments;
    }
    admFillSelect('su-dept-filter', 'All Departments', SETTINGS_UI.departments.map(d => [d.id, d.name]));
    const { users } = await KSI.get('users', {
      search: document.getElementById('su-search').value.trim(),
      department_id: document.getElementById('su-dept-filter').value,
    });
    SETTINGS_UI.staff = users;
    if (!users.length) {
      admTableMessage('su-users-tbody', 9, 'No staff match the filters.');
    } else {
      document.getElementById('su-users-tbody').innerHTML = users.map(user => `<tr>
        <td class="patient-name">${KSI.esc(user.first_name)}</td>
        <td>${KSI.esc(user.middle_name || '—')}</td>
        <td>${KSI.esc(user.last_name)}</td>
        <td style="font-family:var(--font-mono);font-size:12px">${KSI.esc(user.phone || '—')}</td>
        <td style="font-size:12px">${KSI.esc(user.email || '—')}</td>
        <td style="font-size:12px">${KSI.esc(user.department || '—')}</td>
        <td style="font-size:12px">${KSI.esc(user.designations.map(d => d.name).join(', ') || '—')}</td>
        <td>${urStatusBadges(user)}</td>
        <td>${urActions(user)}</td>
      </tr>`).join('');
    }
    document.getElementById('su-users-count').textContent = `${users.length} staff member${users.length === 1 ? '' : 's'}`;
  } catch (error) {
    admTableMessage('su-users-tbody', 9, error.message);
  }
}

// ── Departments & designations ──
async function loadDepartments() {
  admTableMessage('su-departments-tbody', 4, 'Loading…');
  try {
    SETTINGS_UI.departments = (await KSI.get('reference/departments')).departments;
    const canEdit = KSI.can('settings.manage');
    document.getElementById('su-departments-tbody').innerHTML = SETTINGS_UI.departments.map(d => `<tr>
      <td class="patient-name">${KSI.esc(d.name)}</td>
      <td>${d.user_count}</td>
      <td>${settingsStatusBadge(d.is_active)}</td>
      <td>${canEdit ? `<button class="btn btn-secondary btn-sm" onclick="openRefItemModal('department', ${d.id})">Edit</button>` : '—'}</td>
    </tr>`).join('') || '<tr class="table-state"><td colspan="4">No departments yet.</td></tr>';
  } catch (error) {
    admTableMessage('su-departments-tbody', 4, error.message);
  }
}

async function loadDesignations() {
  admTableMessage('su-designations-tbody', 5, 'Loading…');
  try {
    SETTINGS_UI.designations = (await KSI.get('reference/designations')).designations;
    const canEdit = KSI.can('settings.manage');
    document.getElementById('su-designations-tbody').innerHTML = SETTINGS_UI.designations.map(d => `<tr>
      <td class="patient-name">${KSI.esc(d.name)}</td>
      <td><span class="reg-num">${KSI.esc(d.code)}</span></td>
      <td>${d.user_count}</td>
      <td>${settingsStatusBadge(d.is_active)}</td>
      <td>${canEdit ? `<button class="btn btn-secondary btn-sm" onclick="openRefItemModal('designation', ${d.id})">Edit</button>` : '—'}</td>
    </tr>`).join('') || '<tr class="table-state"><td colspan="5">No designations yet.</td></tr>';
  } catch (error) {
    admTableMessage('su-designations-tbody', 5, error.message);
  }
}

function openRefItemModal(kind, itemId = null) {
  if (!KSI.can('settings.manage')) return;
  const list = kind === 'department' ? SETTINGS_UI.departments : SETTINGS_UI.designations;
  const item = itemId ? list.find(x => x.id === itemId) : null;
  SETTINGS_UI.refKind = kind;
  SETTINGS_UI.refId = item ? item.id : null;
  const label = kind === 'department' ? 'Department' : 'Designation';
  document.getElementById('ref-item-title').textContent = `${item ? 'Edit' : 'Add'} ${label}`;
  const form = document.getElementById('form-ref-item');
  form.reset();
  KSI.clearFieldErrors(form);
  form.querySelector('[data-field="name"]').value = item ? item.name : '';
  form.querySelector('[data-field="is_active"]').checked = item ? item.is_active : true;
  document.getElementById('ref-item-active-group').style.display = item ? '' : 'none';
  openModal('modal-ref-item');
  setTimeout(() => form.querySelector('[data-field="name"]').focus(), 50);
}

async function saveRefItem() {
  const form = document.getElementById('form-ref-item');
  KSI.clearFieldErrors(form);
  const kind = SETTINGS_UI.refKind;
  const path = kind === 'department' ? 'reference/departments' : 'reference/designations';
  const body = { name: admValue(form, 'name') };
  if (SETTINGS_UI.refId) body.is_active = form.querySelector('[data-field="is_active"]').checked;

  await admBusy(document.getElementById('ref-item-save'), async () => {
    try {
      if (SETTINGS_UI.refId) await KSI.put(`${path}/${SETTINGS_UI.refId}`, body);
      else await KSI.post(path, body);
      closeModal('modal-ref-item');
      showToast('Saved', body.name, 'success');
      UR.referenceLoaded = false;
      await (kind === 'department' ? loadDepartments() : loadDesignations());
    } catch (error) {
      KSI.reportError(error, form);
    }
  });
}

// ── System rules ──
async function loadSystemSettings() {
  const card = document.getElementById('su-settings-card');
  const canEdit = KSI.can('settings.manage');
  document.getElementById('su-settings-save').style.display = canEdit ? '' : 'none';
  card.innerHTML = '<div class="muted-note" style="padding:28px;text-align:center">Loading…</div>';
  try {
    const order = Object.keys(SETTING_META);
    const rank = key => (order.indexOf(key) + 1) || order.length + 1;
    SETTINGS_UI.settings = (await KSI.get('settings')).settings.sort((a, b) => rank(a.key) - rank(b.key));
    const groups = {};
    SETTINGS_UI.settings.forEach(setting => {
      const meta = SETTING_META[setting.key] || { label: setting.key, unit: '', group: 'Other' };
      (groups[meta.group] ||= []).push({ ...setting, meta });
    });
    card.innerHTML = Object.entries(groups).map(([group, items]) => `
      <div class="settings-group-title">${KSI.esc(group)}</div>
      ${items.map(setting => `<div class="settings-row">
        <div>
          <div class="settings-label">${KSI.esc(setting.meta.label)}</div>
          <div class="settings-desc">${KSI.esc(setting.description || '')}${setting.updated_by ? ` · Changed by ${KSI.esc(setting.updated_by)}, ${KSI.fmtDate(setting.updated_at)}` : ''}</div>
        </div>
        <div class="form-group" style="flex-direction:row;align-items:center;gap:8px;flex-wrap:wrap">
          <input class="form-input" type="number" style="width:96px" data-field="${KSI.esc(setting.key)}" value="${KSI.esc(setting.value)}"
                 min="${KSI.esc(setting.min ?? '')}" max="${KSI.esc(setting.max ?? '')}"${canEdit ? '' : ' disabled'}>
          <span class="muted-note">${KSI.esc(setting.meta.unit)}</span>
        </div>
      </div>`).join('')}`).join('');
    await loadRegistrationNumber(card, canEdit);
  } catch (error) {
    card.innerHTML = `<div class="muted-note" style="padding:28px;text-align:center">${KSI.esc(error.message)}</div>`;
  }
}

// ── Patient numbering ──
// One series with the paper files: patients who already have a paper file keep its number (at
// or below the highest paper number); new patients get the next number above it.
async function loadRegistrationNumber(card, canEdit) {
  const info = await KSI.get('settings/registration-number');
  const used = info.highest_used
    ? `The system has given numbers up to ${info.highest_used}, so it cannot go lower.`
    : 'No new patients numbered yet.';
  card.insertAdjacentHTML('beforeend', `
    <div class="settings-group-title">Patient numbering</div>
    <div class="settings-row">
      <div>
        <div class="settings-label">Highest paper file number</div>
        <div class="settings-desc">Patients with a paper file keep its number, from 1 up to this. Set it once, to the last
          paper file number, before going live. ${info.paper_patients ? `${info.paper_patients} patients registered with paper numbers so far.` : ''}</div>
      </div>
      <div class="form-group" style="flex-direction:row;align-items:center;gap:8px;flex-wrap:wrap">
        <input class="form-input" type="number" style="width:110px" id="su-paper-max" data-field="paper_max" min="1" value="${info.paper_max || ''}"${canEdit ? '' : ' disabled'}>
        ${canEdit ? '<button class="btn btn-secondary btn-sm" type="button" id="su-paper-save" onclick="savePaperMax()">Set</button>' : ''}
      </div>
    </div>
    <div class="settings-row">
      <div>
        <div class="settings-label">Next file number for a new patient</div>
        <div class="settings-desc">The next new patient receives <strong id="su-reg-preview">${KSI.esc(info.next_reg_no)}</strong>.
          It is always above the highest paper file number. ${KSI.esc(used)}</div>
      </div>
      <div class="form-group" style="flex-direction:row;align-items:center;gap:8px;flex-wrap:wrap">
        <input class="form-input" type="number" style="width:110px" id="su-reg-next" data-field="next_number" min="${Math.max(info.highest_used, info.paper_max) + 1}" value="${info.next_number}"${canEdit ? '' : ' disabled'}
               oninput="document.getElementById('su-reg-preview').textContent = String(Math.max(0, parseInt(this.value, 10) || 0))">
        ${canEdit ? '<button class="btn btn-secondary btn-sm" type="button" id="su-reg-save" onclick="saveRegistrationNumber()">Set</button>' : ''}
      </div>
    </div>`);
}

async function savePaperMax() {
  const card = document.getElementById('su-settings-card');
  KSI.clearFieldErrors(card);
  await admBusy(document.getElementById('su-paper-save'), async () => {
    try {
      const info = await KSI.put('settings/paper-file-number', { paper_max: document.getElementById('su-paper-max').value });
      showToast('Paper files set', `Paper files go up to ${info.paper_max}; the next new patient will be ${info.next_reg_no}.`, 'success');
      await loadSystemSettings();
    } catch (error) {
      KSI.reportError(error, card, 'Paper range not changed');
    }
  });
}

async function saveRegistrationNumber() {
  const card = document.getElementById('su-settings-card');
  KSI.clearFieldErrors(card);
  await admBusy(document.getElementById('su-reg-save'), async () => {
    try {
      const info = await KSI.put('settings/registration-number', { next_number: document.getElementById('su-reg-next').value });
      showToast('Numbering updated', `The next new patient will be ${info.next_reg_no}.`, 'success');
      await loadSystemSettings();
    } catch (error) {
      KSI.reportError(error, card, 'Numbering not changed');
    }
  });
}

async function saveSystemSettings() {
  const card = document.getElementById('su-settings-card');
  KSI.clearFieldErrors(card);
  const values = {};
  SETTINGS_UI.settings.forEach(setting => {
    const input = card.querySelector(`[data-field="${CSS.escape(setting.key)}"]`);
    if (input && input.value.trim() !== String(setting.value)) values[setting.key] = input.value.trim();
  });
  if (!Object.keys(values).length) {
    showToast('No changes', 'Nothing to save.', 'info');
    return;
  }
  await admBusy(document.getElementById('su-settings-save'), async () => {
    try {
      await KSI.put('settings', { values });
      showToast('Settings saved', 'The server applies them immediately; open screens pick up timeout changes when next loaded.', 'success');
      await loadSystemSettings();
    } catch (error) {
      KSI.reportError(error, card, 'Settings not saved');
    }
  });
}
