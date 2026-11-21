// Frames, external customers and frame changes, seen on screen.
import { launch, signIn, evaluate, waitUntil, sleep, shot, check, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);

// A frame with two on the shelf, set up through the API.
await api.signIn();
const frame = (await api.post('optical/frames', { name: `Screen ${run}`, brand: 'Vista', colour: 'Blue' })).frame;
await api.post(`optical/frames/${frame.id}/adjust`, { change: 2, reason: 'adjusted', note: 'Opening count' });

await launch(9451);
await signIn();

// ─── Frames screen ────────────────────────────────────────────────────────
await evaluate(`showModule('opt-frames')`);
await waitUntil(`(OPT.frames || []).some(f => f.id === ${frame.id})`, 15000, 'frames list');
check('the frame is listed with its stock', await evaluate(`document.getElementById('opt-frames-tbody').textContent.includes('Screen ${run}')`));
await evaluate(`(() => {
  optOpenFrame();
  document.getElementById('opt-frame-name').value = 'Added On Screen ${run}';
  document.getElementById('opt-frame-colour').value = 'Red';
  return true;
})()`);
await evaluate(`optSaveFrame(document.createElement('button'))`);
await waitUntil(`(OPT.frames || []).some(f => f.name === 'Added On Screen ${run}')`, 10000, 'new frame saved');
check('a frame can be added from the screen', true);
await shot('frames-list', SHOTS);

// ─── External customer ────────────────────────────────────────────────────
await evaluate(`showModule('opt-external')`);
await waitUntil(`document.getElementById('opt-ext-frame')?.options.length > 1`, 15000, 'external form');
const total = await evaluate(`(() => {
  document.getElementById('opt-ext-name').value = 'Screen Customer ${run}';
  document.getElementById('opt-ext-phone').value = '25576${run}9';
  document.getElementById('opt-ext-frame').value = '${frame.id}';
  optExternalTotal();
  return document.getElementById('opt-ext-summary').textContent;
})()`);
check('choosing a frame shows the external price', total.includes('25,000'), total.slice(0, 120));
check('a frame with none free cannot be picked', await evaluate(`[...document.getElementById('opt-ext-frame').options]
  .filter(o => o.textContent.includes('none free')).every(o => o.disabled)`));
await shot('external-customer', SHOTS);
await evaluate(`optSubmitExternal(document.createElement('button'))`);
await waitUntil(`(OPT.active || []).some(o => o.patient.full_name === 'Screen Customer ${run}')`, 15000, 'order in active list');
check('the order shows as an external customer', await evaluate(`document.getElementById('opt-act-tbody').textContent.includes('External customer')`));

// ─── Change frame on spectacles handed over ───────────────────────────────
const sale = (await api.get('optical/orders?scope=active&search=' + encodeURIComponent(`Screen Customer ${run}`))).orders[0];
const bill = await api.pendingBill(b => b.bill_no === sale.bill.bill_no);
await api.post(`bills/${bill.id}/pay`, { method: 'cash', row_version: bill.row_version });
await api.post(`optical/orders/${sale.id}/dispense`, {});
await evaluate(`optOpenDetail(${sale.id}, 'completed')`);
await waitUntil(`document.getElementById('modal-opt-detail').classList.contains('open')`, 8000, 'order detail');
check('a handed-over order offers Change frame', await evaluate(`document.getElementById('opt-detail-footer').textContent.includes('Change frame')`));
await evaluate(`optOpenChangeFrame(${sale.id})`);
await waitUntil(`document.getElementById('modal-opt-change-frame').classList.contains('open')`, 8000, 'change frame');
check('the old frame is filled in from the sale', await evaluate(`document.getElementById('opt-change-old').value.includes('Screen ${run}')`));
await shot('change-frame', SHOTS);
await evaluate(`closeModal('modal-opt-change-frame'); closeModal('modal-opt-detail')`);

// ─── Another pair on a patient's prescription ─────────────────────────────
// frames.mjs leaves a patient referral order behind; pick the newest one made from a prescription
// (another pair repeats the optometrist's lenses, so an order without one never offers it).
const patientOrder = (await api.get('optical/orders?scope=active')).orders
  .filter(o => o.order_type === 'referral' && o.customer_type === 'patient' && o.assessment_id).pop();
check('there is a patient order to work with', !!patientOrder);
if (patientOrder) {
  await evaluate(`optOpenDetail(${patientOrder.id}, 'active')`);
  await waitUntil(`document.getElementById('modal-opt-detail').classList.contains('open')`, 8000, 'patient order');
  check('a patient order offers Add another pair', await evaluate(`document.getElementById('opt-detail-footer').textContent.includes('Add another pair')`));
  await evaluate(`optOpenExtraPair(${patientOrder.id})`);
  await waitUntil(`document.getElementById('modal-opt-extra-pair').classList.contains('open')`, 8000, 'extra pair');
  check('it shows the lenses and a total', await evaluate(`document.getElementById('opt-extra-info').textContent.includes('Lenses')
    && document.getElementById('opt-extra-summary').textContent.includes('TZS')`));
  await shot('extra-pair', SHOTS);
  await evaluate(`closeModal('modal-opt-extra-pair'); closeModal('modal-opt-detail')`);
}

// ─── Store item form links a frame ────────────────────────────────────────
await evaluate(`showModule('stores-stock')`);
await waitUntil(`typeof stOpenItem === 'function'`, 8000, 'store');
await evaluate(`stOpenItem()`);
await waitUntil(`document.getElementById('st-item-frame')?.options.length > 1`, 10000, 'store item form');
check('a store item can be linked to a frame', await evaluate(`[...document.getElementById('st-item-frame').options].some(o => o.textContent.includes('Screen ${run}'))`));
await evaluate(`closeModal('modal-store-item')`);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('frames browser', SHOTS);
