'use strict';
// ─── KSI CLIENT CORE ───────────────────────────────────────────────────────
// Loaded before every other script on the application page.
//  • KSI.user / KSI.boot — identity and settings rendered by the server
//  • KSI.get/post/put/del — JSON API calls with the CSRF token; an expired
//    session sends the browser back to the sign-in page
//  • KSI.can / KSI.canModule — permission checks that shape the UI (the API
//    enforces the same rules server-side)
//  • KSI.esc — escape any value before putting it into HTML
const KSI = (() => {
  const boot = JSON.parse(document.getElementById('ksi-boot')?.textContent || '{}');
  const basePath = boot.basePath || './';
  const permissions = new Set(boot.user?.permissions || []);
  const activityListeners = [];
  let redirecting = false;

  // Screen → permissions (any one grants access)
  const MODULE_PERMISSIONS = {
    'dashboard': ['dashboard.view'],
    'manage-visit': ['visits.view'],
    'manage-patient': ['patients.view'],
    'cashier-payments': ['payments.view'],
    'cashier-refunds': ['refunds.view'],
    'cashier-history': ['payments.history'],
    'approvals-pending': ['approvals.view'],
    'approvals-history': ['approvals.view'],
    'triage': ['triage.view'],
    'optometry': ['optometry.view'],
    'ophthalmology': ['ophthalmology.view'],
    'patient-history': ['ophthalmology.view', 'optometry.view', 'triage.view'],
    'theatre-booking': ['theatre.booking.view'],
    'theatre-preop': ['theatre.preop.view'],
    'theatre-intraop': ['theatre.intraop.view'],
    'theatre-postop': ['theatre.postop.view'],
    'theatre-history': ['theatre.history.view'],
    'diag-booking': ['diagnostics.booking.view'],
    'diag-report': ['diagnostics.report.view'],
    'diag-history': ['diagnostics.history.view'],
    'opt-incoming': ['optical.view'],
    'opt-create': ['optical.orders.manage'],
    'opt-active': ['optical.view'],
    'opt-dispensing': ['optical.view'],
    'opt-completed': ['optical.view'],
    'opt-external': ['optical.orders.manage'],
    'opt-frames': ['optical.view'],
    'pharm-orders': ['pharmacy.orders.view'],
    'pharm-dispense': ['pharmacy.dispense'],
    'pharm-partial': ['pharmacy.dispense'],
    'pharm-refills': ['pharmacy.dispense'],
    'pharm-history': ['pharmacy.history.view'],
    'pharm-inventory': ['pharmacy.inventory.view'],
    'stores-requisition-form': ['requisitions.create'],
    'stores-my-requisitions': ['requisitions.view_own'],
    'stores-requisition-requests': ['requisitions.manage'],
    'stores-requisition-history': ['requisitions.manage'],
    'stores-stock': ['stores.stock.view'],
    'stores-quotations': ['stores.procurement.view'],
    'stores-lpos': ['stores.procurement.view'],
    'stores-grns': ['stores.grn.view'],
    'stores-history': ['stores.procurement.view'],
    'report-terminal': ['reports.view'],
    'report-master': ['reports.view'],
    'report-financial': ['reports.financial'],
    'report-investigation': ['reports.clinical'],
    'report-surgery': ['reports.clinical'],
    'mtuha-main': ['mtuha.view'],
    'mtuha-surgery': ['mtuha.view'],
    'mtuha-pediatric': ['mtuha.view'],
    'mtuha-glaucoma': ['mtuha.view'],
    'users-roles': ['users.view'],
    'settings-users': ['settings.view'],
    'price-list': ['prices.view'],
    'admin-reports': ['admin_reports.view'],
    'audit-log': ['audit.view'],
    'placeholder': [],
  };

  class ApiError extends Error {
    constructor(message, status, code, fields) {
      super(message);
      this.status = status;
      this.code = code;
      this.fields = fields || {};
    }
  }

  async function request(method, path, { body, query, trackActivity = true } = {}) {
    let url = basePath + 'api/' + String(path).replace(/^\/+/, '');
    if (query) {
      const params = new URLSearchParams();
      Object.entries(query).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') params.append(key, value);
      });
      const qs = params.toString();
      if (qs) url += '?' + qs;
    }
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['X-CSRF-Token'] = boot.csrf || '';

    let response;
    try {
      response = await fetch(url, {
        method, headers, credentials: 'same-origin', cache: 'no-store',
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      throw new ApiError('Cannot reach the server. Check the network connection and try again.', 0, 'network_error');
    }

    return handleResponse(response, trackActivity);
  }

  async function handleResponse(response, trackActivity = true) {
    let payload = null;
    try { payload = await response.json(); } catch { /* not JSON */ }
    const error = payload?.error || {};
    if (response.status === 401) {
      goToSignIn(error.code === 'session_expired');
    }
    if (!response.ok || !payload || payload.ok !== true) {
      throw new ApiError(error.message || `Request failed (${response.status}).`, response.status, error.code || 'error', error.fields);
    }
    if (trackActivity) activityListeners.forEach(fn => fn());
    return payload.data;
  }

  /** Multipart upload (FormData) with the CSRF token. */
  async function upload(path, formData) {
    let response;
    try {
      response = await fetch(basePath + 'api/' + String(path).replace(/^\/+/, ''), {
        method: 'POST', body: formData, credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-CSRF-Token': boot.csrf || '' },
      });
    } catch {
      throw new ApiError('Cannot reach the server. Check the network connection and try again.', 0, 'network_error');
    }
    return handleResponse(response);
  }

  /** Fetches a protected file as a Blob (the session cookie authorises it; nothing is cached). */
  async function fetchBlob(path) {
    let response;
    try {
      response = await fetch(basePath + 'api/' + String(path).replace(/^\/+/, ''), { credentials: 'same-origin', cache: 'no-store' });
    } catch {
      throw new ApiError('Cannot reach the server. Check the network connection and try again.', 0, 'network_error');
    }
    if (!response.ok) {
      await handleResponse(response, false);
    }
    const disposition = response.headers.get('Content-Disposition') || '';
    const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    const plain = disposition.match(/filename="([^"]+)"/i);
    const filename = encoded ? decodeURIComponent(encoded[1]) : (plain ? plain[1] : 'download');
    activityListeners.forEach(fn => fn());
    return { blob: await response.blob(), filename };
  }

  async function download(path) {
    const { blob, filename } = await fetchBlob(path);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  }

  /** Opens a protected file in a new tab (PDFs and images display in the browser). */
  async function openFile(path) {
    const tab = window.open('', '_blank');
    try {
      const { blob } = await fetchBlob(path);
      const url = URL.createObjectURL(blob);
      if (tab) tab.location.href = url; else window.location.href = url;
      setTimeout(() => URL.revokeObjectURL(url), 120000);
    } catch (error) {
      if (tab) tab.close();
      throw error;
    }
  }

  function goToSignIn(expired) {
    if (redirecting) return;
    redirecting = true;
    window.location.replace(basePath + (expired ? '?expired=1' : ''));
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"'`]/g, ch => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[ch]
    ));
  }

  const LOCAL_TZ = 'Africa/Dar_es_Salaam';
  const dateTimeFmt = new Intl.DateTimeFormat('en-GB', { timeZone: LOCAL_TZ, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
  const dateFmt = new Intl.DateTimeFormat('en-GB', { timeZone: LOCAL_TZ, day: '2-digit', month: 'short', year: 'numeric' });

  function fmtDateTime(iso) { return iso ? dateTimeFmt.format(new Date(iso)) : '—'; }
  function fmtDate(iso) { return iso ? dateFmt.format(new Date(iso)) : '—'; }
  function fmtMoney(amount) { return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }

  /** Marks inputs carrying data-field="<name>" inside container with the API's field errors. */
  /** Marks each field's message on the form; returns the messages that have no field to sit next to. */
  function showFieldErrors(container, fields) {
    clearFieldErrors(container);
    let first = null;
    const unplaced = [];
    Object.entries(fields || {}).forEach(([name, message]) => {
      const input = container.querySelector(`[data-field="${CSS.escape(name)}"]`);
      if (!input) {
        unplaced.push(message);
        return;
      }
      input.classList.add('is-invalid');
      const note = document.createElement('div');
      note.className = 'field-error';
      note.textContent = message;
      (input.closest('.form-group') || input.parentElement).appendChild(note);
      first = first || input;
    });
    if (first) {
      first.focus({ preventScroll: true });
      scrollToField(first._ssDisplay || first);
    }
    return unplaced;
  }

  /** Brings a field into view by scrolling only the panel it sits in, never the whole page. */
  function scrollToField(el) {
    for (let box = el.parentElement; box && box !== document.body; box = box.parentElement) {
      const overflow = getComputedStyle(box).overflowY;
      if ((overflow === 'auto' || overflow === 'scroll') && box.scrollHeight > box.clientHeight) {
        const offset = el.getBoundingClientRect().top - box.getBoundingClientRect().top;
        box.scrollTop += offset - box.clientHeight / 3;
        return;
      }
    }
    el.scrollIntoView({ block: 'nearest' });
  }

  function clearFieldErrors(container) {
    container.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    container.querySelectorAll('.field-error').forEach(el => el.remove());
  }

  /**
   * Shows an API error: each field's message beside its field, and in the toast any message
   * that has no field on this form (e.g. "the bill is not settled"), so a reason is never lost.
   */
  function reportError(error, container, title = 'Could not save') {
    const fields = Object.values(error.fields || {});
    const unplaced = container && fields.length ? showFieldErrors(container, error.fields) : fields;
    if (fields.length && !unplaced.length) return;
    if (typeof showToast === 'function') showToast(title, unplaced.length ? unplaced.join(' ') : error.message, 'error');
  }

  return {
    boot,
    user: boot.user || null,
    ApiError,
    get: (path, query, options = {}) => request('GET', path, { ...options, query }),
    post: (path, body = {}, options = {}) => request('POST', path, { ...options, body }),
    put: (path, body = {}, options = {}) => request('PUT', path, { ...options, body }),
    del: (path, options = {}) => request('DELETE', path, options),
    upload, download, openFile,
    onActivity: fn => activityListeners.push(fn),
    can: permission => permissions.has(permission),
    canAny: (...list) => list.some(p => permissions.has(p)),
    canModule: module => {
      const required = MODULE_PERMISSIONS[module];
      return !!required && (required.length === 0 || required.some(p => permissions.has(p)));
    },
    esc, fmtDateTime, fmtDate, fmtMoney,
    showFieldErrors, clearFieldErrors, reportError,
  };
})();
