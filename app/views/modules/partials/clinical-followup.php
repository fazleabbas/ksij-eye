<?php
/** Follow-up block of a clinical form. Expects $clPrefix. */
defined('KSI_APP') || exit;
?>
            <div class="followup-card" data-lock="correct">
              <div class="vitals-card-title">Follow-Up</div>
              <label class="followup-check-row">
                <input type="checkbox" data-field="follow_up.required" style="width:15px;height:15px;accent-color:var(--teal)" onchange="clToggleFollowUp('<?= $clPrefix ?>')">
                <span style="font-size:13px;font-weight:600;color:var(--text-2)">Follow-up required — reception will book it</span>
              </label>
              <div class="followup-fields hidden" id="<?= $clPrefix ?>-fu-fields">
                <div class="form-group" style="margin:0">
                  <label class="form-label">Due date <span class="required">*</span></label>
                  <input type="date" class="form-input" data-field="follow_up.due_date">
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Priority</label>
                  <select class="form-select" data-field="follow_up.priority">
                    <option value="routine">Routine</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                  </select>
                </div>
                <div class="form-group" style="margin:0">
                  <label class="form-label">Reason</label>
                  <select class="form-select" data-field="follow_up.reason" data-options="follow_up_reason" data-placeholder="Select reason…"></select>
                </div>
                <div class="form-group" style="margin:0;grid-column:1/-1">
                  <label class="form-label">Notes for the next visit</label>
                  <textarea class="clinical-textarea" rows="2" maxlength="500" data-field="follow_up.notes"></textarea>
                </div>
              </div>
              <div class="cl-note" id="<?= $clPrefix ?>-fu-status"></div>
              <div class="cl-lock-note" style="display:none">The follow-up was sent to reception when the record was completed.</div>
            </div>
