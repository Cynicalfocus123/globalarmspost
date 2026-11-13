// Menu builder (task F5): the header menu as a list with depth. Drag ⋮⋮ (mouse, touch or pen; drag right or left
// to nest or un-nest) or use ↑ ↓ → ←; an item's children always move with it. Each item links to the home page, a
// category, a tag, a story, a page or any web address, with its own text, "new tab" and show/hide. Saved in one
// request (one transaction on the server); the header shows the new menu at once (the page cache is cleared).
import { $, $$, openSheet, closeSheet, notify } from './ui.js';
import { api, esc } from './api.js';

const data = JSON.parse($('#menu-data').textContent);
const list = $('.mb-list');
const sheet = $('#mb-sheet');
const form = $('.mb-form');
const statusLine = $('.mb-status');
const stories = new Map(data.stories.map((s) => [String(s.id), s.title]));
const items = data.items.map((i) => ({ ...i, key: `m${i.id}`, auto: false }));
let selected = items[0]?.key || null;
let newKeys = 0;
const phone = () => matchMedia('(max-width: 899px)').matches;
const TYPES = { home: 'Home page', category: 'Category', tag: 'Tag', story: 'Story', page: 'Page (About Us, Contact …)', url: 'Web address', text: 'Title only (no link)' };
const WHERE = data.location === 'footer' ? 'footer' : 'header'; // which menu this is (task F24)
const MAX_DEPTH = 8;

const indexOf = (key) => items.findIndex((i) => i.key === key);
/** End (exclusive) of an item's block: the item and everything under it. */
function blockEnd(i) {
  let j = i + 1;
  while (j < items.length && items[j].depth > items[i].depth) j++;
  return j;
}
/** Every item is at most one level deeper than the item above it. */
function normalize() {
  items.forEach((it, i) => {
    const max = i === 0 ? 0 : Math.min(MAX_DEPTH, items[i - 1].depth + 1);
    it.depth = Math.max(0, Math.min(it.depth, max));
  });
}
function parentOf(i) {
  for (let j = i - 1; j >= 0; j--) if (items[j].depth === items[i].depth - 1) return items[j];
  return null;
}
function targetText(it) {
  switch (it.type) {
    case 'category': return data.categories.find((c) => c.slug === it.target)?.name || 'choose a category';
    case 'tag': return data.tags.find((t) => t.slug === it.target)?.name || 'choose a tag';
    case 'story': return stories.get(String(it.target)) || 'choose a story';
    case 'page': return data.pages.find((p) => p.slug === it.target)?.title || 'choose a page';
    case 'url': return it.target || 'type an address';
    default: return '';
  }
}

function renderList() {
  list.innerHTML = items.map((it, i) => {
    const t = targetText(it);
    return `<li class="a-sec mb-item mb-d-${it.depth}${it.key === selected ? ' on' : ''}${it.hidden ? ' off' : ''}" data-key="${it.key}" data-depth="${it.depth}">
      <button type="button" class="a-grip" aria-label="Drag to move ${esc(it.label)}">⋮⋮</button>
      <button type="button" class="hb-open" aria-expanded="${it.key === selected}">
        <b class="mb-label">${esc(it.label)}</b>
        <span class="a-small">${esc(TYPES[it.type] || it.type)}${t ? ' · ' + esc(t) : ''}</span>
        <span class="hb-pills">${it.depth ? `<span class="a-pill dr">level ${it.depth + 1}</span>` : ''}${it.hidden ? '<span class="a-pill tr">Hidden</span>' : ''}${it.new_tab ? '<span class="a-pill dr">New tab</span>' : ''}</span>
      </button>
      <span class="hb-move mb-move">
        <button type="button" class="a-btn2 sm" data-act="up" aria-label="Move ${esc(it.label)} up"${i === 0 ? ' disabled' : ''}>↑</button>
        <button type="button" class="a-btn2 sm" data-act="down" aria-label="Move ${esc(it.label)} down"${blockEnd(i) >= items.length ? ' disabled' : ''}>↓</button>
        <button type="button" class="a-btn2 sm" data-act="out" aria-label="Take ${esc(it.label)} out one level"${it.depth === 0 ? ' disabled' : ''}>←</button>
        <button type="button" class="a-btn2 sm" data-act="in" aria-label="Put ${esc(it.label)} under the item above"${i === 0 || it.depth > items[i - 1].depth || it.depth >= MAX_DEPTH ? ' disabled' : ''}>→</button>
      </span>
    </li>`;
  }).join('');
  if (!items.length) list.innerHTML = '<li class="a-mute">No items. Add one with “+ Add item”.</li>';
}

