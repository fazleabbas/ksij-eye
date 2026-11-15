// Changing the lens on screen: on the confirm page, and from the order window while unpaid.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();
const icd = (await api.get('clinical/options')).icd10[0];
const [lensA, lensB, lensC] = (await api.get('optical/options')).lenses;

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Lens${run}`, gender: 'male', date_of_birth: '1985-05-05',
  phone: `25576${run}7`, address: 'Mbagala, Dar es Salaam', kin_name: 'Mama Halima', kin_relationship: 'Parent', kin_phone: `25576${run}8`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 1' })).visit;
// Bills are looked up directly: the bill list is paged, and a long test run leaves many pending.
async function payBill(billId) {
  await api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(api.sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });
}
await payBill(api.sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
await api.post(`optometry/visits/${visit.id}/claim`);
await api.post(`optometry/visits/${visit.id}/complete`, {
  refer_optical: true, lens_item_ids: [lensA.id], provisional_icd_ids: [icd.id], refractions: { final: { sph_right: -2, sph_left: -2 } },
});
const assessmentId = (await api.get('optical/incoming')).referrals.find(r => r.visit_id === visit.id).assessment_id;

await launch(9487);
await signIn();

// ─── Confirm page ─────────────────────────────────────────────────────────
await evaluate(`showModule('opt-incoming')`);
await waitUntil(`OPT.incoming.some(r => r.assessment_id === ${assessmentId})`, 15000, 'incoming');
await evaluate(`optOpenConfirm(${assessmentId})`);
await waitUntil(`!!document.querySelector('#opt-create-lens-1 button')`, 15000, 'confirm page');
check('each pair has "Change lens"', await evaluate(`document.querySelector('#opt-create-lens-1 button').textContent.includes('Change lens')`));
await evaluate(`document.querySelector('#opt-create-lens-1 button').click()`);
await waitUntil(`document.getElementById('modal-opt-change-lens').classList.contains('open')`, 10000, 'lens modal');
equal('Optometry\'s lens is ticked to start with', await evaluate(`[...document.querySelectorAll('#opt-lens-list input:checked')].map(i => Number(i.value))`), [lensA.id]);
check('and the modal says what Optometry chose', await evaluate(`document.getElementById('opt-lens-info').textContent.includes(${JSON.stringify(lensA.name)})`));
await evaluate(`optSubmitLensChange(document.getElementById('opt-lens-save'))`);
check('a reason is required', await evaluate(`document.getElementById('modal-opt-change-lens').classList.contains('open') && document.getElementById('opt-lens-form').textContent.includes('Say why')`));
await evaluate(`(() => {
  document.querySelectorAll('#opt-lens-list input').forEach(i => { i.checked = Number(i.value) === ${lensB.id}; });
  optLensChangeTotal();
  document.getElementById('opt-lens-reason').value = 'Patient wants photochromic';
  optSubmitLensChange(document.getElementById('opt-lens-save'));
})()`);
await sleep(300);
const confirmBox = await evaluate(`document.getElementById('opt-create-lens-1').textContent`);
check('the pair now shows the new lens, what it replaced and why', confirmBox.includes(lensB.name) && confirmBox.includes('Changed from') && confirmBox.includes('Patient wants photochromic'), confirmBox.slice(0, 200));
check('the total follows the new lens', await evaluate(`document.getElementById('opt-create-total').textContent.replace(/\\D/g, '')`) === String(lensB.unit_price));
await shot('optical-confirm-lens-changed', SHOTS);
await evaluate(`optConfirmOrder(document.querySelector('#opt-create-order .btn-primary'))`);
await sleep(1500);
const orderId = Number(api.sql(`SELECT id FROM optical_orders WHERE assessment_id = ${assessmentId}`));
equal('the order is raised with the patient\'s lens', api.sql(`SELECT price_item_id FROM optical_order_items WHERE order_id = ${orderId} AND kind = 'lens'`), String(lensB.id));

// ─── Order window, unpaid ─────────────────────────────────────────────────
await evaluate(`optOpenDetail(${orderId}, 'active')`);
await waitUntil(`document.getElementById('modal-opt-detail').classList.contains('open')`, 10000, 'order window');
check('the order lists the lens change', await evaluate(`document.getElementById('opt-detail-body').textContent.includes('Lens changes') && document.getElementById('opt-detail-body').textContent.includes('Patient wants photochromic')`));
check('and offers "Change lens" while unpaid', await evaluate(`[...document.querySelectorAll('#opt-detail-footer button')].some(b => b.textContent === 'Change lens')`));
await evaluate(`[...document.querySelectorAll('#opt-detail-footer button')].find(b => b.textContent === 'Change lens').click()`);
await waitUntil(`document.getElementById('modal-opt-change-lens').classList.contains('open')`, 10000, 'lens modal from order');
check('it warns the bill is re-raised', await evaluate(`document.getElementById('opt-lens-info').textContent.includes('will be cancelled')`));
await evaluate(`(() => {
  document.querySelectorAll('#opt-lens-list input').forEach(i => { i.checked = Number(i.value) === ${lensC.id}; });
  document.getElementById('opt-lens-reason').value = 'Cheaper option after all';
})()`);
await evaluate(`optSubmitLensChange(document.getElementById('opt-lens-save'))`);
await waitUntil(`!document.getElementById('modal-opt-change-lens').classList.contains('open')`, 10000, 'changed').catch(() => {});
await sleep(1000);
equal('the order now has that lens', api.sql(`SELECT price_item_id FROM optical_order_items WHERE order_id = ${orderId} AND kind = 'lens'`), String(lensC.id));
check('the window shows both changes', await evaluate(`document.getElementById('opt-detail-body').textContent.includes('Cheaper option after all')`));
await shot('optical-order-lens-changed', SHOTS);

// ─── Paid: the button is gone ─────────────────────────────────────────────
await payBill(api.sql(`SELECT bill_id FROM optical_orders WHERE id = ${orderId}`));
await evaluate(`optOpenDetail(${orderId}, 'active')`);
await sleep(800);
check('once paid, "Change lens" is no longer offered', await evaluate(`![...document.querySelectorAll('#opt-detail-footer button')].some(b => b.textContent === 'Change lens')`));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('lens change browser', SHOTS);
