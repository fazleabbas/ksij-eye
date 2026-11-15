// On screen: Pre-Op lists only the patients still waiting for clearance and In Theatre only those
// waiting for their operation; a "Sent" switch on each shows the ones already moved on.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const admin = await api.signIn();
const cataract = Number(api.sql("SELECT id FROM price_items WHERE code = 'SURG-CATARACT-MSICS'"));

let seq = 0;
async function newCase(name) {
  seq += 1;
  const patient = (await api.post('patients', {
    first_name: name, last_name: `Flow${run}`, gender: 'male', date_of_birth: '1958-04-04',
    phone: `25579${seq}${run}1`, address: 'Temeke, Dar es Salaam', kin_name: 'Neema', kin_relationship: 'Spouse', kin_phone: `25579${seq}${run}2`,
  })).patient;
  const booking = (await api.post('theatre/bookings', {
    patient_id: patient.id, surgery_item_id: cataract, surgeon_user_id: admin.id, eye: 'right', timing: 'now', procedures: [], biometry: { axial_length: '23.0' },
  })).booking;
  return { patient, booking };
}
// One waiting for pre-op, one cleared (waiting for theatre), one operated (under post-op care).
const waiting = await newCase('Waiting');
const cleared = await newCase('Cleared');
const operated = await newCase('Operated');
for (const c of [cleared, operated]) {
  api.sql(`INSERT INTO theatre_preop_assessments (booking_id, patient_id, status, completed_at, completed_by, created_by)
           VALUES (${c.booking.id}, ${c.patient.id}, 'cleared', UTC_TIMESTAMP(3) - INTERVAL 3 DAY, ${admin.id}, ${admin.id})`);
}
api.sql(`UPDATE theatre_bookings SET status = 'cleared' WHERE id = ${cleared.booking.id}`);
api.sql(`UPDATE theatre_bookings SET status = 'post_op' WHERE id = ${operated.booking.id}`);

await launch(9513);
await signIn();
const listed = (queue, name) => evaluate(`document.getElementById('${queue}').textContent.includes('${name} Flow${run}')`);
const stage = async (prefix, value) => {
  await evaluate(`(() => { const s = document.getElementById('${prefix}-filter-stage'); s.value = '${value}'; s.dispatchEvent(new Event('change')); })()`);
  await sleep(1200);
};

// ─── Pre-op ───────────────────────────────────────────────────────────────
await evaluate(`showModule('theatre-preop')`);
await waitUntil(`document.getElementById('th-preop-queue').textContent.includes('Waiting Flow${run}')`, 15000, 'pre-op queue');
check('pre-op lists the patient waiting for clearance', await listed('th-preop-queue', 'Waiting'));
check('but not the patient already cleared for theatre', !(await listed('th-preop-queue', 'Cleared')));
check('nor the one already operated', !(await listed('th-preop-queue', 'Operated')));
await stage('th-preop', 'sent');
check('"Sent to theatre" lists the cleared and operated patients', await listed('th-preop-queue', 'Cleared') && await listed('th-preop-queue', 'Operated'));
check('and not the one still waiting', !(await listed('th-preop-queue', 'Waiting')));
check('the queue title follows the switch', await evaluate(`document.getElementById('th-preop-title').textContent === 'Sent to theatre'`));
await evaluate(`thSelectPreop(${operated.booking.id})`);
await waitUntil(`!!document.getElementById('th-pre-va-r')`, 10000, 'pre-op record');
check('an operated patient\'s pre-op is read-only', await evaluate(`document.getElementById('th-pre-va-r').disabled && !document.getElementById('th-preop-panel').textContent.includes('Clear for theatre')`));
await shot('preop-sent', SHOTS);
await stage('th-preop', '');
check('switching back shows the waiting list again', await listed('th-preop-queue', 'Waiting') && !(await listed('th-preop-queue', 'Cleared')));
await shot('preop-waiting', SHOTS);

// ─── In theatre ───────────────────────────────────────────────────────────
await evaluate(`showModule('theatre-intraop')`);
await waitUntil(`document.getElementById('th-op-queue').textContent.includes('Cleared Flow${run}')`, 15000, 'theatre queue');
check('In Theatre lists the cleared patient', await listed('th-op-queue', 'Cleared'));
check('but not the patient already sent to post-op', !(await listed('th-op-queue', 'Operated')));
await stage('th-op', 'sent');
check('"Sent to post-op" lists the operated patient', await listed('th-op-queue', 'Operated'));
check('and not the one still waiting for theatre', !(await listed('th-op-queue', 'Cleared')));
await shot('intraop-sent', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('theatre worklists browser', SHOTS);
