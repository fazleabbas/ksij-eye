// Talks to the API the way the browser does: cookie session, CSRF from auth/me.
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

export const BASE = 'http://localhost:8080/ksij_eye/';
export const ADMIN_PASS = readFileSync(new URL('../admin-pass.txt', import.meta.url), 'utf8').trim();

let cookie = '';
let csrf = '';
const failures = [];
let passed = 0;

export async function call(method, path, body, opts = {}) {
  const headers = { Accept: 'application/json' };
  if (cookie) headers.Cookie = cookie;
  if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const response = await fetch(BASE + 'api/' + path, {
    method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: 'manual',
  });
  const setCookie = response.headers.getSetCookie?.() || [];
  for (const line of setCookie) cookie = line.split(';')[0];
  const text = await response.text();
  let payload = null;
  try { payload = JSON.parse(text); } catch { payload = { raw: text.slice(0, 300) }; }
  if (opts.raw) return { status: response.status, body: payload, text };
  if (!payload?.ok) {
    const error = new Error(payload?.error?.message || `HTTP ${response.status}`);
    error.status = response.status;
    error.code = payload?.error?.code;
    error.fields = payload?.error?.fields || {};
    throw error;
  }
  return payload.data;
}

export const get = (p, o) => call('GET', p, undefined, o);
export const post = (p, b, o) => call('POST', p, b ?? {}, o);
export const put = (p, b, o) => call('PUT', p, b ?? {}, o);
export const del = (p, o) => call('DELETE', p, undefined, o);

/** Multipart upload, with the session cookie and CSRF token the API expects. */
export async function upload(path, formData) {
  const response = await fetch(BASE + 'api/' + path, {
    method: 'POST', body: formData,
    headers: { Accept: 'application/json', Cookie: cookie, 'X-CSRF-Token': csrf },
  });
  const payload = await response.json();
  if (!payload?.ok) {
    const error = new Error(payload?.error?.message || `HTTP ${response.status}`);
    error.status = response.status;
    error.code = payload?.error?.code;
    error.fields = payload?.error?.fields || {};
    throw error;
  }
  return payload.data;
}

export async function signIn(username = 'sysadmin', password = ADMIN_PASS) {
  await post('auth/login', { username, password });
  const me = await get('auth/me');
  csrf = me.csrf;
  return { ...me.user, csrf: me.csrf };
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

/** Asserts the call is refused, and with which complaint. */
export async function expectError(name, fn, status, code) {
  try {
    await fn();
    return check(name, false, 'the call was allowed');
  } catch (error) {
    if (status && error.status !== status) return check(name, false, `status ${error.status}, expected ${status}`);
    if (code && error.code !== code) return check(name, false, `code ${error.code}, expected ${code}`);
    return check(name, true);
  }
}

export function sql(query) {
  return execFileSync('C:/xampp82/mysql/bin/mysql.exe', ['-uroot', 'ksi_eye', '-N', '-B', '-e', query], { encoding: 'utf8' }).trim();
}

export function report(label) {
  for (const failure of failures) console.log('FAIL  ' + failure);
  console.log(`${passed}/${passed + failures.length} passed${label ? ' · ' + label : ''}`);
  process.exit(failures.length ? 1 : 0);
}
