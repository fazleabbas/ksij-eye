// Operation templates: per surgery or procedure, the usual operation notes, post-op medicines,
// post-op instructions and first review day. They come with the theatre booking (joined when the
// case has a surgery and procedures) and are edited in Settings → Clinical Lists.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';
import { execFileSync } from 'node:child_process';

const admin = await signIn();
const run = String(Date.now()).slice(-6);
const itemId = code => Number(sql(`SELECT id FROM price_items WHERE code = '${code}'`));
const cataract = itemId('SURG-CATARACT-MSICS');
const btrp = itemId('PROC-BTRP');
const chalazion = itemId('SURG-CHALAZION');

// ─── The clinic's five templates are loaded ───────────────────────────────
const list = await get('catalogues/operation-templates');
const byId = id => list.items.find(i => i.id === id);
equal('templates are loaded for the five operations',
  ['SURG-CATARACT-MSICS', 'PROC-BTRP', 'SURG-EVISCERATION', 'SURG-PTERYGIUM', 'PROC-AVASTIN'].map(c => !!byId(itemId(c))?.template), [true, true, true, true, true]);
check('the cataract notes are the clinic\'s text', byId(cataract).template.operation_notes.startsWith('Under aseptic technique and adequate anaesthesia'));
equal('with its first review on day 1', byId(cataract).template.review_days, 1);
check('and its medicines from the catalogue', byId(cataract).template.medicines.some(m => /acetazolamide/i.test(m.name)) && byId(cataract).template.medicines.some(m => /paracetamol/i.test(m.name)));
equal('evisceration keeps the bandage instruction', byId(itemId('SURG-EVISCERATION')).template.postop_instructions, 'Keep pressure bandage intact for 48 hours.');
equal('every surgery and procedure is listed, with or without a template', byId(chalazion).template, null);

// Seeding again never overwrites what the administrator changed.
sql(`UPDATE operation_templates SET review_days = 3 WHERE price_item_id = ${cataract}`);
execFileSync('C:/xampp82/php/php.exe', ['bin/install.php', 'seed'], { cwd: 'C:/xampp82/htdocs/ksij_eye' });
equal('seeding again keeps the administrator\'s change', sql(`SELECT review_days FROM operation_templates WHERE price_item_id = ${cataract}`), '3');
equal('and adds no duplicates', sql(`SELECT COUNT(*) FROM operation_templates`), '5');
sql(`UPDATE operation_templates SET review_days = 1 WHERE price_item_id = ${cataract}`);

// ─── Editing in Settings ──────────────────────────────────────────────────
const drug = list.drugs[0];
await expectError('a medicine must be in the catalogue', () => put(`catalogues/operation-templates/${chalazion}`, {
  operation_notes: 'x', is_active: true, medicines: [{ drug_id: 999999, quantity: 1 }] }), 422);
await expectError('the review day stays sensible', () => put(`catalogues/operation-templates/${chalazion}`, { review_days: 500, is_active: true }), 422);
await put(`catalogues/operation-templates/${chalazion}`, {
  operation_notes: `Chalazion incised and curetted ${run}.`, postop_instructions: 'Warm compresses twice a day.', review_days: 7, is_active: true,
  medicines: [{ drug_id: drug.id, quantity: 1, dose: 'Apply', frequency: 'TDS', duration: '1 week' }],
});
const saved = (await get('catalogues/operation-templates')).items.find(i => i.id === chalazion).template;
equal('a new template is created for a surgery that had none', [saved.operation_notes, saved.review_days, saved.medicines.map(m => m.drug_id)],
  [`Chalazion incised and curetted ${run}.`, 7, [drug.id]]);
check('saving is in the audit log', sql(`SELECT COUNT(*) FROM audit_log WHERE action = 'operation_template.saved' AND entity_id = '${chalazion}'`) === '1');

// ─── The template comes with the theatre booking ──────────────────────────
const patient = (await post('patients', {
  first_name: 'Template', last_name: `Case${run}`, gender: 'female', date_of_birth: '1955-05-05',
  phone: `25571${run}8`, address: 'Kigamboni, Dar es Salaam', kin_name: 'Bw Juma', kin_relationship: 'Spouse', kin_phone: `25571${run}9`,
})).patient;
const booking = (await post('theatre/bookings', {
  patient_id: patient.id, surgery_item_id: cataract, surgeon_user_id: admin.id, eye: 'right', timing: 'now',
  procedures: [{ price_item_id: btrp, quantity: 1 }], biometry: { axial_length: '23.5' },
})).booking;
const shown = (await get(`theatre/bookings/${booking.id}`)).booking;
equal('a booking with a surgery and a procedure joins their templates, surgery first', shown.template?.names, ['Cataract (MSICS)', 'BTRP']);
check('both sets of notes, each headed', shown.template.operation_notes.startsWith('Cataract (MSICS):\n') && shown.template.operation_notes.includes('\n\nBTRP:\n'));
equal('the earliest review day is kept', shown.template.review_days, 1);
const names = shown.template.medicines.map(m => m.name);
equal('medicines are merged without repeats', names.length, new Set(names).size);
check('the instructions are carried', shown.template.postop_instructions.includes('Review Day 1 for wound check'));

// A switched-off template is not offered.
await put(`catalogues/operation-templates/${btrp}`, { ...byId(btrp).template, medicines: byId(btrp).template.medicines, is_active: false });
equal('a switched-off template is left out', (await get(`theatre/bookings/${booking.id}`)).booking.template?.names, ['Cataract (MSICS)']);

const plain = (await post('theatre/bookings', {
  patient_id: patient.id, surgery_item_id: itemId('SURG-STRABISMUS'), surgeon_user_id: admin.id, eye: 'left', timing: 'now', procedures: [],
})).booking;
equal('an operation without a template has none', (await get(`theatre/bookings/${plain.id}`)).booking.template, null);

// ─── Only administrators of the catalogues may change templates ───────────
const nurseName = `tplnurse${run}`;
await post('users', {
  first_name: 'Test', last_name: 'Nurse', phone: `25571${run}7`, department_id: Number(sql('SELECT MIN(id) FROM departments')),
  role_ids: [Number(sql("SELECT id FROM roles WHERE code = 'clinical'"))], designation_ids: [], username: nurseName, password: `Tpl-Nurse-${run}-x9`,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${nurseName}'`);
await signIn(nurseName, `Tpl-Nurse-${run}-x9`);
await expectError('a nurse cannot change templates', () => put(`catalogues/operation-templates/${cataract}`, { is_active: true }), 403);

report('operation templates');
