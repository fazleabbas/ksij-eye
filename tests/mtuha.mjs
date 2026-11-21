// The MTUHA registers: the outpatient register built from a day's visit, and the
// theatre register built from operations performed. Plus the doctor's referral.
import { signIn, get, pendingBill, post, check, equal, expectError, report, sql } from './lib/api.mjs';

const me = await signIn();
const run = String(Date.now()).slice(-6);
const today = (await get('reports/options')).today;

async function doctorVisit(lastName, phoneTail, dateOfBirth = '1970-06-15') {
  const patient = (await post('patients', {
    first_name: 'Mtuha', last_name: lastName, gender: 'female', date_of_birth: dateOfBirth,
    phone: `25578${run}${phoneTail}`, address: `Kariakoo ${run}, Dar es Salaam`,
    kin_name: 'Ndugu Ali', kin_relationship: 'Sibling', kin_phone: `25578${run}${phoneTail}9`,
  })).patient;
  const visit = (await post('visits', {
    patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'doctor_room', room: 'Room 1',
  })).visit;
  const bill = await pendingBill(b => b.visit_no === visit.visit_no);
  await post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
  await post(`ophthalmology/visits/${visit.id}/claim`);
  return { patient, visit };
}

const options = await get('clinical/options');
const icd = (options.icd10 || options.icd10_codes || [])[0];
const abnormal = options.exam_sections.flatMap(s => s.findings).find(f => !f.is_normal);
const consultation = {
  clinical_notes: 'Advised to return if pain increases.',
  provisional_icd_ids: [icd.id], final_icd_ids: [icd.id],
  findings: [{ finding_id: abnormal.id, eye: 'left' }],
};

// ─── The doctor can record a referral to another facility ─────────────────
const referred = await doctorVisit(`Referred${run}`, '1');
await expectError('a referral must say where to',
  () => post(`ophthalmology/visits/${referred.visit.id}/complete`, { ...consultation, referral: { referred: true } }), 422);
await post(`ophthalmology/visits/${referred.visit.id}/complete`, {
  ...consultation, referral: { referred: true, facility: 'Muhimbili National Hospital' },
});
const record = (await get(`ophthalmology/visits/${referred.visit.id}`)).record
  ?? (await get(`ophthalmology/visits/${referred.visit.id}`)).workspace?.record;
equal('the referral is kept on the consultation', record?.referral?.facility, 'Muhimbili National Hospital');
check('and stored encrypted', sql(
  `SELECT referral_facility_enc NOT LIKE '%Muhimbili%' FROM ophthalmology_consultations WHERE visit_id = ${referred.visit.id}`) === '1');

// ─── A second patient is recommended for surgery ──────────────────────────
const surgical = await doctorVisit(`Surgical${run}`, '2');
const surgeryItem = (await get('prices?category=surgery')).items.find(i => i.is_active);
await post(`ophthalmology/visits/${surgical.visit.id}/complete`, {
  ...consultation, surgery: [{ price_item_id: surgeryItem.id, eye: 'right', urgency: 'elective', indication: 'Mature cataract' }],
});

// ─── Main MTUHA register ──────────────────────────────────────────────────
const main = (await get('reports/run?' + new URLSearchParams({ type: 'mtuha_main', from: today, to: today }))).report;
const row = main.rows.find(r => r.name.includes(`Referred${run}`));
check('the visit is in the register', !!row);
equal('with where the patient lives', row?.place, `Kariakoo ${run}, Dar es Salaam`);
check('their age on the day', row?.age >= 55 && row?.age <= 57, row?.age);
equal('gender', row?.gender, 'Female');
check('the ICD diagnosis', row?.diagnosis.startsWith(icd.code), row?.diagnosis);
check('the abnormal eye is ticked', row?.eye_le === '✓' && row?.eye_re === '', `${row?.eye_re}/${row?.eye_le}`);
equal('outcome is the referral, with the facility', row?.outcome, 'Referral — Muhimbili National Hospital');
equal('payment', row?.payment, 'Cash');
equal('suggestion is the doctor\'s note', row?.suggestion, 'Advised to return if pain increases.');
check('nothing is borrowed from other visits', row?.va_re === '' && row?.weight === '');
const surgicalRow = main.rows.find(r => r.name.includes(`Surgical${run}`));
equal('a surgery recommendation is an admission', surgicalRow?.outcome_code, 'admission');
check('and shows as a treatment', surgicalRow?.treatments.includes(surgeryItem.name), surgicalRow?.treatments);
check('visual acuity and eye are grouped columns',
  main.columns.filter(c => c.group === 'Visual acuity').length === 2 && main.columns.filter(c => c.group === 'Eye').length === 2);

