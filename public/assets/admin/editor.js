// Story editor (tasks F13, F14, B19): headline, text toolbar, pictures anywhere in the body (toolbar, paste,
// drag and drop) each with caption + credit, publish box, category / tag / author pickers that add new ones
// on the spot (saved at once, no reload), main photo + gallery, search preview.
import { $, $$, openSheet, closeSheet } from './ui.js';
import { api, esc } from './api.js';
import { uploadPicture, imageFiles } from './images.js';

const data = JSON.parse($('#editor-data').textContent);
const form = $('#editor');
const body = $('#ed-body');
const st = data.story || {};
const state = {
  id: st.id || 0,
  state: st.state || 'draft',
  categoryId: st.category_id || 0,
  authorId: st.author_id || 0,
  tagIds: new Set(st.tag_ids || []),
  mediaId: st.media_id || 0,
  gallery: (st.gallery || []).map((g) => ({ ...g })),
  busy: 0,
};
const els = {
  title: $('#ed-title'), summary: $('#ed-summary'), excerpt: $('#ed-excerpt'), credits: $('#ed-credits'), date: $('#ed-date'),
  sticky: $('#ed-sticky'), live: $('#ed-live'), slug: $('#ed-slug'), saved: $('.ed-saved', form), save: $('.ed-save', form),
  error: $('.ed-error', form), convert: $('.ed-convert', form), stateText: $('.ed-state', form),
};

/* ---------- fill in (a new story keeps whatever was typed before this script loaded) ---------- */
if (data.story) {
  els.title.value = st.title || '';
  els.summary.value = st.summary || '';
  els.excerpt.value = st.excerpt || '';
  els.credits.value = st.credits || '';
  els.date.value = st.published_local || '';
  els.sticky.checked = !!st.sticky;
  els.live.checked = !!st.live_label;
  els.slug.value = st.slug || '';
  $(`input[name="status"][value="${st.status === 'published' ? 'published' : 'draft'}"]`, form).checked = true;
  body.innerHTML = st.body || '<p><br></p>';
} else if (!body.textContent.trim()) body.innerHTML = '<p><br></p>';
for (const fig of $$('figure[data-media]', body)) hydrateFigure(fig, data.body_media[fig.dataset.media] || '');
document.execCommand('defaultParagraphSeparator', false, 'p');

const autoGrow = () => { els.title.style.height = 'auto'; els.title.style.height = `${els.title.scrollHeight}px`; };
els.title.addEventListener('input', autoGrow);
requestAnimationFrame(autoGrow);

/* ---------- phone tabs: Write / Details / Photos ---------- */
form.dataset.active = 'write';
for (const tab of $$('.ed-tabs [role="tab"]', form)) {
  tab.addEventListener('click', () => {
    form.dataset.active = tab.dataset.show;
    for (const t of $$('.ed-tabs [role="tab"]', form)) {
      t.classList.toggle('on', t === tab);
      t.setAttribute('aria-selected', String(t === tab));
    }
    window.scrollTo({ top: 0 });
  });
}

/* ---------- body: toolbar ---------- */
let lastRange = null;
const inBody = (node) => node && (node === body || body.contains(node));
document.addEventListener('selectionchange', () => {
  const sel = getSelection();
  if (sel.rangeCount && inBody(sel.anchorNode) && !sel.anchorNode.closest?.('figure') && !sel.anchorNode.parentElement?.closest('figure')) lastRange = sel.getRangeAt(0).cloneRange();
});
function restoreRange() {
  const sel = getSelection();
  if (document.activeElement === body && sel.rangeCount && inBody(sel.anchorNode)) return; // the cursor is still in the text
  body.focus({ preventScroll: true });
  if (lastRange) {
    sel.removeAllRanges();
    sel.addRange(lastRange);
  }
}
const tool = $('.ed-tool', form);
tool.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); }); // keep the text selection
tool.addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  if (b.hasAttribute('data-picture')) return pickBodyPictures();
  restoreRange();
  if (b.dataset.cmd) document.execCommand(b.dataset.cmd, false, null);
  else if (b.dataset.block) {
    const current = (document.queryCommandValue('formatBlock') || '').toLowerCase().replace(/[<>]/g, '');
    document.execCommand('formatBlock', false, current === b.dataset.block ? 'p' : b.dataset.block);
  } else if (b.hasAttribute('data-link')) {
    const url = prompt('Link address (https://…)', 'https://');
    if (url && /^(https?:\/\/|mailto:|\/(?!\/))/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
  }
  changed();
});
body.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && ['b', 'i', 'u'].includes(e.key.toLowerCase())) {
    e.preventDefault();
    document.execCommand({ b: 'bold', i: 'italic', u: 'underline' }[e.key.toLowerCase()]);
  }
});
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault();
    save();
  }
});

