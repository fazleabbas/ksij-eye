<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: TRIAGE ════════════════════════════════════════════════ -->
    <div id="module-triage" class="hidden" style="height:calc(100vh - var(--topnav-h));overflow:hidden;">
      <div class="triage-layout">

        <div class="triage-queue-panel">
<?php $clPrefix = 'tr'; $clTitle = 'Triage Queue'; $clStage = 'TRIAGE'; require __DIR__ . '/partials/clinical-queue.php'; ?>
        </div>

        <div class="triage-form-panel" id="tr-form-panel">
          <div class="emg-alert-bar" id="tr-emg-bar" style="display:none">EMERGENCY PATIENT — PRIORITY ASSESSMENT</div>
          <div class="triage-empty-state" id="tr-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <div class="triage-empty-title">No Patient Selected</div>
            <div class="triage-empty-sub">Select a patient from the queue to begin the triage assessment.</div>
          </div>

          <form class="triage-form-inner" id="tr-form" style="display:none" novalidate onsubmit="event.preventDefault()">
            <div class="patient-banner" id="tr-banner"></div>
            <div id="tr-mode"></div>

            <div class="emergency-section" id="tr-emg-section" data-lock="correct">
              <label class="emg-check-row">
                <input type="checkbox" class="emg-checkbox" data-field="is_emergency" onchange="TRIAGE_FORM.toggleEmergency()">
                <span class="emg-label">Emergency case</span>
              </label>
              <div class="cl-note" id="tr-emg-current"></div>
              <div class="emg-fields" id="tr-emg-fields">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Emergency reason <span class="required">*</span></label>
                  <select class="form-select" data-field="emergency_reason" data-options="emergency_reason" data-placeholder="Select reason…"></select>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Notes</label>
                  <textarea class="form-input" rows="2" maxlength="1000" data-field="emergency_notes" placeholder="Describe the emergency…"></textarea>
                </div>
              </div>
              <div class="cl-lock-note" style="display:none">The emergency flag can no longer be changed.</div>
            </div>

            <div class="vitals-card">
              <div class="vitals-card-title">General Vitals</div>
              <div class="vitals-grid" style="margin-bottom:12px">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Blood pressure (mmHg) <span class="required">*</span></label>
                  <div class="cl-bp">
                    <input type="number" class="form-input" min="50" max="260" placeholder="120" data-field="bp_systolic" aria-label="Systolic">
                    <span>/</span>
                    <input type="number" class="form-input" min="30" max="160" placeholder="80" data-field="bp_diastolic" aria-label="Diastolic">
                  </div>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Blood sugar test</label>
                  <select class="form-select" data-field="blood_sugar_type">
                    <option value="">Not done</option>
                    <option value="fasting">Fasting</option>
                    <option value="random">Random</option>
                  </select>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Blood sugar (mmol/L)</label>
                  <input type="number" class="form-input" min="1" max="40" step="0.1" data-field="blood_sugar_mmol">
                </div>
              </div>
              <div class="vitals-grid" style="margin-bottom:12px">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Weight (kg)</label>
                  <input type="number" class="form-input" min="1" max="300" step="0.1" data-field="weight_kg" oninput="TRIAGE_FORM.bmi()">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Height (m)</label>
                  <input type="number" class="form-input" min="0.3" max="2.5" step="0.01" data-field="height_m" oninput="TRIAGE_FORM.bmi()">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">BMI <span class="text-muted text-xs">(calculated)</span></label>
                  <input type="text" class="form-input" id="tr-bmi" readonly tabindex="-1" style="background:var(--canvas)">
                </div>
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label">Remarks</label>
                <textarea class="form-input" rows="2" maxlength="1000" data-field="remarks" placeholder="General observations…"></textarea>
              </div>
            </div>

            <div class="vitals-card">
              <div class="vitals-card-title">Eye Measurements</div>
              <div class="table-wrap">
                <table class="eye-table">
                  <thead><tr><th>Measurement</th><th>Right Eye (OD)</th><th>Left Eye (OS)</th></tr></thead>
                  <tbody>
                    <tr><td>Unaided VA</td>
                      <td><select class="eye-input" data-field="va_unaided_right" data-options="va"></select></td>
                      <td><select class="eye-input" data-field="va_unaided_left" data-options="va"></select></td></tr>
                    <tr><td>Aided VA</td>
                      <td><select class="eye-input" data-field="va_aided_right" data-options="va"></select></td>
                      <td><select class="eye-input" data-field="va_aided_left" data-options="va"></select></td></tr>
                    <tr><td>IOP (mmHg)</td>
                      <td><input type="number" class="eye-input" min="0" max="80" data-field="iop_right"></td>
                      <td><input type="number" class="eye-input" min="0" max="80" data-field="iop_left"></td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="route-section" data-lock="correct">
              <div class="vitals-card-title">Send Patient To <span class="required">*</span></div>
              <div class="route-options">
                <label class="route-card">
                  <input type="radio" name="tr-route" value="optometry" data-field="route_to">
                  <div class="route-card-title">Optometry</div>
                  <div class="route-card-sub">Refraction, lenses, vision disorders</div>
                </label>
                <label class="route-card">
                  <input type="radio" name="tr-route" value="ophthalmology" data-field="route_to">
                  <div class="route-card-title">Ophthalmology</div>
                  <div class="route-card-sub">Medical and surgical eye conditions</div>
                </label>
              </div>
              <div class="cl-lock-note" style="display:none;margin-top:8px">The patient has already been sent on.</div>
            </div>

            <div class="audit-strip" id="tr-audit"></div>
            <div id="tr-addenda"></div>
            <div class="triage-actions" id="tr-actions"></div>
          </form>
        </div>
      </div>
    </div>

