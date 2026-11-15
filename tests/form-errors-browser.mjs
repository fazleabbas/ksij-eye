// A form error on a searchable dropdown must not scroll the whole page away (the screen went white).
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';
const run = String(Date.now()).slice(-6);
await api.signIn();
const patient = (await api.post('patients', { first_name: 'White', last_name: `Screen${run}`, gender: 'male', date_of_birth: '1960-01-01', phone: `25576${run}1`, address: 'Ilala', kin_name: 'X Y', kin_relationship: 'Spouse', kin_phone: `25576${run}2` })).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'triage' })).visit;
const bill = (await api.get('bills?status=pending')).bills.find(b => b.visit_no === visit.visit_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
api.sql(`UPDATE visits SET status = 'waiting_optometry' WHERE id = ${visit.id}`);
await api.post(`optometry/visits/${visit.id}/claim`);
await launch(9462); await signIn();
await evaluate(`showModule('optometry')`);
await waitUntil(`!!document.querySelector('.tq-card[data-visit="${visit.id}"]')`, 15000, 'queue');
await evaluate(`OPTO.select(${visit.id})`).catch(e => console.log('sel', e.message));
await sleep(2500);
await evaluate(`document.getElementById('op-complete-btn')?.click() ?? 'nobtn'`).catch(e => console.log(e.message));
await sleep(2500);
const state = await evaluate(`({ win: window.scrollY, html: document.documentElement.scrollTop, focused: document.activeElement.classList.contains('ss-display') && document.activeElement.classList.contains('is-invalid'),
  visible: (() => { const r = document.activeElement.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; })() })`);
check('the page itself does not scroll away', state.win === 0 && state.html === 0, JSON.stringify(state));
check('the cursor goes to the visible box of the field with the error', state.focused);
check('and that field is on screen', state.visible);
await shot('optometry-form-error', 'C:/xampp82/htdocs/ksij_eye/tests/shots');
check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('form errors browser', 'C:/xampp82/htdocs/ksij_eye/tests/shots');
