// On screen: the doctor's queue has an "At Optometry" list; a patient there opens to read with a
// "Call to Doctor" button. The optometrist can tick Ophthalmology after the doctor's referral.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();
const icd = (await api.get('clinical/options')).icd10[0];
const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Fro${run}`, gender: 'male', date_of_birth: '1972-02-02',
  phone: `25574${run}1`, address: 'Mikocheni', kin_name: 'Bi Asha', kin_relationship: 'Spouse', kin_phone: `25574${run}2`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
const [billId, version] = api.sql(`SELECT id, row_version FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`).split('\t');
await api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(version) });
await api.post(`ophthalmology/visits/${visit.id}/claim`);
await api.post(`ophthalmology/visits/${visit.id}/complete`, { provisional_icd_ids: [icd.id], final_icd_ids: [icd.id], refer_optometry: true });
// Make the consultation old enough that the doctor reads it rather than corrects it.
api.sql(`UPDATE ophthalmology_consultations SET completed_at = DATE_SUB(completed_at, INTERVAL 3 DAY) WHERE visit_id = ${visit.id}`);

await launch(9551);
await signIn();

// ─── Optometry: the referral back is open to tick ─────────────────────────
await evaluate(`showModule('optometry')`);
await waitUntil(`(CL_STAGES.optometry?.state?.queue || []).some(q => q.visit_id === ${visit.id})`, 10000, 'optometry queue');
await evaluate(`CL_STAGES.optometry.select(${visit.id})`);
await waitUntil(`CL_STAGES.optometry.state.ws?.visit.id === ${visit.id}`, 8000, 'optometry form');
check('the Ophthalmology box can be ticked after the doctor\'s referral',
  await evaluate(`!document.querySelector('#op-form [data-field="refer_ophthalmology"]').disabled`));
check('the note explains it sends the patient back', /send the patient back to the doctor/.test(await evaluate(`document.getElementById('op-route-note').textContent`)));
await evaluate(`CL_STAGES.optometry.release()`);
await sleep(800);

// ─── Doctor: At Optometry ─────────────────────────────────────────────────
await evaluate(`showModule('ophthalmology')`);
await waitUntil(`!!document.querySelector('#oph-q-stats .tq-stat[title="At Optometry"]')`, 10000, 'At Optometry filter');
await evaluate(`CL_STAGES.ophthalmology.setFilter('optometry')`);
await waitUntil(`CL_STAGES.ophthalmology.state.queue.some(q => q.visit_id === ${visit.id})`, 8000, 'patient listed at Optometry');
const card = await evaluate(`document.querySelector('#oph-q-list .tq-card[data-visit="${visit.id}"]').textContent`);
check('the card says the patient is at Optometry', /Waiting at Optometry/.test(card), card.slice(0, 160));
await evaluate(`CL_STAGES.ophthalmology.select(${visit.id})`);
await waitUntil(`CL_STAGES.ophthalmology.state.ws?.visit.id === ${visit.id}`, 8000, 'doctor form');
equal('opening does not take the patient from Optometry', api.sql(`SELECT status FROM visits WHERE id = ${visit.id}`), 'waiting_optometry');
check('the strip offers Call to Doctor', /Call to Doctor/.test(await evaluate(`document.getElementById('oph-mode').textContent`)));
await shot('doctor-at-optometry', SHOTS);
await evaluate(`CL_STAGES.ophthalmology.callOver()`);
await waitUntil(`CL_STAGES.ophthalmology.state.ws?.mode === 'edit'`, 8000, 'called over');
equal('called to the doctor', api.sql(`SELECT CONCAT(status, '/', clinical_stage) FROM visits WHERE id = ${visit.id}`), 'in_progress/ophthalmology');
check('the doctor sees it is back from Optometry', /Back from Optometry/.test(await evaluate(`document.getElementById('oph-upstream').textContent`)));
await shot('doctor-called-over', SHOTS);

await finish('clinical transfer browser', SHOTS);
