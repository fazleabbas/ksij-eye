// New Refund for a walk-in counter sale: no patient record to search, so the cashier
// finds the paid bill by its bill, receipt or EXT number ("Walk-in counter sale?").
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);

await api.signIn();
const drug = (await api.get('clinical/options')).drugs[0];
await api.post(`pharmacy/stock/${drug.id}`, { change: 10, reason: 'received', note: 'Stock for the walk-in refund browser test' });
// No age given: the customer card must show "—", not "undefined" or "NaN".
const name = `Counter Refund ${run}`;
const order = (await api.post('pharmacy/walk-in-sales', {
  name, phone: `25572${run}1`, gender: 'male', items: [{ drug_id: drug.id, quantity: 2, unit_price: 7500 }],
})).order;
const billId = Number(order.bill.id);
const paid = await api.post(`bills/${billId}/pay`, { method: 'cash', row_version: Number(api.sql(`SELECT row_version FROM bills WHERE id = ${billId}`)) });
const walkinNo = order.customer.reference;
const receiptNo = paid.receipt.receipt_no;

await launch(9520);
await signIn();
await evaluate(`showModule('cashier-refunds')`);
await waitUntil(`!!document.getElementById('rf-new-btn') && !document.getElementById('module-cashier-refunds').classList.contains('hidden')`, 10000, 'refunds screen');
await evaluate(`document.getElementById('rf-new-btn').click()`);
await waitUntil(`document.getElementById('modal-new-refund').classList.contains('open') || getComputedStyle(document.getElementById('modal-new-refund')).display !== 'none'`, 5000, 'new refund window');
check('the window offers the walk-in lookup', await evaluate(`!!document.getElementById('nr-number') && document.getElementById('nr-picker').textContent.includes('Walk-in counter sale?')`));

const findBy = async number => {
  await evaluate(`(() => { const box = document.getElementById('nr-number'); box.value = ${JSON.stringify(number)};
    box.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); })()`);
  await sleep(1200);
};
const fieldError = () => evaluate(`(document.querySelector('#nr-form [data-field="number"] .form-error, #nr-form .field-error, #nr-form .is-invalid ~ .form-error')?.textContent
  || [...document.querySelectorAll('#nr-form .form-error')].map(e => e.textContent).join(' ')).trim()`);

// Nothing typed: asked for a number, nothing looked up.
await findBy('');
check('Find with nothing typed asks for the number', /bill, receipt or EXT number/i.test(await fieldError()), await fieldError());
check('and nothing is selected', await evaluate(`document.getElementById('nr-selected').style.display === 'none'`));

// A number that matches nothing: said so, the lookup stays open.
await findBy(`EXT-NOPE-${run}`);
check('an unknown number is reported beside the box', /No walk-in bill or receipt with that number/.test(await fieldError()), await fieldError());
check('and the lookup stays open', await evaluate(`document.getElementById('nr-picker').style.display !== 'none'`));

// The EXT number: the walk-in is shown with the paid bill to refund.
await findBy(walkinNo);
await waitUntil(`document.getElementById('nr-selected').style.display !== 'none'`, 5000, 'walk-in selected');
const card = await evaluate(`document.getElementById('nr-selected').textContent`);
check('found by EXT number, the walk-in is shown by name', card.includes(name), card.slice(0, 200));
check('with their EXT number', card.includes(walkinNo), card.slice(0, 200));
check('an unknown age shows as "—", never undefined or NaN', !/undefined|NaN/.test(card) && card.includes('—'), card.slice(0, 200));
await waitUntil(`document.querySelectorAll('#nr-bills input[name="nr-bill"]').length > 0`, 5000, 'paid bills');
const bills = await evaluate(`[...document.querySelectorAll('#nr-bills input[name="nr-bill"]')].map(i => Number(i.value))`);
equal('the paid counter-sale bill is offered', bills, [billId]);

// Change clears the lookup so the next customer starts fresh.
await evaluate(`[...document.querySelectorAll('#nr-selected button')].find(b => b.textContent.trim() === 'Change').click()`);
await sleep(300);
check('Change goes back to the search and clears the number',
  await evaluate(`document.getElementById('nr-picker').style.display !== 'none' && document.getElementById('nr-number').value === ''`));

// By receipt number, using the Find button this time, then send the request.
await evaluate(`document.getElementById('nr-number').value = ${JSON.stringify(receiptNo)}`);
await evaluate(`[...document.querySelectorAll('#nr-picker button')].find(b => b.textContent.trim() === 'Find').click()`);
// Wait for the card to come back: the bill list from the first lookup is only hidden, not emptied.
await waitUntil(`document.getElementById('nr-selected').style.display !== 'none' && !document.getElementById('nr-bills').textContent.includes('Loading')`, 5000, 'found by receipt');
check('found by receipt number with the Find button', (await evaluate(`document.getElementById('nr-selected').textContent`)).includes(name));
await evaluate(`(() => { const r = document.querySelector('#nr-bills input[value="${billId}"]'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); })()`);
await sleep(300);
equal('the full amount is filled in', Number(await evaluate(`document.getElementById('nr-amount').value`)), 15000);
await evaluate(`(() => { document.getElementById('nr-amount').value = 7500; document.getElementById('nr-reason').value = 'One bottle returned unopened'; })()`);
await shot('walkin-refund-filled', SHOTS);
await evaluate(`document.getElementById('nr-submit').click()`);
await sleep(2000);
const refund = api.sql(`SELECT CONCAT_WS('|', patient_id IS NULL, walkin_id IS NOT NULL, requested_amount) FROM refunds WHERE bill_id = ${billId}`);
equal('the refund request is saved for the walk-in, not a patient', refund, '1|1|7500.00');
check('the window closes', await evaluate(`!document.getElementById('modal-new-refund').classList.contains('open')`));
await waitUntil(`document.getElementById('rf-tbody').textContent.includes(${JSON.stringify(name)})`, 8000, 'listed').catch(() => {});
check('the pending refunds list shows the walk-in by name', await evaluate(`document.getElementById('rf-tbody').textContent.includes(${JSON.stringify(name)})`));
await shot('walkin-refund-listed', SHOTS);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('walk-in refund browser', SHOTS);
