<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: DASHBOARD ═════════════════════════════════════════════ -->
    <div id="module-dashboard" class="hidden db-page">
      <div class="page-header">
        <div>
          <div class="page-title">Dashboard</div>
          <div class="page-breadcrumb"><span id="db-welcome">Today at the clinic</span></div>
        </div>
        <div class="page-actions">
          <button class="btn btn-secondary" onclick="dbLoad()">Refresh</button>
        </div>
      </div>

      <div class="section-padding" style="padding-top:0">
        <div id="db-alerts"></div>
        <div class="stats-row" id="db-tiles"></div>

        <div class="clinical-grid">
          <div class="card">
            <div class="card-head">
              <div class="card-title">In the building now</div>
              <span class="table-count" id="db-queue-count">0 patients</span>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Patient</th><th>Visit</th><th>Where</th><th>Arrived</th><th>Status</th></tr></thead>
                <tbody id="db-queue"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-head"><div class="card-title">Waiting for someone</div></div>
            <div id="db-waiting"></div>
          </div>
        </div>
      </div>
    </div>
