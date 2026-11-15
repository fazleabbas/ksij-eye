// On screen: the intra-op operation notes and the first post-op review start from the template;
// Settings → Clinical Lists → Operation Templates edits them.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const admin = await api.signIn();
const cataract = Number(api.sql("SELECT id FROM price_items WHERE code = 'SURG-CATARACT-MSICS'"));
api.sql(`UPDATE operation_templates SET is_active = 1`);

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Template${run}`, gender: 'male', date_of_birth: '1950-01-01',
  phone: `25572${run}1`, address: 'Mbezi, Dar es Salaam', kin_name: 'Mama Aisha', kin_relationship: 'Spouse', kin_phone: `25572${run}2`,
})).patient;
const booking = (await api.post('theatre/bookings', {
  patient_id: patient.id, surgery_item_id: cataract, surgeon_user_id: admin.id, eye: 'right', timing: 'now', procedures: [], biometry: { axial_length: '23.4' },
})).booking;
api.sql(`UPDATE theatre_bookings SET status = 'cleared' WHERE id = ${booking.id}`);

await launch(9503);
await signIn();

// ─── Intra-op: operation notes start from the template ────────────────────
await evaluate(`showModule('theatre-intraop')`);
await sleep(1000);
await evaluate(`thSelectOperation(${booking.id})`);
await waitUntil(`!!document.getElementById('th-op-notes')`, 15000, 'operation form');
const notes = await evaluate(`document.getElementById('th-op-notes').value`);
check('the operation notes start with the cataract template', notes.startsWith('Under aseptic technique and adequate anaesthesia'), notes.slice(0, 80));
check('and say where they came from', await evaluate(`document.getElementById('th-op-panel').textContent.includes('Started from the Cataract (MSICS) template')`));
await shot('intraop-template-notes', SHOTS);

// ─── Post-op: the first review starts with the medicines, instructions and review date ─
api.sql(`UPDATE operation_templates SET postop_instructions = 'Keep the shield on at night.' WHERE price_item_id = ${cataract}`);
api.sql(`INSERT INTO theatre_operations (booking_id, patient_id, status, anaesthesia_type, started_at, ended_at, completed_as_planned, surgical_notes_enc, completed_at, created_by)
         VALUES (${booking.id}, ${patient.id}, 'completed', 'local', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, NULL, UTC_TIMESTAMP(3), ${admin.id})`);
api.sql(`UPDATE theatre_bookings SET status = 'post_op' WHERE id = ${booking.id}`);
await evaluate(`showModule('theatre-postop')`);
await sleep(1000);
await evaluate(`thSelectPostop(${booking.id})`);
await waitUntil(`!!document.getElementById('th-postop-new') && !document.getElementById('th-postop-detail-view').classList.contains('hidden')`, 15000, 'post-op case');
await evaluate(`thNewPostopVisit()`);
await waitUntil(`!!document.getElementById('th-pop-recovery') && document.querySelectorAll('#th-pop-meds tr').length > 0`, 15000, 'post-op form');
await sleep(500);
const post = await evaluate(`({
  recovery: document.getElementById('th-pop-recovery').value,
  review: document.getElementById('th-pop-followup').value,
  meds: TH.meds.map(m => m.name),
  rows: document.getElementById('th-pop-meds').textContent,
})`);
equal('the instructions go into the recovery notes', post.recovery, 'Keep the shield on at night.');
const tomorrow = await evaluate(`new Date(new Date(uiToday() + 'T00:00:00Z').getTime() + 86400000).toISOString().slice(0, 10)`);
equal('the review date is the day after the operation (TCA D1)', post.review, tomorrow);
check('the template medicines are listed', post.meds.some(n => /acetazolamide/i.test(n)) && post.meds.some(n => /paracetamol/i.test(n)) && /acetazolamide/i.test(post.rows), JSON.stringify(post.meds));
await shot('postop-template-first-review', SHOTS);

// ─── Settings → Clinical Lists → Operation Templates ──────────────────────
await evaluate(`showModule('users-roles')`);
await sleep(600);
await evaluate(`switchSettingsTab('clinical')`);
await sleep(600);
await evaluate(`catSwitch('templates')`);
await waitUntil(`document.getElementById('cat-tbody').textContent.includes('Cataract (MSICS)')`, 15000, 'templates tab');
check('the tab lists the surgeries and procedures with their templates', await evaluate(`document.getElementById('cat-tbody').textContent.includes('Day 1')`));
check('there is no "Add" button on this tab', await evaluate(`document.getElementById('su-add-btn').style.display === 'none'`));
await evaluate(`opTplOpen(${cataract})`);
await waitUntil(`document.getElementById('modal-op-template').classList.contains('open')`, 10000, 'template editor');
check('the editor shows the notes and medicines', await evaluate(`document.getElementById('op-tpl-notes').value.startsWith('Under aseptic') && document.getElementById('op-tpl-meds').textContent.toLowerCase().includes('paracetamol')`));
await shot('settings-operation-template', SHOTS);
await evaluate(`(() => { document.getElementById('op-tpl-review').value = '2'; opTplSave(); })()`);
await sleep(1200);
equal('saving from the editor works', api.sql(`SELECT review_days FROM operation_templates WHERE price_item_id = ${cataract}`), '2');
api.sql(`UPDATE operation_templates SET review_days = 1, postop_instructions = NULL WHERE price_item_id = ${cataract}`);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('operation templates browser', SHOTS);
