// On screen: Refills → Refill opens the medicines at today's price; "Raise refill bill" sends it to the cashier.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();
const options = await api.get('clinical/options');
const drug = options.drugs[4];
api.sql(`UPDATE drugs SET unit_price = 2500 WHERE id = ${drug.id}`);
await api.post(`pharmacy/stock/${drug.id}`, { change: 20, reason: 'received', note: 'Stock for the refill screen test' });
const payBill = async billId => api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(api.sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Refill${run}`, gender: 'female', date_of_birth: '1966-06-06',
  phone: `25577${run}6`, address: 'Kimara, Dar es Salaam', kin_name: 'Bw Selemani', kin_relationship: 'Spouse', kin_phone: `25577${run}7`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 4' })).visit;
await payBill(api.sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));
await api.post(`ophthalmology/visits/${visit.id}/claim`);
await api.post(`ophthalmology/visits/${visit.id}/complete`, {
  provisional_icd_ids: [options.icd10[0].id], final_icd_ids: [options.icd10[0].id],
  prescription: { items: [{ drug_id: drug.id, quantity: 2, dose: '1 drop', frequency: 'TDS', duration: '2 weeks' }] },
});
const orderId = (await api.get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === patient.id).id;
const itemId = Number(api.sql(`SELECT id FROM pharmacy_order_items WHERE order_id = ${orderId}`));
const reviewed = (await api.post(`pharmacy/orders/${orderId}/review`, { items: [{ item_id: itemId, quantity: 2 }] })).order;
await payBill(reviewed.bill.id);
await api.post(`pharmacy/orders/${orderId}/dispense`, { items: [{ item_id: itemId, quantity: 2 }] });
api.sql(`UPDATE drugs SET unit_price = 2800 WHERE id = ${drug.id}`);

await launch(9509);
await signIn();
await evaluate(`showModule('pharm-refills')`);
const refillButton = `[...document.querySelectorAll('#ph-ref-tbody tr')].find(r => r.textContent.includes('Refill${run}'))?.querySelector('button.action-edit')`;
await waitUntil(`!!${refillButton}`, 15000, 'refills list');
await evaluate(`${refillButton}.click()`);
await waitUntil(`document.getElementById('modal-pharm-order').classList.contains('open') && !!document.querySelector('#ph-order-body .ph-qty')`, 10000, 'refill window');
const body = await evaluate(`document.getElementById('ph-order-body').textContent`);
check('the refill window shows today\'s price', body.includes('2,800') && body.includes('Last supplied'), body.slice(0, 300));
equal('it starts from the quantity last supplied', await evaluate(`document.querySelector('#ph-order-body .ph-qty').value`), '2');
equal('and totals it at the price of today', await evaluate(`document.getElementById('ph-review-total').textContent`), 'TZS 5,600');
await shot('pharmacy-refill', SHOTS);
await evaluate(`phConfirmRefill(document.querySelector('#ph-order-footer .btn-primary'))`);
await sleep(1500);
const refillId = Number(api.sql(`SELECT id FROM pharmacy_orders WHERE refill_of_order_id = ${orderId}`));
check('a refill order is raised', refillId > 0);
equal('awaiting payment at the cashier', api.sql(`SELECT b.status FROM pharmacy_orders o JOIN bills b ON b.id = o.bill_id WHERE o.id = ${refillId}`), 'pending');

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('pharmacy refill browser', SHOTS);
