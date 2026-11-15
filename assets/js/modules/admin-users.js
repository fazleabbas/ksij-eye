'use strict';
// ─── ADMINISTRATION · SHARED HELPERS ───────────────────────────────────────
const ADM_ICONS = {
  users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  check: '<polyline points="20 6 9 17 4 12"/>',
  star: '<path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/>',
  lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  clock: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/>',
  tag: '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
  alert: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
};

function admStatCards(containerId, cards) {
  document.getElementById(containerId).innerHTML = cards.map(card => `
    <div class="stores-stat-card">
      <div class="stores-stat-icon ${card.color}"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${ADM_ICONS[card.icon]}</svg></div>
      <div class="stores-stat-num">${KSI.esc(card.value)}</div>
      <div class="stores-stat-label">${KSI.esc(card.label)}</div>
    </div>`).join('');
}

function admTableMessage(tbodyId, columns, message) {
  document.getElementById(tbodyId).innerHTML = `<tr class="table-state"><td colspan="${columns}">${KSI.esc(message)}</td></tr>`;
}

function admFillSelect(selectId, placeholder, options) {
  const select = document.getElementById(selectId);
  const current = select.value;
  select.innerHTML = `<option value="">${KSI.esc(placeholder)}</option>`
    + options.map(([value, label]) => `<option value="${KSI.esc(value)}">${KSI.esc(label)}</option>`).join('');
  if ([...select.options].some(option => option.value === current)) select.value = current;
}

function admValue(form, field) {
  const input = form.querySelector(`[data-field="${field}"]`);
  return input ? input.value.trim() : '';
}

function admCheckedIds(container) {
  return [...container.querySelectorAll('input[type="checkbox"]:checked')].map(cb => Number(cb.value));
}

function admCheckCard(value, label, sub, checked, disabled) {
  return `<label class="check-card${checked ? ' checked' : ''}">
    <input type="checkbox" value="${KSI.esc(value)}"${checked ? ' checked' : ''}${disabled ? ' disabled' : ''} onchange="this.closest('.check-card').classList.toggle('checked', this.checked)">
    <span>${KSI.esc(label)}${sub ? `<small>${KSI.esc(sub)}</small>` : ''}</span>
  </label>`;
}

/** Disables the button while the task runs; returns the task's result. */
async function admBusy(button, task) {
  button.disabled = true;
  try {
    return await task();
  } finally {
    button.disabled = false;
  }
}

function admModuleVisible(moduleId) {
  const el = document.getElementById(moduleId);
  return !!el && !el.classList.contains('hidden');
}

// ─── ADMINISTRATION · USERS & ROLES ────────────────────────────────────────
const UR = {
  tab: 'users',
  users: [],
  allUsers: [],
  roles: [],
  permissionGroups: [],
  permissionCount: 0,
  departments: [],
  designations: [],
  referenceLoaded: false,
  editingUserId: null,
  editingRoleId: null,
  resetUserId: null,
  sessionsUserId: null,
  searchTimer: null,
};
const UR_ROLE_BADGE = { purple: 'badge-discount-req', blue: 'badge-consult', amber: 'badge-pending', teal: 'badge-in-progress', green: 'badge-complete', red: 'badge-cancel-req' };

async function admLoadReference(force = false) {
  if (UR.referenceLoaded && !force) return;
  const [roles, permissions, departments, designations] = await Promise.all([
    KSI.get('roles'), KSI.get('permissions'), KSI.get('reference/departments'), KSI.get('reference/designations'),
  ]);
  UR.roles = roles.roles;
  UR.permissionCount = roles.permission_count;
  UR.permissionGroups = permissions.groups;
  UR.departments = departments.departments;
  UR.designations = designations.designations;
  UR.referenceLoaded = true;
}

