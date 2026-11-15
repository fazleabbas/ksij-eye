<?php defined('KSI_APP') || exit; ?>

<!-- ══ NEW / EDIT DIAGNOSTIC BOOKING ═══════════════════════════════════ -->
<div class="modal-overlay" id="modal-diag-booking">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title">New diagnostic booking</div>
        <div class="modal-subtitle">Each procedure becomes its own booking. Nothing is charged until the patient arrives.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-diag-booking')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" id="diag-patient-row" data-field="patient_id">
        <label class="form-label" for="diag-patient-search">Patient <span class="required">*</span></label>
        <div class="search-wrap" style="max-width:100%">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" class="search-input" id="diag-patient-search" placeholder="e.g. Amina Hassan, 0712 345 678 or file number 3127" autocomplete="off" maxlength="80" style="max-width:100%">
        </div>
        <div id="diag-patient-results" style="display:none;margin-top:6px"></div>
      </div>
      <div id="diag-patient-card"></div>

      <div class="form-group" style="margin-top:12px">
        <label class="form-label" for="diag-ref-doctor">Referring doctor <span class="required">*</span></label>
        <select class="form-select" id="diag-ref-doctor" data-field="referring_user_id"></select>
        <div class="alert alert-warning" id="diag-doctor-note" style="display:none;margin-top:8px"></div>
      </div>

      <div class="form-group" data-field="procedures">
        <label class="form-label">Procedure <span class="required">*</span></label>
        <div class="diag-proc-grid" id="diag-proc-grid"></div>
        <div class="muted-note" id="diag-proc-count"></div>
      </div>
      <div id="diag-proc-details" class="diag-proc-details"></div>

      <div class="form-group" data-field="eye">
        <label class="form-label">Eye <span class="required">*</span></label>
        <div class="radio-group">
          <label class="radio-card"><input type="radio" name="diag-eye" value="right"><div><div class="radio-card-label">Right</div><div class="radio-card-sub">OD</div></div></label>
          <label class="radio-card"><input type="radio" name="diag-eye" value="left"><div><div class="radio-card-label">Left</div><div class="radio-card-sub">OS</div></div></label>
          <label class="radio-card"><input type="radio" name="diag-eye" value="both"><div><div class="radio-card-label">Both</div><div class="radio-card-sub">OU</div></div></label>
        </div>
      </div>

      <div class="form-group" data-field="timing">
        <label class="form-label">When <span class="required">*</span></label>
        <div class="radio-group">
          <label class="radio-card"><input type="radio" name="diag-timing" value="now" onchange="diagTimingChanged()"><div><div class="radio-card-label">Now</div><div class="radio-card-sub">The patient is here today</div></div></label>
          <label class="radio-card"><input type="radio" name="diag-timing" value="later" onchange="diagTimingChanged()"><div><div class="radio-card-label">Another day</div><div class="radio-card-sub">Give a date and time</div></div></label>
        </div>
      </div>

      <div class="form-grid" id="diag-schedule-fields" style="display:none">
        <div class="form-group">
          <label class="form-label" for="diag-sched-date">Date <span class="required">*</span></label>
          <input type="date" class="form-input" id="diag-sched-date" data-field="scheduled_date">
        </div>
        <div class="form-group">
          <label class="form-label" for="diag-sched-time">Time</label>
          <input type="time" class="form-input" id="diag-sched-time" data-field="scheduled_time" value="08:00">
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-diag-booking')">Cancel</button>
      <button class="btn btn-primary" type="button" id="diag-form-submit" onclick="diagSaveBooking(this)">Book test</button>
    </div>
  </div>
</div>

<!-- ══ CHECK IN / RAISE THE CHARGE ═════════════════════════════════════ -->
<div class="modal-overlay" id="modal-diag-checkin">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Check in and charge</div>
        <div class="modal-subtitle">The test can go ahead whether or not the patient has paid.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-diag-checkin')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="diag-checkin-info"></div>

      <div class="form-group" style="margin-top:14px">
        <label class="form-label">Charge <span class="required">*</span></label>
        <div class="radio-group">
          <label class="radio-card"><input type="radio" name="diag-checkin-mode" value="bill" onchange="diagCheckInModeChanged()"><div><div class="radio-card-label">Raise the bill</div><div class="radio-card-sub"><span id="diag-checkin-amount"></span></div></div></label>
          <label class="radio-card"><input type="radio" name="diag-checkin-mode" value="no_charge" onchange="diagCheckInModeChanged()"><div><div class="radio-card-label">No charge</div><div class="radio-card-sub">An administrator must agree</div></div></label>
        </div>
      </div>

      <div class="alert alert-info" id="diag-checkin-bill-note">The bill goes to the cashier. The patient may pay before or after the test.</div>

      <div id="diag-checkin-nocharge" style="display:none">
        <div class="form-group" data-field="no_charge_reason">
          <label class="form-label" for="diag-checkin-reason">Why is it not charged? <span class="required">*</span></label>
          <select class="form-select" id="diag-checkin-reason"></select>
        </div>
        <div class="alert alert-warning">The test still goes ahead. The request waits in Approvals, and if it is refused the bill stands at the list price.</div>
      </div>

      <div class="form-group" data-field="remarks">
        <label class="form-label" for="diag-checkin-remarks">Remarks</label>
        <textarea class="form-input" rows="2" id="diag-checkin-remarks" maxlength="500" placeholder="Anything the cashier or administrator should know"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-diag-checkin')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="diagSubmitCheckIn(this)">Check in</button>
    </div>
  </div>
</div>

<!-- ══ REQUEST CANCELLATION ════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-diag-cancel">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Request cancellation</div>
        <div class="modal-subtitle">An administrator decides. The booking stays until then.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-diag-cancel')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="diag-cancel-info"></div>
      <div class="form-group" data-field="reason" style="margin-top:14px">
        <label class="form-label" for="diag-cancel-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="diag-cancel-reason" maxlength="500" placeholder="Why the test is not going ahead"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-diag-cancel')">Keep booking</button>
      <button class="btn btn-danger" type="button" onclick="diagSubmitCancel(this)">Request cancellation</button>
    </div>
  </div>
</div>

<!-- ══ VIEW A REPORT ═══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-diag-view">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="diag-view-title">Report</div>
        <div class="modal-subtitle">As given to the referring doctor.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-diag-view')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="diag-view-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-diag-view')">Close</button>
      <button class="btn btn-primary" type="button" onclick="diagDownloadReport(DIAG.selected.id)">Download</button>
    </div>
  </div>
</div>

<!-- ══ ADDENDUM TO A REPORT ════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-diag-addendum">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Add addendum</div>
        <div class="modal-subtitle">The report itself is not changed.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-diag-addendum')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" data-field="reason">
        <label class="form-label" for="diag-addendum-reason">Reason <span class="required">*</span></label>
        <input class="form-input" id="diag-addendum-reason" maxlength="200" placeholder="e.g. Further detail for the referring doctor">
      </div>
      <div class="form-group" data-field="note">
        <label class="form-label" for="diag-addendum-note">Addendum <span class="required">*</span></label>
        <textarea class="form-input" rows="5" id="diag-addendum-note" maxlength="2000"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-diag-addendum')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="diagSaveAddendum(this)">Add addendum</button>
    </div>
  </div>
</div>
