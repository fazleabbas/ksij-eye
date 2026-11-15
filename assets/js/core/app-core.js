'use strict';

// ─── CLOCK & DATE ────────────────────────────────────────────────────────
function updateClock() {
  const now = new Date();
  const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const d = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
  const t = now.toTimeString().split(' ')[0];
  // Write only on change so the header is not repainted needlessly.
  const dateEl = document.getElementById('live-date');
  const clockEl = document.getElementById('live-clock');
  if (dateEl && dateEl.textContent !== d) dateEl.textContent = d;
  if (clockEl) clockEl.textContent = t;
}
setInterval(updateClock, 1000);
updateClock();

// ─── TOAST ───────────────────────────────────────────────────────────────
function showToast(title, msg='', type='success') {
  const icons = {
    success:`<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>`,
    error:`<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
    info:`<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16"/></svg>`,
  };
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.innerHTML = `<div class="toast-icon">${icons[type]}</div><div class="toast-text"><div class="toast-title">${title}</div>${msg?`<div class="toast-msg">${msg}</div>`:''}</div>`;
  document.getElementById('toast-container').appendChild(t);
  setTimeout(()=>{ t.style.animation='fadeOut .3s ease forwards'; setTimeout(()=>t.remove(),300); }, 3800);
}

// ─── SIDEBAR ─────────────────────────────────────────────────────────────
let sidebarCollapsed = false;

function closeAllNavGroups() {
  document.querySelectorAll('.nav-group').forEach(g => g.classList.remove('expanded'));
}

// On tablet/phone widths the sidebar is an off-canvas drawer (see responsive.css);
// on desktop the same button collapses it to an icon rail.
const drawerQuery = window.matchMedia('(max-width: 1024px)');
function setSidebarDrawerOpen(open) {
  document.body.classList.toggle('sidebar-open', open);
  document.getElementById('sidebar-toggle').setAttribute('aria-expanded', String(open));
}
drawerQuery.addEventListener('change', e => {
  setSidebarDrawerOpen(false);
  if (e.matches && sidebarCollapsed) {
    sidebarCollapsed = false;
    document.getElementById('sidebar').classList.remove('collapsed');
  }
});
document.getElementById('sidebar-backdrop').addEventListener('click', () => setSidebarDrawerOpen(false));
document.getElementById('sidebar').addEventListener('click', e => {
  if (drawerQuery.matches && e.target.closest('.nav-child, .sidebar-footer-item')) setSidebarDrawerOpen(false);
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) setSidebarDrawerOpen(false);
});

document.getElementById('sidebar-toggle').addEventListener('click', () => {
  if (drawerQuery.matches) {
    setSidebarDrawerOpen(!document.body.classList.contains('sidebar-open'));
    return;
  }
  sidebarCollapsed = !sidebarCollapsed;
  const sidebar = document.getElementById('sidebar');
  sidebar.classList.toggle('collapsed', sidebarCollapsed);

  if (sidebarCollapsed) {
    // Close all expanded groups when collapsing
    closeAllNavGroups();
  }
});

function toggleGroup(id) {
  // Don't expand groups when sidebar is collapsed
  if (sidebarCollapsed) return;
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('expanded');
}

// ─── DROPDOWNS ───────────────────────────────────────────────────────────
document.getElementById('notif-btn').addEventListener('click', e=>{
  e.stopPropagation();
  document.getElementById('notif-dropdown').classList.toggle('open');
  document.getElementById('user-dropdown').classList.remove('open');
});
document.getElementById('user-chip').addEventListener('click', e=>{
  e.stopPropagation();
  document.getElementById('user-dropdown').classList.toggle('open');
  document.getElementById('notif-dropdown').classList.remove('open');
});
document.addEventListener('click', ()=>{
  document.getElementById('notif-dropdown').classList.remove('open');
  document.getElementById('user-dropdown').classList.remove('open');
});

// ─── MODULE ROUTING ───────────────────────────────────────────────────────
