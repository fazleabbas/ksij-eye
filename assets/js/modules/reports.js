'use strict';
// ─── REPORTS ──────────────────────────────────────────────────────────────
// One screen for every report. The server decides which reports a person may
// see, works out the figures, and returns the same shape each time — summary
// figures, an optional grouped panel, then the rows.

const RP = {
  options: null,
  report: null,
  type: null,
  request: 0,
};

/** Opens the reports screen, on a given report when a menu entry asks for one (e.g. the MTUHA registers). */
async function rpOpen(type = null) {
  if (!RP.options) {
    RP.options = await KSI.get('reports/options');
    admFillSelect('rp-type', '', RP.options.reports.map(r => [r.type, r.title]));
    admFillSelect('rp-scheme', 'All discount schemes', (RP.options.schemes || []).map(s => [s, s]));
    RP.type = RP.options.reports[0]?.type || null;
    document.getElementById('rp-type').value = RP.type || '';
    rpSetDefaultDates();
  }
  if (type && type !== RP.type && RP.options.reports.some(r => r.type === type)) {
    document.getElementById('rp-type').value = type;
    rpTypeChanged();
    return;
  }
  await rpLoad();
}

function rpDefinition() {
  return (RP.options?.reports || []).find(r => r.type === RP.type);
}

function rpSetDefaultDates() {
  // Every report opens on today (clinic decision); the window stays configurable per report.
  const days = rpDefinition()?.days ?? 0;
  const to = RP.options.today;
  // Counted in UTC so the browser's time zone cannot shift the date by a day.
  const from = new Date(new Date(to + 'T00:00:00Z').getTime() - days * 86400000).toISOString().slice(0, 10);
  document.getElementById('rp-from').value = from;
  document.getElementById('rp-to').value = to;
}

function rpTypeChanged() {
  RP.type = document.getElementById('rp-type').value;
  rpSetDefaultDates();
  document.getElementById('rp-search').value = '';
  rpShowFilters();
  rpLoad();
}

/** Only show the filters a report actually uses. */
function rpShowFilters() {
  const statuses = RP.options?.statuses?.[RP.type] || [];
  const status = document.getElementById('rp-status');
  status.style.display = statuses.length ? '' : 'none';
  if (statuses.length && status.dataset.type !== RP.type) {
    admFillSelect('rp-status', 'All statuses', statuses.map(s => [s, uiLabel(rpStatusGroup(), s)]));
    status.dataset.type = RP.type;
  }
  // The discount filters narrow the Financial Summary's discount tables.
  document.getElementById('rp-scheme').style.display = RP.type === 'financial' ? '' : 'none';
  document.getElementById('rp-max-age').style.display = RP.type === 'financial' ? '' : 'none';
}

function rpStatusGroup() {
  return {
    financial: 'billStatus', reception: 'visitStatus', diagnostics: 'diagnosticStatus',
    theatre: 'theatreStatus', optical: 'opticalStatus', pharmacy: 'pharmacyStatus', mtuha_main: 'mtuhaOutcome',
  }[RP.type] || '';
}

function rpQuery() {
  const value = id => document.getElementById(id)?.value || '';
  const query = { type: RP.type, from: value('rp-from'), to: value('rp-to'), search: value('rp-search') };
  if (document.getElementById('rp-status').style.display !== 'none') query.status = value('rp-status');
  if (RP.type === 'financial') {
    query.scheme = value('rp-scheme');
    query.max_age = value('rp-max-age');
  }
  return query;
}

function rpReset() {
  document.getElementById('rp-search').value = '';
  document.getElementById('rp-status').value = '';
  document.getElementById('rp-scheme').value = '';
  document.getElementById('rp-max-age').value = '';
  rpSetDefaultDates();
  rpLoad();
}

async function rpLoad() {
  if (!RP.type || !document.getElementById('rp-body')) return;
  rpShowFilters();
  admTableMessage('rp-body', 6, 'Working out the figures…');
  // Reports take a while to work out, so a slow answer must never overwrite a newer one.
  const request = ++RP.request;
  try {
    const { report } = await KSI.get('reports/run', rpQuery(), { trackActivity: false });
    if (request !== RP.request) return;
    RP.report = report;
    rpRender();
  } catch (error) {
    if (request !== RP.request) return;
    admTableMessage('rp-body', 6, 'Could not produce this report.');
    KSI.reportError(error, null, 'Could not produce this report');
  }
}

