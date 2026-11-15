<?php defined('KSI_APP') || exit; ?>
<!-- ─── STAFF ACCOUNT ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-ur-user">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="ur-user-modal-title">Register Staff Account</div>
        <div class="modal-sub">Staff sign in with their username; a new account must change its temporary password first.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-ur-user')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="form-ur-user" novalidate onsubmit="event.preventDefault()">
      <div class="alert alert-info" id="ur-user-self-note" style="display:none;margin-bottom:14px">
        This is your own account: your roles and status can only be changed by another administrator.
      </div>
      <div class="form-section-title" style="margin-top:0">Personal Details</div>
      <div class="form-grid form-grid-3">
        <div class="form-group">
          <label class="form-label">First Name <span class="required">*</span></label>
          <input class="form-input" data-field="first_name" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Second Name</label>
          <input class="form-input" data-field="middle_name" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Last Name <span class="required">*</span></label>
          <input class="form-input" data-field="last_name" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number <span class="required">*</span></label>
          <input class="form-input" data-field="phone" maxlength="20" placeholder="255XXXXXXXXX" inputmode="tel" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input class="form-input" type="email" data-field="email" maxlength="190" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Department <span class="required">*</span></label>
          <select class="form-select" id="ur-user-department" data-field="department_id"></select>
        </div>
      </div>

      <div class="form-section-title">Account</div>
      <div class="form-grid form-grid-3">
        <div class="form-group">
          <label class="form-label">Username <span class="required">*</span></label>
          <input class="form-input" data-field="username" maxlength="50" placeholder="e.g. dr.amina" autocomplete="off" autocapitalize="none" spellcheck="false">
        </div>
        <div class="form-group" id="ur-user-password-group">
          <label class="form-label">Temporary Password <span class="required">*</span></label>
          <input class="form-input" type="password" data-field="password" maxlength="128" autocomplete="new-password">
          <span class="form-hint">Share it in person. The user must replace it at first sign-in.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select class="form-select" data-field="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div class="form-section-title">Roles <span class="required">*</span></div>
      <div class="form-group">
        <div class="check-grid" id="ur-user-roles" data-field="role_ids"></div>
        <span class="form-hint">A user receives every permission of every role ticked.</span>
      </div>

      <div class="form-section-title">Designations</div>
      <div class="form-group">
        <div class="check-grid" id="ur-user-designations" data-field="designation_ids"></div>
        <span class="form-hint">Used to list staff in pickers — e.g. only Surgeons appear as surgeons on theatre bookings.</span>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-ur-user')">Cancel</button>
      <button class="btn btn-primary" type="button" id="ur-user-save" onclick="saveUser()">Save Account</button>
    </div>
  </div>
</div>

<!-- ─── ROLE ──────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-ur-role">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="ur-role-modal-title">New Role</div>
      <button class="modal-close" type="button" onclick="closeModal('modal-ur-role')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="form-ur-role" novalidate onsubmit="event.preventDefault()">
      <div class="form-grid" style="margin-bottom:16px">
        <div class="form-group">
          <label class="form-label">Role Name <span class="required">*</span></label>
          <input class="form-input" data-field="name" maxlength="80" placeholder="e.g. Senior Nurse">
        </div>
        <div class="form-group">
          <label class="form-label">Badge Colour</label>
          <select class="form-select" data-field="color">
            <option value="teal">Teal</option>
            <option value="blue">Blue</option>
            <option value="amber">Amber</option>
            <option value="green">Green</option>
            <option value="purple">Purple</option>
            <option value="red">Red</option>
          </select>
        </div>
        <div class="form-group col-2">
          <label class="form-label">Description</label>
          <input class="form-input" data-field="description" maxlength="255" placeholder="What this role is for…">
        </div>
      </div>
      <div class="form-section-title" style="margin-bottom:8px">Permissions</div>
      <div class="alert alert-warning perm-locked-note" id="ur-role-locked-note" style="display:none">
        The Admin role always holds every permission and cannot be restricted.
      </div>
      <div class="form-group">
        <div id="ur-role-perm-body" data-field="permissions"></div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-danger" type="button" id="ur-role-delete-btn" style="display:none" onclick="deleteRole(UR.editingRoleId)">Delete Role</button>
      <div style="flex:1"></div>
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-ur-role')">Close</button>
      <button class="btn btn-primary" type="button" id="ur-role-save" onclick="saveRole()">Save Role</button>
    </div>
  </div>
</div>

