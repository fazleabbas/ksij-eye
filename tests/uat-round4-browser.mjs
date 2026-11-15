// UAT round 4 on screen: the Optometry destination at Reception, the doctor's form in the
// clinic's order with Clinical History and "Refer to Optometry", the Optometry screen after a
// doctor's referral, and the discount tables inside the Financial Summary.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);

await api.signIn();
const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Spectacles${run}`, gender: 'male', date_of_birth: '2017-04-04',
  phone: `25576${run}1`, address: 'Ilala, Dar es Salaam', kin_name: 'Mama Zuhura', kin_relationship: 'Parent', kin_phone: `25576${run}2`,
})).patient;
const found = (await api.get('patients?' + new URLSearchParams({ search: patient.reg_no }))).patients.find(p => p.id === patient.id);
// A visit for the doctor, who will refer the patient on to Optometry.
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
const bill = (await api.get('bills?status=pending')).bills.find(b => b.visit_no === visit.visit_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
await api.post(`ophthalmology/visits/${visit.id}/claim`);

await launch(9461);
await signIn();

// ─── Reception: Optometry destination with its own rooms ──────────────────
await evaluate(`showModule('manage-visit')`);
await sleep(500);
await evaluate(`vsOpenNewVisit(${JSON.stringify(found)})`);
await sleep(400);
const reception = await evaluate(`(() => {
  uiSetRadio('nv-visit-type', 'consultation'); vsOnTypeChange();
  uiSetRadio('nv-destination', 'optometry'); vsOnFormChange();
  const optometry = { shown: document.getElementById('nv-room-group').style.display, label: document.getElementById('nv-room-label').textContent,
    rooms: [...document.querySelectorAll('#nv-room option')].map(o => o.value).filter(Boolean) };
  uiSetRadio('nv-destination', 'doctor_room'); vsOnFormChange();
  const doctor = [...document.querySelectorAll('#nv-room option')].map(o => o.value).filter(Boolean);
  uiSetRadio('nv-destination', 'optometry'); vsOnFormChange();
  return { optometry, doctor };
})()`);
check('reception offers Optometry as a destination', await evaluate(`!!document.querySelector('input[name="nv-destination"][value="optometry"]')`));
check('which asks for the optometry room', reception.optometry.shown === '' && reception.optometry.label.includes('Optometry Room'), JSON.stringify(reception.optometry));
equal('from the optometry rooms list', reception.optometry.rooms, ['Optometry Room 1', 'Optometry Room 2']);
check('the Doctor Room keeps its own rooms', reception.doctor.includes('Room 1') && !reception.doctor.includes('Optometry Room 1'));
await evaluate(`(() => { document.getElementById('nv-room').value = 'Optometry Room 2'; document.getElementById('nv-room').dispatchEvent(new Event('change')); })()`);
await shot('reception-optometry-destination', SHOTS);
await evaluate(`vsSaveVisit(true)`); // true: the patient already has an open visit with the doctor
await waitUntil(`!document.getElementById('modal-new-visit').classList.contains('open')`, 10000, 'visit saved').catch(() => {});
await sleep(800);
equal('the visit is saved for Optometry Room 2',
  api.sql(`SELECT CONCAT(destination, '|', room) FROM visits WHERE patient_id = ${patient.id} AND destination = 'optometry'`), 'optometry|Optometry Room 2');

// ─── Doctor: the form in the clinic's order ───────────────────────────────
await evaluate(`showModule('ophthalmology')`);
await waitUntil(`!!document.querySelector('#oph-q-list .tq-card[data-visit="${visit.id}"]')`, 15000, 'doctor queue');
await evaluate(`OPH.select(${visit.id})`);
await waitUntil(`document.getElementById('oph-form').style.display === '' && document.getElementById('oph-mode').textContent.includes('seeing this patient')`, 15000, 'consultation open');
const steps = await evaluate(`[...document.querySelectorAll('#oph-form .oph-step')].map(s => s.textContent.replace(/^\\d+/, '').trim())`);
equal('the sections follow the clinic\'s order', steps,
  ['Clinical History', 'Examination', 'Investigations', 'Provisional and Final Diagnosis', 'Medicine', 'Surgery', 'Referral']);
const order = await evaluate(`(() => {
  const all = [...document.querySelectorAll('#oph-form .oph-step, #oph-form [data-field="clinical_history"], #oph-exam, #oph-inv-card, #oph-dx-final, #oph-rx, #oph-surg-card, #oph-proc-card, #oph-form [data-field="refer_optometry"]')];
  return all.map(el => el.id || el.dataset.field || 'step');
})()`);
check('each part sits under its section', JSON.stringify(order) === JSON.stringify(
  ['step', 'clinical_history', 'step', 'oph-exam', 'step', 'oph-inv-card', 'step', 'oph-dx-final', 'step', 'oph-rx', 'step', 'oph-surg-card', 'oph-proc-card', 'step', 'refer_optometry']), JSON.stringify(order));
await evaluate(`(() => {
  const f = document.getElementById('oph-form');
  f.querySelector('[data-field="clinical_history"]').value = 'Cannot see the board at school';
  f.querySelector('[data-field="refer_optometry"]').checked = true;
})()`);
const collected = await evaluate(`(() => { const c = clCollect(document.getElementById('oph-form')); return { h: c.clinical_history, r: c.refer_optometry }; })()`);
equal('the history and the referral are sent with the form', collected, { h: 'Cannot see the board at school', r: true });
await shot('ophthalmology-sections', SHOTS);

// Complete it through the API (diagnosis chips are covered elsewhere), then reopen on screen.
const icd = (await api.get('clinical/options')).icd10[0];
await api.post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id], clinical_history: 'Cannot see the board at school', refer_optometry: true,
});
await evaluate(`OPH.reload()`);
await sleep(1200);
check('after completing, the referral can no longer be changed (routing)',
  await evaluate(`document.querySelector('#oph-form [data-field="refer_optometry"]').disabled && document.querySelector('#oph-form [data-field="refer_optometry"]').checked`));

// ─── Optometry: referred by the doctor ────────────────────────────────────
await evaluate(`showModule('optometry')`);
await waitUntil(`!!document.querySelector('#op-q-list .tq-card[data-visit="${visit.id}"]')`, 15000, 'optometry queue');
check('the Optometry queue says the doctor sent the patient', await evaluate(`document.querySelector('#op-q-list .tq-card[data-visit="${visit.id}"]').textContent.includes('Doctor')`));
await api.post(`optometry/visits/${visit.id}/claim`);
await evaluate(`OPTO.select(${visit.id})`);
await waitUntil(`document.getElementById('op-form').style.display === '' && document.getElementById('op-upstream').textContent.length > 0`, 15000, 'optometry open');
const opto = await evaluate(`({
  note: document.getElementById('op-upstream').textContent,
  history: document.getElementById('op-upstream').textContent.includes('Cannot see the board at school'),
  locked: document.querySelector('#op-form [data-field="refer_ophthalmology"]').disabled,
  optical: document.querySelector('#op-form [data-field="refer_optical"]').disabled,
  route: document.getElementById('op-route-note').textContent,
})`);
check('the optometrist sees it is a doctor\'s referral', opto.note.includes('Referred by the doctor for spectacles'));
check('with the doctor\'s clinical history', opto.history);
check('Ophthalmology cannot be chosen again', opto.locked && opto.route.includes('Referred by the doctor'), opto.route);
check('the Optical Room still can', opto.optical === false);
await shot('optometry-doctor-referral', SHOTS);

// ─── Reports: discounts inside the Financial Summary ──────────────────────
await evaluate(`showModule('report-terminal')`);
await waitUntil(`document.getElementById('rp-type').options.length > 0`, 15000, 'reports');
const types = await evaluate(`[...document.getElementById('rp-type').options].map(o => o.value)`);
check('the Discounts and Waivers report is no longer listed', !types.includes('waivers') && types.includes('financial'), types.join(','));
await evaluate(`(() => { document.getElementById('rp-type').value = 'financial'; rpTypeChanged(); })()`);
await waitUntil(`RP.report && RP.report.title === 'Financial Summary' && document.getElementById('rp-summary').textContent.includes('Discounted bills')`, 15000, 'financial summary');
const fin = await evaluate(`({
  tables: [...document.querySelectorAll('#rp-summary .card-title')].map(t => t.textContent),
  scheme: document.getElementById('rp-scheme').style.display, age: document.getElementById('rp-max-age').style.display,
})`);
equal('the Financial Summary shows its summary and both discount tables', fin.tables,
  ['Collected by category and item — VIP and normal', 'Discounts by scheme', 'Discounted bills']);
check('with the discount scheme and age filters', fin.scheme === '' && fin.age === '');
await shot('financial-summary-discounts', SHOTS);
await evaluate(`(() => { document.getElementById('rp-type').value = 'reception'; rpTypeChanged(); })()`);
check('other reports do not show the discount filters', await evaluate(`document.getElementById('rp-scheme').style.display === 'none'`));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('uat round 4 browser', SHOTS);
