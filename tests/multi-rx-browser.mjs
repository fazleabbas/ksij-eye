// Several spectacle prescriptions in one optometry visit, seen on screen.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);

// A patient with Bifocal + Reading prescriptions, set up through the API.
await api.signIn();
const options = await api.get('optical/options');
const [lensA, lensB] = [options.lenses[0], options.lenses[1] || options.lenses[0]];
const icd = (await api.get('clinical/options')).icd10[0];
const patient = (await api.post('patients', {
  first_name: 'Screen', last_name: `Twin${run}`, gender: 'male', date_of_birth: '1958-03-03',
  phone: `25571${run}5`, address: 'Upanga, Dar es Salaam', kin_name: 'Mama Rehema', kin_relationship: 'Spouse', kin_phone: `25571${run}6`,
})).patient;
const visit = (await api.post('visits', { patient_id: patient.id, visit_type: 'consultation', sub_type: 'normal', destination: 'triage' })).visit;
const bill = await api.pendingBill(b => b.visit_no === visit.visit_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
api.sql(`UPDATE visits SET status = 'waiting_optometry' WHERE id = ${visit.id}`);
await api.post(`optometry/visits/${visit.id}/claim`);
await api.post(`optometry/visits/${visit.id}/complete`, {
  refer_optical: true, provisional_icd_ids: [icd.id], final_label: 'Bifocal', lens_item_ids: [lensA.id],
  refractions: { final: { sph_right: -1.0, sph_left: -1.0, add_right: 2.0, add_left: 2.0 } },
  extra_rx: [{ label: 'Reading', refraction: { sph_right: 1.5, sph_left: 1.5 }, lens_item_ids: [lensB.id] }],
});
const referral = (await api.get('optical/incoming')).referrals.find(r => r.visit_id === visit.id);

await launch(9453);
await signIn();

// ─── Optometry: add and remove a prescription ─────────────────────────────
await evaluate(`showModule('optometry')`);
await waitUntil(`!!CL_OPTIONS?.data?.rx_labels && !!document.getElementById('op-extra-rx')`, 15000, 'optometry');
const added = await evaluate(`(() => {
  OPTO_FORM.mode = 'edit';
  OPTO_FORM.extra = [];
  OPTO_FORM.addRx();
  const card = document.querySelector('#op-extra-rx .vitals-card');
  const labels = [...card.querySelectorAll('select[data-field="extra_rx.0.label"] option')].map(o => o.textContent);
  OPTO_FORM.setExtra(0, 'label', 'Reading');
  OPTO_FORM.setExtra(0, 'sph_right', '+1.50');
  const collected = OPTO_FORM.collect(document.getElementById('op-form')).extra_rx;
  return { cards: document.querySelectorAll('#op-extra-rx .vitals-card').length, labels, collected };
})()`);
equal('"Add another prescription" adds a second refraction card', added.cards, 1);
check('with the labels from the admin list', added.labels.includes('Reading') && added.labels.includes('Bifocal'), added.labels.join(','));
check('and its values are saved with the record', added.collected?.[0]?.label === 'Reading' && added.collected[0].refraction.sph_right === '+1.50',
  JSON.stringify(added.collected));
await shot('optometry-second-rx', SHOTS);
equal('it can be removed again', await evaluate(`(() => { OPTO_FORM.removeRx(0); return document.querySelectorAll('#op-extra-rx .vitals-card').length; })()`), 0);

// ─── Optical: one order, a frame picker per pair ──────────────────────────
await evaluate(`showModule('opt-incoming')`);
await waitUntil(`(OPT.incoming || []).some(r => r.assessment_id === ${referral.assessment_id})`, 15000, 'incoming');
check('Incoming shows both prescriptions by label', await evaluate(`document.getElementById('opt-in-tbody').textContent.includes('Reading:')`));
await evaluate(`optOpenConfirm(${referral.assessment_id})`);
await waitUntil(`document.querySelectorAll('#opt-create-order select[data-set]').length === 2`, 15000, 'confirm page');
check('the confirm page offers a frame for each pair', true);
check('and lists both prescriptions', await evaluate(`(() => { const t = document.getElementById('opt-create-order').textContent; return t.includes('Bifocal') && t.includes('Reading') && t.includes('2 pairs, one bill'); })()`));
await shot('optical-two-pairs', SHOTS);

// ─── Printing: choose which prescriptions ─────────────────────────────────
const printed = await evaluate(`(async () => {
  OPT.rx = OPT.referral.prescription;
  optPrintRx();
  await new Promise(r => setTimeout(r, 300));
  const boxes = [...document.querySelectorAll('#opt-print-sets input')];
  let html = '';
  const original = window.open;
  window.open = () => ({ document: { write: t => { html += t; }, close() {} }, focus() {}, print() {} });
  boxes.find(b => b.nextElementSibling.textContent === 'Bifocal').checked = false;
  optConfirmPrintChoice();
  window.open = original;
  return { boxes: boxes.length, html };
})()`);
equal('printing asks which prescription to print', printed.boxes, 2);
check('and prints only the ticked one', printed.html.includes('<h2>Reading</h2>') && !printed.html.includes('<h2>Bifocal</h2>'), (printed.html.match(/<h2>[^<]*<\/h2>/g) || []).join(' '));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('multi rx browser', SHOTS);
