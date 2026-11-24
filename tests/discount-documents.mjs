// A document attached to one discount request stays with the patient: the next discount
// request (cashier, optical or diagnostics "no charge") shows it, and so does the approver's
// view of that request, so the same letter is never uploaded twice.
import { signIn, get, post, upload, pendingBill, check, equal, expectError, report, sql } from './lib/api.mjs';

const me = await signIn();
const run = String(Date.now()).slice(-6);
const discountType = (await get('reference/lookups?list=discount_type')).values[0].value;

async function newPatient(lastName, tail) {
  return (await post('patients', {
    first_name: 'Docs', last_name: lastName, gender: 'female', date_of_birth: '1975-05-05',
    phone: `25574${run}${tail}`, address: 'Kariakoo, Dar es Salaam',
    kin_name: 'Mama Asha', kin_relationship: 'Parent', kin_phone: `25574${run}${tail}9`,
  })).patient;
}
const pdf = name => {
  const form = new FormData();
  form.append('file', new Blob([Buffer.from(`%PDF-1.4 ${name}`)], { type: 'application/pdf' }), name);
  return form;
};

// ─── First discount, at the cashier, with a sponsor letter ────────────────
const patient = await newPatient(`Sponsor${run}`, '1');
const visit = (await post('visits', {
  patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1',
})).visit;
const bill = await pendingBill(b => b.visit_no === visit.visit_no);
const first = (await post(`bills/${bill.id}/discount-request`, {
  discount_type: discountType, amount: 5000, sponsor: 'Parish fund', remarks: 'Sponsored patient', row_version: bill.row_version,
})).approval;
const letter = (await upload(`approvals/${first.id}/files`, pdf('sponsor-letter.pdf'))).file;

equal('the patient\'s documents list the letter', (await get(`patients/${patient.id}/discount-documents`)).files.map(f => [f.id, f.name, f.approval_no]),
  [[letter.id, 'sponsor-letter.pdf', first.approval_no]]);
equal('it opens from the patient\'s documents', (await get(`patients/${patient.id}/discount-documents/${letter.id}`, { raw: true })).status, 200);
equal('the request itself does not repeat its own letter as "on file"', (await get(`approvals/${first.id}`)).approval.patient_files, []);

// ─── Second discount: diagnostics "no charge" — the letter is already there ─
const options = await get('diagnostics/options');
const test = options.procedures.find(p => !p.is_laser && p.unit_price > 0);
const booking = (await post('diagnostics/bookings', {
  patient_id: patient.id, referring_user_id: me.id, eye: 'right', timing: 'now', procedures: [{ price_item_id: test.id }],
})).bookings[0];
const checkedIn = (await post(`diagnostics/bookings/${booking.id}/check-in`, {
  no_charge: true, no_charge_reason: options.no_charge_reasons[0].label, remarks: 'Same sponsor',
})).booking;
check('check-in returns the no-charge request', Number.isInteger(checkedIn.no_charge_approval_id));
const second = (await get(`approvals/${checkedIn.no_charge_approval_id}`)).approval;
equal('the approver sees the earlier letter with the new request', second.patient_files.map(f => [f.id, f.approval_id]), [[letter.id, first.id]]);
equal('…and can open it', (await get(`approvals/${first.id}/files/${letter.id}`, { raw: true })).status, 200);
const extra = (await upload(`approvals/${second.id}/files`, pdf('staff-id.pdf'))).file;
check('a new document can still be added to the no-charge request', Boolean(extra.id));
equal('both now show for the patient, newest first', (await get(`patients/${patient.id}/discount-documents`)).files.map(f => f.name),
  ['staff-id.pdf', 'sponsor-letter.pdf']);
equal('the first request now sees the second one\'s document', (await get(`approvals/${first.id}`)).approval.patient_files.map(f => f.name), ['staff-id.pdf']);

// ─── Boundaries ───────────────────────────────────────────────────────────
const other = await newPatient(`Other${run}`, '2');
equal('another patient has nothing on file', (await get(`patients/${other.id}/discount-documents`)).files, []);
await expectError('a document cannot be opened through another patient', () => get(`patients/${other.id}/discount-documents/${letter.id}`), 404);
await expectError('an unknown patient is not found', () => get('patients/999999999/discount-documents'), 404);

const cancelVisit = (await post('visits', {
  patient_id: other.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1',
})).visit;
const cancelBill = await pendingBill(b => b.visit_no === cancelVisit.visit_no);
const cancel = (await post(`bills/${cancelBill.id}/cancel-request`, { reason: 'Registered twice', row_version: cancelBill.row_version })).approval;
await expectError('documents attach only to discount requests', () => upload(`approvals/${cancel.id}/files`, pdf('x.pdf')), 403);

// Someone who can neither ask for nor review discounts does not see the documents.
const roleCode = `nodocs_${run}`;
sql(`INSERT INTO roles (code, name, description, color, created_at) VALUES ('${roleCode}', 'No docs ${run}', 'Test', 'teal', UTC_TIMESTAMP(3))`);
sql(`INSERT INTO role_permissions (role_id, permission_id)
     SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'patients.view' WHERE r.code = '${roleCode}'`);
const dept = Number(sql("SELECT id FROM departments WHERE name = 'Cashier'"));
const password = `NoDocs-${run}-x9`;
await post('users', {
  first_name: 'No', last_name: 'Docs', phone: `25574${run}7`, department_id: dept,
  role_ids: [Number(sql(`SELECT id FROM roles WHERE code = '${roleCode}'`))], designation_ids: [], username: `nodocs${run}`, password,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = 'nodocs${run}'`);
await signIn(`nodocs${run}`, password);
await expectError('without discount or approval rights the list is refused', () => get(`patients/${patient.id}/discount-documents`), 403);
await expectError('…and so is the file', () => get(`patients/${patient.id}/discount-documents/${letter.id}`), 403);

report('discount documents');
