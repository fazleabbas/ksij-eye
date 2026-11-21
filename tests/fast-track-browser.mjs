// Fast track on screen: the follow-up choice at Reception, the cashier upgrade, the badges.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);

await api.signIn();
const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Quick${run}`, gender: 'female', date_of_birth: '1969-09-09',
  phone: `25575${run}1`, address: 'Mbezi, Dar es Salaam', kin_name: 'Bw Hamisi', kin_relationship: 'Spouse', kin_phone: `25575${run}2`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'triage' })).visit;
const bill = await api.pendingBill(b => b.visit_no === visit.visit_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
// As the patient search returns them.
const found = (await api.get('patients?' + new URLSearchParams({ search: patient.reg_no }))).patients.find(p => p.id === patient.id);

await launch(9455);
await signIn();

// ─── Reception: follow-ups get a fast-track tick ──────────────────────────
await evaluate(`showModule('manage-visit')`);
await sleep(500);
await evaluate(`vsOpenNewVisit(${JSON.stringify(found)})`);
await waitUntil(`document.getElementById('modal-new-visit').classList.contains('open') || !!document.querySelector('#modal-new-visit.active, .modal-overlay.open#modal-new-visit')`, 8000, 'new visit').catch(() => {});
const shown = await evaluate(`(() => {
  uiSetRadio('nv-visit-type', 'consultation'); vsOnTypeChange();
  const consultation = document.getElementById('nv-fast-track-group').style.display;
  uiSetRadio('nv-visit-type', 'follow_up'); vsOnTypeChange();
  return { consultation, followUp: document.getElementById('nv-fast-track-group').style.display };
})()`);
check('a consultation chooses fast track as its sub-type (no extra tick)', shown.consultation === 'none');
check('a follow-up shows the fast-track tick', shown.followUp === '');
await evaluate(`(() => { uiSetRadio('nv-destination', 'triage'); document.getElementById('nv-fast-track').checked = true; vsOnFormChange(); })()`);
await waitUntil(`document.getElementById('nv-fee').textContent.includes('Fast Track')`, 8000, 'fast-track fee');
check('the fee shows the follow-up fast-track price', await evaluate(`document.getElementById('nv-fee').textContent.includes('20,000')`),
  await evaluate(`document.getElementById('nv-fee').textContent`));
await shot('reception-follow-up-fast-track', SHOTS);
await evaluate(`closeModal('modal-new-visit')`);

// ─── Cashier: fast track a visit ──────────────────────────────────────────
await evaluate(`showModule('cashier-payments')`);
await waitUntil(`document.getElementById('cs-fast-track-btn').style.display === ''`, 10000, 'fast track button');
check('Manage Payments has "Fast Track a Visit"', true);
await evaluate(`(async () => { csOpenFastTrack(); await csFastTrackPatient(${JSON.stringify(found)}); return true; })()`);
await waitUntil(`!!document.querySelector('#ft-visits input[name="ft-visit"]')`, 10000, 'visit list');
await evaluate(`(() => { const r = document.querySelector('#ft-visits input[value="${visit.id}"]'); r.checked = true; r.dispatchEvent(new Event('change')); })()`);
await waitUntil(`document.getElementById('ft-quote').textContent.includes('To pay now')`, 10000, 'quote');
const quoteText = await evaluate(`document.getElementById('ft-quote').textContent.replace(/\\s+/g, ' ')`);
check('it shows the fast-track price, what was charged and the balance', quoteText.includes('40,000') && quoteText.includes('25,000') && quoteText.includes('15,000'), quoteText);
await shot('cashier-fast-track', SHOTS);
await evaluate(`csConfirmFastTrack()`);
await waitUntil(`document.getElementById('modal-process-payment').classList.contains('open') || document.getElementById('pp-subtitle').textContent.length > 0`, 10000, 'payment opens');
check('confirming opens the payment for the balance', await evaluate(`document.getElementById('pp-amount').textContent.includes('15,000')`),
  await evaluate(`document.getElementById('pp-amount').textContent`));
await evaluate(`(() => { uiSetRadio('pp-method', 'cash'); csOnMethodChange(); return csConfirmPayment(); })()`);
await sleep(1500);
check('paid: the visit is fast track', api.sql(`SELECT is_fast_track FROM visits WHERE id = ${visit.id}`) === '1');

// ─── Triage queue shows the badge ─────────────────────────────────────────
await evaluate(`showModule('triage')`);
await waitUntil(`!!document.querySelector('.tq-card[data-visit="${visit.id}"]')`, 15000, 'triage queue');
check('the triage queue card is marked FAST TRACK', await evaluate(`!!document.querySelector('.tq-card[data-visit="${visit.id}"] .tq-card-tag.fast-track')`));
await shot('triage-fast-track', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('fast track browser', SHOTS);