async function renderUsersRolesModule() {
  document.getElementById('ur-new-user-btn').hidden = !KSI.can('users.manage');
  document.getElementById('ur-new-role-btn').hidden = !KSI.can('roles.manage');
  switchURTab(UR.tab);
  admTableMessage('ur-users-tbody', 8, 'Loading staff accounts…');
  try {
    await admLoadReference(true);
    admFillSelect('ur-user-role-filter', 'All Roles', UR.roles.map(r => [r.id, r.name]));
    admFillSelect('ur-user-dept-filter', 'All Departments', UR.departments.map(d => [d.id, d.name]));
    renderURRoles();
    await loadURUsers();
  } catch (error) {
    admTableMessage('ur-users-tbody', 8, error.message);
  }
}

function switchURTab(tab) {
  UR.tab = tab;
  document.querySelectorAll('#module-users-roles .tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
  document.getElementById('ur-panel-users').style.display = tab === 'users' ? '' : 'none';
  document.getElementById('ur-panel-roles').style.display = tab === 'roles' ? '' : 'none';
}

function onURUserFilter(debounce) {
  clearTimeout(UR.searchTimer);
  UR.searchTimer = setTimeout(loadURUsers, debounce ? 300 : 0);
}

async function loadURUsers() {
  const query = {
    search: document.getElementById('ur-user-search').value.trim(),
    role_id: document.getElementById('ur-user-role-filter').value,
    department_id: document.getElementById('ur-user-dept-filter').value,
    status: document.getElementById('ur-user-status-filter').value,
  };
  try {
    const filtered = Object.values(query).some(Boolean);
    const [list, all] = await Promise.all([
      KSI.get('users', query),
      filtered ? KSI.get('users') : null,
    ]);
    UR.users = list.users;
    UR.allUsers = all ? all.users : list.users;
    renderURStats();
    renderURUsers();
  } catch (error) {
    admTableMessage('ur-users-tbody', 8, error.message);
  }
}

function renderURStats() {
  const all = UR.allUsers;
  admStatCards('ur-stats-grid', [
    { label: 'Staff Accounts', value: all.length, color: 'teal', icon: 'users' },
    { label: 'Active', value: all.filter(u => u.status === 'active').length, color: 'green', icon: 'check' },
    { label: 'Roles', value: UR.roles.length, color: 'blue', icon: 'star' },
    { label: 'Locked / Temporary Password', value: `${all.filter(u => u.is_locked).length} / ${all.filter(u => u.must_change_password).length}`, color: 'amber', icon: 'lock' },
  ]);
}

function urStatusBadges(user) {
  const badges = [user.status === 'active'
    ? '<span class="badge badge-complete">Active</span>'
    : '<span class="badge badge-cancelled">Inactive</span>'];
  if (user.is_locked) badges.push('<span class="badge badge-locked">Locked</span>');
  if (user.must_change_password) badges.push('<span class="badge badge-warn">Temp password</span>');
  return `<div class="badge-list">${badges.join('')}</div>`;
}

function urActions(user) {
  const self = user.id === KSI.user.id;
  const actions = [];
  if (KSI.can('users.manage')) {
    actions.push(`<button class="btn btn-secondary btn-sm" onclick="openUserModal(${user.id})">Edit</button>`);
    if (!self) {
      actions.push(`<button class="btn btn-secondary btn-sm" onclick="toggleUserStatus(${user.id})">${user.status === 'active' ? 'Deactivate' : 'Activate'}</button>`);
      actions.push(`<button class="btn btn-secondary btn-sm" onclick="openResetPassword(${user.id})">Reset Password</button>`);
    }
    if (user.is_locked) actions.push(`<button class="btn btn-secondary btn-sm" onclick="unlockUser(${user.id})">Unlock</button>`);
  }
  if (KSI.can('sessions.manage')) {
    actions.push(`<button class="btn btn-ghost btn-sm" onclick="openUserSessions(${user.id})">Sessions</button>`);
  }
  return actions.length ? `<div class="row-actions">${actions.join('')}</div>` : '<span class="muted-note">—</span>';
}

function urRoleBadges(user) {
  return user.roles.map(r => `<span class="badge ${UR_ROLE_BADGE[r.color] || 'badge-normal'}">${KSI.esc(r.name)}</span>`).join('') || '—';
}

function renderURUsers() {
  if (!UR.users.length) {
    admTableMessage('ur-users-tbody', 8, 'No staff accounts match the filters.');
  } else {
    document.getElementById('ur-users-tbody').innerHTML = UR.users.map(user => `<tr>
      <td><span class="reg-num">${KSI.esc(user.staff_no)}</span></td>
      <td>
        <div class="patient-name">${KSI.esc(user.full_name)}</div>
        <div class="sub-text">${KSI.esc(user.designations.map(d => d.name).join(', ') || '—')}</div>
      </td>
      <td style="font-family:var(--font-mono);font-size:12px;color:var(--text-2)">${KSI.esc(user.username)}</td>
      <td><div class="badge-list">${urRoleBadges(user)}</div></td>
      <td style="font-size:12px">${KSI.esc(user.department || '—')}</td>
      <td style="font-size:12px;color:var(--text-3);white-space:nowrap">${user.last_login_at ? KSI.fmtDateTime(user.last_login_at) : 'Never'}</td>
      <td>${urStatusBadges(user)}</td>
      <td>${urActions(user)}</td>
    </tr>`).join('');
  }
  document.getElementById('ur-users-count').textContent = `${UR.users.length} account${UR.users.length === 1 ? '' : 's'}`;
}

function admFindUser(userId) {
  const lists = [UR.users, UR.allUsers, typeof SETTINGS_UI !== 'undefined' ? SETTINGS_UI.staff : []];
  for (const list of lists) {
    const user = list.find(u => u.id === userId);
    if (user) return user;
  }
  return null;
}

async function refreshUserViews() {
  const tasks = [];
  if (admModuleVisible('module-users-roles')) tasks.push(loadURUsers());
  if (admModuleVisible('module-settings-users') && typeof loadStaffRegister === 'function') tasks.push(loadStaffRegister());
  await Promise.all(tasks);
}

// ── Staff account modal ──
async function openUserModal(userId = null) {
  if (!KSI.can('users.manage')) return;
  try {
    await admLoadReference();
  } catch (error) {
    showToast('Could not load roles', error.message, 'error');
    return;
  }
  const user = userId ? admFindUser(userId) : null;
  const self = !!user && user.id === KSI.user.id;
  UR.editingUserId = user ? user.id : null;

  const form = document.getElementById('form-ur-user');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('ur-user-modal-title').textContent = user ? `Edit Staff Account — ${user.full_name}` : 'Register Staff Account';

  const field = name => form.querySelector(`[data-field="${name}"]`);
  field('first_name').value = user?.first_name || '';
  field('middle_name').value = user?.middle_name || '';
  field('last_name').value = user?.last_name || '';
  field('phone').value = user?.phone || '';
  field('email').value = user?.email || '';
  field('username').value = user?.username || '';
  field('username').disabled = !!user;
  field('password').value = '';
  document.getElementById('ur-user-password-group').style.display = user ? 'none' : '';

  const departments = UR.departments.filter(d => d.is_active || d.id === user?.department_id);
  admFillSelect('ur-user-department', 'Select department…', departments.map(d => [d.id, d.name]));
  field('department_id').value = user?.department_id ?? '';
  field('status').value = user?.status || 'active';
  field('status').disabled = self;

  const roleIds = new Set((user?.roles || []).map(r => r.id));
  document.getElementById('ur-user-roles').innerHTML = UR.roles
    .map(role => admCheckCard(role.id, role.name, role.description, roleIds.has(role.id), self)).join('');

  const designationIds = new Set((user?.designations || []).map(d => d.id));
  const designations = UR.designations.filter(d => d.is_active || designationIds.has(d.id));
  document.getElementById('ur-user-designations').innerHTML = designations.length
    ? designations.map(d => admCheckCard(d.id, d.name, '', designationIds.has(d.id), false)).join('')
    : '<div class="muted-note">No designations yet — add them under Settings → Designations.</div>';

  document.getElementById('ur-user-self-note').style.display = self ? '' : 'none';
  openModal('modal-ur-user');
  setTimeout(() => field('first_name').focus(), 50);
}

async function saveUser() {
  const form = document.getElementById('form-ur-user');
  KSI.clearFieldErrors(form);
  const editing = UR.editingUserId ? admFindUser(UR.editingUserId) : null;
  const body = {
    first_name: admValue(form, 'first_name'),
    middle_name: admValue(form, 'middle_name'),
    last_name: admValue(form, 'last_name'),
    phone: admValue(form, 'phone'),
    email: admValue(form, 'email'),
    department_id: Number(admValue(form, 'department_id')) || null,
    status: admValue(form, 'status'),
    role_ids: admCheckedIds(document.getElementById('ur-user-roles')),
    designation_ids: admCheckedIds(document.getElementById('ur-user-designations')),
  };
  if (editing) {
    body.row_version = editing.row_version;
  } else {
    body.username = admValue(form, 'username');
    body.password = form.querySelector('[data-field="password"]').value;
  }

  await admBusy(document.getElementById('ur-user-save'), async () => {
    try {
      const result = editing ? await KSI.put(`users/${editing.id}`, body) : await KSI.post('users', body);
      closeModal('modal-ur-user');
      showToast(editing ? 'Account updated' : 'Account registered',
        editing ? result.user.full_name : `${result.user.full_name} (${result.user.staff_no}) must change the temporary password at first sign-in.`, 'success');
      await refreshUserViews();
    } catch (error) {
      KSI.reportError(error, form);
    }
  });
}

async function toggleUserStatus(userId) {
  const user = admFindUser(userId);
  if (!user) return;
  const next = user.status === 'active' ? 'inactive' : 'active';
  if (next === 'inactive' && !confirm(`Deactivate ${user.full_name}?\n\nThey are signed out immediately and cannot sign in until reactivated.`)) return;
  try {
    await KSI.post(`users/${userId}/status`, { status: next });
    showToast(next === 'active' ? 'Account activated' : 'Account deactivated', user.full_name, 'success');
    await refreshUserViews();
  } catch (error) {
    KSI.reportError(error, null, 'Status not changed');
  }
}

async function unlockUser(userId) {
  const user = admFindUser(userId);
  try {
    await KSI.post(`users/${userId}/unlock`);
    showToast('Account unlocked', user ? user.full_name : '', 'success');
    await refreshUserViews();
  } catch (error) {
    KSI.reportError(error, null, 'Account not unlocked');
  }
}

// ── Temporary password ──
function openResetPassword(userId) {
  const user = admFindUser(userId);
  if (!user) return;
  UR.resetUserId = userId;
  const form = document.getElementById('form-ur-reset-password');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('ur-reset-subtitle').textContent = `${user.full_name} (${user.username})`;
  openModal('modal-ur-reset-password');
  setTimeout(() => form.querySelector('[data-field="password"]').focus(), 50);
}

async function submitResetPassword() {
  const form = document.getElementById('form-ur-reset-password');
  KSI.clearFieldErrors(form);
  const password = form.querySelector('[data-field="password"]').value;
  if (password !== form.querySelector('[data-field="confirm_password"]').value) {
    KSI.showFieldErrors(form, { confirm_password: 'The passwords do not match.' });
    return;
  }
  const user = admFindUser(UR.resetUserId);
  await admBusy(document.getElementById('ur-reset-save'), async () => {
    try {
      await KSI.post(`users/${UR.resetUserId}/reset-password`, { password });
      closeModal('modal-ur-reset-password');
      showToast('Temporary password set', `${user ? user.full_name : 'The user'} must choose a new password at the next sign-in.`, 'success');
      await refreshUserViews();
    } catch (error) {
      KSI.reportError(error, form, 'Password not set');
    }
  });
}

// ── Sessions ──
function urDeviceLabel(userAgent) {
  if (!userAgent) return 'Unknown device';
  const browser = /Edg\//.test(userAgent) ? 'Edge' : /Chrome\//.test(userAgent) ? 'Chrome' : /Firefox\//.test(userAgent) ? 'Firefox' : /Safari\//.test(userAgent) ? 'Safari' : 'Browser';
  const os = /Windows/.test(userAgent) ? 'Windows' : /Android/.test(userAgent) ? 'Android' : /iPhone|iPad/.test(userAgent) ? 'iOS' : /Mac OS/.test(userAgent) ? 'macOS' : /Linux/.test(userAgent) ? 'Linux' : '';
  return os ? `${browser} on ${os}` : browser;
}

async function openUserSessions(userId) {
  const user = admFindUser(userId);
  UR.sessionsUserId = userId;
  document.getElementById('ur-sessions-subtitle').textContent = user ? `${user.full_name} (${user.username})` : '';
  admTableMessage('ur-sessions-tbody', 4, 'Loading…');
  const revokeButton = document.getElementById('ur-sessions-revoke');
  revokeButton.disabled = true;
  openModal('modal-ur-sessions');
  try {
    const { sessions } = await KSI.get(`users/${userId}/sessions`);
    if (!sessions.length) {
      admTableMessage('ur-sessions-tbody', 4, 'No active sessions.');
      return;
    }
    revokeButton.disabled = false;
    document.getElementById('ur-sessions-tbody').innerHTML = sessions.map(s => `<tr>
      <td style="white-space:nowrap">${KSI.fmtDateTime(s.created_at)}${s.is_current ? ' <span class="badge badge-in-progress">This session</span>' : ''}</td>
      <td style="white-space:nowrap">${KSI.fmtDateTime(s.last_activity_at)}</td>
      <td style="font-family:var(--font-mono);font-size:12px">${KSI.esc(s.ip_address)}</td>
      <td style="font-size:12px;color:var(--text-3)">${KSI.esc(urDeviceLabel(s.user_agent))}</td>
    </tr>`).join('');
  } catch (error) {
    admTableMessage('ur-sessions-tbody', 4, error.message);
  }
}

async function revokeUserSessions() {
  const user = admFindUser(UR.sessionsUserId);
  const self = UR.sessionsUserId === KSI.user.id;
  if (!confirm(self ? 'Sign out all of your other sessions?' : `Sign ${user ? user.full_name : 'this user'} out of every device now?`)) return;
  const done = await admBusy(document.getElementById('ur-sessions-revoke'), async () => {
    try {
      const { revoked } = await KSI.post(`users/${UR.sessionsUserId}/revoke-sessions`);
      showToast('Sessions ended', `${revoked} session${revoked === 1 ? '' : 's'} signed out.`, 'success');
      return true;
    } catch (error) {
      KSI.reportError(error, null, 'Sessions not ended');
      return false;
    }
  });
  if (done) await openUserSessions(UR.sessionsUserId);
}

// ── Roles ──
function renderURRoles() {
  const canManage = KSI.can('roles.manage');
  document.getElementById('ur-roles-grid').innerHTML = UR.roles.map(role => {
    const percent = UR.permissionCount ? Math.round(role.permissions.length / UR.permissionCount * 100) : 0;
    return `<div class="card" style="padding:16px">
      <div style="font-size:14px;font-weight:700;color:var(--text-1);display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        ${KSI.esc(role.name)}
        <span class="badge ${UR_ROLE_BADGE[role.color] || 'badge-normal'}">${role.user_count} user${role.user_count === 1 ? '' : 's'}</span>
        ${role.is_system ? '<span class="badge badge-normal">System</span>' : ''}
        ${role.is_locked ? '<span class="badge badge-locked">Locked</span>' : ''}
      </div>
      <div style="font-size:12px;color:var(--text-3);margin-top:4px">${KSI.esc(role.description || '—')}</div>
      <div style="margin-top:12px;display:flex;align-items:center;gap:8px">
        <div style="flex:1;height:6px;background:var(--canvas);border-radius:99px;overflow:hidden">
          <div style="height:100%;width:${percent}%;background:var(--teal)"></div>
        </div>
        <span style="font-size:11px;color:var(--text-3);white-space:nowrap">${role.permissions.length}/${UR.permissionCount} permissions</span>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px">
        <button class="btn btn-secondary btn-sm" onclick="openRoleModal(${role.id})">${canManage ? 'Edit' : 'View'}</button>
        ${canManage && !role.is_system ? `<button class="btn btn-danger btn-sm" onclick="deleteRole(${role.id})">Delete</button>` : ''}
      </div>
    </div>`;
  }).join('') || '<div class="muted-note">No roles defined.</div>';
}

function openRoleModal(roleId = null) {
  const role = roleId ? UR.roles.find(r => r.id === roleId) : null;
  const canManage = KSI.can('roles.manage');
  if (!role && !canManage) return;
  UR.editingRoleId = role ? role.id : null;

  const form = document.getElementById('form-ur-role');
  form.reset();
  KSI.clearFieldErrors(form);
  const field = name => form.querySelector(`[data-field="${name}"]`);
  const locked = !!role?.is_locked;
  document.getElementById('ur-role-modal-title').textContent = role ? `${canManage ? 'Edit Role' : 'Role'} — ${role.name}` : 'New Role';
  field('name').value = role?.name || '';
  field('name').disabled = !canManage || !!role?.is_system;
  field('color').value = role?.color || 'teal';
  field('color').disabled = !canManage;
  field('description').value = role?.description || '';
  field('description').disabled = !canManage;
  document.getElementById('ur-role-locked-note').style.display = locked ? '' : 'none';

  const granted = new Set(role?.permissions || []);
  const disabled = !canManage || locked ? ' disabled' : '';
  document.getElementById('ur-role-perm-body').innerHTML = UR.permissionGroups.map((group, index) => {
    const allChecked = group.permissions.every(p => granted.has(p.code));
    return `<div class="ur-perm-group${locked ? ' locked' : ''}">
      <div class="ur-perm-group-head">
        <label class="ur-perm-check"><input type="checkbox" class="ur-perm-head" data-group="${index}" onchange="urToggleGroup(${index}, this.checked)"${allChecked ? ' checked' : ''}${disabled}><span>${KSI.esc(group.category)}</span></label>
      </div>
      <div class="ur-perm-grid">
        ${group.permissions.map(p => `<label class="ur-perm-check" title="${KSI.esc(p.code)}"><input type="checkbox" class="ur-perm-cb" data-group="${index}" value="${KSI.esc(p.code)}"${granted.has(p.code) ? ' checked' : ''}${disabled} onchange="urSyncGroup(${index})"><span>${KSI.esc(p.label)}</span></label>`).join('')}
      </div>
    </div>`;
  }).join('');

  document.getElementById('ur-role-delete-btn').style.display = canManage && role && !role.is_system ? '' : 'none';
  document.getElementById('ur-role-save').style.display = canManage ? '' : 'none';
  openModal('modal-ur-role');
}

function urToggleGroup(index, checked) {
  document.querySelectorAll(`#ur-role-perm-body .ur-perm-cb[data-group="${index}"]`).forEach(cb => { if (!cb.disabled) cb.checked = checked; });
}

function urSyncGroup(index) {
  const items = [...document.querySelectorAll(`#ur-role-perm-body .ur-perm-cb[data-group="${index}"]`)];
  const head = document.querySelector(`#ur-role-perm-body .ur-perm-head[data-group="${index}"]`);
  if (head) head.checked = items.every(cb => cb.checked);
}

async function saveRole() {
  const form = document.getElementById('form-ur-role');
  KSI.clearFieldErrors(form);
  const role = UR.editingRoleId ? UR.roles.find(r => r.id === UR.editingRoleId) : null;
  const body = { name: admValue(form, 'name'), description: admValue(form, 'description'), color: admValue(form, 'color') };
  if (!role?.is_locked) body.permissions = [...form.querySelectorAll('.ur-perm-cb:checked')].map(cb => cb.value);
  if (role) body.row_version = role.row_version;

  await admBusy(document.getElementById('ur-role-save'), async () => {
    try {
      const result = role ? await KSI.put(`roles/${role.id}`, body) : await KSI.post('roles', body);
      closeModal('modal-ur-role');
      showToast(role ? 'Role updated' : 'Role created', `${result.role.name} — ${result.role.permissions.length} permissions`, 'success');
      await admLoadReference(true);
      renderURRoles();
      admFillSelect('ur-user-role-filter', 'All Roles', UR.roles.map(r => [r.id, r.name]));
      renderURStats();
    } catch (error) {
      KSI.reportError(error, form, 'Role not saved');
    }
  });
}

async function deleteRole(roleId) {
  const role = UR.roles.find(r => r.id === roleId);
  if (!role || !confirm(`Delete the role "${role.name}"? This cannot be undone.`)) return;
  try {
    await KSI.del(`roles/${roleId}`);
    closeModal('modal-ur-role');
    showToast('Role deleted', role.name, 'success');
    await admLoadReference(true);
    renderURRoles();
    admFillSelect('ur-user-role-filter', 'All Roles', UR.roles.map(r => [r.id, r.name]));
    renderURStats();
  } catch (error) {
    KSI.reportError(error, null, 'Role not deleted');
  }
}

// ─── ADMINISTRATION REPORTS ────────────────────────────────────────────────
async function renderAdminReportsModule() {
  admTableMessage('ar-recent-logins', 5, 'Loading…');
  try {
    const { users } = await KSI.get('users');
    const weekAgo = Date.now() - 7 * 24 * 3600 * 1000;
    admStatCards('ar-stats-grid', [
      { label: 'Staff Accounts', value: users.length, color: 'teal', icon: 'users' },
      { label: 'Active Accounts', value: users.filter(u => u.status === 'active').length, color: 'green', icon: 'check' },
      { label: 'Signed In (Last 7 Days)', value: users.filter(u => u.last_login_at && Date.parse(u.last_login_at) > weekAgo).length, color: 'blue', icon: 'clock' },
      { label: 'Locked Accounts', value: users.filter(u => u.is_locked).length, color: 'amber', icon: 'lock' },
      { label: 'Temporary Passwords', value: users.filter(u => u.must_change_password).length, color: 'purple', icon: 'alert' },
    ]);

    const byRole = {};
    const byDepartment = {};
    users.forEach(user => {
      user.roles.forEach(role => { byRole[role.name] = (byRole[role.name] || 0) + 1; });
      const department = user.department || 'No department';
      byDepartment[department] = (byDepartment[department] || 0) + 1;
    });
    arBars('ar-by-role', byRole, 'var(--teal)');
    arBars('ar-by-department', byDepartment, 'var(--blue)');

    const recent = users.filter(u => u.last_login_at)
      .sort((a, b) => Date.parse(b.last_login_at) - Date.parse(a.last_login_at))
      .slice(0, 15);
    if (!recent.length) {
      admTableMessage('ar-recent-logins', 5, 'Nobody has signed in yet.');
      return;
    }
    document.getElementById('ar-recent-logins').innerHTML = recent.map(user => `<tr>
      <td class="patient-name">${KSI.esc(user.full_name)}</td>
      <td><div class="badge-list">${urRoleBadges(user)}</div></td>
      <td style="font-size:12px">${KSI.esc(user.department || '—')}</td>
      <td style="font-size:12px;color:var(--text-3);white-space:nowrap">${KSI.fmtDateTime(user.last_login_at)}</td>
      <td>${urStatusBadges(user)}</td>
    </tr>`).join('');
  } catch (error) {
    admTableMessage('ar-recent-logins', 5, error.message);
  }
}

function arBars(containerId, counts, color) {
  const entries = Object.entries(counts).sort((a, b) => b[1] - a[1]);
  const max = Math.max(1, ...entries.map(([, count]) => count));
  document.getElementById(containerId).innerHTML = entries.length
    ? entries.map(([label, count]) => `<div class="ar-bar-row">
        <div class="ar-bar-label">${KSI.esc(label)}</div>
        <div class="ar-bar-track"><div class="ar-bar-fill" style="width:${Math.round(count / max * 100)}%;background:${color}"></div></div>
        <div class="ar-bar-count">${count}</div>
      </div>`).join('')
    : '<div class="muted-note">No data yet</div>';
}
