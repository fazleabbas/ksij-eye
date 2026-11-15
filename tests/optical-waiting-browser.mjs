// On screen: "Order now" per pair on the confirm page; the pair left out waits in Incoming and
// is ordered later at its locked price.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();
const icd = (await api.get('clinical/options')).icd10[0];
const [lensA, lensB] = (await api.get('optical/options')).lenses;
const payBill = async billId => api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(api.sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Pairs${run}`, gender: 'female', date_of_birth: '1977-07-07',
  phone: `25570${run}1`, address: 'Sinza, Dar es Salaam', kin_name: 'Bw Omari', kin_relationship: 'Spouse', kin_phone: `25570${run}2`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 1' })).visit;
await payBill(api.sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
await api.post(`optometry/visits/${visit.id}/claim`);
await api.post(`optometry/visits/${visit.id}/complete`, {
  refer_optical: true, provisional_icd_ids: [icd.id], final_label: 'Distance', lens_item_ids: [lensA.id],
  refractions: { final: { sph_right: -1, sph_left: -1 } },
  extra_rx: [{ label: 'Reading', refraction: { sph_right: 2, sph_left: 2 }, lens_item_ids: [lensB.id] }],
});
const assessmentId = (await api.get('optical/incoming')).referrals.find(r => r.visit_id === visit.id).assessment_id;

await launch(9497);
await signIn();

// ─── Confirm only the distance pair ───────────────────────────────────────
await evaluate(`showModule('opt-incoming')`);
await waitUntil(`OPT.incoming.some(r => r.assessment_id === ${assessmentId})`, 15000, 'incoming');
await evaluate(`optOpenConfirm(${assessmentId})`);
await waitUntil(`document.querySelectorAll('#opt-create-order input[data-now]').length === 2`, 15000, 'order-now ticks');
check('each pair has "Order now", ticked', await evaluate(`[...document.querySelectorAll('#opt-create-order input[data-now]')].every(i => i.checked)`));
equal('the total starts with both pairs', await evaluate(`document.getElementById('opt-create-total').textContent.replace(/\\D/g, '')`), String(lensA.unit_price + lensB.unit_price));
await evaluate(`(() => { const box = document.querySelector('#opt-create-order input[data-now="2"]'); box.checked = false; box.dispatchEvent(new Event('change')); })()`);
equal('unticking the reading pair takes it off the total for today', await evaluate(`document.getElementById('opt-create-total').textContent.replace(/\\D/g, '')`), String(lensA.unit_price));
check('the total says one pair is for later', await evaluate(`document.getElementById('opt-create-total-label').textContent.includes('1 for later')`));
check('and disables its frame and lens change', await evaluate(`document.getElementById('opt-create-frame-2').disabled && document.querySelector('#opt-create-lens-2 button').disabled`));
await shot('optical-order-now', SHOTS);
await evaluate(`optConfirmOrder(document.querySelector('#opt-create-order .btn-primary'))`);
await sleep(1500);
equal('one order with the distance pair', api.sql(`SELECT GROUP_CONCAT(i.set_no) FROM optical_orders o JOIN optical_order_items i ON i.order_id = o.id WHERE o.assessment_id = ${assessmentId}`), '1');

// ─── Incoming: the reading pair waits ─────────────────────────────────────
await evaluate(`showModule('opt-incoming')`);
await waitUntil(`OPT.incoming.some(r => r.assessment_id === ${assessmentId} && r.waiting)`, 15000, 'waiting in incoming');
const row = await evaluate(`[...document.querySelectorAll('#opt-in-tbody tr')].find(r => r.textContent.includes('Pairs${run}')).textContent`);
check('Incoming shows the waiting pair and what was ordered earlier', row.includes('Waiting since') && row.includes('Ordered earlier') && row.includes('Order waiting pairs'), row.slice(0, 300));
await shot('optical-incoming-waiting', SHOTS);
await evaluate(`optOpenConfirm(${assessmentId})`);
await waitUntil(`document.getElementById('opt-create-order').textContent.includes('Ordered earlier')`, 15000, 'waiting confirm');
check('the confirm page offers only the waiting pair', await evaluate(`!document.getElementById('opt-create-lens-1') && !!document.getElementById('opt-create-lens-2')`));
await evaluate(`optConfirmOrder(document.querySelector('#opt-create-order .btn-primary'))`);
await sleep(1500);
equal('the reading pair has its own order now', api.sql(`SELECT COUNT(*) FROM optical_orders WHERE assessment_id = ${assessmentId} AND order_type = 'referral'`), '2');
check('nothing waits any more', api.sql(`SELECT status FROM optical_waiting_pairs WHERE assessment_id = ${assessmentId}`) === 'ordered');

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('optical waiting browser', SHOTS);