<!-- ─── RESET PASSWORD ────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-ur-reset-password">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Set Temporary Password</div>
        <div class="modal-sub" id="ur-reset-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-ur-reset-password')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="form-ur-reset-password" novalidate onsubmit="event.preventDefault();submitResetPassword()">
      <div class="alert alert-warning" style="margin-bottom:14px">
        The user is signed out everywhere and must choose a new password at the next sign-in. Give them the temporary password in person.
      </div>
      <div class="form-grid" style="grid-template-columns:1fr">
        <div class="form-group">
          <label class="form-label">Temporary Password <span class="required">*</span></label>
          <input class="form-input" type="password" data-field="password" maxlength="128" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label class="form-label">Repeat Password <span class="required">*</span></label>
          <input class="form-input" type="password" data-field="confirm_password" maxlength="128" autocomplete="new-password">
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-ur-reset-password')">Cancel</button>
      <button class="btn btn-primary" type="button" id="ur-reset-save" onclick="submitResetPassword()">Set Password</button>
    </div>
  </div>
</div>

<!-- ─── ACTIVE SESSIONS ───────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-ur-sessions">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Active Sessions</div>
        <div class="modal-sub" id="ur-sessions-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-ur-sessions')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" style="padding:0">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Signed In</th><th>Last Activity</th><th>IP Address</th><th>Device</th></tr></thead>
          <tbody id="ur-sessions-tbody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-ur-sessions')">Close</button>
      <button class="btn btn-danger" type="button" id="ur-sessions-revoke" onclick="revokeUserSessions()">Sign Out All Sessions</button>
    </div>
  </div>
</div>

<!-- ─── DEPARTMENT / DESIGNATION ──────────────────────────────────────── -->
<div class="modal-overlay" id="modal-ref-item">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="ref-item-title">Add</div>
      <button class="modal-close" type="button" onclick="closeModal('modal-ref-item')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="form-ref-item" novalidate onsubmit="event.preventDefault();saveRefItem()">
      <div class="form-grid" style="grid-template-columns:1fr">
        <div class="form-group">
          <label class="form-label">Name <span class="required">*</span></label>
          <input class="form-input" data-field="name" maxlength="100">
        </div>
        <div class="form-group" id="ref-item-active-group">
          <label class="ur-perm-check"><input type="checkbox" data-field="is_active"> <span>Active (available for selection)</span></label>
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-ref-item')">Cancel</button>
      <button class="btn btn-primary" type="button" id="ref-item-save" onclick="saveRefItem()">Save</button>
    </div>
  </div>
</div>

<!-- ─── CLINICAL LIST ENTRY ───────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-catalogue-item">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="cat-item-title">Add Entry</div>
        <div class="modal-sub">Entries are switched off rather than deleted, so earlier records keep their meaning.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-catalogue-item')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="cat-form" novalidate onsubmit="event.preventDefault();catSaveItem()">
      <div class="form-grid" style="grid-template-columns:1fr" id="cat-fields"></div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-catalogue-item')">Cancel</button>
      <button class="btn btn-primary" type="button" id="cat-save" onclick="catSaveItem()">Save</button>
    </div>
  </div>
</div>

<!-- ─── PRICE ITEM ────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-price-item">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="price-item-title">Add Price Item</div>
        <div class="modal-sub">New bills use the new price; existing bills keep the price they were raised with.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-price-item')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="form-price-item" novalidate onsubmit="event.preventDefault();savePrice()">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Code <span class="required">*</span></label>
          <input class="form-input" data-field="code" maxlength="40" placeholder="e.g. INV-OCT" style="text-transform:uppercase">
        </div>
        <div class="form-group">
          <label class="form-label">Category <span class="required">*</span></label>
          <select class="form-select" id="price-item-category" data-field="category"></select>
        </div>
        <div class="form-group col-2">
          <label class="form-label">Item Name <span class="required">*</span></label>
          <input class="form-input" data-field="name" maxlength="150">
        </div>
        <div class="form-group">
          <label class="form-label">Price (TZS) <span class="required">*</span></label>
          <input class="form-input" type="number" min="0" step="1" data-field="unit_price" inputmode="numeric">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <label class="ur-perm-check"><input type="checkbox" data-field="is_confirmed"> <span>Confirmed by finance</span></label>
          <label class="ur-perm-check" id="price-item-active-wrap"><input type="checkbox" data-field="is_active"> <span>Active</span></label>
        </div>
        <div class="form-group col-2" id="price-item-reason-group">
          <label class="form-label">Reason for price change</label>
          <input class="form-input" data-field="reason" maxlength="255" placeholder="Required when the price changes, e.g. 2027 tariff approved by board">
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-price-item')">Cancel</button>
      <button class="btn btn-primary" type="button" id="price-item-save" onclick="savePrice()">Save Price</button>
    </div>
  </div>
</div>

<!-- ─── PRICE HISTORY ─────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-price-history">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">Price History</div>
        <div class="modal-sub" id="price-history-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-price-history')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" style="padding:0">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Changed</th><th style="text-align:right">From</th><th style="text-align:right">To</th><th>By</th><th>Reason</th></tr></thead>
          <tbody id="price-history-tbody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <div></div>
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-price-history')">Close</button>
    </div>
  </div>
</div>
