'use strict';
// ─── SEARCHABLE DROPDOWNS ─────────────────────────────────────────────────
// Any dropdown with more than SS_MIN_OPTIONS choices gets a search box, everywhere in the
// app and without changing the screens: the original <select> stays in the page (hidden)
// and remains the source of truth. Picking an option sets it and fires its change event,
// so each screen's own onchange code runs exactly as before. Short lists stay as they are.
// A select can opt out with data-plain, or opt in below the threshold with data-searchable.

const SS_MIN_OPTIONS = 8;
// Small selects inside tables (visual acuity, pupils, dose rows) are left alone.
const SS_SKIP_CLASSES = ['refr-input', 'pupil-select', 'med-row-input'];
const SS = { open: null, highlight: -1 };

function ssWanted(select) {
  if (select.multiple || select.dataset.plain !== undefined || select.dataset.ss === 'on') return false;
  if (SS_SKIP_CLASSES.some(c => select.classList.contains(c))) return false;
  return select.dataset.searchable !== undefined || select.options.length > SS_MIN_OPTIONS;
}

function ssEnhance(select) {
  select.dataset.ss = 'on';
  const display = document.createElement('button');
  display.type = 'button';
  display.className = `${select.className} ss-display`;
  display.style.cssText = select.style.cssText;
  display.setAttribute('aria-haspopup', 'listbox');
  display.addEventListener('click', () => (SS.open?.select === select ? ssClose() : ssOpen(select)));
  display.addEventListener('keydown', event => {
    if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
      event.preventDefault();
      ssOpen(select);
    }
  });
  select.after(display);
  select.classList.add('ss-native');
  select.tabIndex = -1;
  select._ssDisplay = display;
  // The real select is hidden; anything that focuses it (e.g. the first field with an error)
  // focuses the visible box instead, so the page does not jump to where the hidden select sits.
  select.focus = options => display.focus(options);

  // Keep the box in step when a screen sets the value in code (select.value = …), which fires no event.
  const proto = HTMLSelectElement.prototype;
  for (const prop of ['value', 'selectedIndex']) {
    const descriptor = Object.getOwnPropertyDescriptor(proto, prop);
    Object.defineProperty(select, prop, {
      configurable: true,
      get() { return descriptor.get.call(this); },
      set(v) { descriptor.set.call(this, v); ssRefresh(this); },
    });
  }
  select.addEventListener('change', () => ssRefresh(select));
  select.form?.addEventListener('reset', () => setTimeout(() => ssRefresh(select)));
  // Options refilled, the select disabled, an error class added: mirror it on the box.
  new MutationObserver(() => ssRefresh(select)).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'class', 'style'] });
  ssRefresh(select);
}

function ssRefresh(select) {
  const display = select._ssDisplay;
  if (!display) return;
  const option = select.options[select.selectedIndex];
  display.textContent = option ? option.textContent : '';
  display.disabled = select.disabled;
  display.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
  display.classList.toggle('ss-placeholder', !option || option.value === '');
  display.style.display = select.style.display === 'none' ? 'none' : '';
  if (SS.open?.select === select) ssRenderList();
}

function ssOpen(select) {
  ssClose();
  if (select.disabled) return;
  const panel = document.createElement('div');
  panel.className = 'ss-panel';
  panel.innerHTML = '<input type="text" class="ss-search" placeholder="Type to search…" autocomplete="off"><div class="ss-list" role="listbox"></div>';
  document.body.appendChild(panel);
  SS.open = { select, panel };
  ssPosition();
  const search = panel.querySelector('.ss-search');
  search.addEventListener('input', () => { SS.highlight = 0; ssRenderList(); });
  search.addEventListener('keydown', ssKeys);
  ssRenderList(true);
  search.focus();
}

function ssPosition() {
  if (!SS.open) return;
  const rect = SS.open.select._ssDisplay.getBoundingClientRect();
  const panel = SS.open.panel;
  const below = window.innerHeight - rect.bottom;
  panel.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - Math.max(rect.width, 240) - 8))}px`;
  panel.style.width = `${Math.max(rect.width, 240)}px`;
  if (below < 260 && rect.top > below) {
    panel.style.top = '';
    panel.style.bottom = `${window.innerHeight - rect.top + 4}px`;
  } else {
    panel.style.bottom = '';
    panel.style.top = `${rect.bottom + 4}px`;
  }
}

function ssMatches() {
  const { select, panel } = SS.open;
  const words = panel.querySelector('.ss-search').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
  return [...select.options].filter(o => words.every(w => o.textContent.toLowerCase().includes(w)));
}

function ssRenderList(scrollToSelected = false) {
  const { select, panel } = SS.open;
  const options = ssMatches();
  if (scrollToSelected) SS.highlight = Math.max(0, options.findIndex(o => o.value === select.value));
  SS.highlight = Math.min(Math.max(SS.highlight, 0), options.length - 1);
  const list = panel.querySelector('.ss-list');
  list.innerHTML = options.length
    ? options.map((o, i) => `<div class="ss-option${i === SS.highlight ? ' active' : ''}${o.value === select.value ? ' selected' : ''}${o.disabled ? ' disabled' : ''}"
        role="option" data-index="${i}">${KSI.esc(o.textContent)}</div>`).join('')
    : '<div class="ss-empty">Nothing matches</div>';
  list.querySelectorAll('.ss-option').forEach(el => {
    el.addEventListener('mousedown', event => {
      event.preventDefault();
      ssChoose(options[Number(el.dataset.index)]);
    });
  });
  list.querySelector('.ss-option.active')?.scrollIntoView({ block: 'nearest' });
}

function ssKeys(event) {
  const options = ssMatches();
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    SS.highlight += event.key === 'ArrowDown' ? 1 : -1;
    ssRenderList();
  } else if (event.key === 'Enter') {
    event.preventDefault();
    if (options[SS.highlight]) ssChoose(options[SS.highlight]);
  } else if (event.key === 'Escape') {
    event.preventDefault();
    const display = SS.open.select._ssDisplay;
    ssClose();
    display.focus();
  } else if (event.key === 'Tab') {
    ssClose();
  }
}

function ssChoose(option) {
  if (!option || option.disabled) return;
  const select = SS.open.select;
  ssClose();
  if (select.value !== option.value) {
    select.value = option.value;
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }
  select._ssDisplay.focus();
}

function ssClose() {
  SS.open?.panel.remove();
  SS.open = null;
}

/** Enhances every eligible dropdown under root; also called as screens add or refill them. */
function uiEnhanceSelects(root = document) {
  root.querySelectorAll('select').forEach(select => {
    if (ssWanted(select)) ssEnhance(select);
  });
}

document.addEventListener('mousedown', event => {
  if (SS.open && !SS.open.panel.contains(event.target) && event.target !== SS.open.select._ssDisplay) ssClose();
});
window.addEventListener('resize', ssClose);
document.addEventListener('scroll', event => {
  if (SS.open && !SS.open.panel.contains(event.target)) ssPosition();
}, true);

// Screens build and refill dropdowns all the time; look again (at most once a frame) when they do.
let ssPending = false;
new MutationObserver(() => {
  if (ssPending) return;
  ssPending = true;
  requestAnimationFrame(() => {
    ssPending = false;
    uiEnhanceSelects();
  });
}).observe(document.documentElement, { childList: true, subtree: true });
document.addEventListener('DOMContentLoaded', () => uiEnhanceSelects());
