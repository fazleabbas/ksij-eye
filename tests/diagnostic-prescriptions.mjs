// A diagnostic report can prescribe medicine. It is sent to the pharmacy with the report and
// goes the usual road: pharmacy review, bill at today's price, cashier, hand over.
// While the report is still correctable, medicine can be added if none was sent; only once.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

const me = await signIn();
const run = String(Date.now()).slice(-6);
const options = await get('diagnostics/options');
check('the report form gets the medicine list', Array.isArray(options.drugs) && options.drugs.length > 0);
const drug = options.drugs[2];
sql(`UPDATE drugs SET unit_price = 2500 WHERE id = ${drug.id}`);
await post(`pharmacy/stock/${drug.id}`, { change: 20, reason: 'received', note: 'Stock for the diagnostic prescription test' });
const investigation = options.procedures.find(p => !p.is_laser);

const patient = (await post('patients', {
  first_name: 'Diag', last_name: `Rx${run}`, gender: 'female', date_of_birth: '1970-05-05',
  phone: `25577${run}1`, address: 'Upanga, Dar es Salaam', kin_name: 'Ali', kin_relationship: 'Spouse', kin_phone: `25577${run}2`,
})).patient;
async function newBooking() {
  const booking = (await post('diagnostics/bookings', {
    patient_id: patient.id, referring_user_id: me.id, eye: 'right', timing: 'now', procedures: [{ price_item_id: investigation.id }],
  })).bookings[0];
  await post(`diagnostics/bookings/${booking.id}/check-in`, { no_charge: false });
  return booking;
}

// ─── Prescribed with the report ───────────────────────────────────────────
const first = await newBooking();
await expectError('a medicine no longer in the catalogue is refused', () => put(`diagnostics/bookings/${first.id}/report`, {
  reporting_user_id: me.id, result: 'Normal.', medicines: [{ drug_id: 99999999, quantity: 1 }],
}), 422);
let saved = (await put(`diagnostics/bookings/${first.id}/report`, {
  reporting_user_id: me.id, result: 'Mild inflammation.', comments: '',
  medicines: [{ drug_id: drug.id, quantity: 2, dose: '1 drop', frequency: 'QID', duration: '1 week' }],
  pharmacy_instructions: 'Shake well',
})).booking;
const rxNo = saved.report.prescription_no;
check('the report carries the prescription number', /^RX-/.test(rxNo || ''), rxNo);
equal('and lists the medicine', saved.report.medicines.map(m => `${m.name} x${m.quantity}`).join(), `${drug.name} x2`);
equal('the prescription is issued from Diagnostics', sql(`SELECT CONCAT(source, '/', status, '/', issued_by) FROM prescriptions WHERE prescription_no = '${rxNo}'`), `diagnostic/issued/${me.id}`);

await expectError('medicine cannot be sent twice for one report', () => put(`diagnostics/bookings/${first.id}/report`, {
  reporting_user_id: me.id, result: 'Mild inflammation.', row_version: saved.report.row_version,
  medicines: [{ drug_id: drug.id, quantity: 1 }],
}), 409);
saved = (await put(`diagnostics/bookings/${first.id}/report`, {
  reporting_user_id: me.id, result: 'Mild inflammation, corrected.', row_version: saved.report.row_version, medicines: [],
})).booking;
equal('a correction without medicine keeps the prescription', saved.report.prescription_no, rxNo);

// ─── The usual pharmacy road ──────────────────────────────────────────────
const queued = (await get('pharmacy/orders?scope=queue')).orders.find(o => o.customer.patient_id === patient.id);
check('the pharmacy queue has the order', !!queued);
let order = (await get(`pharmacy/orders/${queued.id}`)).order;
equal('waiting for pharmacy review', order.status, 'pending_review');
const itemId = order.items[0].id;
order = (await post(`pharmacy/orders/${queued.id}/review`, { items: [{ item_id: itemId, quantity: 2 }] })).order;
equal('billed at today\'s price', Number(order.bill.amount), 5000);
await expectError('not handed over before payment', () => post(`pharmacy/orders/${queued.id}/dispense`, { items: [{ item_id: itemId, quantity: 2 }] }), 409);
await post(`bills/${order.bill.id}/pay`, { method: 'cash', row_version: Number(sql(`SELECT row_version FROM bills WHERE id = ${order.bill.id}`)) });
await post(`pharmacy/orders/${queued.id}/dispense`, { items: [{ item_id: itemId, quantity: 2 }] });
equal('handed over once paid', (await get(`pharmacy/orders/${queued.id}`)).order.status, 'completed');

// ─── Added later, while the report can still be corrected ─────────────────
const second = await newBooking();
saved = (await put(`diagnostics/bookings/${second.id}/report`, { reporting_user_id: me.id, result: 'Normal.', comments: '' })).booking;
equal('a report without medicine sends nothing', saved.report.prescription_no, null);
saved = (await put(`diagnostics/bookings/${second.id}/report`, {
  reporting_user_id: me.id, result: 'Normal.', row_version: saved.report.row_version,
  medicines: [{ drug_id: drug.id, quantity: 1 }],
})).booking;
check('medicine can be added afterwards within the correction window', /^RX-/.test(saved.report.prescription_no || ''));

report('diagnostic prescriptions');
