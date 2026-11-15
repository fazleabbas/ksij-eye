// Test results for doctors (from the consultation, patient history and the bell), and the fuller
// patient history: tests from any source, theatre, medicines handed over, spectacle orders.
// Results and theatre notes: doctors and admin only; others see the status.
import { signIn, get, post, put, call, upload, check, equal, expectError, report, sql } from './lib/api.mjs';

const admin = await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;

// ─── Real accounts: a doctor (who refers tests) and a nurse ───────────────
async function account(role, prefix, designation = null) {
  const username = `${prefix}${run}`;
  const password = `Result-${prefix}-${run}-x9`;
  tail++;
  await post('users', {
    first_name: 'Test', last_name: prefix, phone: `25577${run}${tail}`, department_id: Number(sql('SELECT MIN(id) FROM departments')),
    role_ids: [Number(sql(`SELECT id FROM roles WHERE code = '${role}'`))],
    designation_ids: designation ? [Number(sql(`SELECT id FROM designations WHERE code = '${designation}'`))] : [],
    username, password,
  });
  sql(`UPDATE users SET must_change_password = 0 WHERE username = '${username}'`);
  return { username, password, id: Number(sql(`SELECT id FROM users WHERE username = '${username}'`)) };
}
const doctor = await account('doctor', 'doc', 'ophthalmologist');
const nurse = await account('clinical', 'nurse');
check('Doctors hold the new permission', sql(`SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
  WHERE r.code = 'doctor' AND p.code = 'ophthalmology.results.view'`) === '1');
check('nurses (Clinical role) do not', sql(`SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
  WHERE r.code = 'clinical' AND p.code = 'ophthalmology.results.view'`) === '0');

async function newPatient(lastName) {
  tail++;
  return (await post('patients', {
    first_name: 'Result', last_name: `${lastName}${run}`, gender: 'male', date_of_birth: '1970-07-07',
    phone: `25577${run}${tail}`, address: 'Upanga, Dar es Salaam', kin_name: 'Bi Mwanaisha', kin_relationship: 'Spouse', kin_phone: `25578${run}${tail}`,
  })).patient;
}
async function pay(visitNo) {
  const bill = (await get('bills?status=pending')).bills.find(b => b.visit_no === visitNo);
  await post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
}
async function reportTest(bookingId, text) {
  await post(`diagnostics/bookings/${bookingId}/check-in`, { no_charge: false });
  await put(`diagnostics/bookings/${bookingId}/report`, { reporting_user_id: admin.id, result: text, comments: 'Repeat in 6 months' });
}
const options = await get('clinical/options');
const icd = options.icd10[0];
const test = options.investigation_items[0];

// ─── The doctor requests a test; Diagnostics reports it ───────────────────
const patient = await newPatient('Case');
const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
await pay(visit.visit_no);
await signIn(doctor.username, doctor.password);
await post(`ophthalmology/visits/${visit.id}/claim`);
await post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  investigations: [{ price_item_id: test.id, eye: 'right', priority: 'routine', reason: 'Raised pressure' }],
});
let inv = (await get(`ophthalmology/visits/${visit.id}`)).record.investigations[0];
check('the request reaches Diagnostics', !!inv?.booking_id);
equal('no result yet', inv?.result_ready, false);

await signIn();
const resultText = `Cup-disc ratio 0.7 right ${run}`;
await reportTest(inv.booking_id, resultText);
const form = new FormData();
form.append('file', new Blob([Buffer.from('%PDF-1.4 field scan')], { type: 'application/pdf' }), 'field.pdf');
const fileId = (await upload(`diagnostics/bookings/${inv.booking_id}/files`, form)).files[0].id;

// Someone other than the referring doctor opening it does not clear the doctor's alert.
await get(`clinical/results/${inv.booking_id}`);
equal('an administrator opening it leaves it new for the doctor', sql(`SELECT seen_at IS NULL FROM diagnostic_results WHERE booking_id = ${inv.booking_id}`), '1');

await signIn(doctor.username, doctor.password);
inv = (await get(`ophthalmology/visits/${visit.id}`)).record.investigations[0];
equal('the consultation shows the result is ready', inv.result_ready, true);
let bell = await get('clinical/results/unseen');
const alert = bell.results.find(r => r.booking_id === inv.booking_id);
check('the doctor is told the result is ready', !!alert);
equal('as their own referral', alert?.mine, true);
equal('naming the patient', alert?.patient.reg_no, patient.reg_no);

const opened = (await get(`clinical/results/${inv.booking_id}`)).result;
equal('the doctor reads the result from the consultation', opened.report.result, resultText);
equal('with the comments', opened.report.comments, 'Repeat in 6 months');
equal('and the attached file', opened.report.files.map(f => f.name), ['field.pdf']);
check('the file opens', (await call('GET', `clinical/results/${inv.booking_id}/files/${fileId}`, undefined, { raw: true })).text.startsWith('%PDF'));
check('opening it is in the access log', sql(`SELECT COUNT(*) FROM record_access_log WHERE user_id = ${doctor.id} AND action = 'view' AND entity_type = 'diagnostic_result' AND entity_id = '${opened.booking_no}'`) !== '0');
equal('opening it clears the alert', (await get('clinical/results/unseen')).results.some(r => r.booking_id === inv.booking_id), false);
equal('marked seen by the doctor', sql(`SELECT seen_by FROM diagnostic_results WHERE booking_id = ${inv.booking_id}`), String(doctor.id));

