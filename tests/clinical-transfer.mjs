// UAT: the patient goes to and fro between the doctor and Optometry as often as needed.
// The optometrist may refer the patient back to the doctor (the consultation reopens), even
// after the doctor sent them; the doctor sees the patients who are with Optometry and may call
// one over without waiting for a referral back (one being seen there only on purpose).
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;
const icd = (await get('clinical/options')).icd10[0];

async function account(role, prefix) {
  const username = `${prefix}${run}`;
  const password = `Transfer-${prefix}-${run}-x9`;
  tail++;
  await post('users', {
    first_name: 'Test', last_name: prefix, phone: `25571${run}${tail}`, department_id: Number(sql('SELECT MIN(id) FROM departments')),
    role_ids: [Number(sql(`SELECT id FROM roles WHERE code = '${role}'`))], designation_ids: [], username, password,
  });
  sql(`UPDATE users SET must_change_password = 0 WHERE username = '${username}'`);
  return { username, password };
}
const doctor = await account('doctor', 'tdoc');
const optom = await account('optometrist', 'topt');

async function doctorVisit(lastName) {
  await signIn(); // reception's part, as the administrator
  tail++;
  const patient = (await post('patients', {
    first_name: 'Transfer', last_name: `${lastName}${run}`, gender: 'female', date_of_birth: '1980-03-03',
    phone: `25572${run}${tail}`, address: 'Kigamboni', kin_name: 'Bw Juma', kin_relationship: 'Spouse', kin_phone: `25573${run}${tail}`,
  })).patient;
  const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
  const [billId, version] = sql(`SELECT id, row_version FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`).split('\t');
  await post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(version) });
  return visit;
}
const status = id => sql(`SELECT status FROM visits WHERE id = ${id}`);
const as = user => signIn(user.username, user.password);
const doctorDone = (extra = {}) => ({ provisional_icd_ids: [icd.id], final_icd_ids: [icd.id], ...extra });
const optomDone = (extra = {}) => ({ provisional_icd_ids: [icd.id], refractions: { final: { sph_right: -1, sph_left: -1 } }, va_right: '6/9', ...extra });

// ─── To and fro, twice ────────────────────────────────────────────────────
const visit = await doctorVisit('Fro');
await as(doctor);
await post(`ophthalmology/visits/${visit.id}/claim`);
await post(`ophthalmology/visits/${visit.id}/complete`, doctorDone({ refer_optometry: true, clinical_notes: 'First look' }));
equal('the doctor sends the patient to Optometry', status(visit.id), 'waiting_optometry');

await as(optom);
let ws = await post(`optometry/visits/${visit.id}/claim`);
equal('the optometrist works on the patient', ws.mode, 'edit');
await post(`optometry/visits/${visit.id}/complete`, optomDone({ refer_ophthalmology: true, row_version: ws.record?.row_version ?? null }));
equal('the optometrist refers the patient back to the doctor', status(visit.id), 'waiting_ophthalmology');
equal('the doctor\'s consultation is reopened', sql(`SELECT status FROM ophthalmology_consultations WHERE visit_id = ${visit.id}`), 'draft');
check('…with its earlier referral to Optometry cleared', sql(`SELECT refer_optometry FROM ophthalmology_consultations WHERE visit_id = ${visit.id}`) === '0');
check('the history says referred back', /referred back to Ophthalmology/.test(sql(`SELECT note FROM visit_status_history WHERE visit_id = ${visit.id} ORDER BY id DESC LIMIT 1`)));

await as(doctor);
const back = (await get('ophthalmology/queue')).queue.find(q => q.visit_id === visit.id);
equal('the doctor\'s queue shows the patient came from Optometry', back?.source, 'Optometry');
ws = await post(`ophthalmology/visits/${visit.id}/claim`);
equal('the doctor continues in the same consultation', ws.mode, 'edit');
equal('with what was written before', ws.record.clinical_notes, 'First look');
check('the reopening is shown', !!ws.record.reopened_at && !!ws.record.reopened_by);
check('the optometry findings are there to read', !!ws.upstream.optometry);
await post(`ophthalmology/visits/${visit.id}/complete`, doctorDone({ refer_optometry: true, clinical_notes: 'Second look', row_version: ws.record.row_version }));
equal('and sends the patient to Optometry a second time', status(visit.id), 'waiting_optometry');
equal('the optometry record reopens again', sql(`SELECT status FROM optometry_assessments WHERE visit_id = ${visit.id}`), 'draft');

