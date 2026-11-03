// Builds the site logo and icons from the owner's original logo (task F18).
// Usage: node scripts/build-logo.mjs
// Source (kept as is, never changed): ../site image/logo/globalarmspost-logo-original.webp
// Output: public/assets/img/logo/* and public/favicon.ico, public/apple-touch-icon.png, public/site.webmanifest
//
// Steps: remove the white background (flood fill from the edges, so white inside the logo stays),
// soften the cut edge, trim to the drawing, then write AVIF + WebP at 1x and 2x, a PNG fallback,
// and favicon / app icons cropped from the globe.
import { readFileSync, writeFileSync, mkdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import encodeAvif, { init as initAvif } from '@jsquash/avif/encode.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC = join(ROOT, '..', 'site image', 'logo', 'globalarmspost-logo-original.webp');
const OUT = join(ROOT, 'public', 'assets', 'img', 'logo');
const PUB = join(ROOT, 'public');

// Header logo shown at 240 x auto CSS px (2x = 480). Footer reuses the 1x files.
const LOGO_1X = 240;

mkdirSync(OUT, { recursive: true });
await initAvif(await WebAssembly.compile(readFileSync(join(ROOT, 'node_modules/@jsquash/avif/codec/enc/avif_enc.wasm'))));

const browser = await chromium.launch();
const page = await browser.newPage();
const src = 'data:image/webp;base64,' + readFileSync(SRC).toString('base64');

// Everything that needs a canvas runs in the page.
const result = await page.evaluate(async ({ src, LOGO_1X }) => {
  const img = new Image();
  img.src = src;
  await img.decode();
  const W = img.naturalWidth, H = img.naturalHeight;
  const c = new OffscreenCanvas(W, H);
  const x = c.getContext('2d', { willReadFrequently: true });
  x.drawImage(img, 0, 0);
  const id = x.getImageData(0, 0, W, H);
  const d = id.data;

  // 1. Flood fill the near-white background from every edge pixel.
  const isBg = (i) => Math.min(d[i], d[i + 1], d[i + 2]) > 232;
  const bg = new Uint8Array(W * H);
  const stack = [];
  for (let px = 0; px < W; px++) { stack.push(px, (H - 1) * W + px); }
  for (let py = 0; py < H; py++) { stack.push(py * W, py * W + W - 1); }
  while (stack.length) {
    const p = stack.pop();
    if (bg[p] || !isBg(p * 4)) continue;
    bg[p] = 1;
    const px = p % W, py = (p - px) / W;
    if (px > 0) stack.push(p - 1);
    if (px < W - 1) stack.push(p + 1);
    if (py > 0) stack.push(p - W);
    if (py < H - 1) stack.push(p + W);
  }
  // 2. Background -> transparent. Light pixels touching it are the anti-aliased edge of the black
  //    outline over white: give them partial alpha and the outline colour, so no white halo shows
  //    on dark mode.
  for (let p = 0; p < W * H; p++) {
    const i = p * 4;
    if (bg[p]) { d[i + 3] = 0; continue; }
    const px = p % W;
    const near = (px > 0 && bg[p - 1]) || (px < W - 1 && bg[p + 1]) || bg[p - W] || bg[p + W];
    if (near) {
      const m = Math.min(d[i], d[i + 1], d[i + 2]);
      if (m > 120) { const a = 1 - m / 255; d[i + 3] = Math.round(a * 255); d[i] = d[i + 1] = d[i + 2] = 0; }
    }
  }
  x.putImageData(id, 0, 0);

  // 3. Trim to the drawing (+ 2 px safety).
  let x0 = W, y0 = H, x1 = 0, y1 = 0;
  for (let py = 0; py < H; py++) for (let px = 0; px < W; px++) {
    if (d[(py * W + px) * 4 + 3] > 8) { if (px < x0) x0 = px; if (px > x1) x1 = px; if (py < y0) y0 = py; if (py > y1) y1 = py; }
  }
  x0 = Math.max(0, x0 - 2); y0 = Math.max(0, y0 - 2); x1 = Math.min(W - 1, x1 + 2); y1 = Math.min(H - 1, y1 + 2);
  const tw = x1 - x0 + 1, th = y1 - y0 + 1;

  // High quality downscale by halving steps.
  const scale = (srcCanvas, sx, sy, sw, sh, w, h, bgColor) => {
    let cur = new OffscreenCanvas(sw, sh);
    cur.getContext('2d').drawImage(srcCanvas, sx, sy, sw, sh, 0, 0, sw, sh);
    while (cur.width / 2 >= w) {
      const n = new OffscreenCanvas(Math.round(cur.width / 2), Math.round(cur.height / 2));
      const nx = n.getContext('2d'); nx.imageSmoothingQuality = 'high'; nx.drawImage(cur, 0, 0, n.width, n.height);
      cur = n;
    }
    const o = new OffscreenCanvas(w, h);
    const ox = o.getContext('2d');
    if (bgColor) { ox.fillStyle = bgColor; ox.fillRect(0, 0, w, h); }
    ox.imageSmoothingQuality = 'high';
    ox.drawImage(cur, 0, 0, w, h);
    return o;
  };
  const toB64 = async (canvas, type, quality) => {
    const blob = await canvas.convertToBlob({ type, quality });
    const buf = new Uint8Array(await blob.arrayBuffer());
    let s = ''; for (let i = 0; i < buf.length; i += 0x8000) s += String.fromCharCode(...buf.subarray(i, i + 0x8000));
    return btoa(s);
  };
  const raw = (canvas) => {
    const dd = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    let s = ''; for (let i = 0; i < dd.length; i += 0x8000) s += String.fromCharCode(...dd.subarray(i, i + 0x8000));
    return btoa(s);
  };

  const out = { trim: { x0, y0, tw, th, W, H }, files: {} };
  for (const k of [1, 2]) {
    const w = LOGO_1X * k, h = Math.round(th * w / tw);
    const cv = scale(c, x0, y0, tw, th, w, h);
    out.files[`logo-${k}x`] = { w, h, webp: await toB64(cv, 'image/webp', 0.86), png: await toB64(cv, 'image/png'), raw: raw(cv) };
  }

  // Globe icon: the globe and its rings sit in the left part of the trimmed logo (left ~34%).
  // Square crop around it, transparent for the favicon, white behind it for app icons.
  const gw = Math.round(tw * 0.335), side = Math.max(gw, th);
  const gx = x0, gy = y0 + Math.round((th - side) / 2);
  const icon = async (size, bgColor) => {
    const cv = scale(c, gx, gy, side, side, Math.round(size * 0.9), Math.round(size * 0.9));
    const o = new OffscreenCanvas(size, size); const ox = o.getContext('2d');
    if (bgColor) { ox.fillStyle = bgColor; ox.fillRect(0, 0, size, size); }
    ox.drawImage(cv, Math.round(size * 0.05), Math.round(size * 0.05));
    return toB64(o, 'image/png');
  };
  out.icons = { i16: await icon(16), i32: await icon(32), i48: await icon(48), i180: await icon(180, '#ffffff'), i192: await icon(192, '#ffffff'), i512: await icon(512, '#ffffff') };
  return out;
}, { src, LOGO_1X });
await browser.close();

const sizes = {};
const put = (path, buf) => { writeFileSync(path, buf); sizes[path.slice(ROOT.length + 1).replaceAll('\\', '/')] = buf.length; };
for (const [name, f] of Object.entries(result.files)) {
  put(join(OUT, `${name}.webp`), Buffer.from(f.webp, 'base64'));
  if (name === 'logo-1x') put(join(OUT, 'logo.png'), Buffer.from(f.png, 'base64'));
  const rgba = new Uint8ClampedArray(Buffer.from(f.raw, 'base64'));
  put(join(OUT, `${name}.avif`), Buffer.from(await encodeAvif({ data: rgba, width: f.w, height: f.h }, { quality: 60, qualityAlpha: 80, speed: 4 })));
}
put(join(PUB, 'apple-touch-icon.png'), Buffer.from(result.icons.i180, 'base64'));
put(join(OUT, 'icon-192.png'), Buffer.from(result.icons.i192, 'base64'));
put(join(OUT, 'icon-512.png'), Buffer.from(result.icons.i512, 'base64'));
put(join(OUT, 'favicon-32.png'), Buffer.from(result.icons.i32, 'base64'));

// favicon.ico holding 16, 32 and 48 px PNGs.
const pngs = [[16, result.icons.i16], [32, result.icons.i32], [48, result.icons.i48]].map(([s, b]) => [s, Buffer.from(b, 'base64')]);
const header = Buffer.alloc(6 + 16 * pngs.length);
header.writeUInt16LE(0, 0); header.writeUInt16LE(1, 2); header.writeUInt16LE(pngs.length, 4);
let offset = header.length;
pngs.forEach(([s, b], i) => {
  const e = 6 + i * 16;
  header.writeUInt8(s, e); header.writeUInt8(s, e + 1); header.writeUInt8(0, e + 2); header.writeUInt8(0, e + 3);
  header.writeUInt16LE(1, e + 4); header.writeUInt16LE(32, e + 6); header.writeUInt32LE(b.length, e + 8); header.writeUInt32LE(offset, e + 12);
  offset += b.length;
});
put(join(PUB, 'favicon.ico'), Buffer.concat([header, ...pngs.map(([, b]) => b)]));

put(join(PUB, 'site.webmanifest'), Buffer.from(JSON.stringify({
  name: 'Global Arms Post', short_name: 'GlobalArmsPost', start_url: '/', display: 'standalone',
  background_color: '#ffffff', theme_color: '#C8102E',
  icons: [
    { src: '/assets/img/logo/icon-192.png', sizes: '192x192', type: 'image/png' },
    { src: '/assets/img/logo/icon-512.png', sizes: '512x512', type: 'image/png' },
  ],
}, null, 2) + '\n'));

// Sizes for the templates (width/height attributes) and for the report.
const meta = { w1: result.files['logo-1x'].w, h1: result.files['logo-1x'].h, w2: result.files['logo-2x'].w, h2: result.files['logo-2x'].h };
put(join(OUT, 'logo.json'), Buffer.from(JSON.stringify(meta, null, 2) + '\n'));
console.log('original', statSync(SRC).size, 'bytes,', `${result.trim.W}x${result.trim.H}`, '-> trimmed', `${result.trim.tw}x${result.trim.th}`);
console.log(JSON.stringify({ meta, sizes }, null, 2));