const onlyReferrals = (await get('reports/run?' + new URLSearchParams({ type: 'mtuha_main', from: today, to: today, status: 'referral' }))).report;
check('the outcome filter works', onlyReferrals.rows.length >= 1 && onlyReferrals.rows.every(r => r.outcome_code === 'referral'));

// ─── Surgery MTUHA register ───────────────────────────────────────────────
const nurseId = Number(sql(`SELECT id FROM users WHERE username = 'sysadmin'`));
const bookingNo = `TB-T${run}`;
sql(`INSERT INTO theatre_bookings (booking_no, patient_id, surgery_item_id, eye, surgeon_user_id, scheduled_for, status, created_by)
     VALUES ('${bookingNo}', ${surgical.patient.id}, ${surgeryItem.id}, 'right', ${me.id}, UTC_TIMESTAMP(), 'post_op', ${me.id})`);
const bookingId = sql(`SELECT id FROM theatre_bookings WHERE booking_no = '${bookingNo}'`);
sql(`INSERT INTO theatre_operations (booking_id, patient_id, status, started_at, ended_at, completed_as_planned, created_by)
     VALUES (${bookingId}, ${surgical.patient.id}, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, ${me.id})`);
const operationId = sql(`SELECT id FROM theatre_operations WHERE booking_id = ${bookingId}`);
sql(`INSERT INTO theatre_operation_team (operation_id, user_id, role) VALUES (${operationId}, ${nurseId}, 'scrub_nurse')`);
const plannedNo = `TB-P${run}`;
sql(`INSERT INTO theatre_bookings (booking_no, patient_id, surgery_item_id, eye, surgeon_user_id, scheduled_for, status, created_by)
     VALUES ('${plannedNo}', ${referred.patient.id}, ${surgeryItem.id}, 'left', ${me.id}, UTC_TIMESTAMP(), 'booked', ${me.id})`);

const theatre = (await get('reports/run?' + new URLSearchParams({ type: 'mtuha_surgery', from: today, to: today }))).report;
const op = theatre.rows.find(r => r.name.includes(`Surgical${run}`));
check('the operation is in the theatre register', !!op);
equal('with the surgery type', op?.surgery, surgeryItem.name);
check('the operated eye', op?.eye_re === '✓' && op?.eye_le === '');
equal('the year', op?.year, today.slice(0, 4));
check('the scrub nurse from the theatre team', op?.scrub_nurse.length > 0, op?.scrub_nurse);
check('the surgeon falls back to the booking', op?.surgeon.length > 0);
equal('no complications noted', op?.results, 'Completed as planned');
check('a booking not yet operated is not in the register', !theatre.rows.some(r => r.name.includes(`Referred${run}`)));

// ─── Round 3: Reg no, Optical, search by date ─────────────────────────────
const run3 = async (type, extra = {}) => (await get('reports/run?' + new URLSearchParams({ type, from: today, to: today, ...extra }))).report;
const mainAgain = await run3('mtuha_main');
check('the register shows the reg no', mainAgain.columns.some(c => c.key === 'reg_no')
  && mainAgain.rows.find(r => r.name.includes(`Referred${run}`))?.reg_no === referred.patient.reg_no);
check('and an Optical column', mainAgain.columns.some(c => c.key === 'optical'));
check('searching by date finds the day', (await run3('mtuha_main', { search: today })).rows.length === mainAgain.rows.length);

// ─── Pediatric and Glaucoma reports ───────────────────────────────────────
const glaucomaCode = (options.icd10 || options.icd10_codes || []).find(c => /^H40/.test(c.code));
const child = await doctorVisit(`Child${run}`, '3', `${Number(today.slice(0, 4)) - 9}-02-01`);
await post(`ophthalmology/visits/${child.visit.id}/complete`, {
  ...consultation, provisional_icd_ids: [(glaucomaCode || icd).id], final_icd_ids: [(glaucomaCode || icd).id],
});
const pediatric = await run3('pediatric');
check('a nine-year-old is in the Pediatric report', pediatric.rows.some(r => r.name.includes(`Child${run}`)));
check('an adult is not', !pediatric.rows.some(r => r.name.includes(`Referred${run}`)));
equal('the Pediatric columns are the clinic\'s',
  pediatric.columns.map(c => c.label).join(', '), 'Date, Reg no, Name, Age, Sex, Diagnosis, Medicine, Procedure, Optical, Payment');
