// Homepage builder (task F16): drag sections into order (mouse, touch or pen; ↑ ↓ buttons too), every section
// setting from the layout registry, categories as a checkbox list (children indented) with "All categories" and
// a search box, the same list for "Leave out". Saved to home_sections. On phones the settings are a bottom sheet.
// Step 5 (rest of F16): picture size sliders per section, and a live preview drawn by the site itself (the same
// section templates) from the unsaved settings: beside the builder on wide screens, under it on phones.
import { $, $$, openSheet, closeSheet } from './ui.js';
import { api, esc } from './api.js';

const data = JSON.parse($('#builder-data').textContent);
const list = $('.hb-list');
const sheet = $('#hb-sheet');
const formBox = $('.hb-form');
const statusLine = $('.hb-status');
const sections = data.sections.map((s) => ({ ...s, key: `s${s.id}` }));
let selected = sections[0]?.key || null;
let newKeys = 0;
const phone = () => matchMedia('(max-width: 899px)').matches;
const layoutLabel = (l) => data.layouts[l]?.label || l;
const settingsOf = (s) => ({ ...data.layouts[s.layout].settings, ...s.settings });

function renderList() {
  list.innerHTML = sections.map((s, i) => {
    const set = settingsOf(s);
    const cats = (set.categories || []).map((slug) => data.categories.find((c) => c.slug === slug)?.name).filter(Boolean);
    return `<li class="a-sec${s.key === selected ? ' on' : ''}${s.active ? '' : ' off'}" data-key="${s.key}">
      <button type="button" class="a-grip" aria-label="Drag to move ${esc(s.title || layoutLabel(s.layout))}">⋮⋮</button>
      <button type="button" class="hb-open" aria-expanded="${s.key === selected}">
        <b>${esc(s.title || (cats.length ? cats.join(', ') : 'Latest stories'))}</b>
        <span class="a-small">${esc(layoutLabel(s.layout))}</span>
        <span class="hb-pills"><span class="a-pill dr">span ${s.span}</span><span class="a-pill dr">${set.count} stories</span>${s.active ? '' : '<span class="a-pill tr">Hidden</span>'}</span>
      </button>
      <span class="hb-move"><button type="button" class="a-btn2 sm" data-move="-1" aria-label="Move up"${i === 0 ? ' disabled' : ''}>↑</button><button type="button" class="a-btn2 sm" data-move="1" aria-label="Move down"${i === sections.length - 1 ? ' disabled' : ''}>↓</button></span>
    </li>`;
  }).join('');
}

/* ---------- order: drag with pointer events, or ↑ ↓ ---------- */
// The move and end listeners sit on the document, not on the list: the pointer is usually released
// above the first item (outside the list), and before this a release outside the list never ended the
// drag, so the new order was on screen but `sections` kept the old order and Save threw the drag away.
let drag = null;
list.addEventListener('pointerdown', (e) => {
  const grip = e.target.closest('.a-grip');
  if (!grip) return;
  e.preventDefault();
  const li = grip.closest('li');
  grip.setPointerCapture(e.pointerId);
  drag = { li, key: li.dataset.key, id: e.pointerId, y: e.clientY };
  li.classList.add('dragging');
  requestAnimationFrame(edgeScroll);
});
function placeAt(y) {
  const items = $$('li', list).filter((x) => x !== drag.li);
  const after = items.find((x) => { const r = x.getBoundingClientRect(); return y < r.top + r.height / 2; });
  if (after) list.insertBefore(drag.li, after);
  else list.append(drag.li);
}
// A long homepage (many sections) is taller than a laptop screen: held near the top or bottom edge, the page
// scrolls by itself while dragging (step 5), so a section can go anywhere in the list.
function edgeScroll() {
  if (!drag) return;
  const top = 80;
  const bottom = innerHeight - (phone() ? 140 : 80);
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
  drag.li.classList.remove('dragging');
  const order = $$('li', list).map((x) => x.dataset.key);
  sections.sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
  drag = null;
  touched();
  renderList();
};
document.addEventListener('pointerup', endDrag);
document.addEventListener('pointercancel', endDrag);
list.addEventListener('click', (e) => {
  const li = e.target.closest('li');
  if (!li) return;
  const move = e.target.closest('[data-move]');
  if (move) {
    const i = sections.findIndex((s) => s.key === li.dataset.key);
    const j = i + Number(move.dataset.move);
    if (j >= 0 && j < sections.length) {
      [sections[i], sections[j]] = [sections[j], sections[i]];
      touched();
      renderList();
      $(`li[data-key="${li.dataset.key}"] [data-move="${move.dataset.move}"]`, list)?.focus();
    }
    return;
  }
  if (e.target.closest('.hb-open')) select(li.dataset.key);
});

