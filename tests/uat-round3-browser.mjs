// UAT round 3, seen on screen.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const text = sel => evaluate(`(document.querySelector(${JSON.stringify(sel)})?.textContent || '').trim()`);

await launch(9450);
await signIn();

// ─── A reason with no field on the form is still shown ────────────────────
await evaluate(`(() => {
  const form = document.createElement('div');
  form.innerHTML = '<div class="form-group"><input data-field="consent_given"></div>';
  KSI.reportError({ message: 'Please correct the highlighted fields.', fields: {
    consent_given: 'Consent has to be given.',
    biometry: 'A cataract case needs its biometry before clearance.',
  } }, form, 'Could not clear this case');
  return true;
})()`);
await sleep(300);
const toast = (await text('#toast-container')).toLowerCase();
check('the hidden reason appears in the message', toast.includes('needs its biometry'), toast.slice(0, 160));
check('instead of only "highlighted fields"', !toast.includes('highlighted fields'));

// ─── The doctor has separate Surgery and Procedure cards ──────────────────
await evaluate(`showModule('ophthalmology')`);
await waitUntil(`!!CL_OPTIONS?.data?.surgery_items && !!document.getElementById('oph-proc-card')`, 15000, 'doctor screen');
const lists = await evaluate(`(() => {
  OPH_FORM.mode = 'edit';
  OPH_FORM.orders.surgery = []; OPH_FORM.orders.procedures = [];
  OPH_FORM.addOrder('surgery'); OPH_FORM.addOrder('procedures');
  const names = sel => [...document.querySelectorAll(sel + ' select[data-field$="price_item_id"] option')].map(o => o.value).filter(Boolean).map(Number);
  const category = id => CL_OPTIONS.data.surgery_items.find(i => i.id === id)?.category;
  const surgery = names('#oph-surg'), procedures = names('#oph-proc');
  return {
    surgeryOnly: surgery.length > 0 && surgery.every(id => category(id) === 'surgery'),
    proceduresOnly: procedures.length > 0 && procedures.every(id => category(id) === 'procedure'),
    procedureField: document.querySelector('#oph-proc select')?.dataset.field,
  };
})()`);
check('the Surgery card lists surgeries only', lists.surgeryOnly);
check('the Procedure card lists procedures only', lists.proceduresOnly);
check('a procedure row follows the surgery rows when saved', lists.procedureField === 'surgery.1.price_item_id', lists.procedureField);
await shot('round3-procedure-card', SHOTS);

// ─── The clinic's reports are on the MTUHA menu ───────────────────────────
for (const [module, type] of [['mtuha-pediatric', 'pediatric'], ['mtuha-glaucoma', 'glaucoma']]) {
  await evaluate(`showModule('${module}')`);
  await waitUntil(`RP.report?.type === '${type}'`, 15000, type);
  check(`${type} opens from the menu`, await evaluate(`document.getElementById('nav-${module}').classList.contains('active')`));
}
await evaluate(`(() => { document.getElementById('rp-type').value = 'financial'; rpTypeChanged(); return true; })()`);
await waitUntil(`RP.report?.type === 'financial'`, 15000, 'financial');
check('the financial breakdown indents items under their category', await evaluate(`(() => {
  const rows = [...document.querySelectorAll('#rp-summary tbody tr')];
  return rows.some(r => r.style.fontWeight === '700') && rows.some(r => r.children[0].style.paddingLeft === '28px');
})()`));
await shot('round3-financial', SHOTS);

// ─── Clinical queues open on today's patients ─────────────────────────────
const today = await evaluate(`KSI.get('clinical/options').then(o => o.today)`);
for (const module of ['triage', 'optometry', 'ophthalmology']) {
  await evaluate(`showModule('${module}')`);
  await waitUntil(`!!document.querySelector('#module-${module} input[id$="-q-from"]')?.value`, 15000, module);
  const dates = await evaluate(`[...document.querySelectorAll('#module-${module} input[id$="-q-from"], #module-${module} input[id$="-q-to"]')].map(i => i.value)`);
  check(`the ${module} queue opens on today`, dates.length === 2 && dates.every(d => d === today), JSON.stringify(dates));
}

// ─── Patient History: anyone, not just today's queue ──────────────────────
// uat-round3.mjs has already completed consultations for patients named "Round …".
await evaluate(`showModule('ophthalmology')`);
await waitUntil(`!!document.getElementById('oph-q-search')`, 10000, 'ophthalmology queue');
await evaluate(`(() => { const s = document.getElementById('oph-q-search'); s.value = 'Round'; s.dispatchEvent(new Event('input')); return true; })()`);
await waitUntil(`document.getElementById('oph-q-list').textContent.includes('Not in this queue')`, 10000, 'queue search');
check('a queue search that finds nobody points to Patient History',
  await evaluate(`document.getElementById('oph-q-list').textContent.includes('Patient History')`));
