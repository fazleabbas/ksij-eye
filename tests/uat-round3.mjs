// UAT round 3: the clinic's answers to our questions (stuffs/uat/HMS ANSWERS.docx).
import { signIn, get, post, put, check, equal, expectError, report, sql, payDiagnostic } from './lib/api.mjs';

const me = await signIn();
const run = String(Date.now()).slice(-6);

// ─── An investigation chosen at reception reaches Diagnostics ─────────────
const patient = (await post('patients', {
  first_name: 'Round', last_name: `Three${run}`, gender: 'male', date_of_birth: '1985-03-03',
  phone: `25574${run}1`, address: 'Sinza, Dar es Salaam',
  kin_name: 'Mama Neema', kin_relationship: 'Parent', kin_phone: `25574${run}2`,
})).patient;
const test = (await get('prices?category=investigation')).items.find(i => i.is_active);
const quote = (await get('visits/quote?' + new URLSearchParams({
  patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'investigation', investigation_item_id: test.id,
}))).quote;
equal('the visit fee is the consultation; the test is billed on its own', quote.bill_type, 'consultation');

const visit = (await post('visits', {
  patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'investigation',
  investigation_item_id: test.id, investigation_eye: 'left', investigation_reason: 'Blurred vision, query glaucoma',
})).visit;
// Searched by the patient: a busy local database has more open bills than one page holds.
const atReception = (await get(`bills?status=pending&search=reg${patient.reg_no}`)).bills.filter(b => b.visit_no === visit.visit_no);
equal('two separate bills go to the cashier at reception', atReception.length, 2);
const visitBills = atReception.filter(b => b.bill_type === 'consultation');
equal('one is the consultation', visitBills.length, 1);
const testBill = atReception.find(b => b.bill_type === 'investigation');
check('the other is the test, at its price', testBill && Number(testBill.net_amount ?? testBill.amount) === Number(test.unit_price), JSON.stringify(testBill));

const bookingId = Number(sql(`SELECT id FROM diagnostic_bookings WHERE visit_id = ${visit.id}`));
check('the test is booked in Diagnostics straight away', bookingId > 0);
const booking = (await get(`diagnostics/bookings/${bookingId}`)).booking;
equal('for the chosen test', booking.procedure.id, test.id);
equal('and eye', booking.eye, 'left');
equal('without a referring doctor it says so', booking.referring.name, 'Reception — no referring doctor');
equal('the reason travels with it', booking.remark, 'Blurred vision, query glaucoma');

equal('the patient shows as arrived at Diagnostics, no check-in needed', booking.status, 'arrived');
equal('the booking carries the test bill', booking.bill?.bill_no, testBill?.bill_no);

await post(`bills/${visitBills[0].id}/pay`, { method: 'cash', row_version: visitBills[0].row_version });
equal('once paid, the visit waits for the investigation',
  sql(`SELECT status FROM visits WHERE id = ${visit.id}`), 'waiting_investigation');
await payDiagnostic(bookingId);
await put(`diagnostics/bookings/${bookingId}/report`, { reporting_user_id: me.id, result: 'Cup-disc ratio 0.6 left.', comments: '' });
equal('reporting the test ends the visit', sql(`SELECT status FROM visits WHERE id = ${visit.id}`), 'completed');

// Cancelling an unpaid investigation visit takes both bills and the Diagnostics entry with it.
const dropped = (await post('visits', {
  patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'investigation', confirm_open_visit: true,
  investigation_item_id: test.id, investigation_eye: 'right', investigation_reason: 'Changed mind',
})).visit;
await post(`visits/${dropped.id}/cancel`, { reason: 'Patient left before paying' });
equal('both bills are cancelled with the visit',
  sql(`SELECT GROUP_CONCAT(DISTINCT status) FROM bills WHERE visit_id = ${dropped.id}`), 'cancelled');
equal('and the test leaves the Diagnostics list',
  sql(`SELECT status FROM diagnostic_bookings WHERE visit_id = ${dropped.id}`), 'cancelled');

// ─── Surgery and procedures, and medicines bought outside ─────────────────
async function doctorVisit(lastName, tail) {
  const p = (await post('patients', {
    first_name: 'Round', last_name: lastName, gender: 'female', date_of_birth: '1960-01-01',
    phone: `25574${run}${tail}`, address: 'Mbezi, Dar es Salaam',
    kin_name: 'Baba Juma', kin_relationship: 'Spouse', kin_phone: `25574${run}${tail}9`,
  })).patient;
  const v = (await post('visits', {
    patient_id: p.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1',
  })).visit;
  const b = (await get(`bills?status=pending&search=reg${p.reg_no}`)).bills.find(x => x.visit_no === v.visit_no);
  await post(`bills/${b.id}/pay`, { method: 'cash', row_version: b.row_version });
  await post(`ophthalmology/visits/${v.id}/claim`);
  return { patient: p, visit: v };
}
const options = await get('clinical/options');
const icd = options.icd10[0];
const surgeryItem = options.surgery_items.find(i => i.category === 'surgery');
const procedureItem = options.surgery_items.find(i => i.category === 'procedure');
const drug = options.drugs[0];
const base = { provisional_icd_ids: [icd.id], final_icd_ids: [icd.id] };

