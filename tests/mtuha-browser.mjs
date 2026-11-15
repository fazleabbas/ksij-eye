// The MTUHA registers and the doctor's referral box, seen on screen. Run after mtuha.mjs.
import { launch, signIn, evaluate, waitUntil, shot, check, finish, pageErrors } from './lib/browser.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';

await launch(9449);
await signIn();

check('the MTUHA menu is there', await evaluate(`document.getElementById('ng-mtuha').style.display !== 'none'`));

await evaluate(`showModule('mtuha-main')`);
await waitUntil(`RP.report?.type === 'mtuha_main'`, 15000, 'main register');
const head = await evaluate(`(() => {
  const rows = document.querySelectorAll('#rp-head tr');
  const groups = [...rows[0].querySelectorAll('th[colspan]')].map(th => th.textContent + ':' + th.colSpan);
  return { rows: rows.length, groups, sub: [...(rows[1]?.querySelectorAll('th') || [])].map(th => th.textContent) };
})()`);
equal2('the header has two rows', head.rows, 2);
check('Visual acuity and Eye each span RE and LE', head.groups.includes('Visual acuity:2') && head.groups.includes('Eye:2'), JSON.stringify(head));
check('the cells line up with the columns', await evaluate(`(() => {
  const first = document.querySelector('#rp-body tr');
  return !first || first.children.length === RP.report.columns.length;
})()`));
check('the menu entry is highlighted', await evaluate(`document.getElementById('nav-mtuha-main').classList.contains('active')`));
check('the outcome filter is offered', await evaluate(`document.getElementById('rp-status').style.display !== 'none'
  && [...document.getElementById('rp-status').options].some(o => o.textContent === 'Referral')`));
await shot('mtuha-main', SHOTS);

await evaluate(`showModule('mtuha-surgery')`);
await waitUntil(`RP.report?.type === 'mtuha_surgery'`, 15000, 'surgery register');
check('the surgery register opens', await evaluate(`document.getElementById('rp-type').value === 'mtuha_surgery'`));
await shot('mtuha-surgery', SHOTS);

// ─── The doctor's referral box ───────────────────────────────────────────
await evaluate(`showModule('ophthalmology')`);
await waitUntil(`!!document.querySelector('#oph-form [data-field="referral.referred"]')`, 10000, 'doctor screen');
check('where to is hidden until referral is ticked', await evaluate(`document.getElementById('oph-ref-fields').classList.contains('hidden')`));
await evaluate(`(() => { const box = document.querySelector('#oph-form [data-field="referral.referred"]'); box.checked = true; OPH_FORM.toggleReferral(); return true; })()`);
check('ticking it asks where to', await evaluate(`!document.getElementById('oph-ref-fields').classList.contains('hidden')`));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('mtuha browser', SHOTS);

function equal2(name, actual, expected) {
  check(name, actual === expected, `${actual} (expected ${expected})`);
}
