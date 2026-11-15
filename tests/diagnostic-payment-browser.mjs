// A diagnostic report is written only once the test is paid for (or approved as No charge;
// a free test's bill is exempted at check-in). On screen the unpaid test shows why, not the form.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const me = await api.signIn();
const options = await api.get('diagnostics/options');
const paidTest = options.procedures.find(p => !p.is_laser && p.unit_price > 0);
const freeTest = options.procedures.find(p => !p.is_laser && p.id !== paidTest.id);

const patient = (await api.post('patients', {
  first_name: 'Unpaid', last_name: `Test${run}`, gender: 'male', date_of_birth: '1988-08-08',
  phone: `25571${run}1`, address: 'Buguruni, Dar es Salaam', kin_name: 'Asha', kin_relationship: 'Spouse', kin_phone: `25571${run}2`,
})).patient;
const book = async test => (await api.post('diagnostics/bookings', {
  patient_id: patient.id, referring_user_id: me.id, eye: 'right', timing: 'now', procedures: [{ price_item_id: test.id }],
})).bookings[0];
const write = id => api.put(`diagnostics/bookings/${id}/report`, { reporting_user_id: me.id, result: 'Normal study.', comments: '' });
async function refused(name, fn, phrase) {
  try { await fn(); check(name, false, 'was accepted'); }
  catch (error) { check(name, error.status === 409 && error.code === 'unpaid' && error.message.includes(phrase), `${error.status} ${error.code} ${error.message}`); }
}

// ─── API ──────────────────────────────────────────────────────────────────
const booking = await book(paidTest);
await refused('no report before the patient is checked in', () => write(booking.id), 'checked in');
await api.post(`diagnostics/bookings/${booking.id}/check-in`, { no_charge: false });
await refused('no report while the bill is unpaid', () => write(booking.id), 'cashier');

// A free test: its bill is exempted at check-in, so the report can be written straight away.
const freePrice = api.sql(`SELECT unit_price FROM price_items WHERE id = ${freeTest.id}`);
api.sql(`UPDATE price_items SET unit_price = 0 WHERE id = ${freeTest.id}`);
const free = await book(freeTest);
await api.post(`diagnostics/bookings/${free.id}/check-in`, { no_charge: false });
api.sql(`UPDATE price_items SET unit_price = ${freePrice} WHERE id = ${freeTest.id}`);
equal('a free test is reported without payment', (await write(free.id)).booking.status, 'completed');

// "No charge" waiting for approval: still no report.
const noCharge = await book(paidTest);
const reason = options.no_charge_reasons[0].label;
await api.post(`diagnostics/bookings/${noCharge.id}/check-in`, { no_charge: true, no_charge_reason: reason, remarks: 'Staff member' });
await refused('no report while "No charge" waits for approval', () => write(noCharge.id), 'approval');

// ─── On screen ────────────────────────────────────────────────────────────
await launch(9514);
await signIn();
await evaluate(`showModule('diag-report')`);
await sleep(1000);
await evaluate(`diagSelectCase(${booking.id})`);
await waitUntil(`document.getElementById('diag-report-panel').textContent.includes('Waiting for payment')`, 15000, 'payment note');
check('the unpaid test explains why', await evaluate(`document.getElementById('diag-report-panel').textContent.includes('pays at the cashier')`));
check('and shows no report form', await evaluate(`!document.getElementById('diag-rep-result') && !document.querySelector('#diag-report-panel .btn-primary')`));
check('the queue marks it as waiting for payment', await evaluate(`[...document.querySelectorAll('#diag-report-queue-body .diag-report-queue-item')].some(i => i.textContent.includes('Test${run}') && i.textContent.includes('Waiting for payment'))`));
await shot('diag-report-unpaid', SHOTS);

await api.payDiagnostic(booking.id);
await evaluate(`document.querySelector('#diag-report-panel .alert .btn').click()`);
await waitUntil(`!!document.getElementById('diag-rep-result')`, 15000, 'form after payment');
check('once paid, "Check again" opens the report form', await evaluate(`!!document.querySelector('#diag-report-panel .btn-primary')`));
await evaluate(`(() => {
  document.getElementById('diag-rep-doctor').value = '${me.id}';
  document.getElementById('diag-rep-result').value = 'Normal study after payment.';
  diagSaveReport(document.querySelector('#diag-report-panel .btn-primary'));
})()`);
await waitUntil(`DIAG.selected?.report?.result === 'Normal study after payment.'`, 15000, 'report saved');
check('and the report is saved', true);
await shot('diag-report-paid', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('diagnostic payment browser', SHOTS);
