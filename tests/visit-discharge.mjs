// Discharge and re-admission (UAT).
// A visit is discharged automatically once all of the day's services are done: the clinician's
// part, a test booked for today, medicines to collect. Reception may end a visit by hand (with a
// reason) and re-admit a discharged visit to Triage, Optometry or the Doctor: the same visit, no
// new fee, and that stage's completed record reopens as a draft to continue in.
import { signIn, get, post, put, check, equal, expectError, report, sql, payDiagnostic } from './lib/api.mjs';

const admin = await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;
const options = await get('clinical/options');
const icd = options.icd10[0];
const test = options.investigation_items[0];
const drug = options.drugs[0];
await post(`pharmacy/stock/${drug.id}`, { change: 20, reason: 'received', note: 'Stock for the discharge test' });

async function doctorVisit(lastName) {
  tail++;
  const patient = (await post('patients', {
    first_name: 'Discharge', last_name: `${lastName}${run}`, gender: 'female', date_of_birth: '1966-06-06',
    phone: `25579${run}${tail}`, address: 'Mbezi, Dar es Salaam', kin_name: 'Bw Hamisi', kin_relationship: 'Sibling', kin_phone: `25570${run}${tail}`,
  })).patient;
  const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
  const [billId, version] = sql(`SELECT id, row_version FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`).split('\t');
  await post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(version) });
  return { patient, visit };
}
const status = id => sql(`SELECT status FROM visits WHERE id = ${id}`);
const lastNote = id => sql(`SELECT note FROM visit_status_history WHERE visit_id = ${id} ORDER BY id DESC LIMIT 1`);
const consult = (id, extra = {}) => post(`ophthalmology/visits/${id}/complete`, { provisional_icd_ids: [icd.id], final_icd_ids: [icd.id], ...extra });

// ─── Automatic discharge ──────────────────────────────────────────────────
const plain = await doctorVisit('Plain');
await post(`ophthalmology/visits/${plain.visit.id}/claim`);
await consult(plain.visit.id);
equal('nothing else booked: discharged when the doctor completes', status(plain.visit.id), 'completed');
check('the history says so', /discharged/.test(lastNote(plain.visit.id)), lastNote(plain.visit.id));

const withTest = await doctorVisit('Test');
await post(`ophthalmology/visits/${withTest.visit.id}/claim`);
await consult(withTest.visit.id, { investigations: [{ price_item_id: test.id, eye: 'right', priority: 'routine', reason: 'Raised pressure' }] });
equal('a test booked for today: the visit waits for it', status(withTest.visit.id), 'awaiting_services');
let shown = (await get(`visits/${withTest.visit.id}`)).visit;
check('Reception sees the test still to do', shown.outstanding.length === 1 && shown.outstanding[0].kind === 'diagnostic', JSON.stringify(shown.outstanding));
check('and may end it by hand or re-admit it', shown.can_end && shown.can_readmit);
const bookingId = Number(sql(`SELECT id FROM diagnostic_bookings WHERE visit_id = ${withTest.visit.id}`));
await post(`diagnostics/bookings/${bookingId}/check-in`, { no_charge: false });
await payDiagnostic(bookingId);
equal('checked in and paid is not yet done', status(withTest.visit.id), 'awaiting_services');
await put(`diagnostics/bookings/${bookingId}/report`, { reporting_user_id: admin.id, result: 'Normal fields.', comments: '' });
equal('once the test is reported the visit is discharged', status(withTest.visit.id), 'completed');
check('…with the reason in the history', /reported . discharged/.test(lastNote(withTest.visit.id)), lastNote(withTest.visit.id));