const both = await doctorVisit(`Orders${run}`, '3');
await expectError('a prescription line needs a medicine or a name',
  () => post(`ophthalmology/visits/${both.visit.id}/complete`, { ...base, prescription: { items: [{ quantity: 1 }] } }), 422);
await post(`ophthalmology/visits/${both.visit.id}/complete`, {
  ...base,
  surgery: [
    { price_item_id: surgeryItem.id, eye: 'right', urgency: 'elective', indication: 'Mature cataract' },
    { price_item_id: procedureItem.id, eye: 'left', urgency: 'urgent', indication: 'Raised pressure' },
  ],
  prescription: { items: [
    { drug_id: drug.id, quantity: 1, dose: '1 drop', frequency: 'BD' },
    { outside_name: 'Imported Eye Gel 10g', quantity: 2, dose: 'thin layer', frequency: 'ON' },
  ] },
});
const recommendations = (await get('theatre/recommendations')).recommendations.filter(r => r.patient.id === both.patient.id);
check('surgery and procedure both reach Theatre', recommendations.some(r => r.surgery.id === surgeryItem.id)
  && recommendations.some(r => r.surgery.id === procedureItem.id), JSON.stringify(recommendations.map(r => r.surgery.name)));

const queue = (await get('pharmacy/orders?scope=queue')).orders;
const order = queue.find(o => o.customer?.reference === both.patient.reg_no || o.patient?.id === both.patient.id);
check('the prescription reaches the pharmacy', !!order);
const detail = order && (await get(`pharmacy/orders/${order.id}`)).order;
equal('only the stocked medicine is to be dispensed', detail?.items.length, 1);
equal('the typed one is listed to buy outside', detail?.outside_items[0]?.name, 'Imported Eye Gel 10g');
equal('with its directions', detail?.outside_items[0]?.directions, 'thin layer · ON');

const outsideOnly = await doctorVisit(`Outside${run}`, '4');
await post(`ophthalmology/visits/${outsideOnly.visit.id}/complete`, {
  ...base, prescription: { items: [{ outside_name: 'Special Drops', quantity: 1 }] },
});
const queueAgain = (await get('pharmacy/orders?scope=queue')).orders;
check('a prescription with nothing stocked does not go to the pharmacy',
  !queueAgain.some(o => o.customer?.reference === outsideOnly.patient.reg_no || o.patient?.id === outsideOnly.patient.id));
const rx = (await get(`ophthalmology/visits/${outsideOnly.visit.id}`));
const rxItems = (rx.record ?? rx.workspace?.record)?.prescription?.items || [];
check('the doctor\'s record keeps it, marked as outside', rxItems[0]?.outside === true && rxItems[0]?.name === 'Special Drops');

// ─── Paying a pharmacy counter sale ───────────────────────────────────────
await post(`pharmacy/stock/${drug.id}`, { change: 10, reason: 'received', note: 'Stock for the counter sale test' });
const counter = await post('pharmacy/walk-in-sales', {
  name: `Counter ${run}`, phone: `25574${run}7`, items: [{ drug_id: drug.id, quantity: 1, unit_price: 2500 }],
});
const counterBill = (await get(`bills?status=pending&search=${counter.order.bill.bill_no}`)).bills.find(b => b.bill_no === counter.order.bill.bill_no);
const paid = await post(`bills/${counterBill.id}/pay`, { method: 'cash', row_version: counterBill.row_version });
check('paying a counter sale returns its receipt', !!paid.receipt?.receipt_no, JSON.stringify(paid).slice(0, 120));
equal('made out to the walk-in customer', paid.receipt?.patient.full_name, `Counter ${run}`);
check('with their EXT number', /^EXT-/.test(paid.receipt?.patient.reg_no || ''));
const reopened = (await get(`receipts/${paid.receipt.id}`)).receipt;
equal('the receipt can be opened again later', reopened.receipt_no, paid.receipt.receipt_no);
const todayLocal = (await get('reports/options')).today;
const collections = (await get('reports/run?' + new URLSearchParams({ type: 'collections', from: todayLocal, to: todayLocal }))).report;
check('the counter sale counts in Daily Collections', collections.rows.some(r => r.patient === `Counter ${run}` && r.collected === 2500));

// ─── Error messages that have no field still reach the user ───────────────
// (checked in the browser suite: reportError shows them in the toast)

report('uat round 3');
