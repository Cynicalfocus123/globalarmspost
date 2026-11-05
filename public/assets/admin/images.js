// Picture pipeline (tasks B6, F15): the admin's browser makes every size as WebP (canvas) and AVIF (jSquash
// in a worker), then uploads them. Browsers that cannot make WebP (Safari) send the picture once and the
// server makes the sizes with PHP GD. The server checks every file by its content either way.
import { postForm } from './api.js';

const WIDTHS = { photo: [480, 960, 1600], avatar: [160, 320] };
const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

let worker = null;
let avifBroken = false;
let seq = 0;
const waiting = new Map();

function avifWorker() {
  if (!worker) {
    worker = new Worker(new URL('./avif-worker.js', import.meta.url), { type: 'module' });
    worker.onmessage = ({ data }) => {
      const w = waiting.get(data.id);
      waiting.delete(data.id);
      if (w) data.ok ? w.resolve(data.buf) : w.reject(new Error(data.error));
    };
    worker.onerror = () => {
      avifBroken = true;
      for (const w of waiting.values()) w.reject(new Error('AVIF is not available in this browser'));
      waiting.clear();
      worker = null;
    };
  }
  return worker;
}

function encodeAvif(img) {
  return new Promise((resolve, reject) => {
    const id = ++seq;
    waiting.set(id, { resolve, reject });
    const buf = img.data.buffer;
    avifWorker().postMessage({ id, buf, width: img.width, height: img.height }, [buf]);
    setTimeout(() => { if (waiting.delete(id)) reject(new Error('AVIF took too long')); }, 120_000);
  });
}

const toBlob = (canvas, type, q) => new Promise((r) => canvas.toBlob(r, type, q));

let webpOk;
async function canMakeWebp() {
  if (webpOk === undefined) {
    const c = document.createElement('canvas');
    c.width = c.height = 4;
    const b = await toBlob(c, 'image/webp', 0.8);
    webpOk = !!b && b.type === 'image/webp';
  }
  return webpOk;
}

/** Dashboard check: can this browser make AVIF? */
export async function browserAvif() {
  try {
    const c = document.createElement('canvas');
    c.width = c.height = 8;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#c8102e';
    ctx.fillRect(0, 0, 8, 8);
    const out = await encodeAvif(ctx.getImageData(0, 0, 8, 8));
    return out.byteLength > 0;
  } catch {
    return false;
  }
}

/**
 * Turn a picked/pasted/dropped file into a stored picture.
 * @returns the media row from the server: { id, path, widths, has_avif, width, height, thumb, src }
 */
export async function uploadPicture(file, { kind = 'photo', alt = '', onStatus = () => {} } = {}) {
  if (!TYPES.includes(file.type)) {
    // Some browsers (WebKit on Windows, some Safari pickers) give no type: read it from the first bytes.
    const type = sniffType(new Uint8Array(await file.slice(0, 12).arrayBuffer()));
    if (!type) throw new Error('Please choose a JPEG, PNG, WebP or GIF picture.');
    file = new File([file], file.name || 'picture', { type });
  }
  if (file.size > 20 * 1048576) throw new Error('This picture is larger than 20 MB.');
  let bitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    throw new Error('This file could not be read as a picture.');
  }
  const form = new FormData();
  form.append('kind', kind);
  form.append('name', file.name || 'picture');
  form.append('alt', alt);
  if (!(await canMakeWebp())) {
    onStatus('Sending the picture; the server makes the sizes…');
    form.append('source', file, file.name || 'picture');
    return postForm('/admin/api/media', form);
  }
  let widths = WIDTHS[kind].filter((w) => w <= bitmap.width);
  if (widths.length === 0) widths = [bitmap.width];
  let avif = !avifBroken;
  const avifParts = [];
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  for (const w of widths) {
    const h = Math.max(1, Math.round((bitmap.height * w) / bitmap.width));
    canvas.width = w;
    canvas.height = h;
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, 0, 0, w, h);
    onStatus(`Making WebP ${w} px…`);
    const webp = await toBlob(canvas, 'image/webp', 0.82);
    form.append(`webp_${w}`, webp, `p-${w}.webp`);
    if (avif) {
      onStatus(`Making AVIF ${w} px…`);
      try {
        const buf = await encodeAvif(ctx.getImageData(0, 0, w, h));
        avifParts.push([w, new Blob([buf], { type: 'image/avif' })]);
      } catch {
        avif = false; // WebP only; the site still works
      }
    }
  }
  if (avif) for (const [w, blob] of avifParts) form.append(`avif_${w}`, blob, `p-${w}.avif`);
  const big = widths[widths.length - 1];
  form.append('height', String(Math.max(1, Math.round((bitmap.height * big) / bitmap.width))));
  bitmap.close?.();
  onStatus('Uploading…');
  return postForm('/admin/api/media', form);
}

/** JPEG, PNG, GIF or WebP by the file's first bytes, or '' when it is none of them. */
function sniffType(b) {
  const ascii = (from, to) => String.fromCharCode(...b.slice(from, to));
  if (b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return 'image/jpeg';
  if (b[0] === 0x89 && ascii(1, 4) === 'PNG') return 'image/png';
  if (ascii(0, 4) === 'GIF8') return 'image/gif';
  if (ascii(0, 4) === 'RIFF' && ascii(8, 12) === 'WEBP') return 'image/webp';
  return '';
}

/** The picture files in a clipboard or drop (DataTransfer). Files with no type are checked by uploadPicture. */
export function imageFiles(dt) {
  let files = [...(dt?.files || [])];
  // Some browsers list a pasted picture only under items.
  if (!files.length) files = [...(dt?.items || [])].filter((i) => i.kind === 'file').map((i) => i.getAsFile()).filter(Boolean);
  return files.filter((f) => TYPES.includes(f.type) || f.type === '' || f.type === 'application/octet-stream');
}