/* ---------- moving ---------- */
function moveBlock(key, act) {
  const i = indexOf(key);
  if (i < 0) return false;
  const end = blockEnd(i);
  const len = end - i;
  if (act === 'up') {
    if (i === 0) return false;
    const block = items.splice(i, len);
    items.splice(i - 1, 0, ...block);
  } else if (act === 'down') {
    if (end >= items.length) return false;
    const nextEnd = blockEnd(end);
    const block = items.splice(i, len);
    items.splice(nextEnd - len, 0, ...block);
  } else if (act === 'in') {
    if (i === 0 || items[i].depth > items[i - 1].depth || items[i].depth >= MAX_DEPTH) return false;
    for (let j = i; j < end; j++) items[j].depth++;
  } else if (act === 'out') {
    if (items[i].depth === 0) return false;
    for (let j = i; j < end; j++) items[j].depth--;
  }
  normalize();
  return true;
}
/** Put an item (with its children) as the last child of another item ('' = top level, at the end). */
function moveUnder(key, parentKey) {
  const i = indexOf(key);
  const block = items.splice(i, blockEnd(i) - i);
  const shift = (parentKey === '' ? 0 : items[indexOf(parentKey)].depth + 1) - block[0].depth;
  for (const it of block) it.depth = Math.min(MAX_DEPTH, it.depth + shift);
  const at = parentKey === '' ? items.length : blockEnd(indexOf(parentKey));
  items.splice(at, 0, ...block);
  normalize();
}

list.addEventListener('click', (e) => {
  const li = e.target.closest('li[data-key]');
  if (!li) return;
  const act = e.target.closest('[data-act]');
  if (act) {
    if (moveBlock(li.dataset.key, act.dataset.act)) {
      touched();
      renderList();
      renderForm();
      $(`li[data-key="${li.dataset.key}"] [data-act="${act.dataset.act}"]:not(:disabled)`, list)?.focus();
    }
    return;
  }
  if (e.target.closest('.hb-open')) select(li.dataset.key);
});

// Drag: the item and its children move together. Released more than 40 px to the right or left, it goes one
// level in or out. The listeners are on the document: the pointer is often released outside the list.
let drag = null;
list.addEventListener('pointerdown', (e) => {
  const grip = e.target.closest('.a-grip');
  if (!grip) return;
  e.preventDefault();
  const li = grip.closest('li');
  grip.setPointerCapture(e.pointerId);
  const i = indexOf(li.dataset.key);
  const keys = items.slice(i, blockEnd(i)).map((x) => x.key);
  const nodes = keys.map((k) => $(`li[data-key="${k}"]`, list));
  for (const n of nodes) n.classList.add('dragging');
  drag = { key: li.dataset.key, keys, nodes, id: e.pointerId, x: e.clientX, y: e.clientY };
  requestAnimationFrame(edgeScroll);
});
/** Put the dragged rows where the pointer is (before the first row whose middle is below it). */
function placeAt(y) {
  const others = $$('li[data-key]', list).filter((x) => !drag.keys.includes(x.dataset.key));
  const after = others.find((x) => { const r = x.getBoundingClientRect(); return y < r.top + r.height / 2; });
  for (const n of drag.nodes) {
    if (after) list.insertBefore(n, after);
    else list.append(n);
  }
}
// A long menu is taller than a phone's screen: held near the top or bottom edge, the page scrolls by itself
// (faster the closer to the edge), so an item can be dragged anywhere in the list.
function edgeScroll() {
  if (!drag) return;
  const top = 80;
  const bottom = innerHeight - (phone() ? 140 : 80); // the bottom tab bar covers the last 64 px on phones
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
  const order = $$('li[data-key]', list).map((x) => x.dataset.key);
  items.sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
  normalize();
  const dx = e.clientX - drag.x;
  if (Math.abs(dx) > 40) moveBlock(drag.key, dx > 0 ? 'in' : 'out');
  drag = null;
  touched();
  renderList();
  renderForm();
};
document.addEventListener('pointerup', endDrag);
document.addEventListener('pointercancel', endDrag);

/* ---------- the item's form ---------- */
function select(key) {
  selected = key;
  renderList();
  renderForm();
  if (phone()) openSheet(sheet);
}
sheet.addEventListener('sheet-close', () => { sheet.hidden = false; }); // on desktop the panel stays

