// Several spectacle prescriptions: the patient orders some pairs now and the rest later. The
// pairs left over wait in Incoming at the lens price locked on the first order (no expiry),
// until ordered or closed by hand.
import { signIn, get, post, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;
const icd = (await get('clinical/options')).icd10[0];
const [lensA, lensB, lensC] = (await get('optical/options')).lenses;

async function payBill(billId) {
  await post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });
}
async function twoPairReferral() {
  tail++;
  const patient = (await post('patients', {
    first_name: 'Pairs', last_name: `Later${run}${tail}`, gender: 'male', date_of_birth: '1980-08-08',
    phone: `25577${run}${tail}`, address: 'Ubungo, Dar es Salaam', kin_name: 'Mama Rukia', kin_relationship: 'Spouse', kin_phone: `25579${run}${tail}`,
  })).patient;
  const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 1' })).visit;
  await payBill(sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
  await post(`optometry/visits/${visit.id}/claim`);
  await post(`optometry/visits/${visit.id}/complete`, {
    refer_optical: true, provisional_icd_ids: [icd.id], final_label: 'Distance', lens_item_ids: [lensA.id],
    refractions: { final: { sph_right: -1.5, sph_left: -1.25 } },
    extra_rx: [{ label: 'Reading', refraction: { sph_right: 1.5, sph_left: 1.5 }, lens_item_ids: [lensB.id] }],
  });
  return { patient, visit, assessmentId: (await get('optical/incoming')).referrals.find(r => r.visit_id === visit.id).assessment_id };
}
const incoming = async assessmentId => (await get('optical/incoming')).referrals.find(r => r.assessment_id === assessmentId);
const originalB = lensB.unit_price;

// ─── Order the distance pair now, the reading pair later ──────────────────
const first = await twoPairReferral();
await expectError('at least one pair must be ordered now', () => post('optical/orders', { assessment_id: first.assessmentId, set_nos: [] }), 422);
await expectError('only pairs on this prescription', () => post('optical/orders', { assessment_id: first.assessmentId, set_nos: [7] }), 422);
const now = (await post('optical/orders', { assessment_id: first.assessmentId, set_nos: [1] })).order;
const nowItems = (await get(`optical/orders/${now.id}`)).order.items;
equal('only the pair taken now is ordered', nowItems.map(i => i.set_no), [1]);
equal('and billed', now.bill.amount, lensA.unit_price);

let waiting = await incoming(first.assessmentId);
check('the prescription stays in Incoming for the pair left over', !!waiting?.waiting);
equal('with just that pair', waiting?.waiting.map(p => [p.set_no, p.label]), [[2, 'Reading']]);
equal('and which order took the rest', waiting?.ordered_orders, [now.order_no]);
equal('its lens and locked price', waiting?.lenses.map(l => [l.price_item_id, l.unit_price]), [[lensB.id, originalB]]);
check('the dashboard counts it as still to order', (await get('dashboard')).waiting.find(w => w.module === 'opt-incoming')?.lines.some(l => l.label === 'Prescriptions to order' && l.value >= 1));

// ─── Later: the price list has gone up; the locked price stands ───────────
sql(`UPDATE price_items SET unit_price = unit_price + 5000 WHERE id = ${lensB.id}`);
const later = (await post('optical/orders', { assessment_id: first.assessmentId })).order;
const laterItems = (await get(`optical/orders/${later.id}`)).order.items;
equal('the waiting pair is ordered on its own order', laterItems.map(i => [i.set_no, i.price_item_id]), [[2, lensB.id]]);
equal('at the price locked on the first order', later.bill.amount, originalB);
check('the prescription leaves Incoming', !(await incoming(first.assessmentId)));
await expectError('nothing more can be ordered from it', () => post('optical/orders', { assessment_id: first.assessmentId }), 409);
equal('the pair is marked ordered by that order', sql(`SELECT CONCAT(status, '|', order_id) FROM optical_waiting_pairs WHERE assessment_id = ${first.assessmentId}`), `ordered|${later.id}`);

// A later order that is cancelled gives its pair back to the waiting list.
await post(`optical/orders/${later.id}/cancel`, { reason: 'Patient changed their mind' });
const approval = (await get('approvals?status=pending')).approvals?.find(a => a.optical_order_id === later.id || (a.reference || '').includes(later.order_no))
  ?? { id: Number(sql(`SELECT id FROM approval_requests WHERE optical_order_id = ${later.id} ORDER BY id DESC LIMIT 1`)) };
await post(`approvals/${approval.id}/approve`, { row_version: (await get(`approvals/${approval.id}`)).approval.row_version });
equal('the cancelled order\'s pair is waiting again', (await incoming(first.assessmentId))?.waiting?.map(p => p.set_no), [2]);

// ─── A lens changed when ordering later is priced on that day ─────────────
const second = await twoPairReferral();
await post('optical/orders', { assessment_id: second.assessmentId, set_nos: [1] });
const changed = (await post('optical/orders', {
  assessment_id: second.assessmentId, lenses: [{ set_no: 2, lens_ids: [lensC.id], reason: 'Thinner reading lens' }],
})).order;
equal('the new lens is at today\'s price', changed.bill.amount, Number(sql(`SELECT unit_price FROM price_items WHERE id = ${lensC.id}`)));
equal('and the change is recorded', (await get(`optical/orders/${changed.id}`)).order.lens_changes.map(c => [c.set_no, c.reason]), [[2, 'Thinner reading lens']]);

// ─── Closing waiting pairs ────────────────────────────────────────────────
const third = await twoPairReferral();
await post('optical/orders', { assessment_id: third.assessmentId, set_nos: [2] });
equal('either pair may wait', (await incoming(third.assessmentId))?.waiting?.map(p => p.set_no), [1]);
await expectError('closing needs a reason', () => post(`optical/referrals/${third.assessmentId}/close-waiting`, { reason: '' }), 422);
equal('closing the waiting pairs', (await post(`optical/referrals/${third.assessmentId}/close-waiting`, { reason: 'No longer wanted' })).closed, 1);
check('removes the prescription from Incoming', !(await incoming(third.assessmentId)));
check('and is in the audit log', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'optical.waiting_closed'`) !== '0');
await expectError('there is then nothing to close', () => post(`optical/referrals/${third.assessmentId}/close-waiting`, { reason: 'Again' }), 409);

// ─── Ordering everything at once still works as before ────────────────────
const all = await twoPairReferral();
const together = (await post('optical/orders', { assessment_id: all.assessmentId })).order;
equal('both pairs on one order', (await get(`optical/orders/${together.id}`)).order.items.map(i => i.set_no).sort(), [1, 2]);
check('nothing waits', !(await incoming(all.assessmentId)));

sql(`UPDATE price_items SET unit_price = ${originalB} WHERE id = ${lensB.id}`);
report('optical waiting pairs');
