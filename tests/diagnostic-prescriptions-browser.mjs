// On screen: the diagnostic report has a Medicines section; on submit the medicine goes to
// the pharmacy and the report shows what was sent instead of the editor.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const me = await api.signIn();
const options = await api.get('diagnostics/options');
const investigation = options.procedures.find(p => !p.is_laser);
const drug = options.drugs[1];

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `DiagRx${run}`, gender: 'male', date_of_birth: '1966-07-07',
  phone: `25578${run}1`, address: 'Sinza, Dar es Salaam', kin_name: 'Rehema', kin_relationship: 'Spouse', kin_phone: `25578${run}2`,
})).patient;
const booking = (await api.post('diagnostics/bookings', {
  patient_id: patient.id, referring_user_id: me.id, eye: 'left', timing: 'now', procedures: [{ price_item_id: investigation.id }],
})).bookings[0];
await api.post(`diagnostics/bookings/${booking.id}/check-in`, { no_charge: false });
await api.payDiagnostic(booking.id);

await launch(9512);
await signIn();
await evaluate(`showModule('diag-report')`);
await sleep(1000);
await evaluate(`diagSelectCase(${booking.id})`);
await waitUntil(`!!document.getElementById('diag-rep-drug')`, 15000, 'report form with medicines');
check('the report has a Medicines section', await evaluate(`document.getElementById('diag-report-panel').textContent.includes('Medicines')`));

await evaluate(`(() => {
  document.getElementById('diag-rep-doctor').value = '${me.id}';
  document.getElementById('diag-rep-result').value = 'Anterior uveitis, left eye.';
  const select = document.getElementById('diag-rep-drug');
  select.value = '${drug.id}';
  select.dispatchEvent(new Event('change'));
})()`);
await sleep(300);
check('the medicine is listed with its dose fields', await evaluate(`document.getElementById('diag-rep-meds').textContent.includes(${JSON.stringify(drug.name)})`));
check('and is no longer offered in the picker', await evaluate(`![...document.getElementById('diag-rep-drug').options].some(o => o.value === '${drug.id}')`));
await evaluate(`document.getElementById('diag-rep-pharm-instructions').value = 'Patient waiting'`);
await shot('diag-report-medicines', SHOTS);

await evaluate(`diagSaveReport(document.querySelector('#diag-report-panel .btn-primary'))`);
await waitUntil(`DIAG.selected?.report?.prescription_no && document.getElementById('diag-report-panel').textContent.includes('Medicine sent to the pharmacy')`, 15000, 'report saved');
await sleep(400);
check('the editor is replaced by what was sent', await evaluate(`!document.getElementById('diag-rep-drug') && document.getElementById('diag-report-panel').textContent.includes(${JSON.stringify(drug.name)})`));
equal('an issued prescription waits for the pharmacy', api.sql(`SELECT CONCAT(status, '/', pharmacy_instructions_enc IS NOT NULL) FROM prescriptions WHERE source = 'diagnostic' AND patient_id = ${patient.id}`), 'issued/1');
const queued = (await api.get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === patient.id);
check('and is in the pharmacy queue', !!queued);
await shot('diag-report-medicine-sent', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('diagnostic prescriptions browser', SHOTS);
