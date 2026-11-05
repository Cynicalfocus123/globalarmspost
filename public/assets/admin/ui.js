// Shared admin UI helpers: $ / $$ and sheets from the bottom (phones) that are side panels on desktop.
export const $ = (s, el = document) => el.querySelector(s);
export const $$ = (s, el = document) => [...el.querySelectorAll(s)];

/* Sheets: [data-sheet-open="id"] opens, [data-sheet-close] / Escape / tap outside closes. */
export function openSheet(wrap) {
  wrap.hidden = false;
  wrap.classList.add('open');
  document.body.classList.add('sheet-open');
  const focus = wrap.querySelector('input, select, textarea, button:not([data-sheet-close]), a');
  focus?.focus({ preventScroll: true });
}
export function closeSheet(wrap) {
  wrap.classList.remove('open');
  wrap.hidden = true;
  if (!$('.a-sheet-wrap.open')) document.body.classList.remove('sheet-open');
  wrap.dispatchEvent(new Event('sheet-close'));
}
const openFrom = (target) => {
  const button = target.closest?.('[data-sheet-open]');
  if (!button) return false;
  openSheet(document.getElementById(button.dataset.sheetOpen));
  return true;
};
// Where the press started, and when a sheet was last opened by a touch press. A sheet that just opened
// covers the screen, so the browser's follow-up click can land on the sheet itself (the More sheet's
// Sign out button sits right where the More tab was: tapping More signed the admin out). A click is only
// honoured inside a sheet when its press started there.
let pressedOn = null;
let touchOpenedAt = 0;
document.addEventListener('pointerdown', (e) => { pressedOn = e.target; }, true);
// A touch or pen press opens the sheet straight away, so a tap opens it even when the browser sends the
// follow-up click late or at stale coordinates (Firefox on phones did).
document.addEventListener('pointerup', (e) => {
  if (e.pointerType && e.pointerType !== 'mouse' && openFrom(e.target)) touchOpenedAt = performance.now();
});
document.addEventListener('click', (e) => {
  const wrap = e.target.closest?.('.a-sheet-wrap.open');
  if (wrap && !wrap.contains(pressedOn) && performance.now() - touchOpenedAt < 800) {
    e.preventDefault(); // the click that follows the touch press that opened the sheet: not a real press
    e.stopPropagation();
    return;
  }
  if (openFrom(e.target)) return;
  const close = e.target.closest('[data-sheet-close]');
  if (close) {
    closeSheet(close.closest('.a-sheet-wrap'));
    return;
  }
  const backdrop = e.target.classList?.contains('a-sheet-wrap') && e.target.classList.contains('open');
  if (backdrop && (pressedOn === null || pressedOn === e.target)) closeSheet(e.target);
}, true);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const wrap = $$('.a-sheet-wrap.open').pop();
    if (wrap) closeSheet(wrap);
  }
});
// Module scripts run after the page is shown: this marks when the buttons above work (tests wait for it).
document.documentElement.dataset.ui = 'ready';