/* ---------- body: pictures anywhere ---------- */
const bodyFile = $('#ed-body-file');
function pickBodyPictures() {
  bodyFile.click();
}
$('.ed-drop', form).addEventListener('click', pickBodyPictures);
bodyFile.addEventListener('change', () => {
  for (const f of bodyFile.files) addBodyPicture(f);
  bodyFile.value = '';
});
body.addEventListener('paste', (e) => {
  const files = imageFiles(e.clipboardData);
  if (files.length) {
    e.preventDefault();
    for (const f of files) addBodyPicture(f);
    return;
  }
  const html = e.clipboardData?.getData('text/html');
  const text = e.clipboardData?.getData('text/plain') || '';
  e.preventDefault();
  if (html) document.execCommand('insertHTML', false, cleanPaste(html));
  else document.execCommand('insertText', false, text);
});
for (const target of [body, $('.ed-drop', form)]) {
  target.addEventListener('dragover', (e) => {
    if ([...(e.dataTransfer?.types || [])].includes('Files')) {
      e.preventDefault();
      target.classList.add('drag-over');
    }
  });
  target.addEventListener('dragleave', () => target.classList.remove('drag-over'));
  target.addEventListener('drop', (e) => {
    target.classList.remove('drag-over');
    const files = imageFiles(e.dataTransfer);
    if (!files.length) return;
    e.preventDefault();
    if (target === body) {
      const r = document.caretRangeFromPoint?.(e.clientX, e.clientY)
        || (() => { const p = document.caretPositionFromPoint?.(e.clientX, e.clientY); if (!p) return null; const rr = document.createRange(); rr.setStart(p.offsetNode, p.offset); return rr; })();
      if (r && inBody(r.startContainer)) lastRange = r;
    }
    for (const f of files) addBodyPicture(f);
  });
}

