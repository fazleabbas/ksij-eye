// Optometry books investigations and reads their results (new Optometrist role), records
// cataract biometry, and Theatre starts a new cataract booking from the latest biometry.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

const admin = await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;

const rolePerm = (role, perm) => sql(`SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
  WHERE r.code = '${role}' AND p.code = '${perm}'`) === '1';
check('there is an Optometrist role', sql("SELECT COUNT(*) FROM roles WHERE code = 'optometrist'") === '1');
check('which may book investigations', rolePerm('optometrist', 'optometry.investigations.request'));
check('and read results', rolePerm('optometrist', 'ophthalmology.results.view'));
check('the Clinical role (triage nurses) still cannot read results', !rolePerm('clinical', 'ophthalmology.results.view'));

async function account(role, prefix, designation = null) {
  const username = `${prefix}${run}`;
  const password = `Opto-${prefix}-${run}-x9`;
  tail++;
  await post('users', {
    first_name: 'Test', last_name: prefix, phone: `25571${run}${tail}`, department_id: Number(sql("SELECT id FROM departments WHERE name = 'Optometry'")),
    role_ids: [Number(sql(`SELECT id FROM roles WHERE code = '${role}'`))],
    designation_ids: designation ? [Number(sql(`SELECT id FROM designations WHERE code = '${designation}'`))] : [],
    username, password,
  });
  sql(`UPDATE users SET must_change_password = 0 WHERE username = '${username}'`);
  return { username, password, id: Number(sql(`SELECT id FROM users WHERE username = '${username}'`)) };
}
const optometrist = await account('optometrist', 'opto', 'optometrist');
const nurse = await account('clinical', 'onurse');

async function optometryVisit(lastName) {
  tail++;
  const patient = (await post('patients', {
    first_name: 'Opto', last_name: `${lastName}${run}`, gender: 'female', date_of_birth: '1958-08-08',
    phone: `25571${run}${tail}`, address: 'Temeke, Dar es Salaam', kin_name: 'Bw Ally', kin_relationship: 'Spouse', kin_phone: `25572${run}${tail}`,
  })).patient;
  return { patient, visit: await newVisit(patient) };
}
async function newVisit(patient) {
  await signIn();
  const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 1', confirm_open_visit: true })).visit;
  const bill = (await get('bills?status=pending')).bills.find(b => b.visit_no === visit.visit_no);
  await post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
  return visit;
}
const options = await get('clinical/options');
const icd = options.icd10[0];
const test = options.investigation_items[0];
const done = extra => ({ provisional_icd_ids: [icd.id], refractions: { final: { sph_right: -0.5 } }, ...extra });
const rightBio = {
  axial_length: '23.54', steep_k: '44.25', flat_k: '43.50', acd: '3.10', corneal_thickness: '540', lens_thickness: '4.50',
  white_to_white: '11.80', a_constant: '118.40', iol_model: 'SN60WF', iol_power: '21.50', remarks: 'Dense nuclear cataract',
};

// ─── Optometry books a test and records biometry ──────────────────────────
const { patient, visit } = await optometryVisit('Cataract');
await signIn(optometrist.username, optometrist.password);
equal('the Optometrist may book investigations from Optometry', (await get('clinical/options')).can.request_optometry_investigations, true);
await post(`optometry/visits/${visit.id}/claim`);
await expectError('an investigation needs its reason', () => put(`optometry/visits/${visit.id}`,
  done({ investigations: [{ price_item_id: test.id, eye: 'right', priority: 'routine' }] })), 422);
await expectError('biometry must be a sensible measurement', () => put(`optometry/visits/${visit.id}`,
  done({ biometry: { right: { axial_length: '55' } } })), 422);

let ws = await put(`optometry/visits/${visit.id}`, done({
  investigations: [{ price_item_id: test.id, eye: 'right', priority: 'urgent', reason: 'Biometry check before cataract surgery' }],
  biometry: { right: rightBio },
}));
equal('the investigation is kept as a draft', ws.record.investigations.map(x => x.status), ['draft']);
equal('biometry is saved for the right eye', ws.record.biometry.right.axial_length, 23.54);
equal('with the IOL the optometrist chose', [ws.record.biometry.right.iol_model, ws.record.biometry.right.iol_power], ['SN60WF', 21.5]);
equal('nothing for the left eye', ws.record.biometry.left.axial_length, null);
equal('remarks are stored encrypted', sql(`SELECT COUNT(*) FROM optometry_biometry WHERE remarks_enc LIKE '%Dense%'`), '0');

ws = await post(`optometry/visits/${visit.id}/complete`, done({
  row_version: ws.record.row_version,
  investigations: [{ price_item_id: test.id, eye: 'right', priority: 'urgent', reason: 'Biometry check before cataract surgery' }],
  biometry: { right: rightBio },
}));
const inv = ws.record.investigations[0];
check('completing books the test at Diagnostics', !!inv?.booking_id && inv.status === 'booked');
equal('as requested by Optometry', sql(`SELECT CONCAT(source, '|', referring_user_id) FROM diagnostic_bookings WHERE id = ${inv.booking_id}`), `optometry|${optometrist.id}`);
equal('against the optometry assessment', sql(`SELECT CONCAT(source, '|', assessment_id IS NOT NULL, '|', consultation_id IS NULL) FROM investigation_requests WHERE id = ${inv.id}`), 'optometry|1|1');
check('without a bill until the patient checks in', sql(`SELECT bill_id IS NULL FROM diagnostic_bookings WHERE id = ${inv.booking_id}`) === '1');
check('the visit history says so', sql(`SELECT COUNT(*) FROM visit_status_history WHERE visit_id = ${visit.id} AND note LIKE '%1 investigation booked at Diagnostics%'`) === '1');

