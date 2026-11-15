<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: USERS & ROLES ═══════════════════════════════════════ -->
    <div id="module-users-roles" class="hidden">
      <div class="stores-page">
        <div class="stores-header">
          <div>
            <div class="stores-title">Users &amp; Roles</div>
            <div class="stores-sub">Staff accounts, role assignments and what each role may do</div>
          </div>
          <div class="page-actions">
            <button class="btn btn-secondary" id="ur-new-role-btn" onclick="openRoleModal()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>
              New Role
            </button>
            <button class="btn btn-primary" id="ur-new-user-btn" onclick="openUserModal()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Register Staff
            </button>
          </div>
        </div>

        <div class="stores-stats-grid" id="ur-stats-grid"></div>

        <div class="tab-bar">
          <div class="tab-btn active" data-tab="users" onclick="switchURTab('users')">Staff Accounts</div>
          <div class="tab-btn" data-tab="roles" onclick="switchURTab('roles')">Roles &amp; Permissions</div>
        </div>

        <div id="ur-panel-users">
          <div class="card">
            <div class="filters-bar">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="ur-user-search" placeholder="Name, username, phone or staff no…" oninput="onURUserFilter(true)" maxlength="80">
              </div>
              <select class="filter-select" id="ur-user-role-filter" onchange="onURUserFilter(false)"><option value="">All Roles</option></select>
              <select class="filter-select" id="ur-user-dept-filter" onchange="onURUserFilter(false)"><option value="">All Departments</option></select>
              <select class="filter-select" id="ur-user-status-filter" onchange="onURUserFilter(false)">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Staff No.</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Roles</th>
                    <th>Department</th>
                    <th>Last Sign-in</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="ur-users-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer">
              <span class="table-count" id="ur-users-count">0 accounts</span>
            </div>
          </div>
        </div>

        <div id="ur-panel-roles" style="display:none">
          <div id="ur-roles-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px"></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: SETTINGS ═════════════════════════════════════════════ -->
    <div id="module-settings-users" class="hidden">
      <div class="stores-page">
        <div class="stores-header">
          <div>
            <div class="stores-title">Settings</div>
            <div class="stores-sub">Staff register, departments, designations and system rules</div>
          </div>
          <div class="page-actions">
            <button class="btn btn-primary" id="su-add-btn" onclick="settingsAddClicked()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              <span id="su-add-label">Add</span>
            </button>
          </div>
        </div>

        <div class="tab-bar">
          <div class="tab-btn active" data-tab="staff" onclick="switchSettingsTab('staff')">Staff Register</div>
          <div class="tab-btn" data-tab="departments" onclick="switchSettingsTab('departments')">Departments</div>
          <div class="tab-btn" data-tab="designations" onclick="switchSettingsTab('designations')">Designations</div>
          <div class="tab-btn" data-tab="security" onclick="switchSettingsTab('security')">Security &amp; Rules</div>
          <div class="tab-btn" data-tab="clinical" onclick="switchSettingsTab('clinical')">Clinical Lists</div>
        </div>

        <!-- Staff register -->
        <div id="su-panel-staff">
          <div class="card">
            <div class="filters-bar">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="su-search" placeholder="Name, phone or username…" oninput="onStaffFilter(true)" maxlength="80">
              </div>
              <select class="filter-select" id="su-dept-filter" onchange="onStaffFilter(false)"><option value="">All Departments</option></select>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>First Name</th>
                    <th>Second Name</th>
                    <th>Last Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Designations</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="su-users-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer"><span class="table-count" id="su-users-count">0 staff</span></div>
          </div>
        </div>

        <!-- Departments -->
        <div id="su-panel-departments" style="display:none">
          <div class="card">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Department</th><th>Staff</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="su-departments-tbody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Designations -->
        <div id="su-panel-designations" style="display:none">
          <div class="card">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Designation</th><th>Code</th><th>Staff</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="su-designations-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer"><span class="table-count">Designations fill staff pickers such as surgeons, nurses and optometrists.</span></div>
          </div>
        </div>

        <!-- Security & rules -->
        <div id="su-panel-security" style="display:none">
          <div class="card" id="su-settings-card"></div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
            <button class="btn btn-primary" id="su-settings-save" onclick="saveSystemSettings()">Save Settings</button>
          </div>
        </div>

        <!-- Clinical lists -->
        <div id="su-panel-clinical" style="display:none">
          <div class="card">
            <div class="tab-bar" id="cat-tabs" style="margin:0;padding:0 12px">
              <button class="tab-btn active" data-cat="icd10" onclick="catSwitch('icd10')">ICD-10 Codes</button>
              <button class="tab-btn" data-cat="drugs" onclick="catSwitch('drugs')">Medicines</button>
              <button class="tab-btn" data-cat="findings" onclick="catSwitch('findings')">Examination Findings</button>
              <button class="tab-btn" data-cat="lists" onclick="catSwitch('lists')">Dropdown Lists</button>
              <button class="tab-btn" data-cat="templates" onclick="catSwitch('templates')">Operation Templates</button>
            </div>
            <div class="filters-bar">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="cat-search" placeholder="Search…" oninput="catRenderTable()" maxlength="80">
              </div>
              <select class="filter-select" id="cat-filter" onchange="catRenderTable()" style="display:none"></select>
            </div>
            <div class="table-wrap">
              <table>
                <thead id="cat-thead"></thead>
                <tbody id="cat-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer"><span class="table-count" id="cat-count"></span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: PRICE LIST ════════════════════════════════════════════ -->
    <div id="module-price-list" class="hidden">
      <div class="stores-page">
        <div class="stores-header">
          <div>
            <div class="stores-title">Price List</div>
            <div class="stores-sub">Service, surgery and lens prices (TZS). Every change is recorded with a reason.</div>
          </div>
          <div class="page-actions">
            <button class="btn btn-primary" id="pl-add-btn" onclick="openPriceModal()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add Item
            </button>
          </div>
        </div>

        <div class="stores-stats-grid" id="pl-stats-grid"></div>

        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="pl-search" placeholder="Item name or code…" oninput="onPriceFilter(true)" maxlength="80">
            </div>
            <select class="filter-select" id="pl-category-filter" onchange="onPriceFilter(false)"><option value="">All Categories</option></select>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Item</th>
                  <th>Category</th>
                  <th style="text-align:right">Price (TZS)</th>
                  <th>Status</th>
                  <th>Last Changed</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="pl-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer"><span class="table-count" id="pl-count">0 items</span></div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: ADMINISTRATION REPORTS ═══════════════════════════════ -->
    <div id="module-admin-reports" class="hidden">
      <div class="stores-page">
        <div class="stores-header">
          <div>
            <div class="stores-title">Administration Reports</div>
            <div class="stores-sub">Staffing and account security at a glance</div>
          </div>
          <div class="page-actions">
            <button class="btn btn-secondary" onclick="window.print()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Print
            </button>
          </div>
        </div>

        <div class="stores-stats-grid" id="ar-stats-grid"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px" class="ar-grid-2">
          <div class="card" style="padding:16px">
            <div class="form-section-title" style="margin-top:0">Staff by Role</div>
            <div id="ar-by-role"></div>
          </div>
          <div class="card" style="padding:16px">
            <div class="form-section-title" style="margin-top:0">Staff by Department</div>
            <div id="ar-by-department"></div>
          </div>
        </div>

        <div class="card">
          <div class="form-section-title" style="margin-top:0;padding:14px 16px 0;border-bottom:none">Recent Sign-Ins</div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Name</th><th>Roles</th><th>Department</th><th>Last Sign-in</th><th>Status</th></tr></thead>
              <tbody id="ar-recent-logins"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: AUDIT LOG ════════════════════════════════════════════ -->
    <div id="module-audit-log" class="hidden">
      <div class="stores-page">
        <div class="stores-header">
          <div>
            <div class="stores-title">Audit Log</div>
            <div class="stores-sub">Permanent record of sign-ins and changes. Entries cannot be edited or deleted.</div>
          </div>
          <div class="page-actions">
            <button class="btn btn-secondary" onclick="window.print()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Print
            </button>
          </div>
        </div>

        <div class="stores-stats-grid" id="al-stats-grid"></div>

        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="al-search" placeholder="Search user, action or details…" oninput="onAuditFilter(true)" maxlength="80">
            </div>
            <select class="filter-select" id="al-module-filter" onchange="onAuditFilter(false)"><option value="">All Modules</option></select>
            <select class="filter-select" id="al-user-filter" onchange="onAuditFilter(false)"><option value="">All Users</option></select>
            <input type="date" class="filter-select" id="al-from" data-default-today onchange="onAuditFilter(false)" title="From date">
            <input type="date" class="filter-select" id="al-to" data-default-today onchange="onAuditFilter(false)" title="To date">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Timestamp</th>
                  <th>User</th>
                  <th>Module</th>
                  <th>Action</th>
                  <th>Details</th>
                  <th>IP Address</th>
                </tr>
              </thead>
              <tbody id="al-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="al-count">0 entries</span>
            <div class="pager">
              <button class="page-btn" id="al-prev" onclick="auditPage(-1)" aria-label="Previous page">‹</button>
              <span class="table-count" id="al-page-label">Page 1</span>
              <button class="page-btn" id="al-next" onclick="auditPage(1)" aria-label="Next page">›</button>
            </div>
          </div>
        </div>
      </div>
    </div>