/** Paste from other sites keeps only simple formatting (the server cleans again). */
function cleanPaste(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const keep = new Set(['P', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'H2', 'H3', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A']);
  const walk = (node) => {
    for (const child of [...node.childNodes]) {
      if (child.nodeType === 1) {
        walk(child);
        if (['SCRIPT', 'STYLE', 'IMG', 'IFRAME', 'svg', 'META', 'LINK'].includes(child.nodeName)) child.remove();
        else if (!keep.has(child.nodeName)) child.replaceWith(...child.childNodes);
        else for (const a of [...child.attributes]) if (!(child.nodeName === 'A' && a.name === 'href')) child.removeAttribute(a.name);
      } else if (child.nodeType !== 3) child.remove();
    }
  };
  walk(doc.body);
  return doc.body.innerHTML;
}

/** The top-level block of the body that holds a node. */
function topBlock(node) {
  while (node && node.parentNode !== body) node = node.parentNode;
  return node && node.parentNode === body ? node : null;
}

function insertFigure(fig) {
  const block = lastRange ? topBlock(lastRange.startContainer) : null;
  if (block) {
    const empty = block.nodeName === 'P' && block.textContent.trim() === '' && !block.querySelector('figure');
    if (empty) block.replaceWith(fig);
    else block.after(fig);
  } else body.append(fig);
  if (!fig.nextElementSibling) fig.after(Object.assign(document.createElement('p'), { innerHTML: '<br>' }));
  // The cursor goes after the picture, so the next picture or text follows it.
  const after = fig.nextElementSibling;
  const r = document.createRange();
  r.setStart(after, 0);
  r.collapse(true);
  lastRange = r;
  if (document.activeElement === body) {
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(r);
  }
}

function hydrateFigure(fig, src) {
  const caption = fig.dataset.caption || '';
  const credit = fig.dataset.credit || '';
  fig.className = `ed-fig fig-${fig.dataset.size === 'inline' ? 'inline' : 'full'}`;
  fig.dataset.size = fig.dataset.size === 'inline' ? 'inline' : 'full';
  fig.contentEditable = 'false';
  fig.innerHTML = `<div class="ed-fig-img">${src ? `<img src="${esc(src)}" alt="">` : ''}<span class="ed-fig-status" hidden></span></div>
    <div class="ed-imgbar" role="toolbar" aria-label="Picture tools">
      <button type="button" data-act="full" aria-pressed="false">↔ Full width</button>
      <button type="button" data-act="inline" aria-pressed="false">Inline</button>
      <button type="button" data-act="replace">Replace</button>
      <button type="button" data-act="up" aria-label="Move picture up">⇡ Up</button>
      <button type="button" data-act="down" aria-label="Move picture down">⇣ Down</button>
      <button type="button" data-act="remove" class="danger">Remove</button>
    </div>
    <input class="ed-cap" placeholder="Caption: type the caption for this picture" aria-label="Picture caption" maxlength="600">
    <input class="ed-credit" placeholder="Credit: Photo by …" aria-label="Picture credit" maxlength="200">`;
  $('.ed-cap', fig).value = caption;
  $('.ed-credit', fig).value = credit;
  markSize(fig);
  return fig;
}
const markSize = (fig) => {
  for (const b of $$('[data-act="full"], [data-act="inline"]', fig)) b.setAttribute('aria-pressed', String(b.dataset.act === fig.dataset.size));
  fig.classList.toggle('fig-inline', fig.dataset.size === 'inline');
  fig.classList.toggle('fig-full', fig.dataset.size !== 'inline');
};

body.addEventListener('click', (e) => {
  const b = e.target.closest('.ed-imgbar button');
  if (!b) return;
  const fig = b.closest('figure');
  const act = b.dataset.act;
  if (act === 'full' || act === 'inline') {
    fig.dataset.size = act;
    markSize(fig);
  } else if (act === 'up' && fig.previousElementSibling) fig.previousElementSibling.before(fig);
  else if (act === 'down' && fig.nextElementSibling) fig.nextElementSibling.after(fig);
  else if (act === 'remove') fig.remove();
  else if (act === 'replace') {
    const input = Object.assign(document.createElement('input'), { type: 'file', accept: bodyFile.accept });
    input.addEventListener('change', () => input.files[0] && uploadInto(fig, input.files[0]));
    input.click();
  }
  changed();
});
// Typing in a caption must not be treated as typing in the story text.
body.addEventListener('keydown', (e) => {
  if (!e.target.matches('.ed-cap, .ed-credit')) return;
  e.stopPropagation();
  if (e.key === 'Enter') e.preventDefault(); // Enter in a caption does not save the form
}, true);
body.addEventListener('paste', (e) => { if (e.target.matches?.('.ed-cap, .ed-credit')) e.stopImmediatePropagation(); }, true);

function addBodyPicture(file) {
  const fig = hydrateFigure(Object.assign(document.createElement('figure'), {}), '');
  fig.dataset.media = '';
  insertFigure(fig);
  uploadInto(fig, file);
}

async function uploadInto(fig, file) {
  const status = $('.ed-fig-status', fig);
  const holder = $('.ed-fig-img', fig);
  const preview = URL.createObjectURL(file);
  let img = $('img', holder);
  if (!img) holder.prepend(img = Object.assign(document.createElement('img'), { alt: '' }));
  img.src = preview;
  fig.dataset.uploading = '1';
  status.hidden = false;
  busy(+1);
  try {
    const m = await uploadPicture(file, { onStatus: (t) => { status.textContent = t; } });
    fig.dataset.media = String(m.id);
    img.src = m.src;
    status.hidden = true;
    delete fig.dataset.uploading;
    changed();
  } catch (err) {
    status.textContent = err.message;
    fig.dataset.failed = '1';
    delete fig.dataset.uploading;
  } finally {
    URL.revokeObjectURL(preview);
    busy(-1);
  }
}

function busy(d) {
  state.busy += d;
  els.convert.hidden = state.busy === 0;
  els.convert.textContent = state.busy ? `Making ${state.busy} picture${state.busy > 1 ? 's' : ''} into AVIF + WebP in your browser…` : '';
  els.save.disabled = state.busy > 0;
}

/** The body as stored: text + <figure data-media data-size data-caption data-credit></figure>. */
function bodyHtml() {
  const copy = body.cloneNode(true);
  const origs = $$('figure', body);
  $$('figure', copy).forEach((fig, i) => {
    const orig = origs[i];
    if (!fig.dataset.media || fig.dataset.failed) { fig.remove(); return; }
    const out = document.createElement('figure');
    out.dataset.media = fig.dataset.media;
    out.dataset.size = fig.dataset.size === 'inline' ? 'inline' : 'full';
    out.dataset.caption = $('.ed-cap', orig).value.trim();
    out.dataset.credit = $('.ed-credit', orig).value.trim();
    fig.replaceWith(out);
  });
  return copy.innerHTML;
}

/* ---------- publish box ---------- */
const status = () => $('input[name="status"]:checked', form).value;
function localNow() {
  const d = new Date(Date.now() + data.tz_offset_ms);
  return d.toISOString().slice(0, 16);
}
function updatePublish() {
  const s = status();
  const future = els.date.value && els.date.value > localNow();
  let label = 'Save draft';
  let text = 'Draft: only people signed in here can see it.';
  if (s === 'published') {
    if (future) {
      label = 'Schedule';
      text = `Scheduled: goes live on ${els.date.value.replace('T', ' at ')}.`;
    } else {
      label = state.state === 'published' ? 'Update' : 'Publish';
      text = els.date.value ? 'Published (live on the site).' : 'Goes live now when you press Publish.';
    }
  }
  setButtonLabel(els.save, label);
  els.stateText.textContent = text;
}
// WebKit (Safari) loses the click on a button whose text is replaced between the press and the release:
// the date field fires its `change` on blur, in the middle of the click on Save, and the label change
// swallowed the click, so nothing was saved. Write the new text into the text node instead of replacing
// it, and only when it really changes (no needless DOM work on every keystroke).
function setButtonLabel(button, label) {
  if (button.textContent === label) return;
  if (button.firstChild && button.firstChild.nodeType === 3) button.firstChild.nodeValue = label;
  else button.textContent = label;
}
// The site's offset from UTC (for "is this date in the future?"), from the editor's time zone label.
data.tz_offset_ms = (() => {
  const m = /GMT([+-])(\d+)(?::(\d+))?/.exec(data.tz || '');
  return m ? (m[1] === '-' ? -1 : 1) * (Number(m[2]) * 60 + Number(m[3] || 0)) * 60000 : 0;
})();
form.addEventListener('change', (e) => { if (e.target.name === 'status' || e.target === els.date) updatePublish(); });
els.date.addEventListener('input', updatePublish);
updatePublish();

$('.ed-trash', form).addEventListener('click', async () => {
  if (!state.id || !confirm('Move this story to the trash? It leaves the site at once.')) return;
  try {
    await api('/admin/api/stories-status', { ids: [state.id], action: 'trash' });
    location.href = '/admin/stories?tab=trash';
  } catch (err) {
    showError(err.message);
  }
});

/* ---------- category: search, choose, add on the spot ---------- */
const catList = $('.ed-cat-list', form);
const catSearch = $('.ed-cat-search', form);
function renderCategories() {
  const q = catSearch.value.trim().toLowerCase();
  catList.innerHTML = data.categories.map((c) => {
    const parent = data.categories.find((p) => p.id === c.parent_id);
    const show = !q || c.name.toLowerCase().includes(q) || (parent && parent.name.toLowerCase().includes(q));
    return `<label class="a-check depth-${Math.min(3, c.depth)}"${show ? '' : ' hidden'}><input type="radio" name="category" value="${c.id}"${c.id === state.categoryId ? ' checked' : ''}> ${esc(c.name)}${parent ? ` <span class="a-mute">(${esc(parent.name)})</span>` : ''}</label>`;
  }).join('');
  $('#ed-cat-parent').innerHTML = '<option value="0">Top level</option>' + data.categories.map((c) => `<option value="${c.id}">${'— '.repeat(c.depth)}${esc(c.name)}</option>`).join('');
}
catSearch.addEventListener('input', renderCategories);
catList.addEventListener('change', (e) => { state.categoryId = Number(e.target.value); seo(); });
const catAdd = $('.ed-cat-add', form);
$('.ed-cat-add-open', form).addEventListener('click', () => {
  catAdd.hidden = false;
  $('#ed-cat-name').value = catSearch.value.trim();
  $('#ed-cat-name').focus();
});
$('.ed-cat-cancel', form).addEventListener('click', () => { catAdd.hidden = true; });
async function addCategory() {
  const name = $('#ed-cat-name').value.trim();
  if (!name) return $('#ed-cat-name').focus();
  try {
    const c = await api('/admin/api/category', { name, parent_id: Number($('#ed-cat-parent').value) });
    if (!data.categories.some((x) => x.id === c.id)) {
      // Into the list under its parent, at the end of the parent's children.
      const parent = data.categories.findIndex((x) => x.id === c.parent_id);
      const depth = parent >= 0 ? data.categories[parent].depth + 1 : 0;
      let at = data.categories.length;
      if (parent >= 0) { at = parent + 1; while (at < data.categories.length && data.categories[at].depth >= depth) at++; }
      data.categories.splice(at, 0, { ...c, depth });
    }
    state.categoryId = c.id;
    catSearch.value = '';
    catAdd.hidden = true;
    renderCategories();
    seo();
  } catch (err) {
    showError(err.message);
  }
}
$('.ed-cat-save', form).addEventListener('click', addCategory);
$('#ed-cat-name').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addCategory(); } });
renderCategories();

