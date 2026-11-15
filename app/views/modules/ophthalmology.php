<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: OPHTHALMOLOGY ════════════════════════════════════════ -->
    <div id="module-ophthalmology" class="hidden" style="height:calc(100vh - var(--topnav-h));overflow:hidden;">
      <div class="ophtho-layout" id="oph-layout">

        <div class="ophtho-left">
<?php $clPrefix = 'oph'; $clTitle = 'Ophthalmology Queue'; $clStage = 'OPH'; require __DIR__ . '/partials/clinical-queue.php'; ?>
        </div>

        <div class="ophtho-center" id="oph-form-panel">
          <div class="emg-alert-bar" id="oph-emg-bar" style="display:none">EMERGENCY PATIENT — IMMEDIATE ATTENTION</div>
          <div class="triage-empty-state" id="oph-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="12" cy="12" r="10"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
            <div class="triage-empty-title">No Patient Selected</div>
            <div class="triage-empty-sub">Select a patient from the queue to begin the consultation.</div>
          </div>

          <form class="ophtho-center-inner" id="oph-form" style="display:none" novalidate onsubmit="event.preventDefault()">
            <div class="patient-banner" id="oph-banner"></div>
            <div id="oph-mode"></div>
            <div class="oph-step"><span class="oph-step-no">1</span>Clinical History</div>
            <div id="oph-upstream"></div>
            <div class="clinical-card">
              <div class="form-group" style="margin:0">
                <label class="form-label">History</label>
                <textarea class="clinical-textarea" rows="4" maxlength="5000" data-field="clinical_history" placeholder="Presenting complaint, history of the complaint, past eye, medical and drug history…"></textarea>
              </div>
            </div>

            <div class="oph-step"><span class="oph-step-no">2</span>Examination</div>
            <div id="oph-exam" data-field="findings"></div>

            <div class="summary-panel" id="oph-summary"></div>

            <div class="diagram-section" data-collect="skip">
              <div class="vitals-card-title">Eye Diagrams</div>
              <div class="diagram-tabs">
                <button type="button" class="diagram-tab active" data-diagram="iris" onclick="OPH_DRAW.tab('iris')">Iris / Retina</button>
                <button type="button" class="diagram-tab" data-diagram="cross_section" onclick="OPH_DRAW.tab('cross_section')">Eye Cross-Section</button>
              </div>
              <div class="diagram-tools" id="oph-draw-tools">
                <button type="button" class="dtool active" data-tool="pen" onclick="OPH_DRAW.tool('pen')">Draw</button>
                <button type="button" class="dtool" data-tool="arrow" onclick="OPH_DRAW.tool('arrow')">Arrow</button>
                <button type="button" class="dtool" data-tool="circle" onclick="OPH_DRAW.tool('circle')">Circle</button>
                <button type="button" class="dtool" data-tool="text" onclick="OPH_DRAW.tool('text')">Text</button>
                <button type="button" class="dtool" data-tool="marker" onclick="OPH_DRAW.tool('marker')">Mark</button>
                <div class="diagram-color-picker" id="oph-draw-colors"></div>
                <input type="text" id="oph-draw-text" maxlength="60" placeholder="Label text…" style="width:120px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:4px 8px;font-size:11px;display:none">
                <button type="button" class="dtool" onclick="OPH_DRAW.undo()">Undo</button>
                <button type="button" class="dtool danger" onclick="OPH_DRAW.clear()">Clear</button>
              </div>
              <div class="diagram-canvas-wrap" data-field="diagrams">
                <canvas id="oph-canvas" class="diagram-canvas" width="560" height="320" style="width:100%;height:auto"></canvas>
              </div>
            </div>

            <div class="oph-step"><span class="oph-step-no">3</span>Investigations</div>
            <div class="clinical-card" data-lock="correct" id="oph-inv-card">
              <div class="vitals-card-title" style="display:flex;align-items:center;gap:8px">Investigations
                <button type="button" class="btn btn-secondary btn-sm" style="margin-left:auto" id="oph-inv-add" onclick="OPH_FORM.addOrder('investigations')">Add investigation</button>
              </div>
              <div data-collect="skip" data-field="investigations" id="oph-inv"></div>
              <div class="cl-note">Each investigation is billed at the cashier when you complete the consultation.</div>
            </div>

            <div class="oph-step"><span class="oph-step-no">4</span>Provisional and Final Diagnosis</div>
            <div class="clinical-card">
              <div class="clinical-grid">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Provisional diagnosis <span class="required">*</span></label>
                  <div data-collect="skip" id="oph-dx-provisional"></div>
                  <textarea class="clinical-textarea" rows="2" maxlength="1000" data-field="provisional_dx_notes" placeholder="Free-text notes (optional)" style="margin-top:8px"></textarea>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Final diagnosis <span class="required">*</span></label>
                  <div data-collect="skip" id="oph-dx-final"></div>
                  <textarea class="clinical-textarea" rows="2" maxlength="1000" data-field="final_dx_notes" placeholder="Free-text notes (optional)" style="margin-top:8px"></textarea>
                </div>
                <div class="form-group" style="margin:0;grid-column:1/-1">
                  <label class="form-label">Clinical notes</label>
                  <textarea class="clinical-textarea" rows="4" maxlength="5000" data-field="clinical_notes" placeholder="Observations, plan…"></textarea>
                </div>
              </div>
            </div>

            <div class="oph-step"><span class="oph-step-no">5</span>Medicine</div>
            <div class="med-orders-card" data-lock="correct">
              <div class="med-orders-header"><div class="med-orders-title">Prescription</div></div>
              <div class="med-orders-body" data-collect="skip" id="oph-rx"></div>
            </div>

            <div class="oph-step"><span class="oph-step-no">6</span>Surgery</div>
            <div class="clinical-card" data-lock="correct" id="oph-surg-card">
              <div class="vitals-card-title" style="display:flex;align-items:center;gap:8px">Surgery Recommendation
                <button type="button" class="btn btn-secondary btn-sm" style="margin-left:auto" id="oph-surg-add" onclick="OPH_FORM.addOrder('surgery')">Recommend surgery</button>
              </div>
              <div data-collect="skip" data-field="surgery" id="oph-surg"></div>
              <div class="cl-note">Theatre booking picks up recommended surgery.</div>
            </div>

            <div class="clinical-card" data-lock="correct" id="oph-proc-card">
              <div class="vitals-card-title" style="display:flex;align-items:center;gap:8px">Procedure Recommendation
                <button type="button" class="btn btn-secondary btn-sm" style="margin-left:auto" id="oph-proc-add" onclick="OPH_FORM.addOrder('procedures')">Recommend procedure</button>
              </div>
              <div data-collect="skip" id="oph-proc"></div>
              <div class="cl-note">Procedures are booked in Theatre, like surgery.</div>
            </div>

            <div class="oph-step"><span class="oph-step-no">7</span>Referral</div>
            <div class="routing-card" data-lock="correct">
              <div class="vitals-card-title">Refer to Optometry</div>
              <div class="routing-options">
                <label class="routing-opt"><input type="checkbox" data-field="refer_optometry"><span class="routing-opt-label">Optometry (spectacles)</span></label>
              </div>
              <div class="cl-note">When you complete the consultation the patient goes to the Optometry queue instead of leaving.</div>
            </div>

            <div class="followup-card">
              <div class="vitals-card-title">Referral to another facility</div>
              <label class="followup-check-row">
                <input type="checkbox" data-field="referral.referred" style="width:15px;height:15px;accent-color:var(--teal)" onchange="OPH_FORM.toggleReferral()">
                <span style="font-size:13px;font-weight:600;color:var(--text-2)">Referred to another facility</span>
              </label>
              <div class="form-group hidden" id="oph-ref-fields" style="margin:8px 0 0">
                <label class="form-label">Referred to <span class="required">*</span></label>
                <input class="form-input" maxlength="200" data-field="referral.facility" placeholder="Hospital or clinic, e.g. Muhimbili National Hospital">
              </div>
            </div>

<?php $clPrefix = 'oph'; require __DIR__ . '/partials/clinical-followup.php'; ?>

            <div class="audit-strip" id="oph-audit"></div>
            <div id="oph-addenda"></div>
            <div class="opto-actions" id="oph-actions"></div>
          </form>
        </div>

        <div class="ophtho-right" id="oph-right">
          <div class="ophtho-right-header">
            <div class="ophtho-right-tabs" id="oph-right-tabs">
              <div class="ophtho-right-tab active" data-tab="visits" onclick="OPH_FORM.historyTab('visits')">History</div>
              <div class="ophtho-right-tab" data-tab="orders" onclick="OPH_FORM.historyTab('orders')">Orders</div>
              <div class="ophtho-right-tab" data-tab="files" onclick="OPH_FORM.historyTab('files')">Scanned</div>
            </div>
            <button type="button" class="ophtho-right-toggle" onclick="clToggleSidePanel('oph-layout', 'right-collapsed')" title="Show or hide history">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            </button>
          </div>
          <div class="ophtho-right-body" id="oph-hist-body"><div class="cl-queue-empty">Select a patient to see their history.</div></div>
        </div>

      </div>
    </div>

