<?php defined('KSI_APP') || exit; ?>
    <!-- ══ MODULE: OPTOMETRY ════════════════════════════════════════════ -->
    <div id="module-optometry" class="hidden" style="height:calc(100vh - var(--topnav-h));overflow:hidden;">
      <div class="opto-layout" id="op-layout">

        <div class="opto-queue-panel">
<?php $clPrefix = 'op'; $clTitle = 'Optometry Queue'; $clStage = 'OPTO'; require __DIR__ . '/partials/clinical-queue.php'; ?>
        </div>

        <div class="opto-center-panel" id="op-form-panel">
          <div class="emg-alert-bar" id="op-emg-bar" style="display:none">EMERGENCY PATIENT — PRIORITY ASSESSMENT</div>
          <div class="triage-empty-state" id="op-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="12" cy="12" r="10"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/></svg>
            <div class="triage-empty-title">No Patient Selected</div>
            <div class="triage-empty-sub">Select a patient from the queue to begin the optometry assessment.</div>
          </div>

          <form class="opto-center-inner" id="op-form" style="display:none" novalidate onsubmit="event.preventDefault()">
            <div class="patient-banner" id="op-banner"></div>
            <div id="op-mode"></div>
            <div id="op-upstream"></div>

            <div class="vitals-card">
              <div class="vitals-card-title">Visual Acuity &amp; Eye Pressure</div>
              <div class="table-wrap">
                <table class="eye-table">
                  <thead><tr><th>Measurement</th><th>Right Eye (OD)</th><th>Left Eye (OS)</th></tr></thead>
                  <tbody>
                    <tr><td>VA</td>
                      <td><select class="eye-input" data-field="va_right" data-options="va"></select></td>
                      <td><select class="eye-input" data-field="va_left" data-options="va"></select></td></tr>
                    <tr><td>IOP (mmHg)</td>
                      <td><input type="number" class="eye-input" min="0" max="80" data-field="iop_right"></td>
                      <td><input type="number" class="eye-input" min="0" max="80" data-field="iop_left"></td></tr>
                  </tbody>
                </table>
              </div>
              <div class="form-group" style="margin:12px 0 0">
                <label class="form-label">History</label>
                <textarea class="clinical-textarea" rows="2" maxlength="500" data-field="va_remarks" placeholder="e.g. blurred vision for 3 months, pinhole improves"></textarea>
              </div>
            </div>

<?php foreach ([['dry_ark', 'Dry Refraction / ARK', false], ['cyclo', 'Cyclo Refraction', false], ['pmt', 'PMT', false], ['final', 'Final Refraction', true]] as [$kind, $title, $required]): ?>
            <div class="vitals-card">
              <div class="vitals-card-title" style="display:flex;align-items:center;gap:10px"><span><?= $title ?> <?= $required ? '<span class="required">*</span>' : '<span style="font-weight:400;text-transform:none">(optional)</span>' ?></span>
<?php if ($kind === 'final'): ?>
                <select class="form-select" data-field="final_label" data-options="rx_labels" data-placeholder="Label (optional)" style="width:auto;margin-left:auto;text-transform:none" title="What these glasses are for"></select>
<?php endif; ?>
              </div>
              <div class="table-wrap">
                <table class="refr-table">
                  <thead><tr><th>Measurement</th><th>Right Eye (OD)</th><th>Left Eye (OS)</th></tr></thead>
                  <tbody>
<?php foreach ([['sph', 'SPH', '+0.00', 'dioptre'], ['cyl', 'CYL', '-0.00', 'dioptre'], ['axis', 'AXIS', '°', ''], ['cva', 'Corrected VA', '', 'va'], ['add', 'ADD', '+0.00', 'dioptre']] as [$key, $label, $placeholder, $format]): ?>
                    <tr><td><?= $label ?></td>
<?php foreach (['right', 'left'] as $eye): $field = "refractions.$kind.{$key}_$eye"; ?>
                      <td><?php if ($format === 'va'): ?><select class="refr-input" data-field="<?= $field ?>" data-options="va"></select><?php else: ?><input class="refr-input" inputmode="decimal" data-field="<?= $field ?>" placeholder="<?= $placeholder ?>"<?= $format ? ' data-format="dioptre"' : '' ?>><?php endif; ?></td>
<?php endforeach; ?>
                    </tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="refr-below">
                <div class="refr-field form-group" style="margin:0"><label>PD (mm)</label><input inputmode="decimal" data-field="refractions.<?= $kind ?>.pd_mm" placeholder="63"></div>
                <div class="refr-field form-group" style="margin:0"><label>NPC</label><input maxlength="20" data-field="refractions.<?= $kind ?>.npc" placeholder="5/7 cm"></div>
              </div>
            </div>
