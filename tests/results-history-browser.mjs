// Results and the fuller patient history on screen, as a real Doctor and as a nurse:
// the bell, "View result" on the consultation, and the Orders tab of Patient History.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const admin = await api.signIn();

let tail = 0;
async function account(role, prefix, designation = null) {
  const username = `${prefix}${run}`;
  const password = `Screen-${prefix}-${run}-x9`;
  tail++;
  await api.post('users', {
    first_name: 'Screen', last_name: prefix, phone: `25579${run}${tail}`, department_id: Number(api.sql('SELECT MIN(id) FROM departments')),
    role_ids: [Number(api.sql(`SELECT id FROM roles WHERE code = '${role}'`))],
    designation_ids: designation ? [Number(api.sql(`SELECT id FROM designations WHERE code = '${designation}'`))] : [],
    username, password,
  });
  api.sql(`UPDATE users SET must_change_password = 0 WHERE username = '${username}'`);
  return { username, password, id: Number(api.sql(`SELECT id FROM users WHERE username = '${username}'`)) };
}
const doctor = await account('doctor', 'sdoc', 'ophthalmologist');
const nurse = await account('clinical', 'snurse');

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Results${run}`, gender: 'female', date_of_birth: '1966-06-06',
  phone: `25579${run}9`, address: 'Mikocheni, Dar es Salaam', kin_name: 'Bw Saidi', kin_relationship: 'Spouse', kin_phone: `25579${run}8`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
const bill = (await api.get('bills?status=pending')).bills.find(b => b.visit_no === visit.visit_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
const options = await api.get('clinical/options');
const icd = options.icd10[0];
await api.signIn(doctor.username, doctor.password);
await api.post(`ophthalmology/visits/${visit.id}/claim`);
await api.post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  investigations: [{ price_item_id: options.investigation_items[0].id, eye: 'left', priority: 'urgent', reason: 'Field loss' }],
});
const bookingId = (await api.get(`ophthalmology/visits/${visit.id}`)).record.investigations[0].booking_id;
await api.signIn();
await api.post(`diagnostics/bookings/${bookingId}/check-in`, { no_charge: false });
await api.put(`diagnostics/bookings/${bookingId}/report`, { reporting_user_id: admin.id, result: `Superior arcuate defect ${run}`, comments: 'Correlate with OCT' });
// A theatre case with an operation, and a medicine handed over.
const surgeryItem = options.surgery_items.find(i => i.category === 'surgery');
api.sql(`INSERT INTO theatre_bookings (booking_no, patient_id, surgery_item_id, eye, surgeon_user_id, scheduled_for, status, created_by)
     VALUES ('TB-S${run}', ${patient.id}, ${surgeryItem.id}, 'left', ${doctor.id}, UTC_TIMESTAMP(), 'post_op', ${admin.id})`);
api.sql(`INSERT INTO theatre_operations (booking_id, patient_id, status, anaesthesia_type, started_at, ended_at, completed_as_planned, completed_at, created_by)
     SELECT id, ${patient.id}, 'completed', 'local', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, UTC_TIMESTAMP(3), ${admin.id} FROM theatre_bookings WHERE booking_no = 'TB-S${run}'`);
api.sql(`INSERT INTO theatre_operation_team (operation_id, user_id, role) SELECT o.id, ${doctor.id}, 'surgeon' FROM theatre_operations o JOIN theatre_bookings b ON b.id = o.booking_id WHERE b.booking_no = 'TB-S${run}'`);
api.sql(`INSERT INTO pharmacy_orders (order_no, source, patient_id, status, created_by) VALUES ('PO-S${run}', 'prescription', ${patient.id}, 'completed', ${admin.id})`);
api.sql(`INSERT INTO pharmacy_dispensings (dispensing_no, order_id, dispensed_at, dispensed_by) SELECT 'DSP-S${run}', id, UTC_TIMESTAMP(3), ${admin.id} FROM pharmacy_orders WHERE order_no = 'PO-S${run}'`);
api.sql(`INSERT INTO pharmacy_dispensing_items (dispensing_id, drug_id, quantity) SELECT id, ${options.drugs[0].id}, 1 FROM pharmacy_dispensings WHERE dispensing_no = 'DSP-S${run}'`);
const found = (await api.get('patients?' + new URLSearchParams({ search: patient.reg_no }))).patients.find(p => p.id === patient.id);

await launch(9467);

// ─── As the doctor ────────────────────────────────────────────────────────
await signIn(doctor.username, doctor.password);
await waitUntil(`document.getElementById('notif-count').style.display === '' && Number(document.getElementById('notif-count').textContent) >= 1`, 15000, 'bell count');
check('the bell shows a result is ready', true);
const before = Number(await evaluate(`document.getElementById('notif-count').textContent`));
await evaluate(`document.getElementById('notif-btn').click()`);
await sleep(300);
const bellText = await evaluate(`document.getElementById('notif-list').textContent`);
check('naming the test and the patient', bellText.includes('Result ready') && bellText.includes(`Results${run}`), bellText.slice(0, 200));
await shot('bell-result-ready', SHOTS);

// The consultation: the investigation card says the result is ready.
await evaluate(`document.getElementById('notif-dropdown').classList.remove('open')`);
await evaluate(`showModule('ophthalmology')`);
await sleep(800);
await evaluate(`OPH.select(${visit.id})`);
await waitUntil(`document.getElementById('oph-form').style.display === '' && !!document.querySelector('#oph-inv .badge')`, 15000, 'consultation');
check('the consultation shows "Result ready"', await evaluate(`document.getElementById('oph-inv').textContent.includes('Result ready')`));
await evaluate(`document.querySelector('#oph-inv button[onclick^="clResultOpen"]').click()`);
await waitUntil(`document.getElementById('modal-cl-result').classList.contains('open')`, 10000, 'result opens');
const modal = await evaluate(`document.getElementById('clr-body').textContent`);
check('"View result" shows the report', modal.includes(`Superior arcuate defect ${run}`) && modal.includes('Correlate with OCT'), modal.slice(0, 200));
await shot('consultation-view-result', SHOTS);
await evaluate(`closeModal('modal-cl-result')`);
// Other doctors' unassigned results may still be waiting, so the count goes down by this one.
const countNow = `(document.getElementById('notif-count').style.display === 'none' ? 0 : Number(document.getElementById('notif-count').textContent))`;
await waitUntil(`${countNow} === ${before - 1}`, 10000, 'bell count drops').catch(() => {});
equal('once read, it leaves the bell', await evaluate(countNow), before - 1);

// Patient History: the Orders tab now carries everything.
await evaluate(`showModule('patient-history')`);
await sleep(500);
await evaluate(`histSelect(${JSON.stringify(found)})`);
await sleep(800);
await evaluate(`histTab('orders')`);
await waitUntil(`document.getElementById('ph-body').textContent.includes('Tests and results')`, 15000, 'orders tab');
const orders = await evaluate(`document.getElementById('ph-body').textContent`);
check('Patient History lists tests with results', orders.includes('Result ready'));
check('theatre cases with what was done', orders.includes('Theatre') && orders.includes('Surgeon:'), orders.slice(0, 300));
check('medicines handed over', orders.includes('Medicines handed over') && orders.includes(`DSP-S${run}`));
check('doctors can open the result from history', await evaluate(`!!document.querySelector('#ph-body button[onclick^="clResultOpen"]')`));
await shot('patient-history-orders-doctor', SHOTS);
await evaluate(`KSI.post('auth/logout').catch(() => {}).then(() => true)`);

// ─── As a nurse ───────────────────────────────────────────────────────────
await signIn(nurse.username, nurse.password);
await sleep(800);
check('a nurse gets no result alerts', await evaluate(`document.getElementById('notif-count').style.display === 'none' && document.getElementById('notif-list').textContent.includes('No new notifications')`));
await evaluate(`showModule('patient-history')`);
await sleep(500);
await evaluate(`histSelect(${JSON.stringify(found)})`);
await sleep(800);
await evaluate(`histTab('orders')`);
await waitUntil(`document.getElementById('ph-body').textContent.includes('Tests and results')`, 15000, 'nurse orders tab');
const nurseOrders = await evaluate(`document.getElementById('ph-body').textContent`);
check('a nurse sees the status', nurseOrders.includes('Result ready') && nurseOrders.includes('Theatre'));
check('but cannot open the result', await evaluate(`!document.querySelector('#ph-body button[onclick^="clResultOpen"]')`));
check('nor read the operation details', !nurseOrders.includes('Surgeon:') && !nurseOrders.includes(`Superior arcuate defect ${run}`));
await shot('patient-history-orders-nurse', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('results and history browser', SHOTS);