function select(key) {
  selected = key;
  renderList();
  renderForm();
  if (phone()) openSheet(sheet);
}
sheet.addEventListener('sheet-close', () => { sheet.hidden = false; }); // on desktop the panel stays

/* ---------- settings form, built from the layout's settings ---------- */
function catBox(name, label, chosen, open) {
  const items = data.categories.map((c) => `<label class="a-check depth-${Math.min(3, c.depth)}" data-name="${esc(c.name.toLowerCase())}"><input type="checkbox" data-list="${name}" value="${esc(c.slug)}"${chosen.includes(c.slug) ? ' checked' : ''}> ${esc(c.name)}</label>`).join('');
  const inner = `<div class="a-catbox" data-box="${name}">
      <input type="search" class="hb-cat-search" placeholder="Search categories" aria-label="Search categories for ${esc(label)}">
      <label class="a-check all"><input type="checkbox" data-all="${name}"${chosen.length ? '' : ' checked'}> <b>${name === 'categories' ? 'All categories' : 'None'}</b></label>
      ${items}
    </div>
    <p class="a-small hb-chosen" data-for="${name}"></p>`;
  return name === 'categories'
    ? `<fieldset class="hb-field"><legend class="a-lbl">${esc(label)}</legend>${inner}</fieldset>`
    : `<details class="hb-field"${open ? ' open' : ''}><summary class="a-lbl">${esc(label)}: <span class="hb-sum">${chosen.length ? chosen.length + ' chosen' : 'None'}</span></summary>${inner}</details>`;
}
function tagBox(chosen) {
  if (!data.tags.length) return '';
  return `<details class="hb-field"${chosen.length ? ' open' : ''}><summary class="a-lbl">Only stories with these tags: <span class="hb-sum">${chosen.length ? chosen.length + ' chosen' : 'Any'}</span></summary>
    <div class="a-catbox">${data.tags.map((t) => `<label class="a-check"><input type="checkbox" data-list="tags" value="${esc(t.slug)}"${chosen.includes(t.slug) ? ' checked' : ''}> ${esc(t.name)}</label>`).join('')}</div></details>`;
}
const range = (key, label, value, min, max) => `<label class="a-lbl" for="hb-${key}">${esc(label)}: <output data-out="${key}">${value}</output></label>
  <input type="range" id="hb-${key}" data-key="${key}" min="${min}" max="${max}" value="${value}">`;

