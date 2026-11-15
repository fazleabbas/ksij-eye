// The patient wants another lens than Optometry chose. The Optical Room may change it, with a
// reason: while confirming, or on an order still unpaid and not yet with the supplier (the bill
// is re-raised). Once paid, or sent to the supplier, it cannot change. The prescription and the
// Optometry record never change.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
let tail = 0;
const options = await get('clinical/options');
const icd = options.icd10[0];
const lenses = (await get('optical/options')).lenses;
check('the price list has at least three lenses to choose from', lenses.length >= 3, String(lenses.length));
const [lensA, lensB, lensC] = lenses;

async function referral(extraRx = null) {
  tail++;
  const patient = (await post('patients', {
    first_name: 'Lens', last_name: `Change${run}${tail}`, gender: 'female', date_of_birth: '1990-09-09',
    phone: `25574${run}${tail}`, address: 'Mwenge, Dar es Salaam', kin_name: 'Bw Musa', kin_relationship: 'Parent', kin_phone: `25575${run}${tail}`,
  })).patient;
  const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 2' })).visit;
  await payBill(sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
  await post(`optometry/visits/${visit.id}/claim`);
  await post(`optometry/visits/${visit.id}/complete`, {
    refer_optical: true, lens_item_ids: [lensA.id], provisional_icd_ids: [icd.id], final_label: extraRx ? 'Distance' : null,
    refractions: { final: { sph_right: -1.0, sph_left: -0.75 } }, extra_rx: extraRx ? [extraRx] : [],
  });
  return { patient, visit, referral: (await get('optical/incoming')).referrals.find(r => r.visit_id === visit.id) };
}
// Looked up directly: the bill list is paged, and a long test run leaves many bills pending.
async function payBill(billId) {
  const version = Number(sql(`SELECT row_version FROM bills WHERE id = ${billId}`));
  await post(`bills/${billId}/pay`, { method: 'cash', row_version: version });
}
const billStatus = billNo => sql(`SELECT status FROM bills WHERE bill_no = '${billNo}'`);

// ─── While confirming ─────────────────────────────────────────────────────
const first = await referral();
await expectError('a changed lens needs a reason', () => post('optical/orders', {
  assessment_id: first.referral.assessment_id, lenses: [{ set_no: 1, lens_ids: [lensB.id], reason: '' }] }), 422);
let order = (await post('optical/orders', {
  assessment_id: first.referral.assessment_id, lenses: [{ set_no: 1, lens_ids: [lensB.id], reason: 'Patient prefers this lens' }],
})).order;
order = (await get(`optical/orders/${order.id}`)).order;
equal('the order carries the patient\'s lens', order.items.filter(i => i.kind === 'lens').map(i => i.price_item_id), [lensB.id]);
equal('at its price-list price', order.total_amount, lensB.unit_price);
equal('and so does the bill', Number(sql(`SELECT gross_amount FROM bills WHERE id = ${order.bill.id}`)), lensB.unit_price);
equal('the change is kept with its reason', order.lens_changes.map(c => [c.stage, c.from, c.to, c.reason]), [['confirm', lensA.name, lensB.name, 'Patient prefers this lens']]);
equal('Optometry\'s record still has its own lens', (await get(`optometry/visits/${first.visit.id}`)).record.lens_items.map(l => l.id), [lensA.id]);
check('the change reason is stored encrypted', sql(`SELECT COUNT(*) FROM optical_lens_changes WHERE reason_enc LIKE '%prefers%'`) === '0');
equal('the lens may still change: unpaid and not with the supplier', order.lens_change_blocked, null);

// Confirming with the same lens as Optometry's is not a change.
const same = await referral();
const sameOrder = (await get(`optical/orders/${(await post('optical/orders', {
  assessment_id: same.referral.assessment_id, lenses: [{ set_no: 1, lens_ids: [lensA.id], reason: 'No change really' }] })).order.id}`)).order;
equal('choosing Optometry\'s own lens records no change', sameOrder.lens_changes.length, 0);

// ─── On an unpaid order not yet with the supplier ─────────────────────────
const oldBill = order.bill.bill_no;
await expectError('a reason is needed', () => post(`optical/orders/${order.id}/lenses`, { set_no: 1, lens_ids: [lensC.id], reason: '', row_version: order.row_version }), 422);
await expectError('the same lens is not a change', () => post(`optical/orders/${order.id}/lenses`, { set_no: 1, lens_ids: [lensB.id], reason: 'Same again', row_version: order.row_version }), 422);
await expectError('a stale order is refused', () => post(`optical/orders/${order.id}/lenses`, { set_no: 1, lens_ids: [lensC.id], reason: 'Cheaper option', row_version: order.row_version + 5 }), 409);
order = (await post(`optical/orders/${order.id}/lenses`, { set_no: 1, lens_ids: [lensC.id], reason: 'Cheaper option', row_version: order.row_version })).order;
equal('the order now has the new lens', order.items.filter(i => i.kind === 'lens').map(i => i.price_item_id), [lensC.id]);
equal('and its total', order.total_amount, lensC.unit_price);
equal('the old unpaid bill is cancelled', billStatus(oldBill), 'cancelled');
check('a new bill is raised for the new total', order.bill.bill_no !== oldBill && order.bill.status === 'pending' && order.bill.amount === lensC.unit_price,
  JSON.stringify(order.bill));
equal('both changes are listed', order.lens_changes.map(c => c.stage), ['confirm', 'order']);
equal('naming the replaced bill', order.lens_changes[1].old_bill_no, oldBill);
check('the change is in the audit log', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'optical.lens_changed' AND entity_id = '${order.order_no}'`) === '1');

// A pending discount request holds it until decided.
await post(`optical/orders/${order.id}/discount`, { amount: 1000, discount_type: 'Discount', remarks: 'Regular patient' });
order = (await get(`optical/orders/${order.id}`)).order;
check('while a discount is waiting for approval the lens cannot change', (order.lens_change_blocked || '').includes('approval'), order.lens_change_blocked);
await expectError('and the server refuses it', () => post(`optical/orders/${order.id}/lenses`, { set_no: 1, lens_ids: [lensA.id], reason: 'Changed mind', row_version: order.row_version }), 409);

// ─── Paid: no more changes ────────────────────────────────────────────────
const paidCase = await referral();
let paid = (await post('optical/orders', { assessment_id: paidCase.referral.assessment_id })).order;
await payBill(paid.bill.id);
paid = (await get(`optical/orders/${paid.id}`)).order;
check('once paid the order says it cannot change', (paid.lens_change_blocked || '').includes('paid'), paid.lens_change_blocked);
await expectError('and the server refuses a change', () => post(`optical/orders/${paid.id}/lenses`, { set_no: 1, lens_ids: [lensB.id], reason: 'Too late', row_version: paid.row_version }), 409);

// ─── Sent to the supplier: no more changes ────────────────────────────────
const sentCase = await referral();
let sent = (await post('optical/orders', { assessment_id: sentCase.referral.assessment_id })).order;
const supplier = (await get('optical/options')).suppliers[0];
sent = (await put(`optical/orders/${sent.id}`, { supplier_id: supplier.id, supplier_order_date: (await get('reports/options')).today, row_version: sent.row_version })).order;
sent = (await get(`optical/orders/${sent.id}`)).order;
equal('the order is with the supplier', sent.status, 'sent_to_supplier');
check('so the lens cannot change', (sent.lens_change_blocked || '').includes('supplier'), sent.lens_change_blocked);
await expectError('and the server refuses it', () => post(`optical/orders/${sent.id}/lenses`, { set_no: 1, lens_ids: [lensB.id], reason: 'Too late', row_version: sent.row_version }), 409);

// ─── Two pairs: only the chosen pair changes ──────────────────────────────
const twoPairs = await referral({ label: 'Reading', refraction: { sph_right: 1.5, sph_left: 1.5 }, lens_item_ids: [lensB.id] });
let pairs = (await post('optical/orders', { assessment_id: twoPairs.referral.assessment_id })).order;
pairs = (await post(`optical/orders/${pairs.id}/lenses`, { set_no: 2, lens_ids: [lensC.id], reason: 'Reading pair: thinner lens', row_version: (await get(`optical/orders/${pairs.id}`)).order.row_version })).order;
equal('the reading pair has the new lens, the distance pair keeps its own',
  pairs.items.filter(i => i.kind === 'lens').map(i => [i.set_no, i.price_item_id]).sort(), [[1, lensA.id], [2, lensC.id]]);
check('labelled with its prescription', pairs.items.find(i => i.set_no === 2).description.startsWith('Reading — '));
equal('one bill covers both pairs', pairs.bill.amount, lensA.unit_price + lensC.unit_price);

report('lens change');