/* ---------- combobox (tags and authors) ---------- */
function combo(input, list, { options, onPick, addLabel, onAdd, emptyText }) {
  let active = -1;
  let items = [];
  const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; input.removeAttribute('aria-activedescendant'); };
  const render = () => {
    const q = input.value.trim();
    items = options(q);
    const exact = items.some((o) => o.name.toLowerCase() === q.toLowerCase());
    const add = addLabel(q, exact);
    let html = items.map((o, i) => `<div class="a-opt" role="option" id="${list.id}-${i}" data-i="${i}">${o.avatar !== undefined ? (o.avatar ? `<img class="a-av" src="${esc(o.avatar)}" alt="">` : `<span class="a-av">${esc(o.name[0] || '?')}</span>`) : ''}${esc(o.name)}</div>`).join('');
    if (!items.length && emptyText) html += `<div class="a-opt none">${esc(emptyText(q))}</div>`;
    if (add) html += `<div class="a-opt a-add" role="option" id="${list.id}-add" data-i="add">${esc(add)}</div>`;
    list.innerHTML = html;
    list.hidden = html === '';
    input.setAttribute('aria-expanded', String(!list.hidden));
    active = -1;
  };
  const choose = (i) => {
    if (i === 'add') onAdd(input.value.trim());
    else if (items[i]) onPick(items[i]);
    close();
  };
  const mark = () => {
    const opts = $$('[role="option"]', list);
    opts.forEach((o, i) => o.classList.toggle('on', i === active));
    if (opts[active]) input.setAttribute('aria-activedescendant', opts[active].id);
  };
  // Close after leaving the box, but not when focus came back in time (a stale timer would hide a fresh
  // list) or while a finger or mouse is pressing an option (WebKit moves focus on touch).
  let pressing = false;
  const closeIfLeft = () => { if (!pressing && document.activeElement !== input) close(); };
  input.addEventListener('input', render);
  input.addEventListener('focus', render);
  input.addEventListener('blur', () => setTimeout(closeIfLeft, 150));
  input.addEventListener('keydown', (e) => {
    const opts = $$('[role="option"]', list);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (list.hidden) render();
      active = Math.max(0, Math.min(opts.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1)));
      mark();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (active >= 0 && opts[active]) choose(opts[active].dataset.i);
      else {
        const q = input.value.trim().toLowerCase();
        const hit = items.findIndex((o) => o.name.toLowerCase() === q);
        if (hit >= 0) choose(hit);
        else if ($('[data-i="add"]', list)) choose('add');
      }
    } else if (e.key === 'Escape') close();
  });
  // Keep focus in the input by cancelling mousedown. Not pointerdown: on a touch WebKit then sends no click.
  list.addEventListener('pointerdown', () => { pressing = true; });
  list.addEventListener('mousedown', (e) => e.preventDefault());
  for (const type of ['pointerup', 'pointercancel']) {
    document.addEventListener(type, () => { if (pressing) setTimeout(() => { pressing = false; closeIfLeft(); }, 300); });
  }
  list.addEventListener('click', (e) => {
    const o = e.target.closest('[data-i]');
    if (o) choose(o.dataset.i);
  });
  return { render, close };
}

