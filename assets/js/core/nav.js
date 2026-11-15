'use strict';
// ─── PERMISSION-DRIVEN NAVIGATION ─────────────────────────────────────────
// Shows only the screens the signed-in user's roles grant (KSI.canModule).
// Hiding a menu item is a convenience; the API refuses the data regardless.
function navModuleOf(item) {
  const match = (item.getAttribute('onclick') || '').match(/showModule\('([^']+)'\)/);
  return match ? match[1] : null;
}

function applyRoleNavVisibility() {
  document.querySelectorAll('#sidebar [onclick*="showModule("]').forEach(item => {
    const module = navModuleOf(item);
    if (module) item.style.display = KSI.canModule(module) ? '' : 'none';
  });
  document.querySelectorAll('#sidebar .nav-group').forEach(group => {
    const anyVisible = [...group.querySelectorAll('.nav-child')].some(child => child.style.display !== 'none');
    group.style.display = anyVisible ? '' : 'none';
  });
}

/** First screen the user may open: the dashboard if allowed, otherwise the first menu entry. */
function defaultModuleForUser() {
  if (KSI.canModule('dashboard')) return 'dashboard';
  const items = [...document.querySelectorAll('#sidebar-nav [onclick*="showModule("]')];
  return items.map(navModuleOf).find(module => module && KSI.canModule(module)) || 'placeholder';
}