await evaluate(`document.querySelector('#oph-q-list a').click()`);
await waitUntil(`!document.getElementById('module-patient-history').classList.contains('hidden')
  && !!document.querySelector('#ph-results .search-result-item')`, 15000, 'patient history search');
check('Patient History opens with the same search', await evaluate(`document.getElementById('ph-search').value === 'Round'`));
await evaluate(`document.querySelector('#ph-results .search-result-item').click()`);
await waitUntil(`!!document.querySelector('#ph-body .hist-visit-card')`, 15000, 'history loaded');
check("the patient's past visits are shown", await evaluate(`document.querySelectorAll('#ph-body .hist-visit-card').length > 0`));
await evaluate(`histTab('orders')`);
check('with their orders', await evaluate(`!!document.getElementById('ph-body').textContent.trim()`));
await shot('patient-history', SHOTS);

// ─── Cashier pays a pharmacy counter sale ─────────────────────────────────
await api.signIn();
const counterDrug = (await api.get('clinical/options')).drugs[0];
await api.post(`pharmacy/stock/${counterDrug.id}`, { change: 10, reason: 'received', note: 'Stock for the counter sale test' });
const counterSale = await api.post('pharmacy/walk-in-sales', {
  name: `Screen Counter ${Date.now() % 100000}`, phone: '255700099887', items: [{ drug_id: counterDrug.id, quantity: 1, unit_price: 1500 }],
});
const counterBillNo = counterSale.order.bill.bill_no;
const counterBillId = (await api.get('bills?status=pending')).bills.find(b => b.bill_no === counterBillNo).id;
await evaluate(`showModule('cashier-payments')`);
await waitUntil(`document.getElementById('cs-tbody').textContent.includes('${counterBillNo}')`, 15000, 'cashier queue');
await evaluate(`csOpenPayment(${counterBillId})`);
await waitUntil(`document.getElementById('modal-process-payment').classList.contains('open')`, 8000, 'payment modal');
await evaluate(`(() => { document.querySelector('input[name="pp-method"][value="cash"]').click(); return true; })()`);
await evaluate(`csConfirmPayment()`);
await waitUntil(`!document.getElementById('modal-process-payment').classList.contains('open')`, 10000, 'payment modal closes');
check('paying a counter sale closes the payment window', true);
check('and shows the receipt', await evaluate(`document.getElementById('modal-receipt').classList.contains('open')
  && document.getElementById('rc-content').textContent.includes('Screen Counter')`));
await waitUntil(`!document.getElementById('cs-tbody').textContent.includes('${counterBillNo}')
  || document.getElementById('cs-tbody').textContent.includes('Paid')`, 10000, 'queue refreshed');
check('and the list no longer shows it as pending', await evaluate(`![...document.querySelectorAll('#cs-tbody tr')]
  .some(tr => tr.textContent.includes('${counterBillNo}') && tr.textContent.includes('Pending'))`));
await shot('counter-sale-paid', SHOTS);
await evaluate(`closeModal('modal-receipt')`);

// ─── Registering an existing paper patient ────────────────────────────────
await evaluate(`showModule('manage-patient')`);
await waitUntil(`typeof ptOpenRegister === 'function'`, 8000, 'patients screen');
await evaluate(`ptOpenRegister()`);
await waitUntil(`document.getElementById('modal-patient-form').classList.contains('open')`, 10000, 'registration form');
check('the paper number box is hidden for a new patient', await evaluate(`document.getElementById('pf-paper-group').style.display === 'none'`));
await evaluate(`(() => { document.querySelector('input[name="pf-type"][value="existing"]').click(); return true; })()`);
check('choosing Existing patient asks for the paper file number', await evaluate(`document.getElementById('pf-paper-group').style.display !== 'none'`));
await shot('register-existing', SHOTS);
await evaluate(`closeModal('modal-patient-form')`);

await evaluate(`showModule('settings-users')`);
await waitUntil(`!!document.getElementById('su-paper-max') || typeof switchSettingsTab === 'function'`, 10000, 'settings');
await evaluate(`(() => { if (!document.getElementById('su-paper-max') && typeof switchSettingsTab === 'function') switchSettingsTab('security'); return true; })()`);
await waitUntil(`!!document.getElementById('su-paper-max')`, 10000, 'numbering settings');
check('Settings shows the highest paper file number', await evaluate(`document.getElementById('su-settings-card').textContent.includes('Highest paper file number')`));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('uat round 3 browser', SHOTS);