/* Tags: chips + "Add new tag: <name>" when it does not exist (saved at once, no reload). */
const chips = $('.ed-chips', form);
const tagInput = $('#ed-tag-input');
function renderChips() {
  chips.innerHTML = [...state.tagIds].map((id) => data.tags.find((t) => t.id === id)).filter(Boolean)
    .map((t) => `<span class="chip" data-id="${t.id}">${esc(t.name)} <button type="button" aria-label="Remove tag ${esc(t.name)}">×</button></span>`).join('');
}
chips.addEventListener('click', (e) => {
  const chip = e.target.closest('button') && e.target.closest('.chip');
  if (chip) { state.tagIds.delete(Number(chip.dataset.id)); renderChips(); changed(); }
});
const tagCombo = combo(tagInput, $('#ed-tag-list'), {
  options: (q) => data.tags.filter((t) => !state.tagIds.has(t.id) && (!q || t.name.toLowerCase().includes(q.toLowerCase()))).slice(0, 8),
  addLabel: (q, exact) => (q && !exact && !data.tags.some((t) => t.name.toLowerCase() === q.toLowerCase()) ? `＋ Add new tag: "${q}"` : ''),
  emptyText: (q) => (q ? 'No tag found' : ''),
  onPick: (t) => { state.tagIds.add(t.id); tagInput.value = ''; renderChips(); changed(); },
  onAdd: async (name) => {
    try {
      const t = await api('/admin/api/tag', { name });
      if (!data.tags.some((x) => x.id === t.id)) data.tags.push({ id: t.id, name: t.name });
      data.tags.sort((a, b) => a.name.localeCompare(b.name));
      state.tagIds.add(t.id);
      tagInput.value = '';
      renderChips();
      changed();
      flashNote(`Tag "${t.name}" ${t.created ? 'saved' : 'added'}.`);
    } catch (err) {
      showError(err.message);
    }
  },
});
renderChips();