function renderForm() {
  const s = sections.find((x) => x.key === selected);
  if (!s) { formBox.innerHTML = '<p class="a-mute">Choose a section.</p>'; return; }
  const set = settingsOf(s);
  const layouts = Object.entries(data.layouts).map(([k, l]) => `<option value="${k}"${k === s.layout ? ' selected' : ''}>${esc(l.label)}</option>`).join('');
  const order = { date: 'Newest first (date)', updated: 'Last updated', title: 'Title A–Z', id: 'Story number', views: 'Most read', random: 'Random' };
  formBox.innerHTML = `
    <label class="a-lbl" for="hb-layout">Layout</label><select id="hb-layout" data-top="layout">${layouts}</select>
    <label class="a-lbl" for="hb-title">Title (empty = no section header)</label><input id="hb-title" data-top="title" maxlength="120" value="${esc(s.title)}">
    ${range('span', 'Width (span 3–12)', s.span, 3, 12)}
    ${range('count', 'Stories', set.count, 1, 20)}
    ${'columns' in set ? range('columns', 'Columns', set.columns, 1, 6) : ''}
    ${'photo_height' in set ? range('photo_height', 'Photo height (1 low and wide … 5 square)', set.photo_height, 1, 5) : ''}
    ${'thumb_size' in set ? range('thumb_size', 'Small photo size in rows (1 small … 5 big)', set.thumb_size, 1, 5) : ''}
    ${'tabs' in set ? `<label class="a-check"><input type="checkbox" data-key="tabs"${set.tabs ? ' checked' : ''}> Top stories / Latest tabs</label>` : ''}
    ${catBox('categories', 'Show stories from', set.categories || [], true)}
    ${catBox('exclude_categories', 'Leave out', set.exclude_categories || [], (set.exclude_categories || []).length > 0)}
    ${tagBox(set.tags || [])}
    <label class="a-lbl" for="hb-order">Order</label><select id="hb-order" data-key="order_by">${Object.entries(order).map(([k, v]) => `<option value="${k}"${set.order_by === k ? ' selected' : ''}>${v}</option>`).join('')}</select>
    <label class="a-lbl" for="hb-dir">Direction</label><select id="hb-dir" data-key="dir"><option value="desc"${set.dir !== 'asc' ? ' selected' : ''}>Descending</option><option value="asc"${set.dir === 'asc' ? ' selected' : ''}>Ascending</option></select>
    <label class="a-lbl" for="hb-skip">Skip first</label><input id="hb-skip" type="number" inputmode="numeric" min="0" max="100" data-key="skip" value="${set.skip}">
    <label class="a-check"><input type="checkbox" data-key="sticky_first"${set.sticky_first ? ' checked' : ''}> Pinned stories first</label>
    <label class="a-check"><input type="checkbox" data-key="avoid_duplicates"${set.avoid_duplicates ? ' checked' : ''}> Avoid duplicates (skip stories shown higher up)</label>
    <label class="a-check"><input type="checkbox" data-top="active"${s.active ? ' checked' : ''}> Show on the homepage</label>
    <button type="button" class="a-btn wide hb-done show-phone">Done</button>`;
  for (const name of ['categories', 'exclude_categories']) chosenText(name);
}

function chosenText(name) {
  const s = sections.find((x) => x.key === selected);
  const chosen = settingsOf(s)[name] || [];
  const names = chosen.map((slug) => data.categories.find((c) => c.slug === slug)?.name).filter(Boolean);
  const p = $(`.hb-chosen[data-for="${name}"]`, formBox);
  if (p) p.textContent = names.length ? `${names.length} chosen: ${names.join(', ')}${name === 'categories' ? ' · ticking a parent includes its children' : ''}` : (name === 'categories' ? 'All categories' : 'Nothing left out');
  const sum = p?.closest('details')?.querySelector('.hb-sum');
  if (sum) sum.textContent = names.length ? `${names.length} chosen` : 'None';
}

formBox.addEventListener('input', (e) => {
  const el = e.target;
  if (el.classList.contains('hb-cat-search')) {
    const q = el.value.trim().toLowerCase();
    for (const l of $$('label[data-name]', el.closest('.a-catbox'))) l.hidden = !!q && !l.dataset.name.includes(q);
    return;
  }
  update(el);
});
formBox.addEventListener('change', (e) => { if (e.target.type === 'checkbox' || e.target.tagName === 'SELECT') update(e.target); });
formBox.addEventListener('click', (e) => { if (e.target.classList.contains('hb-done')) closeSheet(sheet); });

function update(el) {
  const s = sections.find((x) => x.key === selected);
  if (!s) return;
  if (el.dataset.top) {
    if (el.dataset.top === 'layout') {
      s.layout = el.value;
      renderForm();
    } else if (el.dataset.top === 'active') s.active = el.checked;
    else s.title = el.value;
  } else if (el.dataset.key) {
    const key = el.dataset.key;
    const v = el.type === 'checkbox' ? el.checked : el.type === 'range' || el.type === 'number' ? Number(el.value) : el.value;
    if (key === 'span') s.span = v;
    else s.settings = { ...s.settings, [key]: v };
    const out = $(`[data-out="${key}"]`, formBox);
    if (out) out.textContent = String(v);
  } else if (el.dataset.list || el.dataset.all) {
    const name = el.dataset.list || el.dataset.all;
    const box = el.closest('.a-catbox');
    if (el.dataset.all) {
      if (el.checked) for (const c of $$(`input[data-list="${name}"]`, box)) c.checked = false;
      else el.checked = true; // "All" stays until a category is ticked
    }
    const chosen = $$(`input[data-list="${name}"]:checked`, box).map((c) => c.value);
    const all = $(`input[data-all="${name}"]`, box);
    if (all) all.checked = chosen.length === 0;
    s.settings = { ...s.settings, [name]: chosen };
    if (name !== 'tags') chosenText(name);
    else el.closest('details').querySelector('.hb-sum').textContent = chosen.length ? `${chosen.length} chosen` : 'Any';
  }
  touched();
  renderList();
}

