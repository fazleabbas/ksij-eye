<?php defined('KSI_APP') || exit; ?>

<!-- ══ NEW / EDIT THEATRE BOOKING ══════════════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-booking">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="th-booking-title">New theatre booking</div>
        <div class="modal-subtitle">The bill is raised separately, once the case is agreed.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-booking')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" id="th-patient-row" data-field="patient_id">
        <label class="form-label" for="th-patient-search">Patient <span class="required">*</span></label>
        <div class="search-wrap" style="max-width:100%">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" class="search-input" id="th-patient-search" placeholder="e.g. Amina Hassan or file number 3127" autocomplete="off" maxlength="80" style="max-width:100%">
        </div>
        <div id="th-patient-results" style="display:none;margin-top:6px"></div>
      </div>
      <div id="th-patient-card"></div>

      <div class="form-grid" style="margin-top:12px">
        <div class="form-group" data-field="surgery_item_id">
          <label class="form-label" for="th-surgery">Surgery</label>
          <select class="form-select" id="th-surgery" onchange="thSurgeryChanged()"></select>
        </div>
        <div class="form-group" data-field="surgeon_user_id">
          <label class="form-label" for="th-surgeon">Surgeon <span class="required">*</span></label>
          <select class="form-select" id="th-surgeon"></select>
        </div>
      </div>
      <div class="alert alert-warning" id="th-surgeon-note" style="display:none"></div>

      <div class="form-group" data-field="procedures">
        <label class="form-label">Procedures</label>
        <select class="form-select" id="th-procedure-pick" onchange="thAddProcedure(this.value)"></select>
        <div class="table-wrap" style="margin-top:8px">
          <table>
            <thead><tr><th>Procedure</th><th>How many</th><th>Price each</th><th>Total</th><th></th></tr></thead>
            <tbody id="th-procedure-rows"></tbody>
          </table>
        </div>
      </div>

      <div class="opt-price-box">
        <div><div class="pcd-label">Case total</div><div class="muted-note">From the price list. The bill is raised separately.</div></div>
        <div class="opt-price-amount" id="th-total">TZS 0</div>
      </div>

      <div class="form-group" data-field="eye" style="margin-top:12px">
        <label class="form-label">Eye <span class="required">*</span></label>
        <div class="radio-group">
          <label class="radio-card"><input type="radio" name="th-eye" value="right"><div><div class="radio-card-label">Right</div><div class="radio-card-sub">OD</div></div></label>
          <label class="radio-card"><input type="radio" name="th-eye" value="left"><div><div class="radio-card-label">Left</div><div class="radio-card-sub">OS</div></div></label>
          <label class="radio-card"><input type="radio" name="th-eye" value="both"><div><div class="radio-card-label">Both</div><div class="radio-card-sub">OU</div></div></label>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group" data-field="theatre_room_id">
          <label class="form-label" for="th-room">Theatre</label>
          <select class="form-select" id="th-room"></select>
        </div>
      </div>

      <div class="form-group" data-field="timing">
        <label class="form-label">When <span class="required">*</span></label>
        <div class="radio-group">
          <label class="radio-card"><input type="radio" name="th-timing" value="now" onchange="thTimingChanged()"><div><div class="radio-card-label">Today</div><div class="radio-card-sub">Operating list for today</div></div></label>
          <label class="radio-card"><input type="radio" name="th-timing" value="later" onchange="thTimingChanged()"><div><div class="radio-card-label">Another day</div><div class="radio-card-sub">Give a date and time</div></div></label>
        </div>
      </div>
      <div class="form-grid" id="th-schedule-fields" style="display:none">
        <div class="form-group" data-field="scheduled_date">
          <label class="form-label" for="th-sched-date">Date <span class="required">*</span></label>
          <input type="date" class="form-input" id="th-sched-date">
        </div>
        <div class="form-group" data-field="scheduled_time">
          <label class="form-label" for="th-sched-time">Time</label>
          <input type="time" class="form-input" id="th-sched-time" value="08:00">
        </div>
      </div>

      <div id="th-biometry-block" style="display:none">
        <div class="form-label" style="margin-top:10px">Lens and biometry</div>
        <div class="muted-note">A cataract case cannot be cleared for theatre without at least the axial length or the steep K.</div>
        <div class="alert alert-info" id="th-bio-source" style="display:none;margin:8px 0"></div>
        <div class="form-grid">
          <div class="form-group"><label class="form-label" for="th-bio-model">IOL model</label><input class="form-input" id="th-bio-model" maxlength="60" placeholder="e.g. SN60WF"></div>
          <div class="form-group"><label class="form-label" for="th-bio-power">IOL power</label><input type="number" step="0.25" class="form-input" id="th-bio-power"></div>
        </div>
        <div class="form-grid">
          <div class="form-group"><label class="form-label" for="th-bio-axial">Axial length (mm)</label><input type="number" step="0.01" class="form-input" id="th-bio-axial"></div>
          <div class="form-group"><label class="form-label" for="th-bio-constant">A-constant</label><input type="number" step="0.01" class="form-input" id="th-bio-constant"></div>
        </div>
        <div class="form-grid">
          <div class="form-group"><label class="form-label" for="th-bio-steep">Steep K</label><input type="number" step="0.01" class="form-input" id="th-bio-steep"></div>
          <div class="form-group"><label class="form-label" for="th-bio-flat">Flat K</label><input type="number" step="0.01" class="form-input" id="th-bio-flat"></div>
        </div>
        <div class="form-grid">
          <div class="form-group"><label class="form-label" for="th-bio-acd">ACD (mm)</label><input type="number" step="0.01" class="form-input" id="th-bio-acd"></div>
          <div class="form-group"><label class="form-label" for="th-bio-cct">Corneal thickness (µm)</label><input type="number" class="form-input" id="th-bio-cct"></div>
        </div>
        <div class="form-grid">
          <div class="form-group"><label class="form-label" for="th-bio-lt">Lens thickness (mm)</label><input type="number" step="0.01" class="form-input" id="th-bio-lt"></div>
          <div class="form-group"><label class="form-label" for="th-bio-wtw">White-to-white (mm)</label><input type="number" step="0.01" class="form-input" id="th-bio-wtw"></div>
        </div>
        <div class="form-group"><label class="form-label" for="th-bio-remarks">Remarks</label><input class="form-input" id="th-bio-remarks" maxlength="200"></div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-booking')">Cancel</button>
      <button class="btn btn-primary" type="button" id="th-booking-save" onclick="thSaveBooking(this)">Book the case</button>
    </div>
  </div>