const withRx = await doctorVisit('Medicine');
await post(`ophthalmology/visits/${withRx.visit.id}/claim`);
await consult(withRx.visit.id, { prescription: { items: [{ drug_id: drug.id, quantity: 2, dose: '1 drop', frequency: 'BD', duration: '1 week' }] } });
equal('medicines to collect: the visit waits', status(withRx.visit.id), 'awaiting_services');
equal('Reception sees them', (await get(`visits/${withRx.visit.id}`)).visit.outstanding.map(o => o.kind), ['pharmacy']);
const queued = (await get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === withRx.patient.id);
let order = (await get(`pharmacy/orders/${queued.id}`)).order;
const itemId = order.items[0].id;
await post(`pharmacy/orders/${order.id}/review`, { items: [{ item_id: itemId, quantity: 2 }] });
order = (await get(`pharmacy/orders/${queued.id}`)).order;
const [rxBill, rxVersion] = sql(`SELECT id, row_version FROM bills WHERE id = ${order.bill.id}`).split('\t');
await post(`bills/${rxBill}/pay`, { method: 'cash', row_version: Number(rxVersion) });
equal('paid but not handed over: still waiting', status(withRx.visit.id), 'awaiting_services');
await post(`pharmacy/orders/${order.id}/dispense`, { items: [{ item_id: itemId, quantity: 2 }] });
equal('medicines handed over: discharged', status(withRx.visit.id), 'completed');

// A test cancelled through approvals does not call back; the list re-checks waiting visits.
const sweep = await doctorVisit('Sweep');
await post(`ophthalmology/visits/${sweep.visit.id}/claim`);
await consult(sweep.visit.id, { investigations: [{ price_item_id: test.id, eye: 'left', priority: 'routine', reason: 'Check' }] });
equal('waiting for its test', status(sweep.visit.id), 'awaiting_services');
sql(`UPDATE diagnostic_bookings SET status = 'cancelled' WHERE visit_id = ${sweep.visit.id}`);
await get(`visits?search=${encodeURIComponent(sweep.visit.visit_no)}`);
equal('with nothing left, opening the visit list discharges it', status(sweep.visit.id), 'completed');