/* Author: search, choose, or "Add new author" (name, email, profile picture), saved at once. */
const authorInput = $('#ed-author-input');
const authorSheet = $('#author-sheet');
let newAuthorMedia = 0;
const authorName = () => data.authors.find((a) => a.id === state.authorId)?.name || '';
authorInput.value = authorName();
combo(authorInput, $('#ed-author-list'), {
  options: (q) => {
    const typed = q && q !== authorName() ? q.toLowerCase() : '';
    return data.authors.filter((a) => !typed || a.name.toLowerCase().includes(typed)).slice(0, 8);
  },
  addLabel: () => '＋ Add new author',
  emptyText: () => 'No other match',
  onPick: (a) => { state.authorId = a.id; authorInput.value = a.name; changed(); },
  onAdd: (q) => {
    $('#ed-author-name').value = q && q !== authorName() ? q : '';
    $('#ed-author-email').value = '';
    $('.ed-author-error', form).hidden = true;
    newAuthorMedia = 0;
    $('.ed-author-pic', form).innerHTML = '＋';
    openSheet(authorSheet);
    $('#ed-author-name').focus();
  },
});
authorInput.addEventListener('blur', () => setTimeout(() => { if (!authorInput.value.trim()) state.authorId = 0; else if (authorInput.value !== authorName()) authorInput.value = authorName(); }, 200));
$('.ed-author-pic', form).addEventListener('click', () => $('#ed-author-file').click());
$('#ed-author-file').addEventListener('change', async (e) => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f) return;
  const btn = $('.ed-author-pic', form);
  const save = $('.ed-author-save', form);
  btn.innerHTML = '…';
  save.disabled = true;
  try {
    const m = await uploadPicture(f, { kind: 'avatar' });
    newAuthorMedia = m.id;
    btn.innerHTML = `<img src="${esc(m.thumb)}" alt="">`;
  } catch (err) {
    btn.innerHTML = '＋';
    authorError(err.message);
  } finally {
    save.disabled = false;
  }
});
const authorError = (t) => { const p = $('.ed-author-error', form); p.textContent = t; p.hidden = false; };
$('.ed-author-save', form).addEventListener('click', async () => {
  const name = $('#ed-author-name').value.trim();
  if (!name) { authorError('Please type the author\'s name.'); return; }
  try {
    const a = await api('/admin/api/author', { name, email: $('#ed-author-email').value.trim(), media_id: newAuthorMedia });
    data.authors.push({ id: a.id, name: a.name, email: a.email, avatar: a.avatar });
    data.authors.sort((x, y) => x.name.localeCompare(y.name));
    state.authorId = a.id;
    authorInput.value = a.name;
    closeSheet(authorSheet);
    changed();
    flashNote(`Author "${a.name}" saved and chosen.`);
  } catch (err) {
    authorError(err.message);
  }
});

