// On screen: Post-Op opens on a list of cases (filters, last/next review); opening a case
// shows its reviews with "New Post-Op Visit" on the left and the review on the right,
// filling the screen — the visit list and the form scroll, the page does not.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
const admin = await api.signIn();
const cataract = Number(api.sql("SELECT id FROM price_items WHERE code = 'SURG-CATARACT-MSICS'"));

const patient = (await api.post('patients', {
  first_name: 'Postop', last_name: `Layout${run}`, gender: 'female', date_of_birth: '1955-03-01',
  phone: `25573${run}1`, address: 'Kariakoo, Dar es Salaam', kin_name: 'Juma', kin_relationship: 'Spouse', kin_phone: `25573${run}2`,
})).patient;
const booking = (await api.post('theatre/bookings', {
  patient_id: patient.id, surgery_item_id: cataract, surgeon_user_id: admin.id, eye: 'left', timing: 'now', procedures: [], biometry: { axial_length: '23.1' },
})).booking;
api.sql(`INSERT INTO theatre_operations (booking_id, patient_id, status, anaesthesia_type, started_at, ended_at, completed_as_planned, surgical_notes_enc, completed_at, created_by)
         VALUES (${booking.id}, ${patient.id}, 'completed', 'local', UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, NULL, UTC_TIMESTAMP(3), ${admin.id})`);
api.sql(`UPDATE theatre_bookings SET status = 'post_op' WHERE id = ${booking.id}`);

await launch(9511);
await signIn();

// ─── The list ─────────────────────────────────────────────────────────────
await evaluate(`showModule('theatre-postop')`);
await waitUntil(`document.getElementById('th-postop-tbody').textContent.includes('Layout${run}')`, 15000, 'post-op list');
check('the page opens on the list', await evaluate(`!document.getElementById('th-postop-list-view').classList.contains('hidden') && document.getElementById('th-postop-detail-view').classList.contains('hidden')`));
check('the surgery and surgeon filters are filled', await evaluate(`document.getElementById('th-postop-filter-surgery').options.length > 1 && document.getElementById('th-postop-filter-surgeon').options.length > 1`));
check('a new case shows "None yet" for the last review', await evaluate(`[...document.querySelectorAll('#th-postop-tbody tr')].find(r => r.textContent.includes('Layout${run}')).textContent.includes('None yet')`));
await shot('postop-list', SHOTS);

// ─── Opening a case with no reviews ───────────────────────────────────────
await evaluate(`[...document.querySelectorAll('#th-postop-tbody tr')].find(r => r.textContent.includes('Layout${run}')).click()`);
await waitUntil(`!document.getElementById('th-postop-detail-view').classList.contains('hidden')`, 15000, 'case detail');
await sleep(400);
check('the back button shows', await evaluate(`!document.getElementById('th-postop-back').classList.contains('hidden')`));
check('the left header names the patient', await evaluate(`document.getElementById('th-postop-name').textContent.includes('Layout${run}')`));
check('no blank form until New Post-Op Visit', await evaluate(`!document.getElementById('th-pop-recovery') && document.getElementById('th-postop-panel').textContent.includes('No review recorded yet')`));

// ─── New Post-Op Visit, save two reviews ──────────────────────────────────
for (const n of [1, 2]) {
  await evaluate(`document.getElementById('th-postop-new').click()`);
  await waitUntil(`!!document.getElementById('th-pop-recovery')`, 10000, 'review form');
  await evaluate(`(() => {
    document.getElementById('th-pop-va-r').value = '6/${n * 6}';
    document.getElementById('th-pop-recovery').value = 'Comfortable, review ${n}';
    document.getElementById('th-pop-followup').value = '2030-01-0${n}';
    TH.meds = [];
    thSavePostop(document.querySelector('#th-postop-panel .btn-primary'), true);
  })()`);
  await waitUntil(`document.querySelectorAll('#th-postop-visits .pop-visit-item').length === ${n}`, 15000, `review ${n} saved`);
  await sleep(500);
}
check('the newest review is on top and selected', await evaluate(`(() => { const first = document.querySelector('#th-postop-visits .pop-visit-item'); return first.classList.contains('selected') && first.textContent.includes('Review 2'); })()`));
await evaluate(`document.querySelectorAll('#th-postop-visits .pop-visit-item')[1].click()`);
await sleep(300);
equal('clicking an older review opens it', await evaluate(`document.getElementById('th-pop-recovery').value`), 'Comfortable, review 1');

// ─── Layout: fills the screen; the page itself does not scroll ───────────
const layout = await evaluate(`(() => {
  const main = document.getElementById('main');
  const detail = document.getElementById('th-postop-detail-view').getBoundingClientRect();
  const right = document.querySelector('#th-postop-detail-view .pop-detail-right');
  const newBtn = document.getElementById('th-postop-new').getBoundingClientRect();
  return { pageScrolls: main.scrollHeight > main.clientHeight + 1, bottom: detail.bottom, viewport: innerHeight,
           formScrolls: right.scrollHeight > right.clientHeight, newVisible: newBtn.top > 0 && newBtn.bottom < innerHeight };
})()`);
check('the page itself does not scroll', !layout.pageScrolls, JSON.stringify(layout));
check('the case panel ends inside the window', layout.bottom <= layout.viewport, JSON.stringify(layout));
check('the review form scrolls inside its panel', layout.formScrolls, JSON.stringify(layout));
check('New Post-Op Visit stays in view', layout.newVisible, JSON.stringify(layout));
await shot('postop-case', SHOTS);

// ─── Back to the list: last and next review show ─────────────────────────
await evaluate(`thPostopShowList()`);
await waitUntil(`!document.getElementById('th-postop-list-view').classList.contains('hidden') && document.getElementById('th-postop-tbody').textContent.includes('Layout${run}')`, 15000, 'back to list');
await sleep(500);
const row = await evaluate(`[...document.querySelectorAll('#th-postop-tbody tr')].find(r => r.textContent.includes('Layout${run}')).textContent`);
check('the list shows the next review date', row.includes('2030'), row);
check('and no longer says "None yet"', !row.includes('None yet'), row);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('post-op layout browser', SHOTS);
