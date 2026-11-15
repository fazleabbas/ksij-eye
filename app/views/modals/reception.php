<?php defined('KSI_APP') || exit; ?>

<!-- ══ TOAST CONTAINER ══════════════════════════════════════════════════ -->
<div id="toast-container"></div>

<!-- ══ NEW VISIT ════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-new-visit">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">New Visit</div>
        <div class="modal-subtitle">Visit date <span id="nv-visit-date">—</span> · the visit number is assigned when saved</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-new-visit')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="nv-body">
      <div class="form-section-title" style="margin-top:0">Patient</div>
      <div class="form-group" id="nv-picker" data-field="patient_id">
        <label class="form-label" for="nv-patient-search">Search by name, phone or registration number <span class="required">*</span></label>
        <div class="search-wrap" style="max-width:100%">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" class="search-input" id="nv-patient-search" placeholder="e.g. Amina Hassan, 0712 345 678 or file number 3127" autocomplete="off" maxlength="80" style="max-width:100%">
        </div>
        <div id="nv-search-results" style="display:none;margin-top:6px"></div>
      </div>
      <div id="nv-selected-patient" style="display:none"></div>
      <div class="alert alert-info" id="nv-followup-note" style="display:none;margin-top:10px"></div>

      <div class="form-section-title">Visit</div>
      <div class="form-group">
        <label class="form-label">Visit Type <span class="required">*</span></label>
        <div class="radio-group" data-field="visit_type">
          <label class="radio-card"><input type="radio" name="nv-visit-type" value="consultation" onchange="vsOnTypeChange()"><div><div class="radio-card-label">Consultation</div><div class="radio-card-sub">New or recurring problem</div></div></label>
          <label class="radio-card"><input type="radio" name="nv-visit-type" value="follow_up" onchange="vsOnTypeChange()"><div><div class="radio-card-label">Follow-Up</div><div class="radio-card-sub">Review after an earlier visit</div></div></label>
        </div>
      </div>
      <div class="form-group" id="nv-sub-type-group" style="display:none;margin-top:12px">
        <label class="form-label">Sub-type <span class="required">*</span></label>
        <div class="radio-group" id="nv-sub-types" data-field="sub_type"></div>
      </div>
      <div class="form-group" id="nv-fast-track-group" style="display:none;margin-top:8px">
        <label class="ur-perm-check"><input type="checkbox" id="nv-fast-track" data-field="fast_track" onchange="vsOnFormChange()">
          <span>Fast track — seen before the normal queue (fast-track price applies, even within the free follow-up days)</span></label>
      </div>

      <div class="form-group" style="margin-top:14px">
        <label class="form-label">Destination <span class="required">*</span></label>
        <div class="radio-group" data-field="destination">
          <label class="radio-card"><input type="radio" name="nv-destination" value="doctor_room" onchange="vsOnFormChange()"><div><div class="radio-card-label">Doctor Room</div><div class="radio-card-sub">Straight to consultation</div></div></label>
          <label class="radio-card"><input type="radio" name="nv-destination" value="triage" onchange="vsOnFormChange()"><div><div class="radio-card-label">Triage</div><div class="radio-card-sub">Nurse assessment first</div></div></label>
          <label class="radio-card"><input type="radio" name="nv-destination" value="investigation" onchange="vsOnFormChange()"><div><div class="radio-card-label">Investigation</div><div class="radio-card-sub">Diagnostic test only</div></div></label>
        </div>
      </div>

      <div class="form-grid" id="nv-room-group" style="display:none;margin-top:12px">
        <div class="form-group">
          <label class="form-label" for="nv-room">Consultation Room <span class="required">*</span></label>
          <select class="form-select" id="nv-room" data-field="room"></select>
        </div>
      </div>

      <div id="nv-investigation-group" style="display:none;margin-top:12px">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="nv-investigation">Investigation <span class="required">*</span></label>
            <select class="form-select" id="nv-investigation" data-field="investigation_item_id" onchange="vsOnFormChange()"></select>
          </div>
          <div class="form-group">
            <label class="form-label" for="nv-doctor">Referring Doctor</label>
            <select class="form-select" id="nv-doctor" data-field="referring_doctor_id"></select>
          </div>
          <div class="form-group col-2">
            <label class="form-label">Eye <span class="required">*</span></label>
            <div class="radio-group" data-field="investigation_eye">
              <label class="radio-card"><input type="radio" name="nv-eye" value="right" onchange="vsOnFormChange()"><div class="radio-card-label">Right (OD)</div></label>
              <label class="radio-card"><input type="radio" name="nv-eye" value="left" onchange="vsOnFormChange()"><div class="radio-card-label">Left (OS)</div></label>
              <label class="radio-card"><input type="radio" name="nv-eye" value="both" onchange="vsOnFormChange()"><div class="radio-card-label">Both (OU)</div></label>
            </div>
          </div>
          <div class="form-group col-2">
            <label class="form-label" for="nv-reason">Clinical Reason <span class="required">*</span></label>
            <textarea class="form-input" id="nv-reason" rows="2" maxlength="500" data-field="investigation_reason" placeholder="Why is the test needed?"></textarea>
          </div>
        </div>
      </div>

      <div id="nv-emergency-group" style="display:none">
        <div class="form-section-title">Emergency</div>
        <label class="ur-perm-check"><input type="checkbox" id="nv-emergency" onchange="vsOnFormChange()"> <span>Emergency — treat first and settle the bill afterwards</span></label>
        <div class="form-group" id="nv-emergency-reason-group" style="display:none;margin-top:8px">
          <label class="form-label" for="nv-emergency-reason">What is the emergency? <span class="required">*</span></label>
          <input class="form-input" id="nv-emergency-reason" maxlength="255" data-field="emergency_reason" placeholder="e.g. Chemical splash to the eye" data-emergency>
        </div>
      </div>

      <div class="form-section-title">Fee</div>
      <div class="discount-calc-box" id="nv-fee"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-new-visit')">Cancel</button>
      <button class="btn btn-primary" type="button" id="nv-save" onclick="vsSaveVisit()">Save Visit</button>
    </div>
  </div>
