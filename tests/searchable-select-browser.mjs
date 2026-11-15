// Searchable dropdowns: any list longer than eight choices gets a search box.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);

// Enough store items for the item picker to be long.
await api.signIn();
const storeOptions = await api.get('stores/options');
for (let i = 1; i <= 10; i++) {
  await api.post('stores/stock', { name: `Searchable ${run} Item ${String(i).padStart(2, '0')}`, category: storeOptions.categories[0], unit: storeOptions.units[0] });
}

await launch(9454);
await signIn();
await evaluate(`stNewRequisition()`);
await waitUntil(`!!document.getElementById('st-req-department')?._ssDisplay && document.getElementById('st-req-item-pick')?.options.length > 9`, 15000, 'requisition form');

check('a long list gets a search box', await evaluate(`!!document.getElementById('st-req-department')._ssDisplay`));
check('a short list (priority) stays a plain dropdown', await evaluate(`!document.getElementById('st-req-priority')._ssDisplay`));

// Search the department list and pick with the keyboard.
await evaluate(`document.getElementById('st-req-department')._ssDisplay.click()`);
await waitUntil(`!!document.querySelector('.ss-panel .ss-search')`, 5000, 'panel');
await evaluate(`(() => { const s = document.querySelector('.ss-panel .ss-search'); s.value = 'pharm'; s.dispatchEvent(new Event('input')); return true; })()`);
const shown = await evaluate(`[...document.querySelectorAll('.ss-panel .ss-option')].map(o => o.textContent)`);
equal('typing narrows the list', shown.join(','), 'Pharmacy');
await shot('searchable-dropdown', SHOTS);
await evaluate(`document.querySelector('.ss-panel .ss-search').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))`);
check('Enter picks it', await evaluate(`document.getElementById('st-req-department').selectedOptions[0].textContent === 'Pharmacy'`));
check('the box shows the choice', await evaluate(`document.getElementById('st-req-department')._ssDisplay.textContent === 'Pharmacy'`));
check('and the panel closes', await evaluate(`!document.querySelector('.ss-panel')`));

// Picking from a searchable list still runs the screen's own code (here: add the item to the requisition).
await evaluate(`document.getElementById('st-req-item-pick')._ssDisplay.click()`);
await waitUntil(`!!document.querySelector('.ss-panel .ss-search')`, 5000, 'item panel');
await evaluate(`(() => { const s = document.querySelector('.ss-panel .ss-search'); s.value = '${run} Item 07'; s.dispatchEvent(new Event('input')); return true; })()`);
await evaluate(`document.querySelector('.ss-panel .ss-option').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))`);
await waitUntil(`document.getElementById('st-req-items').textContent.includes('${run} Item 07')`, 5000, 'item added').catch(() => {});
check('choosing an item adds it to the requisition', await evaluate(`document.getElementById('st-req-items').textContent.includes('${run} Item 07')`));

// A value set in code shows in the box.
const typed = await evaluate(`(() => {
  const select = document.getElementById('st-req-department');
  const target = [...select.options].find(o => o.textContent === 'Stores');
  select.value = target.value;
  return select._ssDisplay.textContent;
})()`);
equal('a value set by the screen shows in the box', typed, 'Stores');
check('an error on the dropdown shows on the box', await evaluate(`(() => {
  const select = document.getElementById('st-req-department');
  select.classList.add('is-invalid');
  return new Promise(r => setTimeout(() => r(select._ssDisplay.classList.contains('is-invalid')), 50));
})()`));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('searchable dropdowns', SHOTS);