function targetField(it) {
  switch (it.type) {
    case 'category':
      return `<label class="a-lbl" for="mb-target">Category</label><select id="mb-target" data-f="target">${data.categories.map((c) => `<option value="${esc(c.slug)}"${c.slug === it.target ? ' selected' : ''}>${'— '.repeat(c.depth)}${esc(c.name)}</option>`).join('')}</select>`;
    case 'tag':
      return data.tags.length
        ? `<label class="a-lbl" for="mb-target">Tag</label><select id="mb-target" data-f="target">${data.tags.map((t) => `<option value="${esc(t.slug)}"${t.slug === it.target ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select>`
        : '<p class="a-mute">No tags yet. Add tags in a story first.</p>';
    case 'story':
      return `<label class="a-lbl" for="mb-story-q">Story</label><input id="mb-story-q" class="mb-story-q" type="search" placeholder="Search headlines" autocomplete="off">
        <p class="a-small mb-story-chosen">${it.target ? 'Chosen: ' + esc(stories.get(String(it.target)) || '#' + it.target) : 'No story chosen yet.'} A story that is not public yet stays out of the header until it is.</p>
        <div class="mb-story-list" role="listbox" aria-label="Matching stories"></div>`;
    case 'page':
      return data.pages.length
        ? `<label class="a-lbl" for="mb-target">Page</label><select id="mb-target" data-f="target">${data.pages.map((p) => `<option value="${esc(p.slug)}"${p.slug === it.target ? ' selected' : ''}>${esc(p.title)} (/${esc(p.slug)})</option>`).join('')}</select>`
        : '<p class="a-mute">No pages yet. Add one in Admin → Pages.</p>';
    case 'text':
      return '<p class="a-small">Only the text, with no link: a footer column title, or a header item that opens the items under it.</p>';
    case 'url':
      return `<label class="a-lbl" for="mb-target">Web address (https://…, /path or mailto:)</label><input id="mb-target" data-f="target" maxlength="500" inputmode="url" value="${esc(it.target)}">`;
    default:
      return '<p class="a-small">Links to the home page.</p>';
  }
}

function renderForm() {
  const i = indexOf(selected);
  const it = items[i];
  if (!it) { form.innerHTML = '<p class="a-mute">Choose an item, or add one.</p>'; return; }
  const parent = parentOf(i);
  const end = blockEnd(i);
  const parents = items.filter((x, j) => (j < i || j >= end) && x.depth < MAX_DEPTH);
  form.innerHTML = `
    <label class="a-lbl" for="mb-type">Links to</label>
    <select id="mb-type" data-f="type">${Object.entries(TYPES).map(([k, v]) => `<option value="${k}"${k === it.type ? ' selected' : ''}>${v}</option>`).join('')}</select>
    ${targetField(it)}
    <label class="a-lbl" for="mb-label">Text in the menu</label><input id="mb-label" data-f="label" maxlength="120" value="${esc(it.label)}">
    <label class="a-lbl" for="mb-parent">Inside</label>
    <select id="mb-parent" data-f="parent"><option value="">— Top level —</option>${parents.map((p) => `<option value="${p.key}"${parent && parent.key === p.key ? ' selected' : ''}>${'— '.repeat(p.depth)}${esc(p.label)}</option>`).join('')}</select>
    <label class="a-check"><input type="checkbox" data-f="new_tab"${it.new_tab ? ' checked' : ''}> Open in a new tab</label>
    <label class="a-check"><input type="checkbox" data-f="show"${it.hidden ? '' : ' checked'}> Show in the ${WHERE}</label>
    <div class="a-row"><button type="button" class="a-btn2 danger mb-remove">Remove${end - i > 1 ? ` (with ${end - i - 1} under it)` : ''}</button>
    <button type="button" class="a-btn mb-done show-phone">Done</button></div>`;
  if (it.type === 'story') findStories('');
}

function autoLabel(it) {
  if (!it.auto && it.label !== '') return;
  const name = it.type === 'home' ? 'Home' : targetText(it);
  if (name && !/^choose|^type/.test(name)) {
    it.label = name.replace(/^\//, '');
    it.auto = true;
    const input = $('#mb-label', form);
    if (input) input.value = it.label;
  }
}

form.addEventListener('input', (e) => {
  const it = items[indexOf(selected)];
  if (!it) return;
  if (e.target.classList.contains('mb-story-q')) { findStories(e.target.value); return; }
  const f = e.target.dataset.f;
  if (f === 'label') { it.label = e.target.value; it.auto = false; }
  else if (f === 'target' && e.target.tagName === 'INPUT') { it.target = e.target.value.trim(); autoLabel(it); }
  else return;
  touched();
  renderList();
});
form.addEventListener('change', (e) => {
  const it = items[indexOf(selected)];
  if (!it) return;
  const f = e.target.dataset.f;
  if (f === 'type') {
    it.type = e.target.value;
    it.target = it.type === 'category' ? (data.categories[0]?.slug || '') : it.type === 'tag' ? (data.tags[0]?.slug || '') : it.type === 'page' ? (data.pages[0]?.slug || '') : '';
    if (it.auto || it.label === '') { it.label = ''; it.auto = true; }
    autoLabel(it);
    renderForm();
  } else if (f === 'target' && e.target.tagName === 'SELECT') {
    it.target = e.target.value;
    autoLabel(it);
  } else if (f === 'parent') {
    moveUnder(it.key, e.target.value);
  } else if (f === 'new_tab') {
    it.new_tab = e.target.checked;
  } else if (f === 'show') {
    it.hidden = !e.target.checked;
  } else return;
  touched();
  renderList();
  if (f === 'parent') renderForm();
});
form.addEventListener('click', (e) => {
  if (e.target.classList.contains('mb-done')) { closeSheet(sheet); return; }
  if (e.target.classList.contains('mb-remove')) {
    const i = indexOf(selected);
    const n = blockEnd(i) - i;
    if (!confirm(n > 1 ? `Remove “${items[i].label}” and the ${n - 1} items under it from the menu?` : `Remove “${items[i].label}” from the menu?`)) return;
    items.splice(i, n);
    normalize();
    selected = items[Math.min(i, items.length - 1)]?.key || null;
    touched();
    renderList();
    renderForm();
    if (phone()) closeSheet(sheet);
    return;
  }
  const opt = e.target.closest('.mb-story-opt');
  if (opt) {
    const it = items[indexOf(selected)];
    it.target = opt.dataset.id;
    stories.set(opt.dataset.id, opt.dataset.title);
    autoLabel(it);
    $('.mb-story-chosen', form).textContent = `Chosen: ${opt.dataset.title}`;
    touched();
    renderList();
  }
});

let findTimer = null;
let findSeq = 0;
function findStories(q) {
  clearTimeout(findTimer);
  findTimer = setTimeout(async () => {
    const seq = ++findSeq;
    const box = $('.mb-story-list', form);
    if (!box) return;
    try {
      const res = await api('/admin/api/stories-find', { q });
      if (seq !== findSeq) return;
      box.innerHTML = res.stories.length
        ? res.stories.map((s) => `<button type="button" class="a-opt mb-story-opt" role="option" data-id="${s.id}" data-title="${esc(s.title)}">${esc(s.title)} <span class="a-pill">${esc(s.state)}</span></button>`).join('')
        : '<p class="a-mute">No story matches.</p>';
    } catch (err) {
      box.textContent = err.message;
    }
  }, 250);
}

/* ---------- add, save ---------- */
$('.mb-add').addEventListener('click', () => {
  const c = data.categories[0];
  const it = { key: `n${++newKeys}`, id: 0, type: c ? 'category' : 'url', target: c ? c.slug : '', label: c ? c.name : '', new_tab: false, hidden: false, depth: 0, auto: true };
  items.push(it);
  touched();
  select(it.key);
  $(`li[data-key="${it.key}"]`, list)?.scrollIntoView({ block: 'nearest' });
});

function touched() {
  statusLine.textContent = 'Changes not saved yet.';
  delete statusLine.dataset.saved;
}
$('.mb-save').addEventListener('click', async () => {
  const btn = $('.mb-save');
  btn.disabled = true;
  statusLine.textContent = 'Saving…';
  try {
    const res = await api('/admin/api/menu', {
      location: WHERE,
      items: items.map((it, i) => ({ key: it.key, id: it.id, parent: parentOf(i)?.key || '', type: it.type, label: it.label, target: String(it.target ?? ''), new_tab: it.new_tab, hidden: it.hidden })),
    });
    for (const it of items) if (res.ids[it.key]) it.id = res.ids[it.key];
    statusLine.textContent = `Saved ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}. The ${WHERE} shows the new menu now.`;
    statusLine.dataset.saved = '1';
    notify(true, WHERE === 'footer' ? 'Footer menu saved' : 'Menu saved', `The ${WHERE} shows the new menu now.`);
  } catch (err) {
    statusLine.textContent = err.message;
    notify(false, 'Not saved', err.message);
  } finally {
    btn.disabled = false;
  }
});

renderList();
renderForm();
list.dataset.ready = '1';