</div>

<!-- ══ EMERGENCY: OPERATE BEFORE PAYMENT ═══════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-defer">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Allow surgery before payment</div>
        <div class="modal-subtitle">For an emergency. Your name is recorded against this decision.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-defer')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="th-defer-info"></div>
      <div class="alert alert-warning" style="margin-top:10px">The bill still stands and remains payable at the cashier.</div>
      <div class="form-group" data-field="reason">
        <label class="form-label" for="th-defer-reason">Why can this not wait? <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="th-defer-reason" maxlength="500"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-defer')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="thSubmitDefer(this)">Allow it through</button>
    </div>
  </div>
</div>

<!-- ══ REQUEST CANCELLATION ════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-cancel">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Request cancellation</div>
        <div class="modal-subtitle">An administrator decides. Any unpaid bill is cancelled with it.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-cancel')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="th-cancel-info"></div>
      <div class="form-group" data-field="reason" style="margin-top:12px">
        <label class="form-label" for="th-cancel-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="th-cancel-reason" maxlength="500"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-cancel')">Keep the case</button>
      <button class="btn btn-danger" type="button" onclick="thSubmitCancel(this)">Request cancellation</button>
    </div>
  </div>
</div>

<!-- ══ HOLD OR STOP IN PRE-OP ══════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-hold">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="th-hold-title">Put the case on hold</div>
        <div class="modal-subtitle">The case stays in pre-op until it is sorted out.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-hold')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" data-field="reason">
        <label class="form-label" for="th-hold-reason">Reason <span class="required">*</span></label>
        <textarea class="form-input" rows="3" id="th-hold-reason" maxlength="500" placeholder="e.g. blood pressure too high, review tomorrow"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-hold')">Cancel</button>
      <button class="btn btn-primary" type="button" id="th-hold-save" onclick="thSubmitHold(this)">Put on hold</button>
    </div>
  </div>
</div>

<!-- ══ DISCHARGE FROM SURGICAL CARE ════════════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-discharge">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Discharge from surgical care</div>
        <div class="modal-subtitle">The case closes and moves to theatre history.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-discharge')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="cl-note" id="th-discharge-info"></div>
      <div class="form-group" data-field="remarks" style="margin-top:12px">
        <label class="form-label" for="th-discharge-remarks">Discharge remarks</label>
        <textarea class="form-input" rows="3" id="th-discharge-remarks" maxlength="1000" placeholder="How the patient was on discharge, and anything they were told"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-discharge')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="thSubmitDischarge(this)">Discharge</button>
    </div>
  </div>
</div>

<!-- ══ ADDENDUM ════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-addendum">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Add addendum</div>
        <div class="modal-subtitle">The record itself is not changed.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-addendum')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" novalidate onsubmit="event.preventDefault()">
      <div class="form-group" data-field="reason">
        <label class="form-label" for="th-addendum-reason">Reason <span class="required">*</span></label>
        <input class="form-input" id="th-addendum-reason" maxlength="200">
      </div>
      <div class="form-group" data-field="note">
        <label class="form-label" for="th-addendum-note">Addendum <span class="required">*</span></label>
        <textarea class="form-input" rows="5" id="th-addendum-note" maxlength="2000"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-addendum')">Cancel</button>
      <button class="btn btn-primary" type="button" onclick="thSaveAddendum(this)">Add addendum</button>
    </div>
  </div>
</div>

<!-- ══ CASE RECORD ═════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-theatre-case">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="th-case-title">Case record</div>
        <div class="modal-subtitle" id="th-case-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-theatre-case')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="th-case-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-theatre-case')">Close</button>
      <button class="btn btn-primary" type="button" onclick="thPrintCase()">Print</button>
    </div>
  </div>
</div>
