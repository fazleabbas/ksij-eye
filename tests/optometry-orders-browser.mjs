// On screen: Optometry's Biometry and Investigations cards (as an Optometrist), and a new
// cataract booking in Theatre filling its biometry from Optometry.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();

const username = `sopto${run}`;
const password = `Screen-Opto-${run}-x9`;
await api.post('users', {
  first_name: 'Screen', last_name: 'Optometrist', phone: `25573${run}1`, department_id: Number(api.sql("SELECT id FROM departments WHERE name = 'Optometry'")),
  role_ids: [Number(api.sql("SELECT id FROM roles WHERE code = 'optometrist'"))],
  designation_ids: [Number(api.sql("SELECT id FROM designations WHERE code = 'optometrist'"))], username, password,
});
api.sql(`UPDATE users SET must_change_password = 0 WHERE username = '${username}'`);

const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Biometry${run}`, gender: 'male', date_of_birth: '1952-02-02',
  phone: `25573${run}2`, address: 'Kinondoni, Dar es Salaam', kin_name: 'Mama Rehema', kin_relationship: 'Spouse', kin_phone: `25573${run}3`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'optometry', room: 'Optometry Room 1' })).visit;
const bill = (await api.get('bills?status=pending')).bills.find(b => b.visit_no === visit.visit_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
const found = (await api.get('patients?' + new URLSearchParams({ search: patient.reg_no }))).patients.find(p => p.id === patient.id);
const icd = (await api.get('clinical/options')).icd10[0];

await launch(9473);

// ─── Optometry, as the Optometrist ────────────────────────────────────────
await signIn(username, password);
await evaluate(`showModule('optometry')`);
await waitUntil(`!!document.querySelector('#op-q-list .tq-card[data-visit="${visit.id}"]')`, 15000, 'optometry queue');
await api.signIn(username, password);
await api.post(`optometry/visits/${visit.id}/claim`);
await evaluate(`OPTO.select(${visit.id})`);
await waitUntil(`document.getElementById('op-form').style.display === '' && document.getElementById('op-mode').textContent.includes('seeing this patient')`, 15000, 'optometry open');
check('the Biometry card is on the form', await evaluate(`!!document.querySelector('#op-form [data-field="biometry.right.axial_length"]') && !!document.querySelector('#op-form [data-field="biometry.left.iol_power"]')`));
check('so is "Add investigation"', await evaluate(`document.getElementById('op-inv-add').style.display === ''`));
await evaluate(`(() => {
  const f = document.getElementById('op-form');
  const set = (field, value) => { f.querySelector('[data-field="' + field + '"]').value = value; };
  set('biometry.right.axial_length', '23.80'); set('biometry.right.steep_k', '44.10'); set('biometry.right.iol_model', 'SA60AT'); set('biometry.right.iol_power', '20.50');
  OPTO_FORM.addInvestigation();
  const inv = OPTO_FORM.invs[0];
  inv.price_item_id = String(CL_OPTIONS.data.investigation_items[0].id); inv.eye = 'right'; inv.reason = 'Pre-cataract assessment';
  OPTO_FORM.renderInvestigations();
})()`);
const collected = await evaluate(`(() => { const c = OPTO_FORM.collect(document.getElementById('op-form')); return { al: c.biometry.right.axial_length, model: c.biometry.right.iol_model, inv: c.investigations.length, reason: c.investigations[0].reason }; })()`);
equal('the form sends the biometry and the investigation', collected, { al: '23.80', model: 'SA60AT', inv: 1, reason: 'Pre-cataract assessment' });
await shot('optometry-biometry-investigations', SHOTS);

// Complete through the API with what the screen collected, then check the record shows it.
const body = await evaluate(`OPTO_FORM.collect(document.getElementById('op-form'))`);
await api.post(`optometry/visits/${visit.id}/complete`, { ...body, provisional_icd_ids: [icd.id], refractions: { ...body.refractions, final: { ...body.refractions.final, sph_right: '-1.00' } } });
await evaluate(`OPTO.reload()`);
await sleep(1500);
check('after completing, the booked test is listed', await evaluate(`document.getElementById('op-inv').textContent.includes('Booked At Diagnostics')`),
  await evaluate(`document.getElementById('op-inv').textContent.slice(0, 120)`));
equal('and the biometry is kept', await evaluate(`document.querySelector('#op-form [data-field="biometry.right.axial_length"]').value`), '23.8');
await evaluate(`KSI.post('auth/logout').catch(() => {}).then(() => true)`);

// ─── Theatre, new cataract booking ────────────────────────────────────────
await signIn();
await evaluate(`showModule('theatre-booking')`);
await sleep(800);
await evaluate(`thOpenBooking()`);
await waitUntil(`document.getElementById('modal-theatre-booking').classList.contains('open')`, 10000, 'booking form');
const cataractId = await evaluate(`TH.options.surgeries.find(s => s.name.toLowerCase().includes('cataract')).id`);
await evaluate(`(() => {
  TH.patient = ${JSON.stringify(found)};
  document.getElementById('th-patient-card').innerHTML = uiPatientCard(TH.patient);
  document.getElementById('th-surgery').value = '${cataractId}';
  thSurgeryChanged();
  uiSetRadio('th-eye', 'right');
  document.querySelector('input[name="th-eye"][value="right"]').dispatchEvent(new Event('change'));
})()`);
await waitUntil(`document.getElementById('th-bio-axial').value !== ''`, 10000, 'biometry filled').catch(() => {});
const filled = await evaluate(`({ al: document.getElementById('th-bio-axial').value, k: document.getElementById('th-bio-steep').value,
  model: document.getElementById('th-bio-model').value, power: document.getElementById('th-bio-power').value, note: document.getElementById('th-bio-source').textContent })`);
equal('choosing cataract and the right eye fills the biometry from Optometry', [filled.al, filled.k, filled.model, filled.power], ['23.8', '44.1', 'SA60AT', '20.5']);
check('and says where it came from', filled.note.includes('Filled from Optometry') && filled.note.includes(visit.visit_no), filled.note);
await shot('theatre-biometry-filled', SHOTS);
await evaluate(`(() => { uiSetRadio('th-eye', 'left'); document.querySelector('input[name="th-eye"][value="left"]').dispatchEvent(new Event('change')); })()`);
await sleep(600);
const left = await evaluate(`({ al: document.getElementById('th-bio-axial').value, note: document.getElementById('th-bio-source').textContent })`);
check('switching to the left eye (none recorded) clears the figures and says so', left.al === '' && left.note.includes('No biometry has been recorded'), JSON.stringify(left));
check('the booking form also has lens thickness and white-to-white', await evaluate(`!!document.getElementById('th-bio-lt') && !!document.getElementById('th-bio-wtw')`));
await evaluate(`closeModal('modal-theatre-booking')`);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('optometry orders browser', SHOTS);
