// Categories page (task F17): drag ⋮⋮ (mouse, touch or pen) or ↑ ↓ to change the order. A category moves with its
// sub-categories and stays inside its own parent (Edit moves it into another one). "Save order" stores it.
import { $, $$, notify } from './ui.js';
import { api } from './api.js';

const list = $('.cat-list');
const saveBtn = $('.cat-save');
const statusLine = $('.cat-status');
const depth = (li) => Number((/depth-(\d)/.exec(li.className) || [0, 0])[1]);
const rows = () => $$('li[data-id]', list);
/** The li and every li under it (deeper, right after it). */
function block(li) {
  const all = rows();
  const i = all.indexOf(li);
  const out = [li];
  for (let j = i + 1; j < all.length && depth(all[j]) > depth(li); j++) out.push(all[j]);
  return out;
}
/** The parent row of an li (the nearest row above it that is one level up), or null at the top. */
function parentOf(li) {
  const all = rows();
  for (let j = all.indexOf(li) - 1; j >= 0; j--) if (depth(all[j]) < depth(li)) return all[j];
  return null;
}
function siblingBefore(li) {
  const all = rows();
  for (let j = all.indexOf(li) - 1; j >= 0; j--) {
    if (depth(all[j]) < depth(li)) return null;
    if (depth(all[j]) === depth(li)) return all[j];
  }
  return null;
}
function siblingAfter(li) {
  const b = block(li);
  const next = b[b.length - 1].nextElementSibling;
  return next && depth(next) === depth(li) ? next : null;
}
function refresh() {
  for (const li of rows()) {
    $('[data-move="-1"]', li).disabled = !siblingBefore(li);
    $('[data-move="1"]', li).disabled = !siblingAfter(li);
  }
}
function changed() {
  saveBtn.disabled = false;
  statusLine.textContent = 'Order changed, not saved yet.';
  delete statusLine.dataset.saved;
  refresh();
}

list.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-move]');
  if (!btn) return;
  const li = btn.closest('li');
  const b = block(li);
  if (btn.dataset.move === '-1') {
    const before = siblingBefore(li);
    if (!before) return;
    for (const n of b) list.insertBefore(n, before);
  } else {
    const after = siblingAfter(li);
    if (!after) return;
    const ab = block(after);
    const anchor = ab[ab.length - 1].nextElementSibling;
    for (const n of b) list.insertBefore(n, anchor);
  }
  changed();
  (btn.disabled ? $('[data-move]:not(:disabled)', li) : btn)?.focus();
});

let drag = null;
list.addEventListener('pointerdown', (e) => {
  const grip = e.target.closest('.a-grip');
  if (!grip) return;
  e.preventDefault();
  const li = grip.closest('li');
  grip.setPointerCapture(e.pointerId);
  const nodes = block(li);
  for (const n of nodes) n.classList.add('dragging');
  drag = { li, nodes, id: e.pointerId, parent: parentOf(li), order: rows(), y: e.clientY };
  requestAnimationFrame(edgeScroll);
});
function placeAt(y) {
  const others = rows().filter((x) => !drag.nodes.includes(x));
  const after = others.find((x) => { const r = x.getBoundingClientRect(); return y < r.top + r.height / 2; });
  for (const n of drag.nodes) {
    if (after) list.insertBefore(n, after);
    else list.append(n);
  }
}
// Held near the top or bottom of the screen, the page scrolls by itself, so a category can be dragged past the
// part of a long list that is on screen (phones).
function edgeScroll() {
  if (!drag) return;
  const top = 80;
  const bottom = innerHeight - (matchMedia('(max-width: 899px)').matches ? 140 : 80);
  const dy = drag.y < top ? -Math.ceil((top - drag.y) / 4) : drag.y > bottom ? Math.ceil((drag.y - bottom) / 4) : 0;
  if (dy) {
    scrollBy(0, dy);
    placeAt(drag.y);
  }
  requestAnimationFrame(edgeScroll);
}
document.addEventListener('pointermove', (e) => {
  if (!drag || e.pointerId !== drag.id) return;
  drag.y = e.clientY;
  placeAt(e.clientY);
});
const endDrag = (e) => {
  if (!drag || e.pointerId !== drag.id) return;
  for (const n of drag.nodes) n.classList.remove('dragging');
  // Dropped among other parents' sub-categories: put it back (Edit moves a category into another one).
  const inside = (() => { const all = rows(); const last = drag.nodes[drag.nodes.length - 1]; const next = all[all.indexOf(last) + 1]; return !next || depth(next) <= depth(drag.li); })();
  if (parentOf(drag.li) !== drag.parent || !inside) {
    for (const n of drag.order) list.append(n);
    statusLine.textContent = 'A category stays inside its own parent here. Use Edit to move it into another one.';
  } else if (drag.order.some((n, i) => rows()[i] !== n)) {
    changed();
  }
  drag = null;
  refresh();
};
document.addEventListener('pointerup', endDrag);
document.addEventListener('pointercancel', endDrag);

saveBtn.addEventListener('click', async () => {
  saveBtn.disabled = true;
  statusLine.textContent = 'Saving…';
  try {
    await api('/admin/api/categories-order', { ids: rows().map((li) => Number(li.dataset.id)) });
    statusLine.textContent = 'Order saved. The menu, footer and lists use it now.';
    statusLine.dataset.saved = '1';
    notify(true, 'Order saved', 'The menu, footer and lists use it now.');
  } catch (err) {
    statusLine.textContent = err.message;
    notify(false, 'Not saved', err.message);
    saveBtn.disabled = false;
  }
});
refresh();
list.dataset.ready = '1';
