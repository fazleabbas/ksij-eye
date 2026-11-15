'use strict';
// NOTE: toISODate/inRange are defined properly (and used extensively) in the
// Report Terminal script, which loads after this file. filterVisits() needs
// them during start-up, so lightweight copies live here; the later definitions
// simply replace these with identical logic.
function toISODate(s){
  if(!s) return '';
  if(/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0,10);
  const d = new Date(s);
  return isNaN(d.getTime()) ? s.slice(0,10) : d.toISOString().slice(0,10);
}
function inRange(dateStr,from,to){
  if(!dateStr) return true;
  const d=toISODate(dateStr);
  if(from && d<from) return false;
  if(to   && d>to  ) return false;
  return true;
}

// ─── START ─────────────────────────────────────────────────────────────────
// Runs once every script and modal on the page has loaded.
document.addEventListener('DOMContentLoaded', () => {
  updateApprovalsBadge();
  setInterval(updateApprovalsBadge, 120000);
  // Doctors: results ready for tests they referred.
  clResultsBell();
  setInterval(clResultsBell, 60000);
  applyRoleNavVisibility();
  const first = defaultModuleForUser();
  showModule(first, first === 'placeholder' ? 'No Access —' : '');
  if (first === 'placeholder') {
    document.getElementById('placeholder-name').textContent = 'No screens assigned';
  }
});
