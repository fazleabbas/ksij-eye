// Refills are paid for: a refill of a completed prescription is its own order at today's price,
// billed to the cashier and handed over once paid. The old free refill is closed.
import { signIn, get, post, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
const options = await get('clinical/options');
const icd = options.icd10[0];
const drug = options.drugs[3];
sql(`UPDATE drugs SET unit_price = 3000 WHERE id = ${drug.id}`);
await post(`pharmacy/stock/${drug.id}`, { change: 40, reason: 'received', note: 'Stock for the refill test' });
async function payBill(billId) {
  await post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });
}
const orderOf = async id => (await get(`pharmacy/orders/${id}`)).order;

const patient = (await post('patients', {
  first_name: 'Refill', last_name: `Patient${run}`, gender: 'male', date_of_birth: '1962-02-02',
  phone: `25576${run}4`, address: 'Mwananyamala, Dar es Salaam', kin_name: 'Mama Zainab', kin_relationship: 'Spouse', kin_phone: `25576${run}5`,
})).patient;
const visit = (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 3' })).visit;
await payBill(sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
await post(`ophthalmology/visits/${visit.id}/claim`);
await post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  prescription: { items: [{ drug_id: drug.id, quantity: 2, dose: '1 drop', frequency: 'BD', duration: '1 month' }] },
});
const orderId = (await get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === patient.id).id;
let order = await orderOf(orderId);
const itemId = order.items[0].id;
await expectError('an order not yet completed cannot be refilled', () => post(`pharmacy/orders/${orderId}/refill`, { items: [{ item_id: itemId, quantity: 1 }] }), 409);
order = (await post(`pharmacy/orders/${orderId}/review`, { items: [{ item_id: itemId, quantity: 2 }] })).order;
await payBill(order.bill.id);
await post(`pharmacy/orders/${orderId}/dispense`, { items: [{ item_id: itemId, quantity: 2 }] });
equal('the first supply is complete', (await orderOf(orderId)).status, 'completed');

// ─── The refill ───────────────────────────────────────────────────────────
await expectError('the old free refill is closed', () => post(`pharmacy/orders/${orderId}/dispense`, { refill: true, items: [{ item_id: itemId, quantity: 1 }] }), 409);
await expectError('a refill needs a quantity', () => post(`pharmacy/orders/${orderId}/refill`, { items: [{ item_id: itemId, quantity: 0 }] }), 422);
sql(`UPDATE drugs SET unit_price = 3500 WHERE id = ${drug.id}`);
const stockBefore = Number(sql(`SELECT quantity FROM pharmacy_stock WHERE drug_id = ${drug.id}`));
const refill = (await post(`pharmacy/orders/${orderId}/refill`, { items: [{ item_id: itemId, quantity: 3 }] })).order;
check('a refill is its own order', refill.id !== orderId && refill.source === 'refill');
equal('linked to the order it repeats', refill.refill_of, order.order_no);
equal('billed at today\'s price', refill.bill.amount, 3 * 3500);
equal('waiting for payment', refill.status, 'awaiting_payment');
equal('the original order counts the refill', (await orderOf(orderId)).refill_count, 1);
equal('nothing leaves the shelf before payment', Number(sql(`SELECT quantity FROM pharmacy_stock WHERE drug_id = ${drug.id}`)), stockBefore);
await expectError('it cannot be handed over before it is paid', () => post(`pharmacy/orders/${refill.id}/dispense`, { items: [{ item_id: refill.items[0].id, quantity: 3 }] }), 409);
check('the refill bill says what it is', sql(`SELECT description FROM bills WHERE id = ${refill.bill.id}`).startsWith(`Medicine refill of ${order.order_no}`));
check('it is in the audit log', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'pharmacy.refill_billed' AND entity_id = '${refill.order_no}'`) === '1');

await payBill(refill.bill.id);
equal('once paid it is ready to hand over', (await orderOf(refill.id)).status, 'paid');
check('and shows in Dispense Medication', (await get('pharmacy/orders?scope=dispensing')).orders.some(o => o.id === refill.id));
await post(`pharmacy/orders/${refill.id}/dispense`, { items: [{ item_id: refill.items[0].id, quantity: 3 }] });
equal('handed over, the refill is complete', (await orderOf(refill.id)).status, 'completed');
equal('and the stock goes down', Number(sql(`SELECT quantity FROM pharmacy_stock WHERE drug_id = ${drug.id}`)), stockBefore - 3);
check('the refill shows the prescription it came from', (await orderOf(refill.id)).prescription_no?.startsWith('RX-'));
check('patient history lists both hand-overs', (await get(`clinical/patients/${patient.id}/history?all=1`)).medicines.length === 2);
await expectError('a refill order is not itself refilled', () => post(`pharmacy/orders/${refill.id}/refill`, { items: [{ item_id: refill.items[0].id, quantity: 1 }] }), 409);

// A cancelled refill bill cancels only the refill.
const second = (await post(`pharmacy/orders/${orderId}/refill`, { items: [{ item_id: itemId, quantity: 1 }] })).order;
const request = (await post(`bills/${second.bill.id}/cancel-request`, { reason: 'Patient left', row_version: (await get(`bills/${second.bill.id}`)).bill.row_version })).approval;
await post(`approvals/${request.id}/approve`, { row_version: (await get(`approvals/${request.id}`)).approval.row_version });
equal('cancelling the refill bill cancels the refill', (await orderOf(second.id)).status, 'cancelled');
equal('the original order is untouched', (await orderOf(orderId)).status, 'completed');

report('pharmacy refill');
