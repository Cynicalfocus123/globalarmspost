// Admin: sheets from the bottom (phones), bulk choose in the stories list, dashboard browser check,
// author picture upload; the editor and the homepage builder load their own modules.
// /assets/admin/ is served with no-cache (its .htaccess), so modules that import each other are always current.
import './ui.js';

import { $, $$ } from './ui.js';

/* Stories list: tick stories, then publish / move to trash at once. */
const bulkForm = $('.a-bulk-form');
if (bulkForm) {
  const bar = $('.a-bulk', bulkForm);
  const boxes = () => $$('input[name="ids[]"]', bulkForm);
  const update = () => {
    const n = boxes().filter((b) => b.checked).length;
    bar.hidden = n === 0;
    $('.a-bulk-n', bar).textContent = `${n} chosen`;
    $('.check-all', bulkForm).checked = n > 0 && n === boxes().length;
  };
  bulkForm.addEventListener('change', (e) => {
    if (e.target.classList.contains('check-all')) for (const b of boxes()) b.checked = e.target.checked;
    update();
  });
  update(); // boxes ticked before this script ran (or kept by the browser) count too
  bulkForm.addEventListener('submit', (e) => {
    if (e.submitter?.value === 'trash' && !confirm('Move the chosen stories to the trash?')) e.preventDefault();
  });
}

/* Dashboard: can this browser make AVIF pictures? */
const avifCheck = $('.browser-check[data-check="avif"]');
if (avifCheck) {
  import('./images.js').then(async ({ browserAvif }) => {
    const ok = await browserAvif();
    avifCheck.classList.add(ok ? 'ok' : 'bad');
    avifCheck.firstElementChild.textContent = ok ? '✓' : '!';
    if (!ok) avifCheck.insertAdjacentHTML('beforeend', ' <small>pictures are made as WebP here</small>');
  });
}

/* Author page: profile picture. */
const authorForm = $('.author-form');
if (authorForm) {
  const file = $('.author-pic-file', authorForm);
  const status = $('.author-pic-status', authorForm);
  $('.author-pic-pick', authorForm).addEventListener('click', () => file.click());
  const upload = async () => {
    const f = file.files[0];
    if (!f) return;
    const { uploadPicture } = await import('./images.js');
    const save = $('button[type="submit"]', authorForm);
    save.disabled = true;
    try {
      const m = await uploadPicture(f, { kind: 'avatar', onStatus: (t) => { status.textContent = t; } });
      $('.author-media', authorForm).value = m.id;
      const prev = $('.author-pic-preview', authorForm);
      const img = Object.assign(document.createElement('img'), { src: m.thumb, alt: '', width: 64, height: 64, className: 'a-av big author-pic-preview' });
      prev.replaceWith(img);
      status.textContent = 'Picture ready. Save the author to keep it.';
    } catch (err) {
      status.textContent = err.message;
    } finally {
      save.disabled = false;
      file.value = '';
    }
  };
  file.addEventListener('change', upload);
  if (file.files.length) upload(); // a picture chosen before this script ran
}

if ($('#editor')) import('./editor.js');
if ($('#builder-data')) import('./builder.js');