// ─── A test from reception with no referring doctor ───────────────────────
await signIn();
const walkIn = await newPatient('Reception');
const invVisit = (await post('visits', {
  patient_id: walkIn.id, visit_type: 'consultation', sub_type: 'normal', destination: 'investigation',
  investigation_item_id: test.id, investigation_eye: 'left', investigation_reason: 'Own request',
})).visit;
await pay(invVisit.visit_no);
const deskBooking = Number(sql(`SELECT id FROM diagnostic_bookings WHERE visit_id = ${invVisit.id}`));
await reportTest(deskBooking, `Normal fields ${run}`);
await signIn(doctor.username, doctor.password);
bell = await get('clinical/results/unseen');
const unassigned = bell.results.find(r => r.booking_id === deskBooking);
check('a result with no referring doctor is shown to doctors', !!unassigned);
equal('marked as having no referring doctor', unassigned?.mine, false);

// ─── Patient history as a doctor ──────────────────────────────────────────
// A theatre case with an operation, a medicine handed over and a spectacle order (set up directly).
const surgeryItem = options.surgery_items.find(i => i.category === 'surgery');
sql(`INSERT INTO theatre_bookings (booking_no, patient_id, surgery_item_id, eye, surgeon_user_id, scheduled_for, status, created_by)
     VALUES ('TB-R${run}', ${walkIn.id}, ${surgeryItem.id}, 'left', ${doctor.id}, UTC_TIMESTAMP(), 'post_op', ${admin.id})`);
const theatreId = sql(`SELECT id FROM theatre_bookings WHERE booking_no = 'TB-R${run}'`);
sql(`INSERT INTO theatre_operations (booking_id, patient_id, status, anaesthesia_type, started_at, ended_at, completed_as_planned, completed_at, created_by)
     VALUES (${theatreId}, ${walkIn.id}, 'completed', 'local', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, UTC_TIMESTAMP(3), ${admin.id})`);
sql(`INSERT INTO theatre_operation_team (operation_id, user_id, role) SELECT id, ${doctor.id}, 'surgeon' FROM theatre_operations WHERE booking_id = ${theatreId}`);
const drug = options.drugs[0];
sql(`INSERT INTO pharmacy_orders (order_no, source, patient_id, status, created_by) VALUES ('PO-R${run}', 'prescription', ${walkIn.id}, 'completed', ${admin.id})`);
sql(`INSERT INTO pharmacy_dispensings (dispensing_no, order_id, dispensed_at, dispensed_by) SELECT 'DSP-R${run}', id, UTC_TIMESTAMP(3), ${admin.id} FROM pharmacy_orders WHERE order_no = 'PO-R${run}'`);
sql(`INSERT INTO pharmacy_dispensing_items (dispensing_id, drug_id, quantity) SELECT id, ${drug.id}, 2 FROM pharmacy_dispensings WHERE dispensing_no = 'DSP-R${run}'`);
const lens = options.lens_items[0];
sql(`INSERT INTO optical_orders (order_no, order_type, patient_id, status, created_by) VALUES ('OPT-R${run}', 'referral', ${walkIn.id}, 'ordered', ${admin.id})`);
sql(`INSERT INTO optical_order_items (order_id, kind, set_no, rx_label, price_item_id, description, unit_price)
     SELECT id, 'lens', 1, 'Reading', ${lens.id}, '${lens.name.replace(/'/g, "''")}', 1000 FROM optical_orders WHERE order_no = 'OPT-R${run}'`);

const history = await get(`clinical/patients/${walkIn.id}/history?all=1`);
equal('doctors may open results from history', history.can_view_results, true);
check('a visit for a test only is in the visit list', history.visits.some(v => v.id === invVisit.id));
const histTest = history.diagnostics.find(t => t.booking_id === deskBooking);
check('tests booked at reception are listed', !!histTest && histTest.result_ready === true);
const theatreCase = history.theatre.find(c => c.booking_no === `TB-R${run}`);
check('theatre cases are listed', !!theatreCase);
equal('with what was operated', theatreCase?.procedures, [surgeryItem.name]);
check('and, for a doctor, what was done', theatreCase?.operation?.anaesthesia === 'local' && theatreCase?.operation?.team?.[0]?.role === 'surgeon');
const handed = history.medicines.find(m => m.dispensing_no === `DSP-R${run}`);
equal('medicines handed over are listed', handed?.items, [{ name: drug.name, quantity: 2 }]);
const specs = history.optical.find(o => o.order_no === `OPT-R${run}`);
equal('spectacle orders are listed', specs?.items, [`Reading: ${lens.name}`]);

// ─── A nurse sees the status only ─────────────────────────────────────────
await signIn(nurse.username, nurse.password);
const nurseView = await get(`clinical/patients/${walkIn.id}/history?all=1`);
equal('a nurse cannot open results', nurseView.can_view_results, false);
check('but sees that the result is ready', nurseView.diagnostics.find(t => t.booking_id === deskBooking)?.result_ready === true);
const nurseCase = nurseView.theatre.find(c => c.booking_no === `TB-R${run}`);
check('and the theatre case with its status, without the operation notes', nurseCase?.status === 'post_op' && !('operation' in nurseCase));
check('nor any result text in the history', !JSON.stringify(nurseView).includes(`Normal fields ${run}`));
await expectError('a nurse cannot open a result directly', () => get(`clinical/results/${deskBooking}`), 403);
await expectError('nor its file', () => get(`clinical/results/${inv.booking_id}/files/${fileId}`), 403);
await expectError('and gets no result alerts', () => get('clinical/results/unseen'), 403);

report('results and history');
