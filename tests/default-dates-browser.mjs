// Date defaults: history lists open on today, working lists show all open work,
// booking forms start on today, optical tracking dates have a Today button.
import { launch, signIn, evaluate, waitUntil, sleep, check, equal, finish, pageErrors } from './lib/browser.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
await launch(9456);
await signIn();
const today = await evaluate(`uiToday()`);

const history = { 'cashier-history': 'ch', 'approvals-history': 'ah', 'audit-log': 'al', 'diag-history': 'diag-hist', 'theatre-history': 'th-hist',
  'opt-completed': 'opt-cmp', 'pharm-history': 'ph-hist', 'stores-requisition-history': 'st-hist', 'manage-visit': 'vs' };
for (const [module, prefix] of Object.entries(history)) {
  await evaluate(`showModule('${module}')`);
  await sleep(300);
  const dates = await evaluate(`[document.getElementById('${prefix}-from').value, document.getElementById('${prefix}-to').value]`);
  equal(`${module} opens on today`, dates, [today, today]);
}

const working = { 'cashier-payments': 'cs', 'approvals-pending': 'ap', 'diag-booking': 'diag-book', 'theatre-booking': 'th-book',
  'opt-active': 'opt-act', 'opt-dispensing': 'opt-disp', 'opt-incoming': 'opt-in', 'pharm-orders': 'ph-ord', 'pharm-dispense': 'ph-disp' };
for (const [module, prefix] of Object.entries(working)) {
  await evaluate(`showModule('${module}')`);
  await sleep(300);
  const dates = await evaluate(`[document.getElementById('${prefix}-from').value, document.getElementById('${prefix}-to').value]`);
  equal(`${module} keeps all open work (no date filter)`, dates, ['', '']);
}

// Refunds: In Progress is a working list (all dates); Refund History opens on today.
await evaluate(`showModule('cashier-refunds')`);
await sleep(400);
equal('refunds in progress show all open work', await evaluate(`[document.getElementById('rf-from').value, document.getElementById('rf-to').value]`), ['', '']);
await evaluate(`rfSwitchTab('history')`);
await sleep(300);
equal('refund history opens on today', await evaluate(`[document.getElementById('rf-from').value, document.getElementById('rf-to').value]`), [today, today]);
await evaluate(`rfSwitchTab('pending')`);
await sleep(300);
equal('going back to In Progress clears the dates again', await evaluate(`[document.getElementById('rf-from').value, document.getElementById('rf-to').value]`), ['', '']);

// Every report opens on today.
await evaluate(`showModule('report-terminal')`);
await waitUntil(`document.getElementById('rp-type').options.length > 0 && !!RP.options`, 15000, 'reports');
const reportTypes = await evaluate(`[...document.getElementById('rp-type').options].map(o => o.value)`);
for (const type of reportTypes) {
  await evaluate(`(() => { document.getElementById('rp-type').value = '${type}'; rpTypeChanged(); })()`);
  await sleep(150);
  equal(`the ${type} report opens on today`, await evaluate(`[document.getElementById('rp-from').value, document.getElementById('rp-to').value]`), [today, today]);
}

// A date the user changes is kept when they come back to the screen.
await evaluate(`(() => { showModule('pharm-history'); document.getElementById('ph-hist-from').value = '2026-01-01'; showModule('dashboard'); showModule('pharm-history'); return true; })()`);
equal('a changed date is kept', await evaluate(`document.getElementById('ph-hist-from').value`), '2026-01-01');
equal('the All Patients directory has no date limit', await evaluate(`document.getElementById('pt-reg-from').value`), '');

// Booking forms start on today.
await evaluate(`showModule('diag-booking')`);
await sleep(500);
await evaluate(`diagOpenNew()`).catch(e => console.log(e.message));
await sleep(800);
equal('a diagnostics booking starts on today', await evaluate(`document.getElementById('diag-sched-date').value`), today);
await evaluate(`showModule('theatre-booking')`);
await sleep(500);
await evaluate(`thOpenBooking()`).catch(() => {});
await sleep(800);
equal('a theatre booking starts on today', await evaluate(`document.getElementById('th-sched-date').value`), today);

// Today button.
const filled = await evaluate(`(() => {
  document.body.insertAdjacentHTML('beforeend', '<div id="tb-test"><input type="date" id="tb-date">' + uiTodayButton('tb-date') + '</div>');
  document.querySelector('#tb-test button').click();
  const v = document.getElementById('tb-date').value;
  document.getElementById('tb-test').remove();
  return v;
})()`);
equal('the Today button fills today', filled, today);

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('default dates browser', SHOTS);