/* ---------- main photo + gallery ---------- */
const photoBox = $('.ed-main-photo', form);
const photoCaption = $('#ed-photo-caption');
const photoCredit = $('#ed-photo-credit');
photoCaption.value = st.featured_caption || '';
photoCredit.value = st.featured_credit || '';
function renderPhoto(src) {
  photoBox.innerHTML = src ? `<img src="${esc(src)}" alt="">` : '<div class="ed-nophoto">No main photo</div>';
  $('.ed-photo-remove', form).hidden = !state.mediaId;
  $('.ed-photo-pick', form).textContent = state.mediaId ? 'Replace main photo' : 'Choose main photo';
}
renderPhoto(st.media_src || '');
$('.ed-photo-pick', form).addEventListener('click', () => $('#ed-photo-file').click());
$('.ed-photo-remove', form).addEventListener('click', () => { state.mediaId = 0; renderPhoto(''); changed(); });
$('#ed-photo-file').addEventListener('change', async (e) => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f) return;
  const preview = URL.createObjectURL(f);
  renderPhoto(preview);
  photoBox.insertAdjacentHTML('beforeend', '<span class="ed-fig-status">Converting…</span>');
  busy(+1);
  try {
    const m = await uploadPicture(f, { onStatus: (t) => { const s = $('.ed-fig-status', photoBox); if (s) s.textContent = t; } });
    state.mediaId = m.id;
    renderPhoto(m.src);
    changed();
  } catch (err) {
    renderPhoto('');
    showError(err.message);
  } finally {
    URL.revokeObjectURL(preview);
    busy(-1);
  }
});

const galleryList = $('.ed-gallery', form);
function renderGallery() {
  galleryList.innerHTML = state.gallery.map((g, i) => `<li class="ed-gal" data-i="${i}">
      <img src="${esc(g.src)}" alt="">
      <div class="grow"><input class="g-cap" aria-label="Gallery photo ${i + 1} caption" placeholder="Caption" value="${esc(g.caption)}" maxlength="600">
      <input class="g-credit" aria-label="Gallery photo ${i + 1} credit" placeholder="Photo credit" value="${esc(g.credit)}" maxlength="200">
      <div class="a-row"><button type="button" class="a-btn2 sm" data-g="up" aria-label="Move up">↑</button><button type="button" class="a-btn2 sm" data-g="down" aria-label="Move down">↓</button><button type="button" class="a-linkbtn danger" data-g="remove">Remove</button></div></div>
    </li>`).join('');
}
galleryList.addEventListener('input', (e) => {
  const li = e.target.closest('li');
  const g = state.gallery[Number(li.dataset.i)];
  if (e.target.classList.contains('g-cap')) g.caption = e.target.value;
  if (e.target.classList.contains('g-credit')) g.credit = e.target.value;
});
galleryList.addEventListener('click', (e) => {
  const b = e.target.closest('[data-g]');
  if (!b) return;
  const i = Number(b.closest('li').dataset.i);
  const g = state.gallery;
  if (b.dataset.g === 'remove') g.splice(i, 1);
  if (b.dataset.g === 'up' && i > 0) [g[i - 1], g[i]] = [g[i], g[i - 1]];
  if (b.dataset.g === 'down' && i < g.length - 1) [g[i + 1], g[i]] = [g[i], g[i + 1]];
  renderGallery();
  changed();
});
$('.ed-gallery-add', form).addEventListener('click', () => $('#ed-gallery-file').click());
$('#ed-gallery-file').addEventListener('change', async (e) => {
  const files = [...e.target.files];
  e.target.value = '';
  for (const f of files) {
    busy(+1);
    try {
      const m = await uploadPicture(f);
      state.gallery.push({ media_id: m.id, caption: '', credit: '', src: m.thumb });
      renderGallery();
      changed();
    } catch (err) {
      showError(err.message);
    } finally {
      busy(-1);
    }
  }
});
renderGallery();

