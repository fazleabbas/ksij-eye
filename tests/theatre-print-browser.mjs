// Theatre Booking: "Print List" prints exactly what the filters show, grouped by day.
import { launch, signIn, evaluate, waitUntil, sleep, check, equal, finish, pageErrors } from './lib/browser.mjs';
import * as api from './lib/api.mjs';

const SHOTS = 'C:/xampp82/htdocs/ksij_eye/tests/shots';
const run = String(Date.now()).slice(-6);
await api.signIn();

let options = await api.get('theatre/options');
if (!options.surgeons.length) {
  const designation = Number(api.sql("SELECT id FROM designations WHERE code = 'surgeon'"));
  const department = Number(api.sql('SELECT id FROM departments ORDER BY id LIMIT 1'));
  await api.post('users', {
    first_name: 'Print', last_name: `Surgeon${run}`, phone: `25577${run}9`, department_id: department,
    role_ids: [Number(api.sql("SELECT id FROM roles WHERE code = 'doctor'"))], designation_ids: [designation],
    username: `psurgeon${run}`, password: `Surgeon-${run}-x9Q`,
  });
  options = await api.get('theatre/options');
}
const [surgeonA, surgeonB] = [options.surgeons[0], options.surgeons[1] || options.surgeons[0]];
const surgery = Number(api.sql("SELECT id FROM price_items WHERE category = 'surgery' AND is_active = 1 ORDER BY id LIMIT 1"));

async function book(last, surgeon, date, tail) {
  const patient = (await api.post('patients', {
    first_name: 'Print', last_name: `${last}${run}`, gender: 'female', date_of_birth: '1955-02-02',
    phone: `25577${run}${tail}`, address: 'Upanga', kin_name: 'Bw Ali', kin_relationship: 'Spouse', kin_phone: `25577${run}${tail}8`,
  })).patient;
  return (await api.post('theatre/bookings', {
    patient_id: patient.id, surgery_item_id: surgery, eye: 'right', surgeon_user_id: surgeon.id,
    timing: 'later', scheduled_date: date, scheduled_time: '09:30',
  })).booking;
}
const day = offset => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);
const first = await book("Alpha", surgeonA, day(3), 1);
const second = await book("Bravo", surgeonA, day(4), 2);

await launch(9457);
await signIn();
await evaluate(`showModule('theatre-booking')`);
await waitUntil(`(TH.bookings || []).some(b => b.id === ${second.id})`, 15000, 'bookings');

const capture = `(() => {
  let html = '';
  const original = window.open;
  window.open = () => ({ document: { write: t => { html += t; }, close() {} }, print() {} });
  thPrintBookings();
  window.open = original;
  return html;
})()`;

// Narrow to one surgeon and one day: only that case is printed.
await evaluate(`(() => {
  document.getElementById('th-book-from').value = '${day(3)}';
  document.getElementById('th-book-to').value = '${day(3)}';
  return true;
})()`);
await evaluate(`thLoadBookings()`);
await waitUntil(`!(TH.bookings || []).some(b => b.id === ${second.id})`, 15000, 'filtered');
const html = await evaluate(capture);
check('the page has a Print List button', await evaluate(`[...document.querySelectorAll('#module-theatre-booking .page-actions button')].some(b => b.textContent.includes('Print List'))`));
check('the printout lists the filtered case', html.includes(first.booking_no) && html.includes(`Alpha${run}`));
check('and not the case the filter hides', !html.includes(second.booking_no));
check('it states the filters used', html.includes('Scheduled:'));
check('cases are grouped by day, with the time', /<h2>[^<]*— \d+ cases?<\/h2>/.test(html) && html.includes('09:30'), (html.match(/<h2>[^<]*<\/h2>/g) || []).join(' ') + ' 09:30=' + html.includes('09:30'));
check('it prints in landscape', html.includes('A4 landscape'));
check('the footer says Powered by Fabonic', html.includes('Powered by Fabonic'));
equal('the case count matches the screen', (html.match(/<strong>(\d+)<\/strong>Cases/) || [])[1], String(await evaluate(`TH.bookings.length`)));

check('no script errors anywhere', pageErrors.length === 0, pageErrors.join(' / ').slice(0, 200));
await finish('theatre print browser', SHOTS);
