// Page editor (task F24): the text toolbar (bold, italic, subheads, lists, link) on the page's text, the text copied
// into the form on Save (the server cleans it with the story's allow-list), and a confirm before "Delete page".
import { $ } from './ui.js';

const form = $('#page-form');
const body = $('#pg-body');
const field = $('#pg-body-field');
document.execCommand('defaultParagraphSeparator', false, 'p');

let lastRange = null;
document.addEventListener('selectionchange', () => {
  const sel = getSelection();
  if (sel.rangeCount && body.contains(sel.anchorNode)) lastRange = sel.getRangeAt(0).cloneRange();
});
const tool = $('.ed-tool', form);
tool.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); }); // keep the selection
tool.addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  body.focus({ preventScroll: true });
  if (lastRange) { const sel = getSelection(); sel.removeAllRanges(); sel.addRange(lastRange); }
  if (b.dataset.cmd) document.execCommand(b.dataset.cmd, false, null);
  else if (b.dataset.block) {
    const current = (document.queryCommandValue('formatBlock') || '').toLowerCase().replace(/[<>]/g, '');
    document.execCommand('formatBlock', false, current === b.dataset.block ? 'p' : b.dataset.block);
  } else if (b.hasAttribute('data-link')) {
    const url = prompt('Link address (https://…, mailto:… or /page)', 'https://');
    if (url && /^(https?:\/\/|mailto:|\/(?!\/))/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
  }
});
body.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && ['b', 'i'].includes(e.key.toLowerCase())) {
    e.preventDefault();
    document.execCommand(e.key.toLowerCase() === 'b' ? 'bold' : 'italic');
  }
});
// Pasted text keeps its words, not another site's styles.
body.addEventListener('paste', (e) => {
  e.preventDefault();
  document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
});
form.addEventListener('submit', () => { field.value = body.innerHTML; });

const del = $('.page-delete');
if (del) del.addEventListener('submit', (e) => { if (!confirm(del.dataset.confirm)) e.preventDefault(); });
form.dataset.ready = '1'; // tests wait for this