/* ---------- search preview ---------- */
function seo() {
  const cat = data.categories.find((c) => c.id === state.categoryId);
  const slug = els.slug.value.trim() || slugify(els.title.value) || 'story';
  const path = data.link_mode === 'path' ? `/${cat ? cat.slug : 'category'}/${slug}` : `/#news/${slug}`;
  $('.seo-url', form).textContent = data.site + path;
  $('.seo-title', form).textContent = els.title.value.trim() || 'Headline goes here';
  const desc = els.excerpt.value.trim() || body.innerText.replace(/\s+/g, ' ').trim();
  $('.seo-desc', form).textContent = desc.length > 158 ? `${desc.slice(0, 155)}…` : desc;
}
const slugify = (t) => t.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 100);
for (const el of [els.title, els.excerpt, els.slug]) el.addEventListener('input', seo);
body.addEventListener('input', seo);
seo();

/* ---------- save ---------- */
let dirty = false;
function changed() { dirty = true; if (state.id) els.saved.textContent = 'Not saved'; }
form.addEventListener('input', changed);
function showError(text) {
  els.error.textContent = text;
  els.error.hidden = !text;
  if (text) els.error.scrollIntoView({ block: 'nearest' });
}
let noteTimer;
function flashNote(text) {
  els.saved.textContent = text;
  clearTimeout(noteTimer);
  noteTimer = setTimeout(() => { els.saved.textContent = dirty ? 'Not saved' : 'Saved'; }, 3000);
}

async function save() {
  if (state.busy) return showError('Wait until the pictures are ready.');
  showError('');
  if (!els.title.value.trim()) { form.dataset.active = 'write'; els.title.focus(); return showError('Please write a headline.'); }
  if (!state.categoryId) return showError('Please choose a category (Details).');
  els.save.disabled = true;
  els.saved.textContent = 'Saving…';
  try {
    const res = await api('/admin/api/story', {
      id: state.id, title: els.title.value, body: bodyHtml(), excerpt: els.excerpt.value, summary: els.summary.value,
      credits: els.credits.value, status: status(), published_local: els.date.value, sticky: els.sticky.checked,
      live_label: els.live.checked, category_id: state.categoryId, author_id: state.authorId, tag_ids: [...state.tagIds],
      media_id: state.mediaId, featured_caption: photoCaption.value, featured_credit: photoCredit.value,
      gallery: state.gallery.map(({ media_id, caption, credit }) => ({ media_id, caption, credit })), slug: els.slug.value,
    });
    const first = !state.id;
    state.id = res.id;
    state.state = res.state;
    els.date.value = res.published_local;
    els.slug.value = res.slug;
    dirty = false;
    const t = new Date();
    els.saved.textContent = `Saved ${t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} · ${{ draft: 'Draft', scheduled: 'Scheduled', published: 'Published' }[res.state] || res.state}`;
    els.saved.dataset.state = res.state;
    form.dataset.storyId = String(res.id);
    const preview = $('.ed-preview', form);
    preview.href = `/admin/preview/${res.id}`;
    preview.hidden = false;
    $('.ed-trash', form).hidden = false;
    if (first) history.replaceState(null, '', `/admin/stories/${res.id}`);
    updatePublish();
    seo();
  } catch (err) {
    els.saved.textContent = 'Not saved';
    showError(err.message);
  } finally {
    els.save.disabled = state.busy > 0;
  }
}
form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
form.dataset.ready = '1'; // tests wait for this