</div>

<!-- ══ REGISTER / EDIT PATIENT ══════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-patient-form">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="pf-title">Register New Patient</div>
        <div class="modal-subtitle" id="pf-subtitle">The registration number is assigned automatically</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-patient-form')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="pf-form" novalidate onsubmit="event.preventDefault()">
      <div class="alert alert-warning" id="pf-duplicates" style="display:none;margin-bottom:14px;flex-direction:column"></div>
      <div id="pf-type-section">
        <div class="form-section-title" style="margin-top:0">Patient</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="radio-card"><input type="radio" name="pf-type" value="new" checked onchange="ptTypeChanged()">
              <div><div class="radio-card-label">New patient</div><div class="radio-card-sub">Gets the next file number automatically</div></div></label>
          </div>
          <div class="form-group">
            <label class="radio-card"><input type="radio" name="pf-type" value="existing" onchange="ptTypeChanged()">
              <div><div class="radio-card-label">Existing patient</div><div class="radio-card-sub">Already has a paper file — keeps its number</div></div></label>
          </div>
        </div>
        <div class="form-group" id="pf-paper-group" style="display:none">
          <label class="form-label" for="pf-paper-no">Paper file number <span class="required">*</span></label>
          <input class="form-input" id="pf-paper-no" data-field="paper_file_no" inputmode="numeric" maxlength="9" autocomplete="off" placeholder="The number on their paper file, e.g. 3127">
        </div>
      </div>
      <div class="form-section-title" style="margin-top:0">Personal Details</div>
      <div class="form-grid form-grid-3">
        <div class="form-group">
          <label class="form-label">First Name <span class="required">*</span></label>
          <input class="form-input" data-field="first_name" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Middle Name</label>
          <input class="form-input" data-field="middle_name" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Last Name <span class="required">*</span></label>
          <input class="form-input" data-field="last_name" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Gender <span class="required">*</span></label>
          <select class="form-select" data-field="gender">
            <option value="">Select…</option>
            <option value="female">Female</option>
            <option value="male">Male</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Date of Birth <span class="required">*</span></label>
          <input class="form-input" type="date" data-field="date_of_birth" id="pf-dob" oninput="ptUpdateAge()">
        </div>
        <div class="form-group">
          <label class="form-label">Age</label>
          <input class="form-input" id="pf-age" readonly placeholder="From date of birth">
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number <span class="required">*</span></label>
          <input class="form-input" data-field="phone" maxlength="20" inputmode="tel" placeholder="0712 345 678" autocomplete="off">
        </div>
        <div class="form-group col-2">
          <label class="form-label">Physical Address <span class="required">*</span></label>
          <input class="form-input" data-field="address" maxlength="255" placeholder="Street, district, city" autocomplete="off">
        </div>
      </div>
      <div class="form-section-title">Next of Kin</div>
      <div class="form-grid form-grid-3">
        <div class="form-group">
          <label class="form-label">Full Name <span class="required">*</span></label>
          <input class="form-input" data-field="kin_name" maxlength="160" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Relationship <span class="required">*</span></label>
          <select class="form-select" data-field="kin_relationship" id="pf-kin-rel" onchange="ptKinRelChanged()"></select>
        </div>
        <div class="form-group" id="pf-kin-rel-other-group" style="display:none">
          <label class="form-label">Please specify <span class="required">*</span></label>
          <input class="form-input" data-field="kin_relationship_other" id="pf-kin-rel-other" maxlength="100" placeholder="e.g. Neighbour" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Phone <span class="required">*</span></label>
          <input class="form-input" data-field="kin_phone" maxlength="20" inputmode="tel" autocomplete="off">
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <span class="text-xs text-muted" id="pf-footer-note">Patient details are stored encrypted.</span>
      <div class="flex-gap">
        <button class="btn btn-secondary" type="button" onclick="closeModal('modal-patient-form')">Cancel</button>
        <button class="btn btn-primary" type="button" id="pf-save" onclick="ptSavePatient()">Register Patient</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ VIEW PATIENT ═════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-view-patient">
  <div class="modal modal-xl">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="pv-title">Patient</div>
        <div class="modal-subtitle" id="pv-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-view-patient')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" style="padding-top:16px">
      <div class="tab-bar" id="pv-tabs">
        <button class="tab-btn active" data-tab="personal" onclick="ptShowTab('personal')">Personal Info</button>
        <button class="tab-btn" data-tab="kin" onclick="ptShowTab('kin')">Next of Kin</button>
        <button class="tab-btn" data-tab="visits" onclick="ptShowTab('visits')">Visits</button>
        <button class="tab-btn" data-tab="bills" onclick="ptShowTab('bills')">Payments</button>
        <button class="tab-btn" data-tab="files" onclick="ptShowTab('files')">Scanned Records</button>
      </div>
      <div class="tab-panel active" id="pv-tab-personal"><div class="detail-grid" id="pv-personal"></div></div>
      <div class="tab-panel" id="pv-tab-kin"><div class="detail-grid" id="pv-kin"></div></div>
      <div class="tab-panel" id="pv-tab-visits">
        <div class="table-wrap"><table>
          <thead><tr><th>Visit No.</th><th>Date</th><th>Type</th><th>Destination</th><th>Status</th><th></th></tr></thead>
          <tbody id="pv-visits"></tbody>
        </table></div>
      </div>
      <div class="tab-panel" id="pv-tab-bills">
        <div class="table-wrap"><table>
          <thead><tr><th>Bill No.</th><th>Date</th><th>Service</th><th style="text-align:right">Amount</th><th>Status</th></tr></thead>
          <tbody id="pv-bills"></tbody>
        </table></div>
      </div>
      <div class="tab-panel" id="pv-tab-files">
        <div class="alert alert-info" style="margin-bottom:14px">
          Scanned paper records for this patient. Files are stored encrypted; every view and download is recorded.
        </div>
        <div class="file-list" id="pv-files"></div>
        <div style="margin-top:14px" id="pv-upload-wrap">
          <input type="file" id="pv-file-input" accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff" multiple style="display:none" onchange="ptUploadFiles(this)">
          <button class="btn btn-secondary btn-sm" type="button" id="pv-upload-btn" onclick="document.getElementById('pv-file-input').click()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Upload Record
          </button>
          <span class="form-hint" style="margin-left:8px">PDF, JPG, PNG or TIFF · up to 20 MB each</span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <span class="text-xs text-muted" id="pv-meta"></span>
      <div class="flex-gap">
        <button class="btn btn-secondary" type="button" id="pv-fileno-btn" onclick="ptOpenFileNumber()">Correct file number</button>
        <button class="btn btn-secondary" type="button" id="pv-edit-btn" onclick="ptOpenEdit()">Edit Details</button>
        <button class="btn btn-primary" type="button" id="pv-visit-btn" onclick="vsOpenNewVisit(PAT_UI.record ? PAT_UI.record.patient : null)">New Visit</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ VISIT DETAIL ═════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-visit-detail">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="vd-title">Visit</div>
        <div class="modal-subtitle" id="vd-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-visit-detail')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="vd-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" id="vd-cancel-btn" style="color:var(--red);display:none" onclick="vsOpenCancel()">Cancel Visit</button>
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-visit-detail')">Close</button>
    </div>
  </div>