/* ---------- add, save ---------- */
const addBox = $('.hb-add');
$('#hb-add-layout').innerHTML = Object.entries(data.layouts).map(([k, l]) => `<option value="${k}">${esc(l.label)}</option>`).join('');
$('.hb-add-open').addEventListener('click', () => { addBox.hidden = false; $('#hb-add-layout').focus(); });
$('.hb-add-cancel').addEventListener('click', () => { addBox.hidden = true; });
$('.hb-add').addEventListener('click', (e) => {
  if (!e.target.classList.contains('hb-add')) return;
  const layout = $('#hb-add-layout').value;
  const s = { id: 0, key: `n${++newKeys}`, layout, title: '', span: 12, active: true, settings: {} };
  sections.push(s);
  addBox.hidden = true;
  touched();
  select(s.key);
  $(`li[data-key="${s.key}"]`, list)?.scrollIntoView({ block: 'nearest' });
});

function touched() {
  statusLine.textContent = 'Changes not saved yet.';
  delete statusLine.dataset.saved;
  schedulePreview();
}

/* ---------- live preview ---------- */
// The unsaved sections go to /admin/api/homepage-draft (checked like a save, kept in this session only), then the
// frame loads /admin/homepage/preview, which draws them with the site's own layout and section templates.
// The frame is drawn at desktop (1280 px) or phone (390 px) width and scaled down to fit its box.
const frame = $('.hb-frame');
const prevNote = $('.hb-prev-note');
let prevTimer = null;
let prevSeq = 0;
const payload = () => sections.map((s) => ({ id: s.id, layout: s.layout, title: s.title, span: s.span, active: s.active, settings: settingsOf(s) }));
function schedulePreview() {
  if (!frame) return;
  clearTimeout(prevTimer);
  prevTimer = setTimeout(drawPreview, 450);
}
async function drawPreview() {
  const seq = ++prevSeq;
  try {
    await api('/admin/api/homepage-draft', { sections: payload() });
    if (seq !== prevSeq) return; // a newer change is on its way
    frame.dataset.seq = String(seq);
    frame.src = `/admin/homepage/preview?v=${seq}`;
    prevNote.textContent = 'Shows your changes before you save. Drawn by the site itself.';
  } catch (err) {
    prevNote.textContent = `Preview: ${err.message}`;
  }
}
function fitPreview() {
  if (!frame) return;
  const box = frame.parentElement;
  const w = Number($('input[name="hb-prev-w"]:checked')?.value || 1280);
  const k = Math.min(1, box.clientWidth / w);
  frame.style.width = `${w}px`;
  frame.style.height = `${Math.round(box.clientHeight / k)}px`;
  frame.style.transform = `scale(${k})`;
  frame.dataset.width = String(w);
}
if (frame) {
  frame.addEventListener('load', () => { if (frame.dataset.seq) frame.dataset.loaded = frame.dataset.seq; });
  $('.hb-prev-size').addEventListener('change', fitPreview);
  addEventListener('resize', fitPreview);
  if (phone()) $('input[name="hb-prev-w"][value="390"]').checked = true;
  fitPreview();
  schedulePreview();
}
$('.hb-save').addEventListener('click', async () => {
  const btn = $('.hb-save');
  btn.disabled = true;
  statusLine.textContent = 'Saving…';
  try {
    const res = await api('/admin/api/homepage', {
      sections: sections.map((s) => ({ id: s.id, layout: s.layout, title: s.title, span: s.span, active: s.active, settings: settingsOf(s) })),
    });
    res.ids.forEach((id, i) => { if (!sections[i].id) { sections[i].id = id; } });
    statusLine.textContent = `Saved ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}. The homepage shows the new order now.`;
    statusLine.dataset.saved = '1';
  } catch (err) {
    statusLine.textContent = err.message;
  } finally {
    btn.disabled = false;
  }
});

renderList();
renderForm();
list.dataset.ready = '1';
