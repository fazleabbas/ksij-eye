<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: MANAGE VISIT ═════════════════════════════════════════ -->
    <div id="module-manage-visit" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Manage Visit</div>
          <div class="page-breadcrumb">
            <span>Patient</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active">Manage Visit</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" id="vs-new-btn" onclick="vsOpenNewVisit()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Visit
          </button>
        </div>
      </div>

      <div class="section-padding">
        <div class="card">
          <div class="filters-bar">
            <div class="search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="search-input" id="vs-search" placeholder="Patient name, phone, REG or visit no…" oninput="vsFilterChanged()" maxlength="80">
            </div>
            <select class="filter-select" id="vs-filter-type" onchange="vsFilterChanged()">
              <option value="">All Visit Types</option>
              <option value="consultation">Consultation</option>
              <option value="follow_up">Follow-Up</option>
            </select>
            <select class="filter-select" id="vs-filter-dest" onchange="vsFilterChanged()">
              <option value="">All Destinations</option>
              <option value="doctor_room">Doctor Room</option>
              <option value="triage">Triage</option>
              <option value="investigation">Investigation</option>
            </select>
            <select class="filter-select" id="vs-filter-status" onchange="vsFilterChanged()">
              <option value="">All Statuses</option>
              <option value="pending_payment">Pending Payment</option>
              <option value="waiting_triage">Waiting For Triage</option>
              <option value="waiting_doctor">Waiting For Doctor</option>
              <option value="waiting_investigation">Waiting For Investigation</option>
              <option value="in_progress">In Progress</option>
              <option value="completed">Complete</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <input type="date" class="filter-select" id="vs-from" data-default-today onchange="vsFilterChanged()" title="Visit date from">
            <input type="date" class="filter-select" id="vs-to" data-default-today onchange="vsFilterChanged()" title="Visit date to">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Visit No.</th>
                  <th>Patient</th>
                  <th>Visit Date</th>
                  <th>Visit Type</th>
                  <th>Destination</th>
                  <th>Status</th>
                  <th>Bill</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="vs-tbody"></tbody>
            </table>
          </div>
          <div class="table-footer">
            <span class="table-count" id="vs-count">0 visits</span>
            <div class="pagination" id="vs-pagination"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ MODULE: MANAGE PATIENT ══════════════════════════════════════ -->
    <div id="module-manage-patient" class="hidden">
      <div class="page-header">
        <div>
          <div class="page-title">Manage Patient</div>
          <div class="page-breadcrumb">
            <span>Patient</span>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            <span class="crumb-active" id="pt-crumb">Today's Patients</span>
          </div>
        </div>
        <div class="page-actions">
          <button class="btn btn-primary" id="pt-new-btn" onclick="ptOpenRegister()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Patient
          </button>
        </div>
      </div>
      <div class="section-padding">
        <div class="tab-bar" style="margin-bottom:16px">
          <button class="tab-btn active" id="pt-tab-today" onclick="ptSwitchTab('today')">
            Today's Patients
            <span id="pt-today-badge" style="margin-left:6px;background:var(--teal);color:white;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700"></span>
          </button>
          <button class="tab-btn" id="pt-tab-all" onclick="ptSwitchTab('all')">All Patients</button>
          <button class="tab-btn" id="pt-tab-followups" onclick="ptSwitchTab('followups')">
            Follow-ups Due
            <span id="pt-fu-badge" style="margin-left:6px;background:var(--amber);color:white;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700"></span>
          </button>
        </div>

        <!-- FOLLOW-UPS -->
        <div id="pt-panel-followups" style="display:none">
          <div class="stats-strip" id="pt-fu-kpis" style="padding:0 0 16px"></div>
          <div class="card">
            <div class="filters-bar">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="pt-fu-search" placeholder="Name, phone or file number…" oninput="ptFollowUpFilterChanged()" maxlength="80">
              </div>
              <select class="filter-select" id="pt-fu-status" onchange="ptFollowUpFilterChanged()">
                <option value="due">Due</option>
                <option value="booked">Booked</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <input type="date" class="filter-select" id="pt-fu-from" onchange="ptFollowUpFilterChanged()" title="Due from">
              <input type="date" class="filter-select" id="pt-fu-to" onchange="ptFollowUpFilterChanged()" title="Due to">
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Due</th>
                    <th>Patient</th>
                    <th>Phone</th>
                    <th>Reason</th>
                    <th>Priority</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="pt-fu-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer">
              <span class="table-count" id="pt-fu-count">0 follow-ups</span>
              <div class="pagination" id="pt-fu-pagination"></div>
            </div>
          </div>
        </div>

        <!-- TODAY -->
        <div id="pt-panel-today">
          <div class="stats-strip" id="pt-today-kpis" style="padding:0 0 16px"></div>
          <div class="card">
            <div class="filters-bar">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="pt-today-search" placeholder="Name, phone or file number…" oninput="ptTodayFilterChanged()" maxlength="80">
              </div>
              <select class="filter-select" id="pt-today-status" onchange="ptTodayFilterChanged()">
                <option value="">All Statuses</option>
                <option value="pending_payment">Pending Payment</option>
                <option value="waiting_triage">Waiting For Triage</option>
                <option value="waiting_doctor">Waiting For Doctor</option>
                <option value="waiting_investigation">Waiting For Investigation</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Complete</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <select class="filter-select" id="pt-today-type" onchange="ptTodayFilterChanged()">
                <option value="">All Types</option>
                <option value="consultation">Consultation</option>
                <option value="follow_up">Follow-Up</option>
              </select>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Visit No.</th>
                    <th>Patient</th>
                    <th>Age / Gender</th>
                    <th>Visit Type</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th>Bill</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="pt-today-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer">
              <span class="table-count" id="pt-today-count">0 visits today</span>
              <div class="pagination" id="pt-today-pagination"></div>
            </div>
          </div>
        </div>

        <!-- ALL PATIENTS -->
        <div id="pt-panel-all" style="display:none">
          <div class="card">
            <div class="filters-bar">
              <div class="search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="search-input" id="pt-search" placeholder="Name, phone or file number…" oninput="ptAllFilterChanged()" maxlength="80">
              </div>
              <label style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                Registered
                <input type="date" class="filter-select" id="pt-reg-from" onchange="ptAllFilterChanged()" title="Registered from">
                <span style="font-weight:400">to</span>
                <input type="date" class="filter-select" id="pt-reg-to" onchange="ptAllFilterChanged()" title="Registered to">
              </label>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Reg. Number</th>
                    <th>Patient Name</th>
                    <th>Phone</th>
                    <th>Age</th>
                    <th>Gender</th>
                    <th>Visits</th>
                    <th>Last Visit</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="pt-tbody"></tbody>
              </table>
            </div>
            <div class="table-footer">
              <span class="table-count" id="pt-count">0 patients</span>
              <div class="pagination" id="pt-pagination"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

