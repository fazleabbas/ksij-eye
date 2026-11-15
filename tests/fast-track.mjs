// Fast track: chosen at registration (consultation or follow-up), or bought later at the
// cashier for the difference. The visit goes ahead of the normal queue only once paid.
import { signIn, get, post, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
const price = code => Number(sql(`SELECT unit_price FROM price_items WHERE code = '${code}'`));
const FAST = price('CONS-FAST');
const NORMAL = price('CONS-NORMAL');
const FOLLOW_FAST = price('CONS-FOLLOWUP-FAST');
equal('the follow-up fast-track price is in the Price List', FOLLOW_FAST, 20000);

let tail = 0;
async function newPatient(name) {
  tail++;
  return (await post('patients', {
    first_name: 'Fast', last_name: `${name}${run}`, gender: 'male', date_of_birth: '1975-05-05',
    phone: `25574${run}${tail}`, address: 'Kariakoo, Dar es Salaam',
    kin_name: 'Bi Zuhura', kin_relationship: 'Spouse', kin_phone: `25574${run}${tail}9`,
  })).patient;
}
const visitBills = visitId => sql(`SELECT CONCAT(id, ':', source_type, ':', status, ':', gross_amount, ':', discount_amount) FROM bills WHERE visit_id = ${visitId} ORDER BY id`).split('\n');
async function pay(billId) {
  const { bill } = await get(`bills/${billId}`);
  return post(`bills/${billId}/pay`, { method: 'cash', row_version: bill.row_version });
}
async function visitFor(patient, body = {}) {
  return (await post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'triage', confirm_open_visit: true, ...body })).visit;
}
const firstBill = visitId => Number(sql(`SELECT id FROM bills WHERE visit_id = ${visitId} ORDER BY id LIMIT 1`));
const triageOrder = async () => (await get('triage/queue')).queue.map(i => i.visit_id);

// ─── Registration: a fast-track follow-up pays the full price, even when free ─
const followPatient = await newPatient('Follow');
const first = await visitFor(followPatient);
await pay(firstBill(first.id));
const freeQuote = (await get('visits/quote?' + new URLSearchParams({ patient_id: followPatient.id, visit_type: 'follow_up', sub_type: 'doctor_follow_up', destination: 'triage' }))).quote;
check('a follow-up inside the free days is free', freeQuote.exempt === true);
const fastQuote = (await get('visits/quote?' + new URLSearchParams({ patient_id: followPatient.id, visit_type: 'follow_up', sub_type: 'surgery_follow_up', destination: 'triage', fast_track: 1 }))).quote;
check('with fast track it is not free', fastQuote.exempt === false);
equal('and costs the follow-up fast-track price', fastQuote.net_amount, FOLLOW_FAST);
{
  const visit = (await post('visits', { patient_id: followPatient.id, visit_type: 'follow_up', sub_type: 'doctor_follow_up', destination: 'triage', fast_track: true, confirm_open_visit: true })).visit;
  check('a fast-track follow-up is marked fast track', visit.is_fast_track === true);
  equal('its bill is the fast-track price', visitBills(visit.id)[0].split(':')[3], FOLLOW_FAST.toFixed(2));
  check('who and when are recorded', sql(`SELECT fast_tracked_by IS NOT NULL AND fast_tracked_at IS NOT NULL FROM visits WHERE id = ${visit.id}`) === '1');
}
const consult = await visitFor(await newPatient('Consult'), { sub_type: 'fast_track' });
check('a fast-track consultation is marked fast track', consult.is_fast_track === true);

// ─── Upgrade after paying: a second bill for the difference ───────────────
const early = await visitFor(await newPatient('Early'));
await pay(firstBill(early.id));
const late = await visitFor(await newPatient('Late'));
await pay(firstBill(late.id));
let order = await triageOrder();
check('before the upgrade, the later patient waits behind', order.indexOf(early.id) < order.indexOf(late.id));

const quote = (await get(`visits/${late.id}/fast-track`)).quote;
equal('balance = fast-track price less what was charged', quote.balance, FAST - NORMAL);
equal('nothing to re-price: the first bill is paid', quote.reprice_bill, null);
const raised = (await post(`visits/${late.id}/fast-track`)).result;
check('a bill is raised for the difference', raised.bill_id > 0 && raised.activated === false);
equal('that bill is for the balance', visitBills(late.id)[1].split(':').slice(1, 4).join(':'), `fast_track:pending:${(FAST - NORMAL).toFixed(2)}`);
order = await triageOrder();
check('not moved up before payment', order.indexOf(early.id) < order.indexOf(late.id));
equal('the visit shows fast track waiting for payment', (await get(`visits/${late.id}`)).visit.fast_track_pending, true);
await expectError('a second upgrade is refused while one waits for payment', () => post(`visits/${late.id}/fast-track`), 409, 'fast_track_unavailable');