<?php endforeach; ?>

            <div class="vitals-card" data-lock="correct">
              <div class="vitals-card-title">Lens Recommendation</div>
              <div class="cl-lock-note" style="display:none">The lens recommendation went to the Optical Room with the completed record.</div>
              <div class="form-group" style="margin:0" data-collect="skip" id="op-lens"></div>
              <div class="cl-price-total" id="op-lens-total"></div>
            </div>

            <!-- Further spectacle prescriptions (e.g. reading glasses beside bifocals): each its own refraction and lenses. -->
            <div id="op-extra-rx" data-collect="skip"></div>
            <div style="display:flex;align-items:center;gap:10px;margin:4px 0 14px" id="op-extra-add-row">
              <button type="button" class="btn btn-secondary btn-sm" id="op-extra-add" onclick="OPTO_FORM.addRx()">+ Add another prescription</button>
              <span class="muted-note">For a second pair with a different prescription, e.g. reading glasses beside bifocals.</span>
            </div>

            <div class="clinical-card">
              <div class="vitals-card-title">Clinical Assessment</div>
              <div class="clinical-grid">
                <div class="form-group" style="margin:0" data-collect="skip">
                  <label class="form-label">Provisional diagnosis (ICD-10) <span class="required">*</span></label>
                  <div id="op-dx-provisional"></div>
                </div>
                <div class="form-group" style="margin:0" data-collect="skip">
                  <label class="form-label">Final diagnosis (ICD-10)</label>
                  <div id="op-dx-final"></div>
                </div>
<?php foreach ([['pmt_notes', 'PMT notes', 500, 2], ['pcp', 'CPC', 500, 2], ['anterior_segment', 'Anterior segment', 2000, 3], ['posterior_segment', 'Posterior segment', 2000, 3],
                ['management_plan', 'Management plan', 2000, 3], ['orthoptic', 'Orthoptic assessment', 2000, 3], ['low_vision', 'Low vision assessment', 2000, 3], ['remarks', 'Remarks', 2000, 3]] as [$field, $label, $max, $rows]): ?>
                <div class="form-group" style="margin:0">
                  <label class="form-label"><?= $label ?></label>
                  <textarea class="clinical-textarea" rows="<?= $rows ?>" maxlength="<?= $max ?>" data-field="<?= $field ?>"></textarea>
                </div>
<?php endforeach; ?>
              </div>
            </div>

            <div class="med-orders-card" data-lock="correct">
              <div class="med-orders-header"><div class="med-orders-title">Prescription</div></div>
              <div class="med-orders-body" data-collect="skip" id="op-rx"></div>
            </div>

<?php $clPrefix = 'op'; require __DIR__ . '/partials/clinical-followup.php'; ?>

            <div class="routing-card" data-lock="correct">
              <div class="vitals-card-title">Refer Patient To</div>
              <div class="routing-options">
                <label class="routing-opt"><input type="checkbox" data-field="refer_optical"><span class="routing-opt-label">Optical Room (spectacles)</span></label>
                <label class="routing-opt"><input type="checkbox" data-field="refer_ophthalmology"><span class="routing-opt-label">Ophthalmology</span></label>
              </div>
              <div class="cl-note">Without a referral to Ophthalmology the visit is complete when you finish.</div>
            </div>

            <div class="audit-strip" id="op-audit"></div>
            <div id="op-addenda"></div>
            <div class="opto-actions" id="op-actions"></div>
          </form>
        </div>

        <div class="hist-panel" id="op-hist-panel">
          <button type="button" class="hist-toggle-btn" onclick="clToggleSidePanel('op-layout', 'hist-collapsed')" title="Show or hide history">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
          </button>
          <div class="hist-collapsed-label" onclick="clToggleSidePanel('op-layout', 'hist-collapsed')">History</div>
          <div class="cl-hist-content">
            <div class="hist-header"><span class="hist-title">Patient History</span></div>
            <div class="hist-tabs" id="op-hist-tabs">
              <div class="hist-tab active" data-tab="visits" onclick="OPTO_FORM.historyTab('visits')">Earlier visits</div>
              <div class="hist-tab" data-tab="files" onclick="OPTO_FORM.historyTab('files')">Scanned records</div>
            </div>
            <div class="hist-body" id="op-hist-body"><div class="cl-queue-empty">Select a patient to see their history.</div></div>
          </div>
        </div>

      </div>
    </div>

