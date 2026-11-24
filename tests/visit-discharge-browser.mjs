// On screen: the registration form keeps what was typed until it is saved (a stray click
// outside, closing it or moving to another screen loses nothing); End Visit and Re-admit
// from the visit window; the doctor's form shows the patient was re-admitted.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();
const icd = (await api.get('clinical/options')).icd10[0];
const test = (await api.get('clinical/options')).investigation_items[0];

async function doctorVisit(lastName, tail) {
  const patient = (await api.post('patients', {
    first_name: 'Screen', last_name: `${lastName}${run}`, gender: 'male', date_of_birth: '1961-01-01',
    phone: `25578${run}${tail}`, address: 'Tegeta', kin_name: 'Bi Zainabu', kin_relationship: 'Spouse', kin_phone: `25570${run}${tail}`,
  })).patient;
  const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
  const [billId, version] = api.sql(`SELECT id, row_version FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`).split('\t');
  await api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(version) });
  return { patient, visit };
}
// Waiting for a test booked today, so it has something outstanding.
const waiting = await doctorVisit('Waiting', 1);
await api.post(`ophthalmology/visits/${waiting.visit.id}/claim`);
await api.post(`ophthalmology/visits/${waiting.visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  investigations: [{ price_item_id: test.id, eye: 'right', priority: 'routine', reason: 'Fields' }],
});

await launch(9541);
await signIn();
const isOpen = id => evaluate(`document.getElementById('${id}').classList.contains('open')`);

// ─── Registration draft ───────────────────────────────────────────────────
await evaluate(`showModule('manage-patient')`);
await waitUntil(`!!document.getElementById('pt-new-btn') && !document.getElementById('module-manage-patient').classList.contains('hidden')`, 10000, 'patients screen');
await evaluate(`ptOpenRegister()`);
await waitUntil(`document.getElementById('modal-patient-form').classList.contains('open')`, 5000, 'register form');
await evaluate(`(() => {
  const set = (name, value) => { const el = document.querySelector('#pf-form [data-field="' + name + '"]'); el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); };
  set('first_name', 'Halima'); set('last_name', 'Draft${run}'); set('phone', '0712 ${run}');
  const g = document.querySelector('#pf-form [data-field="gender"]'); g.value = 'female'; g.dispatchEvent(new Event('change', { bubbles: true }));
})()`);
// A stray click on the dark background used to close the form.
await evaluate(`document.getElementById('modal-patient-form').dispatchEvent(new MouseEvent('click', { bubbles: true }))`);
check('a click outside does not close the registration form', await isOpen('modal-patient-form'));
await evaluate(`closeModal('modal-patient-form')`);
await evaluate(`showModule('manage-visit')`);
await sleep(600);
await evaluate(`showModule('manage-patient')`);
await sleep(600);
check('the New Patient button offers to continue', /Continue Registration/.test(await evaluate(`document.getElementById('pt-new-btn').textContent`)));
await evaluate(`ptOpenRegister()`);
await waitUntil(`document.getElementById('modal-patient-form').classList.contains('open')`, 5000, 'register form again');
equal('what was typed is back after closing and changing screens',
  await evaluate(`['first_name', 'last_name', 'phone', 'gender'].map(n => document.querySelector('#pf-form [data-field="' + n + '"]').value)`),
  ['Halima', `Draft${run}`, `0712 ${run}`, 'female']);
check('with a note saying so', await evaluate(`getComputedStyle(document.getElementById('pf-draft-note')).display !== 'none'`));
await shot('registration-draft', SHOTS);
check('leaving the page would ask first', await evaluate(`(() => { const e = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; })()`));
await evaluate(`ptDiscardDraft()`);
equal('Start over empties the form', await evaluate(`document.querySelector('#pf-form [data-field="first_name"]').value`), '');
check('and the button is New Patient again', /New Patient/.test(await evaluate(`document.getElementById('pt-new-btn').textContent`)));
check('nothing is kept in the browser\'s storage', await evaluate(`!JSON.stringify(localStorage).includes('Halima') && !JSON.stringify(sessionStorage).includes('Halima')`));
await evaluate(`closeModal('modal-patient-form')`);

// ─── End Visit ────────────────────────────────────────────────────────────
await evaluate(`showModule('manage-visit')`);
await sleep(800);
await evaluate(`vsOpenVisit(${waiting.visit.id})`);
await waitUntil(`document.getElementById('vd-title').textContent.includes(${JSON.stringify(waiting.visit.visit_no)})`, 8000, 'visit window');
await sleep(300);
const body = await evaluate(`document.getElementById('vd-body').textContent`);
check('the visit window lists what is still to be done today', /Still to be done today/.test(body) && body.includes(test.name), body.slice(0, 300));
check('the status reads Awaiting Other Services', body.includes('Awaiting Other Services'));
check('End Visit and Re-admit are offered', await evaluate(`document.getElementById('vd-end-btn').style.display !== 'none' && document.getElementById('vd-readmit-btn').style.display !== 'none'`));
await shot('visit-awaiting-services', SHOTS);
await evaluate(`vsOpenEnd()`);
await waitUntil(`document.getElementById('modal-end-visit').classList.contains('open')`, 5000, 'end visit window');
check('the end window repeats what stays open', (await evaluate(`document.getElementById('ev-outstanding').textContent`)).includes(test.name));
await evaluate(`vsConfirmEnd()`);
await sleep(800);
const reasonError = await evaluate(`document.querySelector('#ev-form .field-error')?.textContent || ''`);
check('without a reason it is refused beside the box', reasonError !== '' && await evaluate(`document.getElementById('modal-end-visit').classList.contains('open')`), reasonError);
await evaluate(`document.getElementById('ev-reason').value = 'Patient went home; will come back for the test'`);
await evaluate(`vsConfirmEnd()`);
await waitUntil(`!document.getElementById('modal-end-visit').classList.contains('open')`, 8000, 'end window closes');
equal('the visit is discharged', api.sql(`SELECT status FROM visits WHERE id = ${waiting.visit.id}`), 'completed');

// ─── Re-admit ─────────────────────────────────────────────────────────────
await evaluate(`vsOpenVisit(${waiting.visit.id})`);
await waitUntil(`document.getElementById('vd-body').textContent.includes('Discharged')`, 8000, 'discharged visit');
check('the window says who ended it and why', (await evaluate(`document.getElementById('vd-body').textContent`)).includes('Patient went home; will come back for the test'));
check('End Visit is gone; Re-admit is offered', await evaluate(`document.getElementById('vd-end-btn').style.display === 'none' && document.getElementById('vd-readmit-btn').style.display !== 'none'`));
await evaluate(`vsOpenReadmit()`);
await waitUntil(`document.getElementById('modal-readmit-visit').classList.contains('open')`, 5000, 'readmit window');
await shot('readmit-window', SHOTS);
await evaluate(`document.getElementById('ra-reason').value = 'Back with the result for the doctor'`);
await evaluate(`vsConfirmReadmit()`);
await waitUntil(`!document.getElementById('modal-readmit-visit').classList.contains('open')`, 8000, 'readmit closes');
equal('the visit waits for the doctor again', api.sql(`SELECT status FROM visits WHERE id = ${waiting.visit.id}`), 'waiting_ophthalmology');

// The doctor opens the patient: the same consultation, editable, marked re-admitted.
await evaluate(`showModule('ophthalmology')`);
await sleep(1200);
await api.post(`ophthalmology/visits/${waiting.visit.id}/claim`);
const banner = await evaluate(`(async () => { const ws = await KSI.get('ophthalmology/visits/${waiting.visit.id}'); return clBanner(ws.visit) + '|' + ws.mode; })()`);
check('the doctor\'s banner shows the re-admission and its reason', banner.includes('Re-admitted') && banner.includes('Back with the result for the doctor'), banner.slice(0, 200));
check('and the consultation is open for editing', banner.endsWith('|edit'));

await finish('visit discharge browser', SHOTS);