function rpCell(row, column) {
  const value = row[column.key];
  if (value === null || value === undefined || value === '') return '—';
  if (column.money) return KSI.fmtMoney(value);
  if (column.label_group) return KSI.esc(uiLabel(column.label_group, value));
  return KSI.esc(String(value));
}

/**
 * Header rows for a table. Columns that share a group (Visual acuity → RE / LE) get a
 * second row; the others span both, so the cells still line up with the data.
 */
function rpHeadHtml(columns) {
  if (!columns.some(c => c.group)) return `<tr>${columns.map(c => `<th>${KSI.esc(c.label)}</th>`).join('')}</tr>`;
  const top = [];
  columns.forEach(c => {
    const last = top[top.length - 1];
    if (c.group && last?.group === c.group) last.span++;
    else top.push({ group: c.group, label: c.group || c.label, span: 1 });
  });
  return `<tr>${top.map(t => t.group
      ? `<th colspan="${t.span}" style="text-align:center">${KSI.esc(t.label)}</th>`
      : `<th rowspan="2">${KSI.esc(t.label)}</th>`).join('')}</tr>
    <tr>${columns.filter(c => c.group).map(c => `<th style="text-align:center">${KSI.esc(c.label)}</th>`).join('')}</tr>`;
}

function rpRender() {
  const report = RP.report;
  document.getElementById('rp-crumb').textContent = report.title;
  document.getElementById('rp-period').textContent = `${report.from} to ${report.to}`;
  document.getElementById('rp-count').textContent = `${report.rows.length} row${report.rows.length === 1 ? '' : 's'}`;

  document.getElementById('rp-kpis').innerHTML = report.kpis.map(k => `
    <div class="stat-card">
      <div class="stat-num" style="font-size:${k.money ? '16px' : '20px'}">${k.money ? 'TZS ' + KSI.fmtMoney(k.value) : KSI.esc(String(k.value))}</div>
      <div class="stat-label">${KSI.esc(k.label)}</div>
    </div>`).join('');

  document.getElementById('rp-summary').innerHTML = rpSummaryTables(report).map(table => `
    <div class="card" style="margin-bottom:14px">
      <div class="card-head"><div class="card-title">${KSI.esc(table.title)}</div></div>
      <div class="table-wrap">
        <table>
          <thead><tr>${table.columns.map(c => `<th>${KSI.esc(c.label)}</th>`).join('')}</tr></thead>
          <tbody>${table.rows.length ? rpSummaryRows(table) : `<tr><td colspan="${table.columns.length}" class="muted-note">Nothing in this period.</td></tr>`}</tbody>
        </table>
      </div>
    </div>`).join('');

  document.getElementById('rp-head').innerHTML = rpHeadHtml(report.columns);
  if (!report.rows.length) {
    admTableMessage('rp-body', report.columns.length, 'Nothing to report for this period.');
    return;
  }
  document.getElementById('rp-body').innerHTML = report.rows.map(row =>
    `<tr>${report.columns.map(c => `<td class="${c.money ? 'money' : ''}">${rpCell(row, c)}</td>`).join('')}</tr>`).join('');
}

/** The summary panel, then any further tables (the Financial Summary's discounts). */
function rpSummaryTables(report) {
  return [...(report.summary ? [report.summary] : []), ...(report.summaries || [])];
}

/** Summary rows; a row with a level is a total (0, bold) or an item under it (1, indented). */
function rpSummaryRows(summary) {
  return summary.rows.map(row => {
    const style = row.level === 0 ? 'font-weight:700' : row.level === 1 ? 'color:var(--text-2)' : '';
    return `<tr style="${style}">${summary.columns.map((c, i) =>
      `<td class="${c.money ? 'money' : ''}"${i === 0 && row.level === 1 ? ' style="padding-left:28px"' : ''}>${rpCell(row, c)}</td>`).join('')}</tr>`;
  }).join('');
}

/** The export is produced by the server, so it carries the summary figures too. */
function rpExport() {
  const query = new URLSearchParams(rpQuery()).toString();
  KSI.download(`reports/export?${query}`);
}

