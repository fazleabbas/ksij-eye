'use strict';
// ─── PATIENT HISTORY ──────────────────────────────────────────────────────
// Any registered patient's past visits, read-only: the same History / Orders /
// Scanned panels the doctor sees beside a consultation, but for every visit and
// without the patient having to be in today's queue. Each opening is logged.

const PHIST = { patient: null, history: null, tab: 'visits', pickerReady: false };

function histOpen() {
  if (!PHIST.pickerReady) {
    PHIST.pickerReady = true;
    uiPatientPicker('ph-search', 'ph-results', patient => histSelect(patient));
  }
  setTimeout(() => document.getElementById('ph-search')?.focus(), 50);
}

/** Opens a patient's history; also used by the queue's "not in this queue" link. */
async function histSelect(patient) {
  PHIST.patient = patient;
  PHIST.history = null;
  document.getElementById('ph-search').value = patient.full_name;
  document.getElementById('ph-results').innerHTML = '';
  document.getElementById('ph-patient').innerHTML = uiPatientCard(patient);
  document.getElementById('ph-panel').style.display = '';
  await histTab('visits');
}

async function histTab(tab) {
  PHIST.tab = tab;
  document.querySelectorAll('#ph-tabs .ophtho-right-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  const patientId = PHIST.patient?.id;
  if (!patientId) return;
  const body = document.getElementById('ph-body');
  if (tab === 'files') {
    body.innerHTML = '<div class="cl-queue-empty">Loading…</div>';
    const html = await clFilesHtml(patientId);
    if (PHIST.patient?.id === patientId && PHIST.tab === 'files') body.innerHTML = html;
    return;
  }
  if (!PHIST.history) {
    const data = await clLoadHistory('ph-body', patientId, 0, true);
    if (PHIST.patient?.id !== patientId) return;
    PHIST.history = data;
  }
  if (!PHIST.history || PHIST.tab !== tab) return;
  body.innerHTML = tab === 'orders' ? clOrdersHtml(PHIST.history) : clHistoryHtml(PHIST.history);
}