await pay(raised.bill_id);
order = await triageOrder();
check('after payment the patient moves ahead of the normal queue', order.indexOf(late.id) < order.indexOf(early.id), order.join(','));
const queued = (await get('triage/queue')).queue.find(i => i.visit_id === late.id);
check('and the queue card shows fast track', queued?.is_fast_track === true);
check('the visit history records the upgrade', sql(`SELECT COUNT(*) FROM visit_status_history WHERE visit_id = ${late.id} AND note = 'Upgraded to fast track'`) === '1');
check('the bill is recorded against the visit', sql(`SELECT fast_track_bill_id FROM visits WHERE id = ${late.id}`) === String(raised.bill_id));
check('the audit log has it', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'visit.fast_track' AND entity_id = '${late.visit_no}'`) === '1');
await expectError('an upgraded visit cannot be upgraded again', () => post(`visits/${late.id}/fast-track`), 409);

// ─── Upgrade before paying: the same bill is re-priced ────────────────────
const unpaid = await visitFor(await newPatient('Unpaid'));
const unpaidQuote = (await get(`visits/${unpaid.id}/fast-track`)).quote;
check('an unpaid first bill is re-priced', unpaidQuote.reprice_bill !== null);
const repriced = (await post(`visits/${unpaid.id}/fast-track`)).result;
equal('no second bill: the first one now asks the fast-track price', visitBills(unpaid.id), [`${firstBill(unpaid.id)}:visit:pending:${FAST.toFixed(2)}:0.00`]);
equal('it is the bill that starts fast track', repriced.bill_id, firstBill(unpaid.id));
check('still not fast track before payment', sql(`SELECT is_fast_track FROM visits WHERE id = ${unpaid.id}`) === '0');
await pay(repriced.bill_id);
check('paid: fast track, and the patient joins the queue', sql(`SELECT CONCAT(is_fast_track, status) FROM visits WHERE id = ${unpaid.id}`) === '1waiting_triage');

// ─── A discount stays with the patient ────────────────────────────────────
const discounted = await visitFor(await newPatient('Discount'));
const dBill = (await get(`bills/${firstBill(discounted.id)}`)).bill;
const discountTypes = (await get('reference/lookups?list=discount_type')).values.map(v => v.value);
const request = (await post(`bills/${dBill.id}/discount-request`, { discount_type: discountTypes[0], amount: 5000, remarks: 'Hardship', row_version: dBill.row_version })).approval;
await post(`approvals/${request.id}/approve`, { row_version: (await get(`approvals/${request.id}`)).approval.row_version });
await pay(dBill.id);
equal('charged counts the price before discount', (await get(`visits/${discounted.id}/fast-track`)).quote.balance, FAST - NORMAL);

// ─── A free follow-up counts as nothing charged ───────────────────────────
const freePatient = await newPatient('Free');
await pay(firstBill((await visitFor(freePatient)).id));
const freeVisit = (await post('visits', { patient_id: freePatient.id, visit_type: 'follow_up', sub_type: 'doctor_follow_up', destination: 'triage', confirm_open_visit: true })).visit;
check('the follow-up was free', freeVisit.fee_exempt === true);
const freeUpgrade = (await get(`visits/${freeVisit.id}/fast-track`)).quote;
equal('so the upgrade costs the full follow-up fast-track price', [freeUpgrade.charged, freeUpgrade.balance], [0, FOLLOW_FAST]);

// ─── Cancelling the upgrade bill withdraws the request ────────────────────
const cancelled = await visitFor(await newPatient('Cancel'));
await pay(firstBill(cancelled.id));
const cBill = (await post(`visits/${cancelled.id}/fast-track`)).result.bill_id;
const cRequest = (await post(`bills/${cBill}/cancel-request`, { reason: 'Changed their mind', row_version: (await get(`bills/${cBill}`)).bill.row_version })).approval;
await post(`approvals/${cRequest.id}/approve`, { row_version: (await get(`approvals/${cRequest.id}`)).approval.row_version });
const afterCancel = (await get(`visits/${cancelled.id}/fast-track`)).quote;
check('after the bill is cancelled the visit can be upgraded again', afterCancel.blocked === null && afterCancel.pending_bill === null, JSON.stringify(afterCancel));

// ─── Emergencies are already first ────────────────────────────────────────
const emergency = await visitFor(await newPatient('Emerg'), { is_emergency: true, emergency_reason: 'Chemical splash' });
check('an emergency cannot be fast tracked', (await get(`visits/${emergency.id}/fast-track`)).quote.blocked !== null);

// ─── Other queues: Diagnostics ────────────────────────────────────────────
const item = Number(sql("SELECT id FROM price_items WHERE category = 'investigation' AND is_active = 1 ORDER BY id LIMIT 1"));
const inv = { destination: 'investigation', investigation_item_id: item, investigation_eye: 'both', investigation_reason: 'Check pressure' };
const invNormal = await visitFor(await newPatient('InvA'), inv);
const invFast = await visitFor(await newPatient('InvB'), { ...inv, sub_type: 'fast_track' });
const bookings = (await get('diagnostics/bookings')).bookings.filter(b => [invNormal.id, invFast.id].includes(b.visit_id));
check('Diagnostics lists the fast-track visit first, with its flag',
  bookings.length === 2 && bookings[0].visit_id === invFast.id && bookings[0].is_fast_track === true && bookings[1].is_fast_track === false,
  JSON.stringify(bookings.map(b => [b.visit_id, b.is_fast_track])));

// ─── Only the cashier ─────────────────────────────────────────────────────
const cashierDept = Number(sql("SELECT id FROM departments WHERE name = 'Cashier'"));
const receptionRole = Number(sql("SELECT id FROM roles WHERE code = 'reception'"));
const cashierRole = Number(sql("SELECT id FROM roles WHERE code = 'cashier'"));
check('the Cashier role has the permission', sql(`SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ${cashierRole} AND p.code = 'payments.fast_track'`) === '1');
const password = `Reception-${run}-x9Q`;
await post('users', {
  first_name: 'Front', last_name: 'Desk', phone: `25574${run}88`, department_id: cashierDept,
  role_ids: [receptionRole], designation_ids: [], username: `desk${run}`, password,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = 'desk${run}'`);
await signIn(`desk${run}`, password);
await expectError('Reception cannot fast track a visit later', () => post(`visits/${early.id}/fast-track`), 403);
await signIn();

report('fast track');
