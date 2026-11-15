'use strict';
// ─── MODALS ────────────────────────────────────────────────────────────────
// Shared open/close helpers. Clicking the dark backdrop closes a modal; the
// listener is delegated so modals added to the page later behave the same.
function openModal(id) { document.getElementById(id).classList.add('open'); }

function closeModal(id) { document.getElementById(id).classList.remove('open'); }

const MAXIMIZE_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/></svg>';

const RESTORE_ICON   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 14h6v6"/><path d="M20 10h-6V4"/><path d="M14 10l7-7"/><path d="M3 21l7-7"/></svg>';
// Toggles a modal between its normal size and a near-fullscreen size — for
// screens like Process Payment that carry a lot of detail (payment grid,
// discount box, follow-up banner, payment method) and benefit from more room.

function toggleModalMaximize(modalId, btnEl){
  const modal = document.querySelector(`#${modalId} .modal`);
  if (!modal) return;
  const isMax = modal.classList.toggle('modal-maximized');
  if (btnEl){
    btnEl.innerHTML = isMax ? RESTORE_ICON : MAXIMIZE_ICON;
    btnEl.title = isMax ? 'Restore' : 'Maximize';
  }
}
document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click', e=>{ if(e.target===o) o.classList.remove('open'); });
});
document.querySelectorAll('.inv-modal-overlay').forEach(o=>{
  o.addEventListener('click', e=>{ if(e.target===o) o.classList.remove('open'); });
});
document.querySelectorAll('.opt-modal-overlay').forEach(o=>{
  o.addEventListener('click', e=>{ if(e.target===o) o.classList.remove('open'); });
});
document.querySelectorAll('.modal-overlay').forEach(o=>{
  if (!o._storesBound) {
    o._storesBound = true;
    o.addEventListener('click', e=>{ if(e.target===o) o.classList.remove('open'); });
  }
});

document.addEventListener('click', e => {
  const overlay = e.target;
  if (overlay instanceof HTMLElement && overlay.matches('.modal-overlay.open, .inv-modal-overlay.open, .opt-modal-overlay.open')) {
    overlay.classList.remove('open');
  }
});