// ─── The result comes back to the optometrist ─────────────────────────────
await signIn();
await post(`diagnostics/bookings/${inv.booking_id}/check-in`, { no_charge: false });
await put(`diagnostics/bookings/${inv.booking_id}/report`, { reporting_user_id: admin.id, result: `AL 23.52 confirmed ${run}`, comments: '' });
await signIn(optometrist.username, optometrist.password);
check('the optometrist is told the result is ready', (await get('clinical/results/unseen')).results.some(r => r.booking_id === inv.booking_id && r.mine));
equal('the Optometry record shows the result is ready', (await get(`optometry/visits/${visit.id}`)).record.investigations[0].result_ready, true);
equal('and the optometrist can read it', (await get(`clinical/results/${inv.booking_id}`)).result.report.result, `AL 23.52 confirmed ${run}`);
equal('which clears the alert', (await get('clinical/results/unseen')).results.some(r => r.booking_id === inv.booking_id), false);

await signIn(nurse.username, nurse.password);
await expectError('a nurse with the Clinical role cannot read it', () => get(`clinical/results/${inv.booking_id}`), 403);

// ─── Corrections may fix a biometry figure ────────────────────────────────
await signIn(optometrist.username, optometrist.password);
const completed = (await get(`optometry/visits/${visit.id}`)).record;
await put(`optometry/visits/${visit.id}`, done({ row_version: completed.row_version, biometry: { right: { ...rightBio, axial_length: '23.60' } } }));
equal('the optometrist corrects the axial length', (await get(`optometry/visits/${visit.id}`)).record.biometry.right.axial_length, 23.6);
check('and the correction is logged', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'record.corrected' AND entity_id = '${visit.visit_no}' AND changes LIKE '%biometry%'`) === '1');

// ─── Theatre starts a cataract booking from the latest biometry ───────────
await signIn();
let latest = (await get(`theatre/patients/${patient.id}/biometry`)).biometry;
equal('Theatre finds the right-eye biometry', latest.right?.axial_length, 23.6);
equal('with where it came from', [latest.right?.visit_no, latest.right?.recorded_by], [visit.visit_no, `Test opto`]);
equal('and nothing for the left eye', latest.left, null);

// A later visit: the newest biometry wins, however old the earlier one is; a draft does not count.
sql(`UPDATE optometry_assessments SET completed_at = completed_at - INTERVAL 400 DAY WHERE visit_id = ${visit.id}`);
const second = await newVisit(patient);
await signIn(optometrist.username, optometrist.password);
await post(`optometry/visits/${second.id}/claim`);
await post(`optometry/visits/${second.id}/complete`, done({ biometry: { right: { axial_length: '23.70', steep_k: '44.00' }, left: { axial_length: '23.40' } } }));
await signIn();
latest = (await get(`theatre/patients/${patient.id}/biometry`)).biometry;
equal('the latest visit\'s biometry is used', [latest.right?.axial_length, latest.right?.visit_no], [23.7, second.visit_no]);
equal('for each eye', latest.left?.axial_length, 23.4);

const third = await newVisit(patient);
await signIn(optometrist.username, optometrist.password);
await post(`optometry/visits/${third.id}/claim`);
await put(`optometry/visits/${third.id}`, done({ biometry: { right: { axial_length: '30.00' } } }));
await signIn();
equal('a draft that is not completed is not used', (await get(`theatre/patients/${patient.id}/biometry`)).biometry.right?.axial_length, 23.7);

// The booking keeps its own copy, now including lens thickness and white-to-white.
const surgeon = Number(sql("SELECT u.id FROM users u WHERE u.username = 'sysadmin'"));
const cataract = (await get('theatre/options')).surgeries.find(s => s.name.toLowerCase().includes('cataract'));
const booked = (await post('theatre/bookings', {
  patient_id: patient.id, surgery_item_id: cataract.id, surgeon_user_id: surgeon, eye: 'right', timing: 'now', procedures: [],
  biometry: { ...latest.right, iol_power: '22.00', lens_thickness: '4.60', white_to_white: '11.90' },
})).booking;
const saved = (await get(`theatre/bookings/${booked.id}`)).booking.biometry;
equal('the theatre booking keeps the figures, changed where needed', [saved.axial_length, saved.iol_power, saved.lens_thickness, saved.white_to_white], [23.7, 22, 4.6, 11.9]);
equal('the Optometry record is not changed by it', (await get(`optometry/visits/${second.id}`)).record.biometry.right.iol_power, null);

await signIn(nurse.username, nurse.password);
await expectError('only theatre bookers can look biometry up for a booking', () => get(`theatre/patients/${patient.id}/biometry`), 403);

report('optometry orders and biometry');
