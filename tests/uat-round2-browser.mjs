// The UAT round 2 changes, seen on screen.
import { launch, signIn, evaluate, waitUntil, shot, check, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';

await launch(9447);
await signIn();

// ─── Price List: the category can be changed when editing ─────────────────
await evaluate(`showModule('price-list')`);
await waitUntil(`PRICE_UI.items.length > 0`, 12000, 'price list');
await evaluate(`openPriceModal(PRICE_UI.items[0].id)`);
await waitUntil(`document.getElementById('modal-price-item').classList.contains('open')`, 5000, 'price editor');
check('the category box is open for editing', await evaluate(`!document.getElementById('price-item-category').disabled`));
check('Medicine is not offered as a category',
  await evaluate(`![...document.getElementById('price-item-category').options].some(o => o.value === 'medicine')`));
await evaluate(`closeModal('modal-price-item')`);

// ─── Store item: add it to the pharmacy in the same step ──────────────────
await evaluate(`showModule('stores-stock')`);
await waitUntil(`typeof stOpenItem === 'function'`, 8000, 'store screen');
await evaluate(`stOpenItem()`);
await waitUntil(`document.getElementById('modal-store-item').classList.contains('open')
  && document.getElementById('st-item-drug').options.length > 1`, 10000, 'store item form');
check('the pharmacy box offers a new medicine',
  await evaluate(`[...document.getElementById('st-item-drug').options].some(o => o.value === 'new')`));
check('the selling price is hidden to begin with',
  await evaluate(`document.getElementById('st-item-new-drug').style.display === 'none'`));
await evaluate(`(() => { const s = document.getElementById('st-item-drug'); s.value = 'new'; stToggleNewDrug(); return true; })()`);
check('choosing a new medicine asks for the selling price',
  await evaluate(`document.getElementById('st-item-new-drug').style.display !== 'none'`));
await shot('store-new-medicine', SHOTS);
const shown = await evaluate(`(() => {
  const drug = ST.options.drugs.find(d => d.unit_price > 0) || ST.options.drugs[0];
  const s = document.getElementById('st-item-drug'); s.value = String(drug.id); stToggleNewDrug();
  const price = document.getElementById('st-item-drug-price');
  return { expected: String(drug.unit_price || ''), value: price.value, readOnly: price.readOnly,
           box: document.getElementById('st-item-new-drug').style.display,
           form: document.getElementById('st-item-drug-form-group').style.display };
})()`);
check('an existing medicine shows its selling price', shown.box !== 'none' && shown.value === shown.expected, JSON.stringify(shown));
check('the form box is only for new medicines', shown.form === 'none');
check('an administrator can edit the price', shown.readOnly === false);
await shot('store-linked-medicine', SHOTS);
await evaluate(`(() => { const s = document.getElementById('st-item-drug'); s.value = ''; stToggleNewDrug(); return true; })()`);
check('no medicine, no price box', await evaluate(`document.getElementById('st-item-new-drug').style.display === 'none'`));
await evaluate(`closeModal('modal-store-item')`);

// ─── A nurse can fill in a requisition ────────────────────────────────────
const run = String(Date.now()).slice(-6);
const nurseName = `bnurse${run}`;
const nursePass = `Triage-Nurse-${run}-x9`;
await api.signIn();
await api.post('users', {
  first_name: 'Browser', last_name: 'Nurse', phone: `25577${run}3`,
  department_id: Number(api.sql("SELECT id FROM departments WHERE name = 'Triage'")),
  role_ids: [Number(api.sql("SELECT id FROM roles WHERE code = 'clinical'"))], designation_ids: [],
  username: nurseName, password: nursePass,
});
api.sql(`UPDATE users SET must_change_password = 0 WHERE username = '${nurseName}'`);
await evaluate(`KSI.post('auth/logout').catch(() => {}).then(() => true)`);
await signIn(nurseName, nursePass);
await evaluate(`stNewRequisition()`);
await waitUntil(`document.getElementById('st-req-department')?.options.length > 1`, 10000, 'requisition form');
check('the nurse sees the departments, with Triage chosen',
  await evaluate(`document.getElementById('st-req-department').selectedOptions[0]?.textContent === 'Triage'`));
check('and can pick store items', await evaluate(`document.getElementById('st-req-item-pick').options.length > 1`));
await shot('nurse-requisition', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('uat round 2 browser', SHOTS);
