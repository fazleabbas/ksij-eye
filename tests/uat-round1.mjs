// The corrections the clinic asked for after reviewing the system (UAT round 1).
import { signIn, get, pendingBill, post, put, del, upload, check, equal, expectError, report, sql, payDiagnostic } from './lib/api.mjs';

const me = await signIn();
// Re-runnable without a database reset: everything this suite creates carries a run tag.
const run = String(Date.now()).slice(-6);

// The Medicine price category from this round was withdrawn in round 2 (uat-round2.mjs).

// ─── "Debt" is offered as a discount type ─────────────────────────────────
const discounts = (await get('reference/lookups?list=discount_type')).values.map(v => v.value);
check('Debt can be chosen as a discount type', discounts.includes('Debt'), discounts.join(', '));

// ─── Next of kin: "Other" has to be spelled out ───────────────────────────
const basePatient = {
  first_name: 'Uat', last_name: `Kinother${run}`, gender: 'female', date_of_birth: '1990-04-04',
  phone: `25571${run}1`, address: 'Mikocheni, Dar es Salaam',
  kin_name: 'Mama Fatuma', kin_phone: `25571${run}2`,
};
await expectError('"Other" on its own is refused',
  () => post('patients', { ...basePatient, kin_relationship: 'Other' }), 422);
const other = await post('patients', {
  ...basePatient, kin_relationship: 'Other', kin_relationship_other: 'Neighbour',
});
equal('the relationship is kept as Other', other.patient.kin_relationship, 'Other');
equal('and what it really is, is kept too', other.patient.kin_relationship_other, 'Neighbour');
check('it is stored encrypted', sql(
  `SELECT kin_relationship_other_enc IS NOT NULL AND kin_relationship_other_enc NOT LIKE '%Neighbour%'
     FROM patients WHERE id = ${other.patient.id}`) === '1');

const listed = await get(`patients/${other.patient.id}`);
equal('it comes back on the patient record', listed.patient.kin_relationship_other, 'Neighbour');

const changed = await put(`patients/${other.patient.id}`, {
  ...basePatient, kin_relationship: 'Spouse', kin_relationship_other: 'Neighbour',
  row_version: listed.patient.row_version,
});
equal('choosing a listed relationship drops the free text', changed.patient.kin_relationship_other, null);

// ─── The doctor cannot finish without both diagnoses ──────────────────────
const patient = (await post('patients', {
  first_name: 'Uat', last_name: `Diagnosis${run}`, gender: 'male', date_of_birth: '1980-02-02',
  phone: `25571${run}3`, address: 'Upanga, Dar es Salaam',
  kin_name: 'Ndugu Juma', kin_relationship: 'Sibling', kin_phone: `25571${run}4`,
})).patient;

const visit = (await post('visits', {
  patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal',
  destination: 'doctor_room', room: 'Room 1',
})).visit;
const bill = await pendingBill(b => b.visit_no === visit.visit_no);
await post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });

await post(`ophthalmology/visits/${visit.id}/claim`);
const options = await get('clinical/options');
const icdId = (options.icd10 || options.icd10_codes || [])[0]?.id;
check('there are ICD-10 codes to diagnose with', !!icdId);

const consultation = {
  clinical_notes: 'Seen, examined.',
  provisional_icd_ids: icdId ? [icdId] : [],
  provisional_dx_notes: icdId ? null : 'Suspected cataract',
};
await expectError('finishing without a final diagnosis is refused',
  () => post(`ophthalmology/visits/${visit.id}/complete`, consultation), 422);
try {
  await post(`ophthalmology/visits/${visit.id}/complete`, consultation);
} catch (error) {
  check('and it says which field is missing', !!error.fields?.final_icd_ids, JSON.stringify(error.fields));
}
const done = await post(`ophthalmology/visits/${visit.id}/complete`, {
  ...consultation,
  final_icd_ids: icdId ? [icdId] : [],
  final_dx_notes: icdId ? null : 'Cataract, right eye',
});
check('with both diagnoses the consultation is finished', !!done);

// ─── A file attached by mistake can be taken off ──────────────────────────
const investigation = (await get('prices?category=investigation')).items.find(i => i.is_active);
const booking = (await post('diagnostics/bookings', {
  patient_id: patient.id, referring_user_id: me.id, eye: 'right', timing: 'now',
  procedures: [{ price_item_id: investigation.id }],
})).bookings[0];
await post(`diagnostics/bookings/${booking.id}/check-in`, { no_charge: false });
await payDiagnostic(booking.id);
await put(`diagnostics/bookings/${booking.id}/report`, {
  reporting_user_id: me.id, result: 'Normal study.', comments: '',
});

const form = new FormData();
form.append('file', new Blob([Buffer.from('%PDF-1.4 test scan')], { type: 'application/pdf' }), 'scan.pdf');
const uploaded = await upload(`diagnostics/bookings/${booking.id}/files`, form);
check('a file can be attached to the report', uploaded.files.length === 1);
const fileId = uploaded.files[0]?.id;

if (fileId) {
  const afterRemove = await del(`diagnostics/bookings/${booking.id}/files/${fileId}`);
  equal('removing it leaves the report with no files', afterRemove.files.length, 0);
  check('the link row is kept for the audit trail',
    sql(`SELECT voided_at IS NOT NULL FROM diagnostic_result_files WHERE file_id = ${fileId}`) === '1');
  await expectError('and it can no longer be downloaded',
    () => get(`diagnostics/bookings/${booking.id}/files/${fileId}`), 404);
  await expectError('removing it twice is refused',
    () => del(`diagnostics/bookings/${booking.id}/files/${fileId}`), 404);
  check('the removal is in the audit log',
    Number(sql("SELECT COUNT(*) FROM audit_log WHERE action = 'diagnostic.file_removed'")) >= 1);
}

report('uat round 1 api');