await as(optom);
ws = await post(`optometry/visits/${visit.id}/claim`);
equal('the referral back is not ticked in advance', ws.record.refer_ophthalmology, false);
await post(`optometry/visits/${visit.id}/complete`, optomDone({ row_version: ws.record.row_version }));
equal('without a referral back the visit ends at Optometry', status(visit.id), 'completed');

// ─── The doctor sees patients at Optometry and calls one over ─────────────
const waiting = await doctorVisit('Waiting');
await as(doctor);
await post(`ophthalmology/visits/${waiting.id}/claim`);
await post(`ophthalmology/visits/${waiting.id}/complete`, doctorDone({ refer_optometry: true }));
await as(optom);
check('Optometry still sees the patient in its own queue', (await get('optometry/queue')).queue.some(i => i.visit_id === waiting.id && i.source === 'Doctor'));
await as(doctor);
let q = await get('ophthalmology/queue');
check('the usual queue does not mix them in', !q.queue.some(i => i.visit_id === waiting.id));
check('they are counted under At Optometry', q.counts.at_optometry >= 1);
q = await get('ophthalmology/queue?filter=optometry');
const item = q.queue.find(i => i.visit_id === waiting.id);
check('the At Optometry filter lists the patient', item?.at_optometry === true);
ws = await get(`ophthalmology/visits/${waiting.id}`);
check('the doctor can open the patient (to read, or correct their own consultation)', ['read', 'correct'].includes(ws.mode), ws.mode);
await post(`ophthalmology/visits/${waiting.id}/claim`);
equal('and call them over without a referral back', sql(`SELECT CONCAT(status, '/', clinical_stage) FROM visits WHERE id = ${waiting.id}`), 'in_progress/ophthalmology');
check('the history says so', /Called to the doctor from Optometry/.test(sql(`SELECT note FROM visit_status_history WHERE visit_id = ${waiting.id} ORDER BY id DESC LIMIT 1`)));
equal('the consultation reopens to continue in', sql(`SELECT status FROM ophthalmology_consultations WHERE visit_id = ${waiting.id}`), 'draft');
await as(optom);
check('the patient leaves the Optometry queue', !(await get('optometry/queue')).queue.some(i => i.visit_id === waiting.id));

// Being seen at Optometry: taken only on purpose; the optometrist's draft is kept.
const busy = await doctorVisit('Busy');
await as(doctor);
await post(`ophthalmology/visits/${busy.id}/claim`);
await post(`ophthalmology/visits/${busy.id}/complete`, doctorDone({ refer_optometry: true }));
await as(optom);
ws = await post(`optometry/visits/${busy.id}/claim`);
await put(`optometry/visits/${busy.id}`, optomDone({ remarks: 'Half way', row_version: ws.record?.row_version ?? null }));
await as(doctor);
q = await get('ophthalmology/queue?filter=optometry');
check('the doctor sees who is seeing the patient at Optometry', !!q.queue.find(i => i.visit_id === busy.id)?.attending);
await expectError('not without saying so', () => post(`ophthalmology/visits/${busy.id}/claim`), 409, 'attended_by_other');
await post(`ophthalmology/visits/${busy.id}/claim`, { take_over: true });
equal('taken over by the doctor', sql(`SELECT clinical_stage FROM visits WHERE id = ${busy.id}`), 'ophthalmology');
check('the audit log names the optometrist taken from', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'patient.taken_over' AND entity_id = '${busy.visit_no}'`) === '1');
equal('the optometry draft is kept', sql(`SELECT status FROM optometry_assessments WHERE visit_id = ${busy.id}`), 'draft');
await as(optom);
await expectError('the optometrist can no longer save over it', () => put(`optometry/visits/${busy.id}`, optomDone({
  row_version: Number(sql(`SELECT row_version FROM optometry_assessments WHERE visit_id = ${busy.id}`)) })), 409);

// A patient who has not been to Optometry is not in that list.
const plain = await doctorVisit('Plain');
await as(doctor);
check('only patients with Optometry are listed there', !(await get('ophthalmology/queue?filter=optometry')).queue.some(i => i.visit_id === plain.id));

report('clinical transfer');
