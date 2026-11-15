'use strict';
// ─── ADMINISTRATION · AUDIT LOG ────────────────────────────────────────────
// Read-only, paginated view of the server's append-only audit trail.
const AUDIT_UI = { page: 1, perPage: 50, total: 0, timer: null };

async function renderAuditLogModule() {
  try {
    const filters = await KSI.get('audit/filters');
    admFillSelect('al-module-filter', 'All Modules', filters.modules.map(m => [m, m]));
    admFillSelect('al-user-filter', 'All Users', filters.users.map(u => [u.id, u.name]));
  } catch {
    // filters are a convenience; the log still loads
  }
  AUDIT_UI.page = 1;
  await loadAuditLog();
}

function onAuditFilter(debounce) {
  clearTimeout(AUDIT_UI.timer);
  AUDIT_UI.timer = setTimeout(() => {
    AUDIT_UI.page = 1;
    loadAuditLog();
  }, debounce ? 300 : 0);
}

function auditPage(delta) {
  const pages = Math.max(1, Math.ceil(AUDIT_UI.total / AUDIT_UI.perPage));
  const next = Math.min(pages, Math.max(1, AUDIT_UI.page + delta));
  if (next !== AUDIT_UI.page) {
    AUDIT_UI.page = next;
    loadAuditLog();
  }
}

function auditChanges(changes) {
  if (!changes || typeof changes !== 'object') return '';
  const show = value => Array.isArray(value) ? (value.join(', ') || '—') : (value === null || value === '' ? '—' : String(value));
  const lines = Object.entries(changes).map(([field, change]) => {
    if (change && typeof change === 'object' && !Array.isArray(change)) {
      if ('changed' in change) return `${field}: changed`;
      if ('from' in change) return `${field}: ${show(change.from)} → ${show(change.to)}`;
      if ('to' in change) return `${field}: ${show(change.to)}`;
      return `${field}: ${JSON.stringify(change)}`;
    }
    return `${field}: ${show(change)}`;
  });
  return lines.length ? `<div class="audit-changes">${KSI.esc(lines.join('\n'))}</div>` : '';
}

async function loadAuditLog() {
  admTableMessage('al-tbody', 6, 'Loading…');
  try {
    const data = await KSI.get('audit', {
      search: document.getElementById('al-search').value.trim(),
      module: document.getElementById('al-module-filter').value,
      user_id: document.getElementById('al-user-filter').value,
      from: document.getElementById('al-from').value,
      to: document.getElementById('al-to').value,
      page: AUDIT_UI.page,
      per_page: AUDIT_UI.perPage,
    });
    AUDIT_UI.total = data.total;
    admStatCards('al-stats-grid', [
      { label: 'Total Log Entries', value: data.stats.total, color: 'teal', icon: 'clock' },
      { label: "Today's Activity", value: data.stats.today, color: 'blue', icon: 'check' },
      { label: 'Distinct Users', value: data.stats.users, color: 'purple', icon: 'users' },
    ]);

    if (!data.entries.length) {
      admTableMessage('al-tbody', 6, 'No audit entries match the filters.');
    } else {
      document.getElementById('al-tbody').innerHTML = data.entries.map(entry => `<tr>
        <td style="font-size:12px;color:var(--text-3);white-space:nowrap">${KSI.fmtDateTime(entry.occurred_at)}</td>
        <td>
          <div class="patient-name" style="font-size:13px">${KSI.esc(entry.user_display || entry.username || 'System')}</div>
          ${entry.username ? `<div class="sub-text">${KSI.esc(entry.username)}</div>` : ''}
        </td>
        <td><span class="badge badge-normal">${KSI.esc(entry.module)}</span></td>
        <td style="font-size:12px;font-weight:600;font-family:var(--font-mono);white-space:nowrap">${KSI.esc(entry.action)}</td>
        <td style="font-size:12px;color:var(--text-2);min-width:240px">${KSI.esc(entry.summary || '—')}${auditChanges(entry.changes)}</td>
        <td style="font-size:12px;font-family:var(--font-mono);color:var(--text-3)">${KSI.esc(entry.ip_address || '—')}</td>
      </tr>`).join('');
    }

    const pages = Math.max(1, Math.ceil(data.total / data.per_page));
    const first = data.total ? (data.page - 1) * data.per_page + 1 : 0;
    const last = Math.min(data.total, data.page * data.per_page);
    document.getElementById('al-count').textContent = `${first}–${last} of ${data.total} entr${data.total === 1 ? 'y' : 'ies'}`;
    document.getElementById('al-page-label').textContent = `Page ${data.page} of ${pages}`;
    document.getElementById('al-prev').disabled = data.page <= 1;
    document.getElementById('al-next').disabled = data.page >= pages;
  } catch (error) {
    admTableMessage('al-tbody', 6, error.message);
  }
}
