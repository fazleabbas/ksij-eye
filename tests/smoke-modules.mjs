// Opens every screen in the app and fails on any script error.
import { launch, signIn, evaluate, waitUntil, sleep, check, finish, pageErrors } from './lib/browser.mjs';

await launch(9445);
await signIn();

const modules = await evaluate(`[...document.querySelectorAll('[id^="module-"]')].map(el => el.id.slice(7))`);
check('the app has its screens', modules.length > 40, `found ${modules.length}`);

for (const name of modules) {
  const before = pageErrors.length;
  await evaluate(`showModule(${JSON.stringify(name)})`);
  await sleep(140);
  // Some menu entries are aliases: the old report pages all open the one reports screen.
  const shown = await evaluate(`(() => {
    const own = !document.getElementById('module-${name}').classList.contains('hidden');
    const visible = [...document.querySelectorAll('[id^="module-"]')].filter(el => !el.classList.contains('hidden'));
    return own ? '${name}' : (visible[0]?.id.slice(7) || '');
  })()`);
  check(`${name} opens`, !!shown, 'nothing became visible');
  check(`${name} opens without a script error`, pageErrors.length === before,
    pageErrors.slice(before).join(' / ').slice(0, 160));
}

await sleep(1200);
check('nothing failed in the background either', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 300));
await finish('module smoke');
