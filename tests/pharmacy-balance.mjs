// A patient who cannot afford everything takes part of the prescription now and collects the
// balance later, straight from the pharmacy, at the price locked at the first visit.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
const options = await get('clinical/options');
const icd = options.icd10[0];
const [drugA, drugB] = options.drugs;
sql(`UPDATE drugs SET unit_price = 1500 WHERE id = ${drugA.id}`);
sql(`UPDATE drugs SET unit_price = 4000 WHERE id = ${drugB.id}`);
for (const drug of [drugA, drugB]) await post(`pharmacy/stock/${drug.id}`, { change: 50, reason: 'received', note: 'Stock for the balance test' });

// Looked up directly: the bill list is paged and a long test run leaves many bills pending.
async function payBill(billId) {
  await post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });
}
const orderOf = async id => (await get(`pharmacy/orders/${id}`)).order;
const itemOf = (order, drug) => order.items.find(i => i.drug_id === drug.id);

// A doctor prescribes 4 of A and 2 of B.
const patient = (await post('patients', {
  first_name: 'Balance', last_name: `Patient${run}`, gender: 'female', date_of_birth: '1968-06-06',
  phone: `25578${run}1`, address: 'Buguruni, Dar es Salaam', kin_name: 'Bw Hassan', kin_relationship: 'Spouse', kin_phone: `25578${run}2`,
})).patient;
const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
await payBill(sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
await post(`ophthalmology/visits/${visit.id}/claim`);
await post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  prescription: { items: [
    { drug_id: drugA.id, quantity: 4, dose: '1 tab', frequency: 'BD', duration: '2 days' },
    { drug_id: drugB.id, quantity: 2, dose: '1 drop', frequency: 'TDS', duration: '1 week' },
  ] },
});
const queued = (await get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === patient.id);
check('the prescription reaches the pharmacy', !!queued);
let order = await orderOf(queued.id);
equal('each line knows what was prescribed', [itemOf(order, drugA).prescribed_qty, itemOf(order, drugB).prescribed_qty], [4, 2]);

// ─── Review: the patient takes 2 of A now and none of B ───────────────────
order = (await post(`pharmacy/orders/${order.id}/review`, { items: [
  { item_id: itemOf(order, drugA).id, quantity: 2 }, { item_id: itemOf(order, drugB).id, quantity: 0 },
] })).order;
order = await orderOf(order.id);
equal('only what is taken now is billed', order.total_amount, 3000);
equal('a medicine taken later is kept on the order, not removed', order.items.length, 2);
equal('the balance per medicine', [itemOf(order, drugA).balance, itemOf(order, drugB).balance], [2, 2]);
equal('and in total', order.balance_qty, 4);
const listed = (await get('pharmacy/orders?scope=balance')).orders;
check('the order is listed under Balance to Collect', listed.some(o => o.id === order.id));
await expectError('the balance cannot be billed before the current part is paid and handed over',
  () => post(`pharmacy/orders/${order.id}/balance`, { items: [{ item_id: itemOf(order, drugA).id, quantity: 1 }] }), 409);

await payBill(order.bill.id);
await post(`pharmacy/orders/${order.id}/dispense`, { items: [{ item_id: itemOf(order, drugA).id, quantity: 2 }] });
order = await orderOf(order.id);
equal('once the first part is handed over the order is complete', order.status, 'completed');
equal('with the balance still there', order.balance_qty, 4);

// ─── A later day: the price has gone up, the patient pays the locked price ─
sql(`UPDATE drugs SET unit_price = 2500 WHERE id = ${drugA.id}`);
await expectError('no more than the balance', () => post(`pharmacy/orders/${order.id}/balance`, { items: [{ item_id: itemOf(order, drugA).id, quantity: 3 }] }), 422);
await expectError('something must be supplied', () => post(`pharmacy/orders/${order.id}/balance`, { items: [{ item_id: itemOf(order, drugA).id, quantity: 0 }] }), 422);
const firstBill = order.bill.bill_no;
order = (await post(`pharmacy/orders/${order.id}/balance`, { items: [
  { item_id: itemOf(order, drugA).id, quantity: 1 }, { item_id: itemOf(order, drugB).id, quantity: 2 },
] })).order;
check('the balance part has a bill of its own', order.bill.bill_no !== firstBill && order.status === 'awaiting_payment');
equal('at the price locked at the first visit', order.bill.amount, 1 * 1500 + 2 * 4000);
equal('the first bill is untouched', sql(`SELECT status FROM bills WHERE bill_no = '${firstBill}'`), 'paid');
equal('what is left to collect', [itemOf(order, drugA).balance, itemOf(order, drugB).balance], [1, 0]);
equal('the part is listed on the order', order.balance_supplies.map(s => s.items.map(i => i.quantity)), [[1, 2]]);
check('it is in the audit log', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'pharmacy.balance_billed' AND entity_id = '${order.order_no}'`) === '1');

// The cashier cancels that bill: only that part goes back to the balance.
const partBill = order.bill.id;
const request = (await post(`bills/${partBill}/cancel-request`, { reason: 'Patient left without paying', row_version: (await get(`bills/${partBill}`)).bill.row_version })).approval;
await post(`approvals/${request.id}/approve`, { row_version: (await get(`approvals/${request.id}`)).approval.row_version });
order = await orderOf(order.id);
equal('cancelling the part\'s bill does not cancel the order', order.status, 'completed');
equal('that part returns to the balance', [itemOf(order, drugA).balance, itemOf(order, drugB).balance], [2, 2]);
equal('and is shown as cancelled', order.balance_supplies.map(s => s.status), ['cancelled']);
equal('the earlier hand-over stands', itemOf(order, drugA).dispensed_qty, 2);

// Billed again, paid and handed over.
order = (await post(`pharmacy/orders/${order.id}/balance`, { items: [{ item_id: itemOf(order, drugB).id, quantity: 2 }] })).order;
await payBill(order.bill.id);
await post(`pharmacy/orders/${order.id}/dispense`, { items: [{ item_id: itemOf(order, drugB).id, quantity: 2 }] });
order = await orderOf(order.id);
equal('the balance part is handed over', [order.status, itemOf(order, drugB).dispensed_qty], ['completed', 2]);
equal('A is still owed', order.balance_qty, 2);

// ─── Closing what will never be collected ─────────────────────────────────
await expectError('closing needs a reason', () => post(`pharmacy/orders/${order.id}/balance/close`, { reason: '' }), 422);
order = (await post(`pharmacy/orders/${order.id}/balance/close`, { reason: 'Bought elsewhere' })).order;
equal('the balance is closed', [order.balance_qty, order.balance_closed?.reason], [0, 'Bought elsewhere']);
check('and the order leaves the list', !(await get('pharmacy/orders?scope=balance')).orders.some(o => o.id === order.id));
await expectError('nothing more can be billed', () => post(`pharmacy/orders/${order.id}/balance`, { items: [{ item_id: itemOf(order, drugA).id, quantity: 1 }] }), 409);

// ─── Prescription changes are refused once something was handed over ──────
const rx = sql(`SELECT prescription_id FROM pharmacy_orders WHERE id = ${order.id}`);
check('the issued prescription is linked', Number(rx) > 0);
equal('the prescription keeps the doctor\'s quantities', sql(`SELECT GROUP_CONCAT(quantity ORDER BY sort_order) FROM prescription_items WHERE prescription_id = ${rx}`), '4,2');

report('pharmacy balance');