// ─── End Visit by hand ────────────────────────────────────────────────────
const left = await doctorVisit('Left');
equal('waiting for the doctor', status(left.visit.id), 'waiting_doctor');
await expectError('a reason is required', () => post(`visits/${left.visit.id}/end`, { reason: '' }), 422);
await post(`visits/${left.visit.id}/end`, { reason: 'Patient went home before being seen' });
equal('ended: discharged', status(left.visit.id), 'completed');
shown = (await get(`visits/${left.visit.id}`)).visit;
check('the visit shows who ended it and why', shown.ended_by && shown.end_reason === 'Patient went home before being seen' && shown.discharged_at, JSON.stringify(shown).slice(0, 300));
check('the reason is stored encrypted', sql(`SELECT COUNT(*) FROM visits WHERE id = ${left.visit.id} AND end_reason_enc LIKE '%went home%'`) === '0');
check('the history names the queue left', /ended by Reception . still outstanding: the Doctor/i.test(lastNote(left.visit.id)), lastNote(left.visit.id));
check('the audit log has it', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'visit.ended' AND entity_id = '${left.visit.visit_no}'`) === '1');
await expectError('it cannot be ended twice', () => post(`visits/${left.visit.id}/end`, { reason: 'Again' }), 409);

tail++;
const unpaidPatient = (await post('patients', {
  first_name: 'Unpaid', last_name: `Visit${run}`, gender: 'male', date_of_birth: '1990-01-01', phone: `25579${run}${tail}`,
  address: 'Kinondoni', kin_name: 'Bi Rehema', kin_relationship: 'Parent', kin_phone: `25570${run}${tail}`,
})).patient;
const unpaid = (await post('visits', { patient_id: unpaidPatient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
await expectError('an unpaid visit is cancelled, not ended', () => post(`visits/${unpaid.id}/end`, { reason: 'Left' }), 409, 'visit_unpaid');

const busy = await doctorVisit('Busy');
await post(`ophthalmology/visits/${busy.visit.id}/claim`);
await expectError('not while a clinician is seeing the patient', () => post(`visits/${busy.visit.id}/end`, { reason: 'Left' }), 409, 'visit_in_progress');

// ─── Re-admit ─────────────────────────────────────────────────────────────
await expectError('an open visit cannot be re-admitted', () => post(`visits/${busy.visit.id}/readmit`, { stage: 'ophthalmology', reason: 'Back' }), 409, 'visit_not_discharged');
await expectError('the destination must be one of the three', () => post(`visits/${plain.visit.id}/readmit`, { stage: 'pharmacy', reason: 'Back' }), 422);

const billsBefore = sql(`SELECT COUNT(*) FROM bills WHERE visit_id = ${withRx.visit.id}`);
const readmit = await post(`visits/${withRx.visit.id}/readmit`, { stage: 'ophthalmology', reason: 'Eye still red after the drops' });
check('re-admitted: the earlier consultation is reopened', readmit.reopened === true);
equal('the visit waits for the doctor again', status(withRx.visit.id), 'waiting_ophthalmology');
equal('no new bill', sql(`SELECT COUNT(*) FROM bills WHERE visit_id = ${withRx.visit.id}`), billsBefore);
equal('the consultation is a draft again', sql(`SELECT status FROM ophthalmology_consultations WHERE visit_id = ${withRx.visit.id}`), 'draft');
const queue = (await get('ophthalmology/queue')).queue;
check('the patient is back in the doctor\'s queue', queue.some(q => q.visit_id === withRx.visit.id));
await post(`ophthalmology/visits/${withRx.visit.id}/claim`);
let ws = await get(`ophthalmology/visits/${withRx.visit.id}`);
equal('the doctor continues in the same form, editable', ws.mode, 'edit');
equal('with what was recorded before', ws.record.diagnoses.provisional.map(d => d.id), [icd.id]);
equal('the issued prescription is kept, not repeated', ws.record.prescription?.status, 'issued');
check('the visit header says it was re-admitted', ws.visit.readmitted?.reason === 'Eye still red after the drops', JSON.stringify(ws.visit.readmitted));
await consult(withRx.visit.id, { row_version: ws.record.row_version });
equal('completed again: discharged (medicines already handed over)', status(withRx.visit.id), 'completed');
equal('still one prescription for the visit', sql(`SELECT COUNT(*) FROM prescriptions WHERE visit_id = ${withRx.visit.id}`), '1');

const toTriage = await post(`visits/${plain.visit.id}/readmit`, { stage: 'triage', reason: 'Pressure check' });
equal('re-admitted where there was no record: nothing to reopen', toTriage.reopened, false);
equal('waiting for triage', status(plain.visit.id), 'waiting_triage');

// Another open visit today for the same patient: continue with that one instead.
const twice = await doctorVisit('Twice');
await post(`visits/${twice.visit.id}/end`, { reason: 'Went to get the result' });
await post('visits', { patient_id: twice.patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1', confirm_open_visit: true });
await expectError('not while the patient has another open visit today', () => post(`visits/${twice.visit.id}/readmit`, { stage: 'ophthalmology', reason: 'Back' }), 409, 'open_visit_exists');

const old = await doctorVisit('Old');
await post(`visits/${old.visit.id}/end`, { reason: 'Went home' });
sql(`UPDATE visits SET visit_date = DATE_SUB(visit_date, INTERVAL 10 DAY) WHERE id = ${old.visit.id}`);
check('an old visit is not offered for re-admission', (await get(`visits/${old.visit.id}`)).visit.can_readmit === false);
await expectError('a visit older than the window needs a new visit', () => post(`visits/${old.visit.id}/readmit`, { stage: 'ophthalmology', reason: 'Back' }), 409, 'readmit_too_late');

// ─── Permissions ──────────────────────────────────────────────────────────
check('Reception holds both new permissions', sql(`SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
  WHERE r.code = 'reception' AND p.code IN ('visits.end', 'visits.readmit')`) === '2');
const nurseName = `nurse${run}`;
const nursePass = `Discharge-${run}-x9`;
await post('users', {
  first_name: 'Test', last_name: 'Nurse', phone: `25579${run}99`, department_id: Number(sql('SELECT MIN(id) FROM departments')),
  role_ids: [Number(sql("SELECT id FROM roles WHERE code = 'clinical'"))], designation_ids: [], username: nurseName, password: nursePass,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${nurseName}'`);
await signIn(nurseName, nursePass);
await expectError('a nurse cannot end a visit', () => post(`visits/${twice.visit.id}/end`, { reason: 'Left' }), 403);
await expectError('nor re-admit one', () => post(`visits/${left.visit.id}/readmit`, { stage: 'triage', reason: 'Back' }), 403);

report('visit discharge');
