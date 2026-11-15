// Approving your own request: refused unless you hold "approve own requests"
// (the Senior Approver role, or Admin), and always marked self-approved.
import { signIn, get, post, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
const run = String(Date.now()).slice(-6);
const roleId = code => Number(sql(`SELECT id FROM roles WHERE code = '${code}'`));
const cashierDept = Number(sql("SELECT id FROM departments WHERE name = 'Cashier'"));

// An approver who may decide other people's requests but not their own.
const plainCode = `approver_${run}`;
sql(`INSERT INTO roles (code, name, description, color, created_at) VALUES ('${plainCode}', 'Approver ${run}', 'Test', 'teal', UTC_TIMESTAMP(3))`);
sql(`INSERT INTO role_permissions (role_id, permission_id)
     SELECT ${roleId(plainCode)}, id FROM permissions WHERE code IN ('approvals.view', 'approvals.decide')`);

async function makeUser(name, roles) {
  const password = `Approver-${run}-${name}-x9`;
  await post('users', {
    first_name: 'Test', last_name: name, phone: `25573${run}${name.length}`, department_id: cashierDept,
    role_ids: roles.map(roleId), designation_ids: [], username: `${name.toLowerCase()}${run}`, password,
  });
  sql(`UPDATE users SET must_change_password = 0 WHERE username = '${name.toLowerCase()}${run}'`);
  return { username: `${name.toLowerCase()}${run}`, password };
}
const plain = await makeUser('Plain', ['cashier', plainCode]);
const senior = await makeUser('Senior', ['cashier', 'senior_approver']);

async function billFor(lastName, tail) {
  const patient = (await post('patients', {
    first_name: 'Approve', last_name: lastName, gender: 'female', date_of_birth: '1980-08-08',
    phone: `25573${run}${tail}`, address: 'Temeke, Dar es Salaam',
    kin_name: 'Kaka Musa', kin_relationship: 'Sibling', kin_phone: `25573${run}${tail}9`,
  })).patient;
  const visit = (await post('visits', {
    patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1',
  })).visit;
  return (await get('bills?status=pending')).bills.find(b => b.visit_no === visit.visit_no);
}
const discountTypes = (await get('reference/lookups?list=discount_type')).values.map(v => v.value);

// ─── Without the permission: still two people ─────────────────────────────
const billA = await billFor(`Plain${run}`, '1');
await signIn(plain.username, plain.password);
const requestA = (await post(`bills/${billA.id}/discount-request`, {
  discount_type: discountTypes[0], amount: 5000, remarks: 'Hardship', row_version: billA.row_version,
})).approval;
const shownA = (await get(`approvals/${requestA.id}`)).approval;
await expectError('an approver cannot approve their own discount',
  () => post(`approvals/${requestA.id}/approve`, { row_version: shownA.row_version }), 403);

// ─── With it: allowed, and marked ─────────────────────────────────────────
await signIn();
const billB = await billFor(`Senior${run}`, '2');
await signIn(senior.username, senior.password);
const requestB = (await post(`bills/${billB.id}/discount-request`, {
  discount_type: discountTypes[0], amount: 5000, remarks: 'Staff family', row_version: billB.row_version,
})).approval;
const shownB = (await get(`approvals/${requestB.id}`)).approval;
const decided = (await post(`approvals/${requestB.id}/approve`, { row_version: shownB.row_version })).approval;
equal('a Senior Approver can approve their own discount', decided.status, 'approved');
check('it is marked self-approved', decided.is_self_approved === true);
check('the audit log says self-approved', sql(
  `SELECT COUNT(*) FROM audit_log WHERE action = 'approval.approved' AND entity_id = '${requestB.approval_no}' AND summary LIKE 'Self-approved%'`) === '1');
check('the plain approver\'s request is not marked', (await get(`approvals/${requestA.id}`)).approval.is_self_approved === false);

const today = (await get('reports/options')).today;
// Discounts are part of the Financial Summary (the separate Discounts report was removed).
const financial = (await get('reports/run?' + new URLSearchParams({ type: 'financial', from: today, to: today }))).report;
const discounted = financial.summaries.find(t => t.title === 'Discounted bills');
const row = discounted?.rows.find(r => r.bill_no === billB.bill_no);
check('the Financial Summary discounts show it as self-approved', (row?.approved_by || '').startsWith('Self-approved'), row?.approved_by);
check('and counts self-approvals', financial.kpis.some(k => k.label === 'Self-approved discounts' && k.value >= 1));

// The plain approver can still decide someone else's request.
await signIn(plain.username, plain.password);
const billC = await (async () => { await signIn(); const b = await billFor(`Other${run}`, '3'); return b; })();
await signIn(senior.username, senior.password);
const requestC = (await post(`bills/${billC.id}/discount-request`, {
  discount_type: discountTypes[0], amount: 3000, remarks: 'Camp', row_version: billC.row_version,
})).approval;
await signIn(plain.username, plain.password);
const shownC = (await get(`approvals/${requestC.id}`)).approval;
equal('a plain approver still decides other people\'s requests',
  (await post(`approvals/${requestC.id}/approve`, { row_version: shownC.row_version })).approval.status, 'approved');

// ─── Approving more than was asked ────────────────────────────────────────
await signIn();
const billD = await billFor(`More${run}`, '4');
const requestD = (await post(`bills/${billD.id}/discount-request`, {
  discount_type: discountTypes[0], amount: 3000, remarks: 'Asked for a little', row_version: billD.row_version,
})).approval;
await signIn(plain.username, plain.password);
let shownD = (await get(`approvals/${requestD.id}`)).approval;
await expectError('a plain approver cannot give more than was asked',
  () => post(`approvals/${requestD.id}/approve`, { approved_amount: 8000, row_version: shownD.row_version }), 422);
await signIn(senior.username, senior.password);
shownD = (await get(`approvals/${requestD.id}`)).approval;
await expectError('nobody can give more than is owed',
  () => post(`approvals/${requestD.id}/approve`, { approved_amount: billD.gross_amount + 1, row_version: shownD.row_version }), 422);
const raised = (await post(`approvals/${requestD.id}/approve`, { approved_amount: 8000, row_version: shownD.row_version })).approval;
equal('a Senior Approver can give more than was asked', raised.approved_amount, 8000);
equal('the bill carries the larger discount', Number(sql(`SELECT discount_amount FROM bills WHERE id = ${billD.id}`)), 8000);
check('the audit log records requested and approved', sql(
  `SELECT COUNT(*) FROM audit_log WHERE action = 'approval.approved' AND entity_id = '${requestD.approval_no}' AND changes LIKE '%3000%' AND changes LIKE '%8000%'`) === '1');

await signIn();
report('self approval');
