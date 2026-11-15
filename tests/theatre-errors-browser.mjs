// Theatre forms say which field is wrong: a pre-op eye pressure of 95 is marked on that box,
// and a message with no box of its own names its field.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const admin = await api.signIn();
const surgery = Number(api.sql("SELECT id FROM price_items WHERE code = 'SURG-PTERYGIUM'"));
const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Preop${run}`, gender: 'male', date_of_birth: '1958-08-08',
  phone: `25573${run}4`, address: 'Mbagala, Dar es Salaam', kin_name: 'Mama Rehema', kin_relationship: 'Spouse', kin_phone: `25573${run}5`,
})).patient;
const booking = (await api.post('theatre/bookings', {
  patient_id: patient.id, surgery_item_id: surgery, surgeon_user_id: admin.id, eye: 'left', timing: 'now', procedures: [],
})).booking;

await launch(9517);
await signIn();
await evaluate(`showModule('theatre-preop')`);
await sleep(1000);
await evaluate(`thSelectPreop(${booking.id})`);
await waitUntil(`!!document.getElementById('th-pre-iop-r')`, 15000, 'pre-op form');
await evaluate(`(() => { document.getElementById('th-pre-iop-r').value = '95'; document.getElementById('th-pre-va-l').value = '6/9'; })()`);
await evaluate(`thSavePreop([...document.querySelectorAll('#th-preop-panel button')].find(b => b.textContent === 'Save'), false)`);
await waitUntil(`!!document.querySelector('#th-pre-iop-r.is-invalid')`, 10000, 'error on the field').catch(() => {});
const marked = await evaluate(`(() => { const box = document.getElementById('th-pre-iop-r'); return { invalid: box.classList.contains('is-invalid'), note: box.closest('.form-group')?.querySelector('.field-error')?.textContent || '' }; })()`);
check('the eye pressure box is marked', marked.invalid, JSON.stringify(marked));
equal('with the message under it', marked.note, 'Must be between 0 and 80.');
await shot('preop-field-error', SHOTS);

// Fallback: an error with no box of its own names the field in the message.
const unplaced = await evaluate(`KSI.showFieldErrors(document.createElement('div'), { iop_left: 'Must be between 0 and 80.', payment: 'The bill must be paid first.' })`);
equal('a message with no box names its field', unplaced, ['Pressure left (IOP): Must be between 0 and 80.', 'The bill must be paid first.']);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('theatre errors browser', SHOTS);
