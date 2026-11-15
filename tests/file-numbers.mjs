// Patient file numbers continue the paper series. Run on an empty database (run-all puts it first):
// the paper range cannot be set once the system has numbered patients below it.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
if (Number(sql('SELECT COUNT(*) FROM patients')) > 0) {
  console.error('file-numbers.mjs needs a freshly reset database (sh tests/reset.sh).');
  process.exit(1);
}
const run = String(Date.now()).slice(-6);
let phone = 0;
const person = (lastName, extra = {}) => ({
  first_name: 'File', last_name: lastName, gender: 'female', date_of_birth: '1977-07-07',
  phone: `25572${run}${++phone}`, address: 'Ilala, Dar es Salaam',
  kin_name: 'Bibi Zawadi', kin_relationship: 'Parent', kin_phone: `25572${run}${phone}9`, ...extra,
});

// ─── Before go-live the paper range is not set ────────────────────────────
equal('no paper range to begin with', (await get('settings/registration-number')).paper_max, 0);
await expectError('a paper number cannot be used until the range is set',
  () => post('patients', person(`Early${run}`, { paper_file_no: '15' })), 422);

// ─── Set the paper range at go-live ───────────────────────────────────────
const set = await put('settings/paper-file-number', { paper_max: 5000 });
equal('new patients then start above the paper files', set.next_reg_no, '5001');
check('the paper range is not in the general settings list',
  !(await get('settings')).settings.some(s => s.key === 'patients.paper_max'));

// ─── New and existing patients ────────────────────────────────────────────
const fresh = (await post('patients', person(`New${run}`))).patient;
equal('a new patient gets the next number', fresh.reg_no, '5001');
equal('shown plainly', /^\d+$/.test(fresh.reg_no), true);
check('and is not marked as a paper file', fresh.paper_file === false);

const paper = (await post('patients', person(`Paper${run}`, { paper_file_no: '3127' }))).patient;
equal('an existing patient keeps their paper number', paper.reg_no, '3127');
check('and is marked as a paper file', paper.paper_file === true);
const zeros = (await post('patients', person(`Zeros${run}`, { paper_file_no: '0042' }))).patient;
equal('leading zeros are dropped', zeros.reg_no, '42');

try {
  await post('patients', person(`Twice${run}`, { paper_file_no: '3127' }));
  check('a paper number cannot be used twice', false);
} catch (error) {
  check('a paper number cannot be used twice, and it says who has it',
    error.status === 422 && (error.fields.paper_file_no || '').includes(`Paper${run}`), error.fields.paper_file_no);
}
await expectError('a number above the paper range is refused',
  () => post('patients', person(`Above${run}`, { paper_file_no: '7000' })), 422);
await expectError('letters are refused', () => post('patients', person(`Letters${run}`, { paper_file_no: 'A31' })), 422);
equal('new patients carry on after a paper registration', (await post('patients', person(`Next${run}`))).patient.reg_no, '5002');

// ─── Searching by number is exact ─────────────────────────────────────────
const found = (await get('patients?search=3127')).patients;
check('searching 3127 finds that file', found.length === 1 && found[0].id === paper.id, JSON.stringify(found.map(p => p.reg_no)));
check('and not other numbers containing those digits', !(await get('patients?search=312')).patients.some(p => p.id === paper.id));
equal('the old REG- form still finds it', (await get('patients?search=REG-3127')).patients[0]?.id, paper.id);

// ─── The rules on the settings ────────────────────────────────────────────
await expectError('the paper range cannot reach a number the system already gave out',
  () => put('settings/paper-file-number', { paper_max: 6000 }), 422);
await expectError('nor drop below a paper number already registered',
  () => put('settings/paper-file-number', { paper_max: 3000 }), 422);
await expectError('the next number cannot go into the paper range',
  () => put('settings/registration-number', { next_number: 4000 }), 422);

// ─── An administrator corrects a number ───────────────────────────────────
const corrected = (await put(`patients/${paper.id}/file-number`, { paper_file_no: '3128', reason: 'Typed 3127 instead of 3128' })).patient;
equal('a mistyped paper number can be corrected', corrected.reg_no, '3128');
check('with the reason in the audit log', sql(
  `SELECT COUNT(*) FROM audit_log WHERE action = 'patient.file_number_corrected' AND changes LIKE '%Typed 3127%'`) === '1');
await expectError('a correction cannot take a number someone else has',
  () => put(`patients/${paper.id}/file-number`, { paper_file_no: '42', reason: 'Clash' }), 422);
await expectError('a correction needs a reason',
  () => put(`patients/${paper.id}/file-number`, { paper_file_no: '3129' }), 422);
const converted = (await put(`patients/${fresh.id}/file-number`, { paper_file_no: '2222', reason: 'Had a paper file after all' })).patient;
check('a patient registered as new can be moved to their paper number', converted.reg_no === '2222' && converted.paper_file === true);

const receptionName = `desk${run}`;
const receptionPass = `Front-Desk-${run}-x9`;
await post('users', {
  first_name: 'Front', last_name: 'Desk', phone: `25572${run}88`, department_id: Number(sql("SELECT id FROM departments WHERE name = 'Reception'")),
  role_ids: [Number(sql("SELECT id FROM roles WHERE code = 'reception'"))], designation_ids: [],
  username: receptionName, password: receptionPass,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${receptionName}'`);
await signIn(receptionName, receptionPass);
await expectError('reception cannot correct a file number',
  () => put(`patients/${zeros.id}/file-number`, { paper_file_no: '43', reason: 'Trying' }), 403);
await signIn();

// ─── Counts ───────────────────────────────────────────────────────────────
const total = Number(sql('SELECT COUNT(*) FROM patients'));
const tile = (await get('dashboard')).tiles.find(t => t.label === 'Registered patients');
equal('the dashboard shows the registered patients', tile?.value, total);
const today = (await get('reports/options')).today;
const patientsReport = (await get('reports/run?' + new URLSearchParams({ type: 'patients', from: today, to: today }))).report;
equal('the Patients report shows the highest file number', patientsReport.kpis.find(k => k.label === 'Highest file number')?.value, 5002);

report('file numbers');
