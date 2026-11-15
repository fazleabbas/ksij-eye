'use strict';
// ─── SESSION TIMEOUT & ACCOUNT ─────────────────────────────────────────────
// The server signs a session out after the configured idle time. This mirrors
// that clock: shortly before the limit a warning offers "Stay signed in"; when
// it lapses the user is signed out and sent to the sign-in page. Activity in
// any tab (any API call) keeps every tab alive.
KSI.session = (() => {
  const config = KSI.boot.session || { idle_seconds: 1800, warning_seconds: 120, remaining_seconds: 1800 };
  const overlay = document.getElementById('session-timeout-overlay');
  const countdown = document.getElementById('session-timeout-countdown');
  const stayButton = document.getElementById('session-stay-btn');
  const channel = 'BroadcastChannel' in window ? new BroadcastChannel('ksi-session') : null;

  let deadline = Date.now() + config.remaining_seconds * 1000;
  let warningOpen = false;
  let verifying = false;
  let ended = false;

  function secondsLeft() {
    return Math.ceil((deadline - Date.now()) / 1000);
  }

  function setDeadline(remainingSeconds, broadcast) {
    deadline = Date.now() + remainingSeconds * 1000;
    if (warningOpen && secondsLeft() > config.warning_seconds) hideWarning();
    if (broadcast && channel) channel.postMessage({ type: 'activity', deadline });
  }

  function showWarning() {
    warningOpen = true;
    overlay.classList.add('open');
    stayButton.focus();
  }

  function hideWarning() {
    warningOpen = false;
    overlay.classList.remove('open');
  }

  function formatClock(seconds) {
    const s = Math.max(0, seconds);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }

  /** Reads the real remaining time from the server without extending the session. */
  async function syncFromServer() {
    if (verifying || ended) return;
    verifying = true;
    try {
      const status = await KSI.get('auth/session', null, { trackActivity: false });
      setDeadline(status.remaining_seconds, false);
    } catch {
      // 401 already redirects; on network errors keep the local countdown
    } finally {
      verifying = false;
    }
  }

  async function tick() {
    if (ended) return;
    const left = secondsLeft();
    if (left <= 0) {
      signOut(true);
      return;
    }
    if (left <= config.warning_seconds) {
      if (!warningOpen && !verifying) {
        await syncFromServer(); // another tab may have kept the session alive
        if (secondsLeft() <= config.warning_seconds && !ended) showWarning();
      }
      countdown.textContent = formatClock(secondsLeft());
    }
  }

  async function staySignedIn() {
    stayButton.disabled = true;
    try {
      const status = await KSI.post('auth/keepalive', {}, { trackActivity: false });
      setDeadline(status.remaining_seconds, true);
      hideWarning();
    } catch (error) {
      if (error.status !== 401) showToast('Could not extend session', error.message, 'error');
    } finally {
      stayButton.disabled = false;
    }
  }

  async function signOut(expired = false) {
    if (ended) return;
    ended = true;
    hideWarning();
    try { await KSI.post('auth/logout', {}, { trackActivity: false }); } catch { /* going to sign-in anyway */ }
    if (channel) channel.postMessage({ type: 'signed-out', expired });
    window.location.replace(KSI.boot.basePath + (expired ? '?expired=1' : '?signed_out=1'));
  }

  KSI.onActivity(() => setDeadline(config.idle_seconds, true));
  if (channel) {
    channel.addEventListener('message', event => {
      const message = event.data || {};
      if (message.type === 'activity' && message.deadline > deadline) {
        deadline = message.deadline;
        if (warningOpen && secondsLeft() > config.warning_seconds) hideWarning();
      } else if (message.type === 'signed-out' && !ended) {
        ended = true;
        window.location.replace(KSI.boot.basePath + (message.expired ? '?expired=1' : '?signed_out=1'));
      }
    });
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') syncFromServer();
  });
  stayButton.addEventListener('click', staySignedIn);
  document.getElementById('session-signout-btn').addEventListener('click', () => signOut(false));
  setInterval(tick, 1000);

  return { syncFromServer, staySignedIn, signOut, secondsLeft };
})();

function doLogout() {
  KSI.session.signOut(false);
}

// ─── CHANGE OWN PASSWORD ───────────────────────────────────────────────────
function openChangePassword() {
  const form = document.getElementById('form-change-password');
  form.reset();
  KSI.clearFieldErrors(form);
  document.getElementById('user-dropdown')?.classList.remove('open');
  openModal('modal-change-password');
  setTimeout(() => form.querySelector('[data-field="current_password"]').focus(), 50);
}

document.getElementById('form-change-password').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  KSI.clearFieldErrors(form);
  const current = form.querySelector('[data-field="current_password"]').value;
  const next = form.querySelector('[data-field="new_password"]').value;
  const confirm = form.querySelector('[data-field="confirm_password"]').value;
  if (next !== confirm) {
    KSI.showFieldErrors(form, { confirm_password: 'The new passwords do not match.' });
    return;
  }
  const button = document.getElementById('cp-submit');
  button.disabled = true;
  try {
    await KSI.post('auth/change-password', { current_password: current, new_password: next });
    closeModal('modal-change-password');
    showToast('Password changed', 'Any other devices signed in to your account have been signed out.', 'success');
  } catch (error) {
    KSI.reportError(error, form, 'Password not changed');
  } finally {
    button.disabled = false;
  }
});
