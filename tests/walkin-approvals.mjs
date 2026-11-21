// A counter sale to a walk-in has no patient. Its bill can still be discounted,
// cancelled and refunded through the approval queue like any other bill, and its
// records open without a patient.
import { signIn, get, post, upload, check, equal, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
const drug = (await get('clinical/options')).drugs[0];
await post(`pharmacy/stock/${drug.id}`, { change: 30, reason: 'received', note: 'Stock for the walk-in approvals test' });
const discountType = (await get('reference/lookups?list=discount_type')).values[0].value;

async function walkInBill(tail) {
  const order = (await post('pharmacy/walk-in-sales', {
    name: `Walk In ${run}${tail}`, phone: `25571${run}${tail}`, gender: 'female', age: 60,
    items: [{ drug_id: drug.id, quantity: 2, unit_price: 10000 }],
  })).order;
  const id = Number(order.bill.id);
  return { id, orderId: order.id, rowVersion: () => Number(sql(`SELECT row_version FROM bills WHERE id = ${id}`)) };
}
const approve = async id => post(`approvals/${id}/approve`, { row_version: (await get(`approvals/${id}`)).approval.row_version });

// ─── Discount ─────────────────────────────────────────────────────────────
const a = await walkInBill('1');
check('a walk-in bill has no patient', sql(`SELECT patient_id IS NULL AND walkin_id IS NOT NULL FROM bills WHERE id = ${a.id}`) === '1');
const discount = (await post(`bills/${a.id}/discount-request`, {
  discount_type: discountType, amount: 10000, remarks: 'Glaucoma club member', row_version: a.rowVersion(),
})).approval;
check('the discount request is accepted', Boolean(discount.approval_no));
check('the approval names no patient', sql(`SELECT patient_id IS NULL FROM approval_requests WHERE id = ${discount.id}`) === '1');
check('the approver finds it in the queue', (await get(`approvals?search=${discount.approval_no}`)).approvals.some(x => x.id === discount.id));
// Opening a walk-in's records must not try to log a patient access (there is no patient).
equal('the request opens, naming no patient', (await get(`approvals/${discount.id}`)).approval.patient, null);
const form = new FormData();
form.append('file', new Blob([Buffer.from('%PDF-1.4 club card')], { type: 'application/pdf' }), 'club-card.pdf');
const attached = (await upload(`approvals/${discount.id}/files`, form)).file;
equal('its supporting document downloads', (await get(`approvals/${discount.id}/files/${attached.id}`, { raw: true })).status, 200);
equal('the walk-in pharmacy order opens', (await get(`pharmacy/orders/${a.orderId}`)).order.customer.patient_id, null);
await approve(discount.id);
equal('approved, the bill is discounted', Number(sql(`SELECT discount_amount FROM bills WHERE id = ${a.id}`)), 10000);

// ─── Cancellation ─────────────────────────────────────────────────────────
const b = await walkInBill('2');
const cancel = (await post(`bills/${b.id}/cancel-request`, { reason: 'Customer changed their mind', row_version: b.rowVersion() })).approval;
check('the cancellation request is accepted', Boolean(cancel.approval_no));
await approve(cancel.id);
equal('approved, the bill is cancelled (it has no visit to cancel)', sql(`SELECT status FROM bills WHERE id = ${b.id}`), 'cancelled');

// ─── Refund ───────────────────────────────────────────────────────────────
const c = await walkInBill('3');
const paid = await post(`bills/${c.id}/pay`, { method: 'cash', row_version: c.rowVersion() });
equal('the walk-in receipt reprints', (await get(`receipts/${paid.receipt.id}`)).receipt.patient.id, null);
const billNo = sql(`SELECT bill_no FROM bills WHERE id = ${c.id}`);
const found = await get(`refunds/eligible?number=${encodeURIComponent(billNo)}`);
equal('found by bill number', found.bills.map(x => x.id), [c.id]);
check('shown as the walk-in', found.customer?.is_walk_in === true && found.customer.full_name === `Walk In ${run}3`);
const receiptNo = sql(`SELECT receipt_no FROM payments WHERE bill_id = ${c.id}`);
equal('found by receipt number too', (await get(`refunds/eligible?number=${encodeURIComponent(receiptNo)}`)).bills.map(x => x.id), [c.id]);

const refund = (await post('refunds', { bill_id: c.id, amount: 5000, reason: 'One bottle returned unopened' })).refund;
check('the refund request is accepted', Boolean(refund.refund_no));
check('the refund belongs to the walk-in', sql(`SELECT patient_id IS NULL AND walkin_id IS NOT NULL FROM refunds WHERE id = ${refund.id}`) === '1');
equal('and is shown with their name', refund.patient.full_name, `Walk In ${run}3`);
const walkinNo = refund.patient.reg_no;
check('the refunds list finds it by walk-in number', (await get(`refunds?search=${encodeURIComponent(walkinNo)}`)).refunds.some(x => x.id === refund.id));
await approve(Number(sql(`SELECT approval_id FROM refunds WHERE id = ${refund.id}`)));
const approved = (await get('refunds')).refunds.find(x => x.id === refund.id);
await post(`refunds/${refund.id}/payout`, { method: 'cash', row_version: approved.row_version });
equal('paid out, the bill shows the refund', Number(sql(`SELECT refunded_amount FROM bills WHERE id = ${c.id}`)), 5000);

// ─── An external spectacle order (also no patient) ────────────────────────
const lens = (await get('optical/options')).lenses[0];
const spectacles = (await post('optical/external', { name: `Specs ${run}`, phone: `25571${run}9`, lens_ids: [lens.id] })).order;
equal('an external optical order opens', (await get(`optical/orders/${spectacles.id}`)).order.patient.id, null);
const specsBill = Number(spectacles.bill.id);
const specsDiscount = (await post(`bills/${specsBill}/discount-request`, {
  discount_type: discountType, amount: 1000, remarks: 'Club member',
  row_version: Number(sql(`SELECT row_version FROM bills WHERE id = ${specsBill}`)),
})).approval;
check('and its bill can be discounted', Boolean(specsDiscount.approval_no));

report('walk-in approvals');