if (glaucomaCode) {
  const glaucoma = await run3('glaucoma');
  check('a glaucoma diagnosis puts the visit in the Glaucoma report', glaucoma.rows.some(r => r.name.includes(`Child${run}`)));
  check('other diagnoses do not', !glaucoma.rows.some(r => r.name.includes(`Referred${run}`)) || /^H4[02]/.test(icd.code));
} else {
  check('there is an H40 glaucoma code to test with', false, 'no H40 code in the ICD list');
}

// ─── The Surgery report in the clinic's format ────────────────────────────
sql(`INSERT INTO theatre_operation_team (operation_id, user_id, role) VALUES (${operationId}, ${nurseId}, 'runner')`);
const surgeryReport = await run3('theatre');
equal('the Surgery report has the clinic\'s columns', surgeryReport.columns.map(c => c.label).join(', '),
  'Date, Reg no, Name, Age, Sex, Procedure, Surgeon, Assistant nurse, Runner, Retrobulbar, Medicine, Payment (status)');
const surgeryRow = surgeryReport.rows.find(r => r.name.includes(`Surgical${run}`));
equal('with the procedure', surgeryRow?.procedure, surgeryItem.name);
check('the runner from the theatre team', surgeryRow?.runner.length > 0);
equal('and the payment status', surgeryRow?.payment, 'no_bill');

// ─── Financial summary: item breakdown under each category ────────────────
const financial = await run3('financial');
const consultationTotal = financial.summary.rows.find(r => r.level === 0 && r.type.startsWith('Consultation'));
check('each category has a total row', !!consultationTotal);
const consultationItems = financial.summary.rows.filter(r => r.level === 1);
check('with its items and number of cases underneath', consultationItems.some(r => / \(\d+ cases?\)$/.test(r.type)));

// ─── The MTUHA permission stands on its own ───────────────────────────────
const groups = (await get('permissions')).groups;
check('the roles screen has an MTUHA group', groups.some(g => g.category === 'MTUHA'
  && g.permissions.some(p => p.code === 'mtuha.view')), JSON.stringify(groups.map(g => g.category)));
const mtuhaRole = `mtuha_only_${run}`;
sql(`INSERT INTO roles (code, name, description, color, created_at) VALUES ('${mtuhaRole}', 'MTUHA ${run}', 'Test', 'teal', UTC_TIMESTAMP(3))`);
sql(`INSERT INTO role_permissions (role_id, permission_id)
     SELECT (SELECT id FROM roles WHERE code = '${mtuhaRole}'), id FROM permissions WHERE code = 'mtuha.view'`);
const recordsName = `records${run}`;
const recordsPass = `Records-Officer-${run}-x9`;
await post('users', {
  first_name: 'Records', last_name: 'Officer', phone: `25579${run}7`,
  department_id: Number(sql("SELECT id FROM departments WHERE name = 'Administration'")),
  role_ids: [Number(sql(`SELECT id FROM roles WHERE code = '${mtuhaRole}'`))], designation_ids: [],
  username: recordsName, password: recordsPass,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${recordsName}'`);
await signIn(recordsName, recordsPass);
const offered = (await get('reports/options')).reports.map(r => r.type).sort();
equal('someone with only MTUHA is offered exactly the four registers', offered.join(','), 'glaucoma,mtuha_main,mtuha_surgery,pediatric');
check('and can open them', (await run3('mtuha_main')).rows !== undefined);
await expectError('but not the other clinical reports', () => get('reports/run?type=theatre'), 403);
await expectError('nor the financial ones', () => get('reports/run?type=financial'), 403);
await signIn();

// ─── Who may see the registers ────────────────────────────────────────────
const cashierName = `cashier${run}`;
const cashierPass = `Cash-Desk-${run}-x9`;
await post('users', {
  first_name: 'Test', last_name: 'Cashier', phone: `25579${run}1`,
  department_id: Number(sql("SELECT id FROM departments WHERE name = 'Cashier'")),
  role_ids: [Number(sql("SELECT id FROM roles WHERE code = 'cashier'"))], designation_ids: [],
  username: cashierName, password: cashierPass,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${cashierName}'`);
await signIn(cashierName, cashierPass);
await expectError('a cashier cannot open the MTUHA register',
  () => get('reports/run?' + new URLSearchParams({ type: 'mtuha_main', from: today, to: today })), 403);
check('nor is it offered to them', !(await get('reports/options')).reports.some(r => r.type.startsWith('mtuha')));
await signIn();

report('mtuha');
