// On screen: a patient's sponsor letter, attached once, is listed in every discount window
// (cashier, optical, diagnostics "no charge") and in the approver's view of the next request.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const me = await api.signIn();
const discountType = (await api.get('reference/lookups?list=discount_type')).values[0].value;
const version = billId => Number(api.sql(`SELECT row_version FROM bills WHERE id = ${billId}`));

// A patient referred to Optical, whose consultation bill is paid (the optical order needs it).
const patient = (await api.post('patients', {
  first_name: 'Letter', last_name: `OnFile${run}`, gender: 'male', date_of_birth: '1970-07-07',
  phone: `25576${run}1`, address: 'Sinza, Dar es Salaam', kin_name: 'Bi Zuhura', kin_relationship: 'Spouse', kin_phone: `25576${run}2`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 2' })).visit;
const consultBill = Number(api.sql(`SELECT id FROM bills WHERE visit_id = ${visit.id} AND status = 'pending'`));

// First discount at the cashier, with the letter — then approved so the visit can carry on.
const first = (await api.post(`bills/${consultBill}/discount-request`, {
  discount_type: discountType, amount: 1000, sponsor: 'Parish fund', remarks: 'Sponsored', row_version: version(consultBill),
})).approval;
const form = new FormData();
form.append('file', new Blob([Buffer.from('%PDF-1.4 letter')], { type: 'application/pdf' }), `parish-letter-${run}.pdf`);
await api.upload(`approvals/${first.id}/files`, form);
await api.post(`approvals/${first.id}/approve`, { row_version: (await api.get(`approvals/${first.id}`)).approval.row_version });
await api.post(`bills/${consultBill}/pay`, { method: 'cash', row_version: version(consultBill) });

// Optical order (unpaid) and a diagnostic booking, both for the same patient.
const icd = (await api.get('clinical/options')).icd10[0];
const lens = (await api.get('optical/options')).lenses[0];
await api.post(`optometry/visits/${visit.id}/claim`);
await api.post(`optometry/visits/${visit.id}/complete`, {
  refer_optical: true, lens_item_ids: [lens.id], provisional_icd_ids: [icd.id], refractions: { final: { sph_right: -1.0, sph_left: -0.75 } }, extra_rx: [],
});
const referral = (await api.get('optical/incoming')).referrals.find(r => r.visit_id === visit.id);
const order = (await api.post('optical/orders', { assessment_id: referral.assessment_id, lenses: [{ set_no: 1, lens_ids: [lens.id], reason: 'As prescribed' }] })).order;
const diagOptions = await api.get('diagnostics/options');
const test = diagOptions.procedures.find(p => !p.is_laser && p.unit_price > 0);
const booking = (await api.post('diagnostics/bookings', {
  patient_id: patient.id, referring_user_id: me.id, eye: 'left', timing: 'now', procedures: [{ price_item_id: test.id }],
})).bookings[0];

await launch(9531);
await signIn();
const onFile = key => evaluate(`document.getElementById('${key}-onfile')?.textContent || ''`);
const letterName = `parish-letter-${run}.pdf`;

// ─── Optical: "Ask for a discount" ─────────────────────────────────────────
await evaluate(`showModule('opt-incoming')`);
await sleep(800);
await evaluate(`optOpenDiscount(${order.id})`);
await waitUntil(`document.getElementById('opt-discount-doc-onfile')?.textContent.includes(${JSON.stringify(letterName)})`, 8000, 'optical on-file list');
const opticalText = await onFile('opt-discount-doc');
check('optical discount window lists the letter already on file', opticalText.includes(letterName) && opticalText.includes(first.approval_no), opticalText.slice(0, 200));
check('and says it need not be uploaded again', /no need to upload it again/.test(opticalText), opticalText.slice(0, 200));
check('a new document can still be attached there', await evaluate(`!!document.getElementById('opt-discount-doc-input')`));
await shot('discount-docs-optical', SHOTS);

// Send it with one more document, attached through the window's own picker.
await evaluate(`(() => {
  const dt = new DataTransfer();
  dt.items.add(new File(['%PDF-1.4 id'], 'id-card-${run}.pdf', { type: 'application/pdf' }));
  const input = document.getElementById('opt-discount-doc-input'); input.files = dt.files; input.dispatchEvent(new Event('change'));
  document.getElementById('opt-discount-amount').value = 2000;
  const type = document.getElementById('opt-discount-type'); type.value = type.options[1].value;
  document.getElementById('opt-discount-remarks').value = 'Same sponsor as before';
})()`);
await evaluate(`optSubmitDiscount(document.querySelector('#modal-opt-discount .btn-primary'))`);
await sleep(2500);
const opticalApproval = Number(api.sql(`SELECT id FROM approval_requests WHERE bill_id = ${order.bill.id} AND type = 'discount'`));
equal('the optical request got its new document', api.sql(`SELECT COUNT(*) FROM approval_files WHERE approval_id = ${opticalApproval}`), '1');

// ─── Approver: the optical request shows both letters ──────────────────────
await evaluate(`showModule('approvals-pending')`);
await sleep(800);
await evaluate(`apOpen(${opticalApproval})`);
await waitUntil(`getComputedStyle(document.getElementById('va-patient-files-panel')).display !== 'none'`, 8000, 'on-file panel');
const panel = await evaluate(`document.getElementById('va-patient-files-panel').textContent`);
check('the approver sees the earlier letter with the new request', panel.includes(letterName) && panel.includes(first.approval_no), panel.slice(0, 200));
check('the request\'s own document stays in its own list', (await evaluate(`document.getElementById('va-files').textContent`)).includes(`id-card-${run}.pdf`));
await evaluate(`document.getElementById('va-patient-files-panel').scrollIntoView()`);
await shot('discount-docs-approver', SHOTS);
await evaluate(`closeModal('modal-view-approval')`);

// ─── Diagnostics: check-in with "No charge" ────────────────────────────────
await evaluate(`showModule('diag-booking')`);
await waitUntil(`(DIAG.bookings || []).some(b => b.id === ${booking.id})`, 10000, 'booking listed');
await evaluate(`diagOpenCheckIn(${booking.id}).then(() => { uiSetRadio('diag-checkin-mode', 'no_charge'); diagCheckInModeChanged(); })`);
await waitUntil(`document.getElementById('diag-checkin-doc-onfile')?.textContent.includes(${JSON.stringify(letterName)})`, 8000, 'diagnostics on-file list');
const diagText = await onFile('diag-checkin-doc');
check('the "No charge" check-in lists both documents on file', diagText.includes(letterName) && diagText.includes(`id-card-${run}.pdf`), diagText.slice(0, 300));
await shot('discount-docs-diagnostics', SHOTS);
await evaluate(`closeModal('modal-diag-checkin')`);

// ─── Cashier: Request Discount on the (now unpaid) diagnostic bill ─────────
await api.post(`diagnostics/bookings/${booking.id}/check-in`, { no_charge: false });
const diagBill = Number(api.sql(`SELECT bill_id FROM diagnostic_bookings WHERE id = ${booking.id}`));
await evaluate(`showModule('cashier-payments')`);
await sleep(800);
await evaluate(`csOpenPayment(${diagBill}).then(() => csOpenDiscount())`);
await waitUntil(`document.getElementById('dr-doc-onfile')?.textContent.includes(${JSON.stringify(letterName)})`, 8000, 'cashier on-file list');
check('the cashier\'s discount window lists the documents on file', (await onFile('dr-doc')).includes(`id-card-${run}.pdf`));
await shot('discount-docs-cashier', SHOTS);

// A patient with nothing on file: no on-file box.
const fresh = (await api.post('patients', {
  first_name: 'No', last_name: `Letters${run}`, gender: 'female', date_of_birth: '1982-02-02',
  phone: `25576${run}3`, address: 'Ilala, Dar es Salaam', kin_name: 'Bw Ali', kin_relationship: 'Sibling', kin_phone: `25576${run}4`,
})).patient;
const freshVisit = (await api.post('visits', { patient_id: fresh.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1' })).visit;
const freshBill = Number(api.sql(`SELECT id FROM bills WHERE visit_id = ${freshVisit.id} AND status = 'pending'`));
await evaluate(`closeModal('modal-discount')`);
await evaluate(`csOpenPayment(${freshBill}).then(() => csOpenDiscount())`);
await sleep(1500);
equal('a patient with nothing on file shows no on-file box', (await onFile('dr-doc')).trim(), '');

await finish('discount documents browser', SHOTS);
