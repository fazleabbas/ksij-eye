'use strict';
// ─── SIGN-IN PAGE ──────────────────────────────────────────────────────────
// Posts credentials to the API; on success the server sets an HttpOnly session
// cookie and a reload renders either the app or the change-password step.
(() => {
  const boot = JSON.parse(document.getElementById('ksi-boot').textContent);
  const base = boot.basePath || './';
  const $ = id => document.getElementById(id);

  async function post(path, body, csrf) {
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (csrf) headers['X-CSRF-Token'] = csrf;
    let response;
    try {
      response = await fetch(base + 'api/' + path, {
        method: 'POST', headers, body: JSON.stringify(body), credentials: 'same-origin', cache: 'no-store',
      });
    } catch {
      throw Object.assign(new Error('Cannot reach the server. Check the connection and try again.'), { fields: {} });
    }
    let payload = null;
    try { payload = await response.json(); } catch { /* not JSON */ }
    if (!response.ok || !payload || payload.ok !== true) {
      const error = payload?.error || {};
      throw Object.assign(new Error(error.message || 'Something went wrong. Please try again.'), { code: error.code, fields: error.fields || {} });
    }
    return payload.data;
  }

  function setError(id, message) {
    const box = $(id);
    box.textContent = message || '';
    box.classList.toggle('show', !!message);
  }

  function setBusy(button, busy, label) {
    button.disabled = busy;
    button.textContent = busy ? 'Please wait…' : label;
  }

  // ── Sign in ──
  const loginForm = $('view-login');
  loginForm.addEventListener('submit', async event => {
    event.preventDefault();
    setError('login-error', '');
    $('login-notice').classList.remove('show');
    const username = $('login-username').value.trim();
    const password = $('login-password').value;
    if (!username || !password) {
      setError('login-error', 'Enter your username and password.');
      return;
    }
    const button = $('login-submit');
    setBusy(button, true, 'Sign In');
    try {
      await post('auth/login', { username, password });
      window.location.replace(base);
    } catch (error) {
      setError('login-error', error.message);
      $('login-password').value = '';
      $('login-password').focus();
      setBusy(button, false, 'Sign In');
    }
  });

  // ── Replace a temporary password ──
  const changeForm = $('view-change-password');
  changeForm.addEventListener('submit', async event => {
    event.preventDefault();
    setError('change-error', '');
    const current = $('cp-current').value;
    const next = $('cp-new').value;
    const confirm = $('cp-confirm').value;
    if (!current || !next || !confirm) {
      setError('change-error', 'Fill in all three fields.');
      return;
    }
    if (next.length < boot.passwordMinLength) {
      setError('change-error', `The new password must be at least ${boot.passwordMinLength} characters.`);
      return;
    }
    if (next !== confirm) {
      setError('change-error', 'The new passwords do not match.');
      return;
    }
    const button = $('change-submit');
    setBusy(button, true, 'Save and continue');
    try {
      await post('auth/change-password', { current_password: current, new_password: next }, boot.csrf);
      window.location.replace(base);
    } catch (error) {
      if (error.code === 'session_expired' || error.code === 'unauthenticated') {
        window.location.replace(base + '?expired=1');
        return;
      }
      setError('change-error', Object.values(error.fields)[0] || error.message);
      setBusy(button, false, 'Save and continue');
    }
  });

  $('change-cancel').addEventListener('click', async () => {
    try { await post('auth/logout', {}, boot.csrf); } catch { /* signing out anyway */ }
    window.location.replace(base + '?signed_out=1');
  });

  if (boot.mode === 'change_password') {
    $('cp-current').focus();
  } else {
    $('login-username').focus();
  }
  if (window.location.search) {
    window.history.replaceState(null, '', base);
  }
})();
