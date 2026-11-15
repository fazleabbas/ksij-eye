// The pharmacy balance on screen: the review shows what is left for later, Balance to Collect
// lists it, and "Supply balance" bills the next part.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();
const options = await api.get('clinical/options');
const icd = options.icd10[0];
const drug = options.drugs[2];
api.sql(`UPDATE drugs SET unit_price = 2000 WHERE id = ${drug.id}`);
await api.post(`pharmacy/stock/${drug.id}`, { change: 30, reason: 'received', note: 'Stock for the balance screen test' });
const payBill = async billId => api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(api.sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Balance${run}`, gender: 'male', date_of_birth: '1971-01-01',
  phone: `25579${run}5`, address: 'Tabata, Dar es Salaam', kin_name: 'Mama Fatma', kin_relationship: 'Spouse', kin_phone: `25579${run}6`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 2' })).visit;
await payBill(api.sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
await api.post(`ophthalmology/visits/${visit.id}/claim`);
await api.post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  prescription: { items: [{ drug_id: drug.id, quantity: 4, dose: '1 drop', frequency: 'QID', duration: '1 month' }] },
});
const orderId = (await api.get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === patient.id).id;

await launch(9491);
await signIn();

// ─── Review: take 1 of 4 now ──────────────────────────────────────────────
await evaluate(`showModule('pharm-orders')`);
await sleep(800);
await evaluate(`phOpenOrder(${orderId}, 'review')`);
await waitUntil(`document.getElementById('modal-pharm-order').classList.contains('open') && !!document.querySelector('#ph-order-body .ph-qty')`, 10000, 'review');
check('the review shows what was prescribed', await evaluate(`document.getElementById('ph-order-body').textContent.includes('Prescribed')`));
await evaluate(`(() => { const i = document.querySelector('#ph-order-body .ph-qty'); i.value = '1'; phReviewTotal(); })()`);
const note = await evaluate(`document.getElementById('ph-review-balance').textContent`);
check('and says what will be left to collect later', note.includes('Balance to collect later') && note.includes('×3'), note);
equal('the bill is only for what is taken now', await evaluate(`document.getElementById('ph-review-total').textContent.replace(/\\D/g, '')`), '2000');
await shot('pharmacy-review-balance', SHOTS);
await evaluate(`phConfirmReview(document.querySelector('#ph-order-footer .btn-primary'))`);
await sleep(1500);
const firstBill = Number(api.sql(`SELECT bill_id FROM pharmacy_orders WHERE id = ${orderId}`));
await payBill(firstBill);
const itemId = Number(api.sql(`SELECT id FROM pharmacy_order_items WHERE order_id = ${orderId}`));
await api.post(`pharmacy/orders/${orderId}/dispense`, { items: [{ item_id: itemId, quantity: 1 }] });

// ─── Balance to Collect ───────────────────────────────────────────────────
await evaluate(`showModule('pharm-balance')`);
await waitUntil(`!!document.querySelector('#ph-bal-tbody button[onclick^="phOpenOrder(${orderId}, \\'balance\\')"]')`, 15000, 'balance list');
const row = await evaluate(`[...document.querySelectorAll('#ph-bal-tbody tr')].find(r => r.textContent.includes('Balance${run}')).textContent`);
check('the list shows what is still to collect', row.includes('×3'), row.slice(0, 200));
check('the sidebar has "Balance to Collect"', await evaluate(`document.getElementById('nav-pharm-balance').textContent.includes('Balance to Collect')`));
await shot('pharmacy-balance-list', SHOTS);
await evaluate(`document.querySelector('#ph-bal-tbody button[onclick^="phOpenOrder(${orderId}, \\'balance\\')"]').click()`);
await waitUntil(`document.getElementById('modal-pharm-order').classList.contains('open') && document.getElementById('ph-order-footer').textContent.includes('Raise the bill for these')`, 10000, 'supply balance');
await evaluate(`(() => { const i = document.querySelector('#ph-order-body .ph-qty'); i.value = '2'; phReviewTotal(); })()`);
equal('the balance part is priced as at the first visit', await evaluate(`document.getElementById('ph-review-total').textContent.replace(/\\D/g, '')`), '4000');
await evaluate(`phConfirmBalance(document.querySelector('#ph-order-footer .btn-primary'))`);
await sleep(1500);
const billed = await api.get(`pharmacy/orders/${orderId}`);
check('a new bill is raised for that part', billed.order.bill.id !== firstBill && billed.order.bill.amount === 4000, JSON.stringify(billed.order.bill));
equal('one left to collect', billed.order.balance_qty, 1);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('pharmacy balance browser', SHOTS);
