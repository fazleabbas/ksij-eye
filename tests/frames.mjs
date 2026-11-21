// Optical frames: the frame list and its stock, store → Optical, external sales,
// frames on patient orders, frames set aside until hand-over, and frame changes.
import { signIn, get, pendingBill, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

const me = await signIn();
const run = String(Date.now()).slice(-6);
const options = async () => (await get('optical/options'));
const frameOf = async id => (await get('optical/frames')).frames.find(f => f.id === id);
const payBill = async billNo => {
  const bill = await pendingBill(b => b.bill_no === billNo);
  await post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
};

// ─── The frame list ───────────────────────────────────────────────────────
const created = (await post('optical/frames', { name: `Classic ${run}`, brand: 'Vista', colour: 'Black', size: '52-18' })).frame;
check('a frame gets its own code', /^FR-\d{4}$/.test(created.code), created.code);
equal('and starts with none in stock', created.quantity, 0);
const prices = (await options()).frame_prices;
equal('a frame costs a patient 1,000', prices.patient, 1000);
equal('and an external customer 25,000', prices.external, 25000);

// ─── Frames come from the store ───────────────────────────────────────────
const storeOptions = await get('stores/options');
const storeItem = (await post('stores/stock', {
  name: `Frame Classic ${run}`, category: storeOptions.categories[0], unit: storeOptions.units[0], frame_id: created.id,
})).item;
equal('a store item can be linked to a frame', storeItem.frame_id, created.id);
await expectError('but not to a frame and a medicine at once', () => post('stores/stock', {
  name: `Both ${run}`, category: storeOptions.categories[0], unit: storeOptions.units[0], frame_id: created.id, drug_id: storeOptions.drugs[0].id,
}), 422);
await post(`stores/stock/${storeItem.id}/adjust`, { change: 10, reason: 'adjusted', note: 'Opening count' });
const optical = storeOptions.departments.find(d => /optic/i.test(d.name));
const requisition = (await post('stores/requisitions', {
  department_id: optical.id, priority: 'normal', submit: true, items: [{ item_id: storeItem.id, quantity: 3 }],
})).requisition;
const line = requisition.items[0].id;
await post(`stores/requisitions/${requisition.id}/decide`, { decision: 'approved', items: [{ item_id: line, qty_approved: 3 }] });
await post(`stores/requisitions/${requisition.id}/issue`, { items: [{ item_id: line, quantity: 3 }] });
let frame = await frameOf(created.id);
equal('issuing three to the Optical Room puts three on its shelf', frame.quantity, 3);
const moves = (await get(`optical/frames/${created.id}/movements`)).movements;
equal('recorded as received from the store', moves[0]?.reason, 'received');

// ─── External sale ────────────────────────────────────────────────────────
const lens = (await options()).lenses[0];
const customer = { name: `Walk In ${run}`, phone: `25575${run}1` };
await expectError('an external sale needs a frame or a lens', () => post('optical/external', customer), 422);
const sale = (await post('optical/external', { ...customer, frame_id: created.id, lens_ids: [lens.id] })).order;
equal('it is an external order', sale.order_type, 'external');
equal('for the customer by name', sale.patient.full_name, customer.name);
check('with an EXT number', /^EXT-/.test(sale.patient.reg_no), sale.patient.reg_no);
equal('frame at the external price plus the lens', sale.total_amount, 25000 + lens.unit_price);
check('the bill goes to the cashier', !!sale.bill?.bill_no);
frame = await frameOf(created.id);
check('the frame is set aside, not taken off', frame.quantity === 3 && frame.set_aside === 1 && frame.available === 2, JSON.stringify(frame));

// Frame on its own: no supplier steps, ready as soon as it is paid.
const frameOnly = (await post('optical/external', { name: `Frame Only ${run}`, phone: `25575${run}2`, frame_id: created.id })).order;
await post('optical/external', { name: `Third ${run}`, phone: `25575${run}3`, frame_id: created.id });
frame = await frameOf(created.id);
equal('three set aside, none free', frame.available, 0);
await expectError('a frame that is all set aside cannot be sold again',
  () => post('optical/external', { name: `Fourth ${run}`, phone: `25575${run}4`, frame_id: created.id }), 422);

await payBill(frameOnly.bill.bill_no);
let detail = (await get(`optical/orders/${frameOnly.id}`)).order;
equal('a paid frame-only order is ready to hand over', detail.status, 'ready_for_dispensing');
await post(`optical/orders/${frameOnly.id}/dispense`, {});
frame = await frameOf(created.id);
check('handing over takes it off the shelf', frame.quantity === 2 && frame.set_aside === 2, JSON.stringify(frame));
equal('recorded as sold against the order', (await get(`optical/frames/${created.id}/movements`)).movements[0]?.reference, frameOnly.order_no);
const found = (await get(`optical/orders?scope=completed&search=${encodeURIComponent(frameOnly.bill.bill_no)}`)).orders;
check('a past sale can be found by its receipt number', found.some(o => o.id === frameOnly.id));

// ─── Frame change ─────────────────────────────────────────────────────────
await expectError('a frame change needs a reason',
  () => post(`optical/orders/${frameOnly.id}/change-frame`, { frame_id: created.id }), 422);
await post(`optical/frames/${created.id}/adjust`, { change: 1, reason: 'returned', note: 'Returned by a customer' });
const change = (await post(`optical/orders/${frameOnly.id}/change-frame`, { frame_id: created.id, reason: 'Hinge broke' })).order;
equal('a frame change is its own order', change.order_type, 'frame_change');
equal('against the original sale', change.original_order_no, frameOnly.order_no);
check('the old frame is recorded', (change.old_frame || '').includes(`Classic ${run}`), change.old_frame);
equal('charged at the normal external frame price', change.total_amount, 25000);
detail = (await get(`optical/orders/${change.id}`)).order;
equal('the reason is kept', detail.change_reason, 'Hinge broke');
check('stored encrypted', sql(`SELECT change_reason_enc NOT LIKE '%Hinge%' FROM optical_orders WHERE id = ${change.id}`) === '1');
await expectError('only spectacles already handed over can have the frame changed',
  () => post(`optical/orders/${sale.id}/change-frame`, { frame_id: created.id, reason: 'Too early' }), 409);

// ─── A patient's spectacles: frame at the patient price ───────────────────
const patient = (await post('patients', {
  first_name: 'Frame', last_name: `Patient${run}`, gender: 'male', date_of_birth: '1975-05-05',
  phone: `25575${run}5`, address: 'Kinondoni, Dar es Salaam',
  kin_name: 'Mama Asha', kin_relationship: 'Spouse', kin_phone: `25575${run}6`,
})).patient;
const visit = (await post('visits', {
  patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'triage',
})).visit;
await payBill((await pendingBill(b => b.visit_no === visit.visit_no)).bill_no);
sql(`UPDATE visits SET status = 'waiting_optometry' WHERE id = ${visit.id}`);
await post(`optometry/visits/${visit.id}/claim`);
const icd = (await get('clinical/options')).icd10[0];
await post(`optometry/visits/${visit.id}/complete`, {
  refer_optical: true, lens_item_ids: [lens.id], provisional_icd_ids: [icd.id],
  refractions: { final: { sph_right: -1.25, sph_left: -1.0 } },
});
const referral = (await get('optical/incoming')).referrals.find(r => r.visit_id === visit.id);
check('the patient is referred to Optical', !!referral);
await post(`optical/frames/${created.id}/adjust`, { change: 1, reason: 'adjusted', note: 'Recount' });
const order = (await post('optical/orders', { assessment_id: referral.assessment_id, frame_id: created.id })).order;
equal('lens plus a frame at the patient price', order.total_amount, lens.unit_price + 1000);
equal('it is a referral order', order.order_type, 'referral');

// ─── Another pair on the same prescription ────────────────────────────────
await post(`optical/frames/${created.id}/adjust`, { change: 1, reason: 'adjusted', note: 'Recount for the extra pair' });
const before = await frameOf(created.id);
const extra = (await post(`optical/orders/${order.id}/extra-pair`, { frame_id: created.id })).order;
equal('another pair is its own order', extra.order_type, 'extra_pair');
equal('linked to the first pair', extra.original_order_no, order.order_no);
equal('for the same patient', extra.patient.id, patient.id);
equal('same lenses again plus a frame, at full patient price', extra.total_amount, lens.unit_price + 1000);
check('with its own bill', !!extra.bill?.bill_no && extra.bill.bill_no !== order.bill.bill_no);
const extraDetail = (await get(`optical/orders/${extra.id}`)).order;
check('it carries the same prescription', extraDetail.assessment_id === order.assessment_id && !!extraDetail.prescription);
equal('its frame is set aside like any other', (await frameOf(created.id)).set_aside, before.set_aside + 1);
const own = (await post(`optical/orders/${order.id}/extra-pair`, {})).order;
equal('the patient may bring their own frame: lenses only', own.total_amount, lens.unit_price);
check('the prescription is not offered again as new', !(await get('optical/incoming')).referrals.some(r => r.visit_id === visit.id));
await expectError('an external sale has no prescription to repeat',
  () => post(`optical/orders/${sale.id}/extra-pair`, {}), 409);

// ─── Several prescriptions in one optometry visit ─────────────────────────
const lenses = (await options()).lenses;
const lensB = lenses[1] || lenses[0];
const twin = (await post('patients', {
  first_name: 'Two', last_name: `Pairs${run}`, gender: 'female', date_of_birth: '1960-02-02',
  phone: `25575${run}7`, address: 'Mikocheni, Dar es Salaam',
  kin_name: 'Kaka Said', kin_relationship: 'Sibling', kin_phone: `25575${run}8`,
})).patient;
const twinVisit = (await post('visits', { patient_id: twin.id, visit_type: 'consultation', sub_type: 'normal', destination: 'triage' })).visit;
await payBill((await pendingBill(b => b.visit_no === twinVisit.visit_no)).bill_no);
sql(`UPDATE visits SET status = 'waiting_optometry' WHERE id = ${twinVisit.id}`);
await post(`optometry/visits/${twinVisit.id}/claim`);
const twoRx = {
  refer_optical: true, provisional_icd_ids: [icd.id], final_label: 'Bifocal',
  refractions: { final: { sph_right: -1.0, sph_left: -0.75, add_right: 2.0, add_left: 2.0 } },
  lens_item_ids: [lens.id],
};
await expectError('a further prescription needs its SPH like the first',
  () => post(`optometry/visits/${twinVisit.id}/complete`, { ...twoRx, extra_rx: [{ label: 'Reading', refraction: {}, lens_item_ids: [lensB.id] }] }), 422);
await expectError('a label must come from the list',
  () => post(`optometry/visits/${twinVisit.id}/complete`, { ...twoRx, extra_rx: [{ label: 'Party glasses', refraction: { sph_right: 1.5 }, lens_item_ids: [lensB.id] }] }), 422);
await post(`optometry/visits/${twinVisit.id}/complete`, {
  ...twoRx, extra_rx: [{ label: 'Reading', refraction: { sph_right: 1.5, sph_left: 1.5, pd_mm: 61 }, lens_item_ids: [lensB.id] }],
});
const optoRecord = (await get(`optometry/visits/${twinVisit.id}`));
const optoRec = optoRecord.record ?? optoRecord.workspace?.record;
equal('the first prescription keeps its label', optoRec?.final_label, 'Bifocal');
equal('the second is saved with its label', optoRec?.extra_rx?.[0]?.label, 'Reading');
equal('and its own values', optoRec?.extra_rx?.[0]?.refraction?.sph_right, 1.5);

const twinReferral = (await get('optical/incoming')).referrals.find(r => r.visit_id === twinVisit.id);
check('both prescriptions arrive in Optical as one entry', twinReferral && new Set(twinReferral.lenses.map(l => l.set_no)).size === 2,
  JSON.stringify(twinReferral?.lenses));
const rx = (await get(`optical/prescriptions/${twinReferral.assessment_id}`)).prescription;
equal('the prescription shows both sets', rx.sets.map(x => x.label).join(','), 'Bifocal,Reading');

await post(`optical/frames/${created.id}/adjust`, { change: 2, reason: 'adjusted', note: 'Two for the two pairs' });
const freeNow = (await frameOf(created.id)).available;
const twinOrder = (await post('optical/orders', {
  assessment_id: twinReferral.assessment_id,
  frames: [{ set_no: 1, frame_id: created.id }, { set_no: 2, frame_id: created.id }],
})).order;
equal('one order, one bill: both pairs and a frame for each', twinOrder.total_amount, lens.unit_price + lensB.unit_price + 2000);
const twinItems = (await get(`optical/orders/${twinOrder.id}`)).order.items;
check('each line says which prescription it is for', twinItems.some(i => i.description.startsWith('Reading — '))
  && twinItems.some(i => i.description.startsWith('Frame (Bifocal)')), JSON.stringify(twinItems.map(i => i.description)));
equal('both frames are set aside', (await frameOf(created.id)).available, freeNow - 2);
await expectError('two frames cannot be taken when only one is free', async () => {
  const one = (await frameOf(created.id)).available;
  await post(`optical/frames/${created.id}/adjust`, { change: 1 - one, reason: 'adjusted', note: 'Leave exactly one free' }).catch(() => {});
  return post(`optical/orders/${twinOrder.id}/extra-pair`, { frame_id: created.id, set_no: 2 }).then(() =>
    post(`optical/orders/${twinOrder.id}/extra-pair`, { frame_id: created.id, set_no: 2 }));
}, 422);
const readingPair = (await post(`optical/orders/${twinOrder.id}/extra-pair`, { set_no: 2 })).order;
equal('an extra pair can be made for the reading prescription only', readingPair.total_amount, lensB.unit_price);

const today = (await get('reports/options')).today;
const main = (await get('reports/run?' + new URLSearchParams({ type: 'mtuha_main', from: today, to: today }))).report;
check('the MTUHA Optical column names the prescriptions', (main.rows.find(r => r.name.includes(`Pairs${run}`))?.optical || '').includes('Reading'));

// ─── Corrections ──────────────────────────────────────────────────────────
frame = await frameOf(created.id);
await expectError('a correction cannot take the count below what is set aside',
  () => post(`optical/frames/${created.id}/adjust`, { change: -frame.quantity, reason: 'adjusted', note: 'Lost' }), 409);
await expectError('a correction needs a note',
  () => post(`optical/frames/${created.id}/adjust`, { change: 1, reason: 'adjusted' }), 422);

report('frames');