</div>

<!-- ══ CANCEL VISIT ═════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-cancel-visit">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Cancel Visit</div>
        <div class="modal-subtitle" id="cv-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-cancel-visit')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="cv-form" novalidate onsubmit="event.preventDefault();vsConfirmCancel()">
      <div class="alert alert-warning" style="margin-bottom:14px">The unpaid bill for this visit is cancelled too. Paid visits need a refund first.</div>
      <div class="form-group">
        <label class="form-label" for="cv-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" id="cv-reason" rows="3" maxlength="500" data-field="reason" placeholder="e.g. Patient left before being seen"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-cancel-visit')">Back</button>
      <button class="btn btn-danger" type="button" id="cv-confirm" onclick="vsConfirmCancel()">Cancel Visit</button>
    </div>
  </div>
</div>

<!-- ══ CANCEL FOLLOW-UP ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-cancel-followup">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Cancel Follow-Up</div>
        <div class="modal-subtitle" id="cf-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-cancel-followup')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="cf-form" novalidate onsubmit="event.preventDefault();ptConfirmCancelFollowUp()">
      <div class="form-group">
        <label class="form-label" for="cf-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" id="cf-reason" rows="3" maxlength="500" data-field="reason" placeholder="e.g. Patient moved to another region"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-cancel-followup')">Back</button>
      <button class="btn btn-danger" type="button" id="cf-confirm" onclick="ptConfirmCancelFollowUp()">Cancel Follow-Up</button>
    </div>
  </div>