function rpPrint() {
  const report = RP.report;
  if (!report) return;
  optPrintWindow(report.title, `
    <h1>${KSI.esc(report.title)}</h1>
    <p>${KSI.esc(report.from)} to ${KSI.esc(report.to)}</p>
    <table class="facts">
      <tr>${report.kpis.map(k => `<th>${KSI.esc(k.label)}</th>`).join('')}</tr>
      <tr>${report.kpis.map(k => `<td>${k.money ? 'TZS ' + KSI.fmtMoney(k.value) : KSI.esc(String(k.value))}</td>`).join('')}</tr>
    </table>
    ${rpSummaryTables(report).map(table => `<h2>${KSI.esc(table.title)}</h2>
      <table>
        <thead><tr>${table.columns.map(c => `<th>${KSI.esc(c.label)}</th>`).join('')}</tr></thead>
        <tbody>${rpSummaryRows(table)}</tbody>
      </table>`).join('')}
    <h2>Details</h2>
    <table>
      <thead>${rpHeadHtml(report.columns)}</thead>
      <tbody>${report.rows.map(row => `<tr>${report.columns.map(c => `<td>${rpCell(row, c)}</td>`).join('')}</tr>`).join('')}</tbody>
    </table>`);
}

// ─── DASHBOARD ────────────────────────────────────────────────────────────

async function dbLoad() {
  if (!document.getElementById('db-tiles')) return;
  try {
    const data = await KSI.get('dashboard', {}, { trackActivity: false });
    dbRender(data);
  } catch (error) {
    document.getElementById('db-tiles').innerHTML = '<div class="muted-note">Could not load the dashboard.</div>';
  }
}

function dbRender(data) {
  document.getElementById('db-welcome').textContent =
    `${KSI.user?.name ? KSI.user.name + ' · ' : ''}${KSI.fmtDate(data.today + 'T00:00:00Z')}`;

  document.getElementById('db-alerts').innerHTML = data.alerts.length
    ? data.alerts.map(a => `<div class="alert alert-warning" style="cursor:pointer" onclick="showModule('${a.module}')">${KSI.esc(a.text)}</div>`).join('')
    : '';

  document.getElementById('db-tiles').innerHTML = data.tiles.map(t => `
    <div class="stat-card" style="cursor:pointer" onclick="showModule('${t.module}')">
      <div class="stat-num" style="font-size:${t.money ? '16px' : '20px'};${t.alert && t.value > 0 ? 'color:var(--red)' : ''}">
        ${t.money ? 'TZS ' + KSI.fmtMoney(t.value) : KSI.esc(String(t.value))}
      </div>
      <div class="stat-label">${KSI.esc(t.label)}</div>
    </div>`).join('') || '<div class="muted-note">Nothing to show for your role.</div>';

  const queueCount = document.getElementById('db-queue-count');
  if (queueCount) queueCount.textContent = `${data.queue.length} patient${data.queue.length === 1 ? '' : 's'}`;
  const queue = document.getElementById('db-queue');
  if (queue) {
    queue.innerHTML = data.queue.length
      ? data.queue.map(v => `<tr>
          <td>
            <div style="font-weight:600">${KSI.esc(v.patient.full_name)}${v.is_emergency ? ' <span class="badge badge-cancel-req">Emergency</span>' : ''}</div>
            <div class="muted-note">${KSI.esc(v.patient.reg_no)}</div>
          </td>
          <td>${KSI.esc(v.visit_no)}</td>
          <td>${KSI.esc(uiLabel('destination', v.destination))}${v.room ? ` · ${KSI.esc(v.room)}` : ''}</td>
          <td>${KSI.fmtDateTime(v.arrived)}</td>
          <td>${uiBadge('visitStatus', v.status)}</td>
        </tr>`).join('')
      : '<tr class="table-state"><td colspan="5">Nobody is waiting.</td></tr>';
  }

  document.getElementById('db-waiting').innerHTML = data.waiting.length
    ? data.waiting.map(section => `
        <div class="db-section" onclick="showModule('${section.module}')">
          <div class="db-section-title">${KSI.esc(section.title)}</div>
          ${section.lines.map(line => `<div class="db-line">
            <span>${KSI.esc(line.label)}</span>
            <strong class="${line.value > 0 ? 'db-count' : 'muted-note'}">${line.value}</strong>
          </div>`).join('')}
        </div>`).join('')
    : '<div class="muted-note" style="padding:14px">Nothing is waiting for you.</div>';
}
