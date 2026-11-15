<?php defined('KSI_APP') || exit; ?>
<datalist id="cl-frequencies"></datalist>

<!-- ══ CLINICAL ADDENDUM ════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-clinical-addendum">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Add Addendum</div>
        <div class="modal-subtitle" id="ad-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-clinical-addendum')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="ad-form" novalidate onsubmit="event.preventDefault();clSaveAddendum()">
      <div class="alert alert-info" style="margin-bottom:14px">The completed record is not changed. The addendum is shown with it, with your name and the time.</div>
      <div class="form-group">
        <label class="form-label">Reason <span class="required">*</span></label>
        <input class="form-input" maxlength="200" data-field="reason" placeholder="e.g. Additional history from relative">
      </div>
      <div class="form-group">
        <label class="form-label">Addendum <span class="required">*</span></label>
        <textarea class="form-input" rows="5" maxlength="2000" data-field="note"></textarea>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-clinical-addendum')">Cancel</button>
      <button class="btn btn-primary" type="button" id="ad-save" onclick="clSaveAddendum()">Add Addendum</button>
    </div>
  </div>
</div>

<!-- ══ TEST RESULT (doctors: from the consultation, patient history and the bell) ══ -->
<div class="modal-overlay" id="modal-cl-result">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="clr-title">Test result</div>
        <div class="modal-subtitle" id="clr-subtitle"></div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-cl-result')" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body" id="clr-body"></div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-cl-result')">Close</button>
    </div>
  </div>
</div>
