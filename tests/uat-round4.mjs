// UAT round 4 (live testing): reception sends patients straight to Optometry, the doctor's
// Clinical History and referral to Optometry (reopening an earlier optometry record), and the
// discounts moved into the Financial Summary.
import { signIn, get, pendingBill, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;

async function newPatient(lastName, dob = '2016-05-05') {
  tail++;
  return (await post('patients', {
    first_name: 'Round', last_name: `${lastName}${run}`, gender: 'female', date_of_birth: dob,
    phone: `25575${run}${tail}`, address: 'Kariakoo, Dar es Salaam',
    kin_name: 'Mama Asha', kin_relationship: 'Parent', kin_phone: `25575${run}${tail}9`,
  })).patient;
}
async function pay(visit) {
  const bill = await pendingBill(b => b.visit_no === visit.visit_no);
  await post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
}
const status = visitId => sql(`SELECT status FROM visits WHERE id = ${visitId}`);
const options = await get('clinical/options');
const icd = options.icd10[0];
const doctorDone = { provisional_icd_ids: [icd.id], final_icd_ids: [icd.id] };
const optometryDone = (extra = {}) => ({ provisional_icd_ids: [icd.id], refractions: { final: { sph_right: -1.25, sph_left: -1 } }, va_right: '6/9', ...extra });

// ─── Reception → Optometry (children skip Triage) ──────────────────────────
const visitOptions = await get('visits/options');
equal('reception has the optometry rooms', visitOptions.optometry_rooms, ['Optometry Room 1', 'Optometry Room 2']);
check('and still the consultation rooms', visitOptions.rooms.includes('Room 1'));

const child = await newPatient('Child');
const base = { patient_id: child.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry' };
const doctorQuote = (await get('visits/quote?' + new URLSearchParams({ ...base, destination: 'doctor_room' }))).quote;
const optoQuote = (await get('visits/quote?' + new URLSearchParams(base))).quote;
equal('Optometry costs the same consultation fee', optoQuote.net_amount, doctorQuote.net_amount);
const fastQuote = (await get('visits/quote?' + new URLSearchParams({ ...base, sub_type: 'fast_track' }))).quote;
equal('fast track is priced as fast track', fastQuote.price_code, 'CONS-FAST');

await expectError('an optometry room is required', () => post('visits', base), 422);
await expectError('a consultation room is not an optometry room', () => post('visits', { ...base, room: 'Room 1' }), 422);
await expectError('emergencies do not go straight to Optometry',
  () => post('visits', { ...base, room: 'Optometry Room 2', is_emergency: true, emergency_reason: 'Eye Trauma' }), 422);

const childVisit = (await post('visits', { ...base, room: 'Optometry Room 2' })).visit;
equal('the child waits for payment first', status(childVisit.id), 'pending_payment');
equal('in Optometry Room 2', sql(`SELECT CONCAT(destination, '|', room) FROM visits WHERE id = ${childVisit.id}`), 'optometry|Optometry Room 2');
await pay(childVisit);
equal('once paid, straight to the Optometry queue', status(childVisit.id), 'waiting_optometry');
const queueItem = (await get('optometry/queue')).queue.find(i => i.visit_id === childVisit.id);
check('the child is in the Optometry queue', !!queueItem);
equal('coming from Reception', queueItem?.source, 'Reception');
equal('with the room', queueItem?.room, 'Optometry Room 2');
check('and not in the Triage queue', !(await get('triage/queue')).queue.some(i => i.visit_id === childVisit.id));
const listed = (await get('visits?destination=optometry')).visits;
check('reception can filter visits by Optometry', listed.some(v => v.id === childVisit.id) && listed.every(v => v.destination === 'optometry'));

// ─── Optometry → doctor → back to Optometry: the same record reopens ───────
await post(`optometry/visits/${childVisit.id}/claim`);
await post(`optometry/visits/${childVisit.id}/complete`, optometryDone({ refer_ophthalmology: true, remarks: 'Squint review' }));
equal('Optometry refers the child to the doctor', status(childVisit.id), 'waiting_ophthalmology');
const firstOpto = sql(`SELECT CONCAT(id, '|', status) FROM optometry_assessments WHERE visit_id = ${childVisit.id}`);

await post(`ophthalmology/visits/${childVisit.id}/claim`);
const history = `Child squints when reading ${run}`;
await post(`ophthalmology/visits/${childVisit.id}/complete`, { ...doctorDone, clinical_history: history, refer_optometry: true });
equal('the doctor sends the child back to Optometry', status(childVisit.id), 'waiting_optometry');
const reopened = sql(`SELECT CONCAT(id, '|', status, '|', reopened_at IS NOT NULL) FROM optometry_assessments WHERE visit_id = ${childVisit.id}`);
equal('the same optometry record is reopened as a draft', reopened, `${firstOpto.split('|')[0]}|draft|1`);
equal('still one optometry record for the visit', sql(`SELECT COUNT(*) FROM optometry_assessments WHERE visit_id = ${childVisit.id}`), '1');
check('the visit history says why', sql(`SELECT COUNT(*) FROM visit_status_history WHERE visit_id = ${childVisit.id} AND to_status = 'waiting_optometry' AND note LIKE '%referred to Optometry for spectacles%reopened%'`) === '1');

const doctorRecord = (await get(`ophthalmology/visits/${childVisit.id}`)).record;
equal('the clinical history is saved', doctorRecord.clinical_history, history);
equal('and the referral to Optometry', doctorRecord.refer_optometry, true);
equal('the history is stored encrypted', sql(`SELECT COUNT(*) FROM ophthalmology_consultations WHERE visit_id = ${childVisit.id} AND clinical_history_enc LIKE '%${run}%'`), '0');

const backItem = (await get('optometry/queue')).queue.find(i => i.visit_id === childVisit.id);
equal('the Optometry queue shows the doctor sent the child', backItem?.source, 'Doctor');
let ws = await post(`optometry/visits/${childVisit.id}/claim`);
equal('the optometrist works on the reopened record', ws.mode, 'edit');
equal('with what was recorded before', ws.record.va_right, '6/9');
equal('and the earlier remarks', ws.record.remarks, 'Squint review');
check('the reopening is shown', !!ws.record.reopened_at && !!ws.record.reopened_by);
check('the doctor\'s consultation is shown to the optometrist', ws.upstream.ophthalmology?.clinical_history === history);

await post(`optometry/visits/${childVisit.id}/complete`, optometryDone({
  refer_ophthalmology: true, refer_optical: true, refractions: { final: { sph_right: -2, sph_left: -1.5 } }, row_version: ws.record.row_version,
}));
// The clinical part ends at Optometry; with spectacles to order, the visit waits for the Optical Room (UAT: discharge).
equal('after the doctor\'s referral the clinical part ends at Optometry; the visit waits for the spectacles order', status(childVisit.id), 'awaiting_services');
check('…and is not sent back to the doctor', !(await get('ophthalmology/queue')).queue.some(q => q.visit_id === childVisit.id));
equal('the change to the refraction is kept', sql(`SELECT sph_right FROM optometry_refractions r JOIN optometry_assessments a ON a.id = r.assessment_id WHERE a.visit_id = ${childVisit.id} AND r.kind = 'final' AND r.set_no = 1`), '-2.00');
equal('the earlier referral to Ophthalmology is kept as it was', sql(`SELECT refer_ophthalmology FROM optometry_assessments WHERE visit_id = ${childVisit.id}`), '1');
check('the change is in the audit log', sql(`SELECT COUNT(*) FROM audit_log WHERE module = 'optometry' AND action = 'record.completed' AND entity_id = '${childVisit.visit_no}' AND changes LIKE '%refractions%'`) !== '0');
check('the spectacles now reach the Optical Room', (await get('optical/incoming')).referrals.some(r => r.visit_no === childVisit.visit_no));

// ─── Doctor Room → doctor → Optometry for the first time ───────────────────
const adult = await newPatient('Adult', '1975-02-02');
const adultVisit = (await post('visits', { patient_id: adult.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
await pay(adultVisit);
await post(`ophthalmology/visits/${adultVisit.id}/claim`);
await post(`ophthalmology/visits/${adultVisit.id}/complete`, { ...doctorDone, refer_optometry: true });
equal('the doctor can send a patient to Optometry who has not been there', status(adultVisit.id), 'waiting_optometry');
check('no optometry record yet, nothing to reopen', sql(`SELECT COUNT(*) FROM optometry_assessments WHERE visit_id = ${adultVisit.id}`) === '0');
ws = await post(`optometry/visits/${adultVisit.id}/claim`);
check('the optometrist starts a new record', ws.record === null && ws.mode === 'edit');
await post(`optometry/visits/${adultVisit.id}/complete`, optometryDone({ refer_ophthalmology: true }));
equal('and the visit ends there', status(adultVisit.id), 'completed');
equal('without a referral back to the doctor', sql(`SELECT refer_ophthalmology FROM optometry_assessments WHERE visit_id = ${adultVisit.id}`), '0');

// A correction of the doctor's record cannot change where the patient went.
const adultRecord = (await get(`ophthalmology/visits/${adultVisit.id}`)).record;
await put(`ophthalmology/visits/${adultVisit.id}`, { ...doctorDone, clinical_history: 'Corrected history', refer_optometry: false, row_version: adultRecord.row_version });
equal('a correction keeps the referral', sql(`SELECT refer_optometry FROM ophthalmology_consultations WHERE visit_id = ${adultVisit.id}`), '1');
equal('but corrects the history', (await get(`ophthalmology/visits/${adultVisit.id}`)).record.clinical_history, 'Corrected history');

// Without the referral, the doctor's consultation still ends the visit as before.
const plain = await newPatient('Plain', '1980-08-08');
const plainVisit = (await post('visits', { patient_id: plain.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 2' })).visit;
await pay(plainVisit);
await post(`ophthalmology/visits/${plainVisit.id}/claim`);
await post(`ophthalmology/visits/${plainVisit.id}/complete`, doctorDone);
equal('a consultation without the referral completes the visit', status(plainVisit.id), 'completed');

// ─── Discounts are part of the Financial Summary ───────────────────────────
const reportOptions = await get('reports/options');
check('the separate Discounts report is gone', !reportOptions.reports.some(r => r.type === 'waivers'));
await expectError('and cannot be run', () => get('reports/run?type=waivers'), 404);
const today = reportOptions.today;
const financial = (await get('reports/run?' + new URLSearchParams({ type: 'financial', from: today, to: today }))).report;
equal('the Financial Summary has the discount tables', (financial.summaries || []).map(t => t.title), ['Discounts by scheme', 'Discounted bills']);
check('and the discount figures', ['Discounts given', 'Discounted bills', 'Patients given a discount', 'Self-approved discounts'].every(l => financial.kpis.some(k => k.label === l)));
check('its own summary is still there', financial.summary?.title?.startsWith('Collected by category'));

report('uat round 4');
