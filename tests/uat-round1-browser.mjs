// The UAT round 1 corrections, seen on screen.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const text = sel => evaluate(`(document.querySelector(${JSON.stringify(sel)})?.textContent || '').trim()`);

await launch(9446);
await signIn();

// ─── Optometry: the renamed boxes ─────────────────────────────────────────
await evaluate(`showModule('optometry')`);
await waitUntil(`!document.getElementById('module-optometry').classList.contains('hidden')`, 10000, 'optometry');
const vaLabel = await evaluate(`(() => {
  const box = document.querySelector('#module-optometry [data-field="va_remarks"]');
  return box?.closest('.form-group')?.querySelector('.form-label')?.textContent.trim();
})()`);
equal('the remarks box is now History', vaLabel, 'History');
const pcpLabel = await evaluate(`(() => {
  const box = document.querySelector('#module-optometry [data-field="pcp"]');
  return box?.closest('.form-group, .vitals-card, div')?.textContent.trim().slice(0, 3);
})()`);
equal('PCP now reads CPC', pcpLabel, 'CPC');
check('nothing still says PCP', !(await text('#module-optometry')).includes('PCP'));

// ─── Registration: "Other" asks what it is ────────────────────────────────
await evaluate(`showModule('manage-patient')`);
await waitUntil(`typeof ptOpenRegister === 'function'`, 8000, 'patients screen');
await evaluate(`ptOpenRegister()`);
await waitUntil(`document.getElementById('modal-patient-form').classList.contains('open')
  && document.getElementById('pf-kin-rel').options.length > 1`, 10000, 'registration form');
check('the specify box is hidden to begin with',
  await evaluate(`document.getElementById('pf-kin-rel-other-group').style.display === 'none'`));
await evaluate(`(() => { const s = document.getElementById('pf-kin-rel'); s.value = 'Other'; ptKinRelChanged(); return true; })()`);
check('choosing Other asks what it is',
  await evaluate(`document.getElementById('pf-kin-rel-other-group').style.display !== 'none'`));
await shot('kin-other', SHOTS);
await evaluate(`(() => { const s = document.getElementById('pf-kin-rel'); s.value = 'Spouse'; ptKinRelChanged(); return true; })()`);
check('choosing a listed relationship hides it again',
  await evaluate(`document.getElementById('pf-kin-rel-other-group').style.display === 'none'`));
await evaluate(`closeModal('modal-patient-form')`);

// ─── Prescribing: every medicine, searchable ──────────────────────────────
await evaluate(`showModule('ophthalmology')`);
await waitUntil(`!document.getElementById('module-ophthalmology').classList.contains('hidden')`, 10000, 'ophthalmology');
// The prescription box lives inside an open consultation, so draw it on its own.
await waitUntil(`!!CL_OPTIONS.data?.drugs?.length`, 15000, 'clinical lists loaded');
await evaluate(`(() => { clRxInit('oph-rx', 'oph-rx', 'ophthalmology'); clRxSet('oph-rx', null, false); return true; })()`);
await waitUntil(`document.querySelector('#module-ophthalmology .med-search-input')`, 10000, 'prescription box');
const picker = await evaluate(`(() => {
  const el = document.querySelector('#module-ophthalmology .med-search-input');
  const list = el.getAttribute('list');
  const datalist = list ? document.getElementById(list) : null;
  return { tag: el.tagName, placeholder: el.placeholder, options: datalist ? datalist.options.length : 0,
           groups: document.querySelectorAll('#module-ophthalmology optgroup').length };
})()`);
equal('the medicine box is a search box, not a dropdown', picker.tag, 'INPUT');
check('the doctor can type to find a medicine', /type/i.test(picker.placeholder), picker.placeholder);
check('every medicine is offered', picker.options > 0, `${picker.options} medicines`);
equal('medicines are no longer split into categories', picker.groups, 0);
await shot('medicine-search', SHOTS);

// Round 3: a name that is not in the catalogue is asked about, then kept as "buy outside".
const asked = await evaluate(`(() => {
  let question = '';
  const original = window.confirm;
  window.confirm = q => { question = q; return true; };
  const el = document.querySelector('#module-ophthalmology .med-search-input');
  el.value = 'Not A Real Medicine';
  el.dispatchEvent(new Event('change'));
  window.confirm = original;
  return question;
})()`);
await sleep(200);
check('an unknown medicine is asked about first', asked.includes('not stocked'), asked);
check('then prescribed as bought outside', (await text('#module-ophthalmology .med-table')).includes('Not stocked — buy outside'));

// ─── Printing says Dar es Salaam ──────────────────────────────────────────
const printed = await evaluate(`(() => {
  const original = window.open;
  let html = '';
  window.open = () => ({ document: { write: t => { html += t; }, close() {} }, focus() {}, print() {} });
  try { optPrintWindow('Test', '<p>body</p>'); } finally { window.open = original; }
  return html;
})()`);
check('printed documents are issued from Dar es Salaam', printed.includes('Dar es Salaam, Tanzania'));
check('they no longer say Zanzibar', !printed.includes('Zanzibar'));

// ─── Cashier: Debt is a discount type ─────────────────────────────────────
const schemes = await evaluate(`(await KSI.get('reference/lookups', { list: 'discount_type' })).values.map(v => v.value)`);
check('Debt can be chosen as a discount', schemes.includes('Debt'), schemes.join(', '));

// ─── Price list: Medicine is a category ───────────────────────────────────
await evaluate(`showModule('price-list')`);
await waitUntil(`document.getElementById('pl-category-filter').options.length > 1`, 12000, 'price list');
const categories = await evaluate(`[...document.getElementById('pl-category-filter').options].map(o => o.textContent)`);
check('the price list no longer has a Medicine category (moved to the pharmacy in round 2)', !categories.includes('Medicine'), categories.join(', '));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('uat round 1 browser', SHOTS);
