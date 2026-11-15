<?php defined('KSI_APP') || exit; ?>
<!-- ─── CHANGE OWN PASSWORD ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-change-password">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Change Password</div>
        <div class="modal-sub">Other devices signed in to your account will be signed out.</div>
      </div>
      <button class="modal-close" type="button" onclick="closeModal('modal-change-password')" aria-label="Close">&times;</button>
    </div>
    <form class="modal-body" id="form-change-password" novalidate>
      <div class="form-grid" style="grid-template-columns:1fr">
        <div class="form-group">
          <label class="form-label" for="cp-current-own">Current password <span class="required">*</span></label>
          <input class="form-input" type="password" id="cp-current-own" data-field="current_password" autocomplete="current-password" maxlength="128">
        </div>
        <div class="form-group">
          <label class="form-label" for="cp-new-own">New password <span class="required">*</span></label>
          <input class="form-input" type="password" id="cp-new-own" data-field="new_password" autocomplete="new-password" maxlength="128">
          <span class="form-hint">At least <?= (int) max(8, App\Core\Settings::int('security.password_min_length', 10)) ?> characters, with a letter and a number, not containing your username.</span>
        </div>
        <div class="form-group">
          <label class="form-label" for="cp-confirm-own">Repeat new password <span class="required">*</span></label>
          <input class="form-input" type="password" id="cp-confirm-own" data-field="confirm_password" autocomplete="new-password" maxlength="128">
        </div>
      </div>
    </form>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" onclick="closeModal('modal-change-password')">Cancel</button>
      <button class="btn btn-primary" type="submit" form="form-change-password" id="cp-submit">Change Password</button>
    </div>
  </div>
</div>

<!-- ─── SESSION TIMEOUT WARNING (cannot be dismissed by clicking outside) ── -->
<div class="ksi-overlay" id="session-timeout-overlay" role="alertdialog" aria-modal="true" aria-labelledby="session-timeout-title" aria-describedby="session-timeout-desc">
  <div class="session-card">
    <div class="session-card-icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="modal-title" id="session-timeout-title">Are you still there?</div>
    <p class="muted-note" id="session-timeout-desc">To protect patient information you will be signed out in</p>
    <div class="session-countdown" id="session-timeout-countdown" aria-live="polite">2:00</div>
    <p class="muted-note">Anything not yet saved on this screen will be lost.</p>
    <div class="session-actions">
      <button class="btn btn-secondary" type="button" id="session-signout-btn">Sign out</button>
      <button class="btn btn-primary" type="button" id="session-stay-btn">Stay signed in</button>
    </div>
  </div>
</div>
