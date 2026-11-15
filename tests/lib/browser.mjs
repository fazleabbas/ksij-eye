// Headless Edge driven over the DevTools protocol.
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export const BASE = 'http://localhost:8080/ksij_eye/';
export const ADMIN_PASS = readFileSync(new URL('../admin-pass.txt', import.meta.url), 'utf8').trim();
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';

let ws, edge, nextId = 1, profile;
const pending = new Map();
export const pageErrors = [];
const failures = [];
let passed = 0;

export async function launch(port = 9444) {
  profile = mkdtempSync(join(tmpdir(), 'ksi-edge-'));
  edge = spawn(EDGE, [
    '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--window-size=1440,900', 'about:blank',
  ], { stdio: 'ignore' });
  const target = await waitForTarget(port);
  ws = new WebSocket(target);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  ws.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown') {
      pageErrors.push(message.params.exceptionDetails?.exception?.description
        || message.params.exceptionDetails?.text || 'unknown error');
    }
    if (message.method === 'Runtime.consoleAPICalled' && message.params.type === 'error') {
      pageErrors.push(message.params.args.map(a => a.description || a.value).join(' '));
    }
    if (message.id && pending.has(message.id)) {
      const { resolve, reject } = pending.get(message.id);
      pending.delete(message.id);
      message.error ? reject(new Error(JSON.stringify(message.error))) : resolve(message.result);
    }
  };
  await send('Page.enable');
  await send('Runtime.enable');
}

async function waitForTarget(port) {
  for (let i = 0; i < 80; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/json/list`);
      const page = (await res.json()).find(t => t.type === 'page');
      if (page) return page.webSocketDebuggerUrl;
    } catch {}
    await sleep(250);
  }
  throw new Error('Edge did not start');
}

export function send(method, params = {}) {
  const id = nextId++;
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
}

export const sleep = ms => new Promise(r => setTimeout(r, ms));

export async function evaluate(expression) {
  const result = await send('Runtime.evaluate', {
    expression: `(async () => { return ${expression}; })()`,
    awaitPromise: true, returnByValue: true,
  });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || 'evaluate failed');
  return result.result.value;
}

export async function navigate(url) {
  await send('Page.navigate', { url });
  await waitUntil('document.readyState === "complete"', 15000, 'page load');
  await sleep(300);
}

export async function waitUntil(expression, timeout = 10000, label = expression) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    try { if (await evaluate(expression)) return true; } catch {}
    await sleep(120);
  }
  throw new Error(`Timed out waiting for: ${label}`);
}

export async function signIn(username = 'sysadmin', password = ADMIN_PASS) {
  await navigate(BASE);
  await waitUntil('!!document.getElementById("login-username")', 10000, 'login form');
  await evaluate(`(() => {
    document.getElementById('login-username').value = ${JSON.stringify(username)};
    document.getElementById('login-password').value = ${JSON.stringify(password)};
    document.getElementById('login-submit').click();
    return true;
  })()`);
  // The app shell streams in: wait until its scripts have actually run.
  await waitUntil(`document.readyState === "complete" && !!document.getElementById("topnav")
    && typeof KSI !== "undefined" && typeof showModule === "function"`, 25000, 'app ready');
  await waitUntil('!!KSI.user', 10000, 'session loaded');
}

export async function shot(name, dir) {
  mkdirSync(dir, { recursive: true });
  const { data } = await send('Page.captureScreenshot', { format: 'png' });
  writeFileSync(join(dir, `${name}.png`), Buffer.from(data, 'base64'));
}

export function check(name, condition, detail = '') {
  if (condition) { passed++; return true; }
  failures.push(`${name}${detail ? ' — ' + detail : ''}`);
  return false;
}

export function equal(name, actual, expected) {
  return check(name, JSON.stringify(actual) === JSON.stringify(expected),
    `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
}

export async function finish(label, shotsDir) {
  for (const failure of failures) console.log('FAIL  ' + failure);
  console.log(`${passed}/${passed + failures.length} passed${label ? ' · ' + label : ''}${shotsDir ? ' · screenshots: ' + shotsDir : ''}`);
  try { ws.close(); } catch {}
  try { edge.kill(); } catch {}
  process.exit(failures.length ? 1 : 0);
}