</div>

<!-- ══ REMOVE SCANNED RECORD ════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-file-remove">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Remove Scanned Record</div>
        <div class="modal-subtitle" id="fr-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-file-remove')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="fr-form" novalidate onsubmit="event.preventDefault();ptConfirmRemoveFile()">
      <div class="alert alert-info" style="margin-bottom:14px">The file is hidden from the record but kept for audit purposes.</div>
      <div class="form-group">
        <label class="form-label" for="fr-reason">Reason <span class="required">*</span></label>
        <input class="form-input" id="fr-reason" maxlength="255" data-field="reason" placeholder="e.g. Uploaded to the wrong patient">
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-file-remove')">Back</button>
      <button class="btn btn-danger" type="button" id="fr-confirm" onclick="ptConfirmRemoveFile()">Remove</button>
    </div>
  </div>
</div>

<!-- ══ CORRECT A PATIENT'S FILE NUMBER (administrators) ═══════════════ -->
<div class="modal-overlay" id="modal-file-number">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Correct file number</div>
        <div class="modal-subtitle">For a paper file number typed wrongly, or a patient registered as new who has a paper file.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-file-number')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="fn-form" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="fn-info"></div>
      <div class="form-group" data-field="paper_file_no" style="margin-top:12px">
        <label class="form-label" for="fn-number">Paper file number <span class="required">*</span></label>
        <input class="form-input" id="fn-number" inputmode="numeric" maxlength="9" autocomplete="off">
      </div>
      <div class="form-group" data-field="reason">
        <label class="form-label" for="fn-reason">Reason <span class="required">*</span></label>
        <input class="form-input" id="fn-reason" maxlength="255" placeholder="e.g. Typed 3172 instead of 3127">
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-file-number')">Cancel</button>
      <button class="btn btn-primary" type="button" id="fn-save" onclick="ptSaveFileNumber()">Save</button>
    </div>
  </div>
</div>
