// Draws simple sample photos for the TEST database only (no real or copied pictures).
// Usage: node scripts/make-demo-images.mjs
// Output: tests/fixtures/demo/scene-N-{480,960,1600}.{avif,webp}  (3:2)
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import encodeAvif, { init as initAvif } from '@jsquash/avif/encode.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'tests', 'fixtures', 'demo');
const WIDTHS = [480, 960, 1600];
mkdirSync(OUT, { recursive: true });
await initAvif(await WebAssembly.compile(readFileSync(join(ROOT, 'node_modules/@jsquash/avif/codec/enc/avif_enc.wasm'))));

const browser = await chromium.launch();
const page = await browser.newPage();
const scenes = await page.evaluate(async (WIDTHS) => {
  const palettes = [
    ['#0b2545', '#8da9c4', '#13315c'], ['#2b2d42', '#ef8354', '#4f5d75'], ['#1b4332', '#95d5b2', '#2d6a4f'],
    ['#3d405b', '#f2cc8f', '#81b29a'], ['#14213d', '#fca311', '#e5e5e5'], ['#22223b', '#c9ada7', '#4a4e69'],
    ['#003049', '#eae2b7', '#d62828'], ['#264653', '#e9c46a', '#2a9d8f'], ['#1d3557', '#a8dadc', '#457b9d'],
    ['#3a0ca3', '#f72585', '#4cc9f0'], ['#283618', '#dda15e', '#606c38'], ['#212529', '#adb5bd', '#495057'],
  ];
  const kinds = ['jet', 'ship', 'tank', 'satellite', 'jet', 'ship', 'heli', 'tank', 'satellite', 'jet', 'heli', 'ship'];
  const draw = (x, W, H, pal, kind, seed) => {
    const g = x.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, pal[0]); g.addColorStop(0.65, pal[1]); g.addColorStop(1, pal[2]);
    x.fillStyle = g; x.fillRect(0, 0, W, H);
    let r = seed;
    const rnd = () => ((r = (r * 9301 + 49297) % 233280) / 233280);
    x.globalAlpha = 0.18; x.fillStyle = '#fff';
    for (let i = 0; i < 6; i++) { x.beginPath(); x.ellipse(rnd() * W, rnd() * H * 0.5, W * (0.08 + rnd() * 0.12), H * 0.03, 0, 0, Math.PI * 2); x.fill(); }
    x.globalAlpha = 1;
    x.fillStyle = pal[2]; x.fillRect(0, H * 0.78, W, H * 0.22);
    x.fillStyle = 'rgba(0,0,0,0.82)';
    const s = W / 1600;
    x.save(); x.translate(W * (0.3 + rnd() * 0.4), H * (kind === 'ship' || kind === 'tank' ? 0.74 : 0.38)); x.scale(s, s);
    x.beginPath();
    if (kind === 'jet') { x.rotate(-0.18); x.moveTo(-260, 0); x.lineTo(240, -10); x.lineTo(300, 0); x.lineTo(240, 10); x.lineTo(-260, 14); x.closePath(); x.moveTo(-20, 0); x.lineTo(-140, -150); x.lineTo(-80, -150); x.lineTo(80, 0); x.closePath(); x.moveTo(-20, 8); x.lineTo(-140, 150); x.lineTo(-80, 150); x.lineTo(80, 8); x.closePath(); x.moveTo(-250, 0); x.lineTo(-300, -80); x.lineTo(-260, -80); x.lineTo(-200, 0); x.closePath(); }
    if (kind === 'ship') { x.moveTo(-420, 0); x.lineTo(420, 0); x.lineTo(360, 70); x.lineTo(-380, 70); x.closePath(); x.rect(-160, -70, 220, 70); x.rect(-60, -140, 60, 70); x.rect(140, -40, 120, 40); }
    if (kind === 'tank') { x.roundRect(-260, -40, 520, 90, 40); x.rect(-150, -110, 260, 70); x.rect(100, -90, 320, 22); }
    if (kind === 'satellite') { x.rect(-60, -60, 120, 120); x.rect(-360, -30, 280, 60); x.rect(80, -30, 280, 60); }
    if (kind === 'heli') { x.ellipse(0, 0, 170, 70, 0, 0, Math.PI * 2); x.rect(140, -12, 260, 24); x.rect(-330, -110, 660, 10); x.rect(-6, -110, 12, 50); x.rect(-120, 70, 240, 8); }
    x.fill(); x.restore();
    x.fillStyle = 'rgba(255,255,255,0.75)'; x.font = `${Math.round(22 * s * 1.6)}px sans-serif`; x.textAlign = 'right';
    x.fillText('Sample photo', W - 24 * s * 1.6, H - 20 * s * 1.6);
  };
  const out = [];
  for (let n = 0; n < palettes.length; n++) {
    const sizes = [];
    for (const W of WIDTHS) {
      const H = Math.round(W * 2 / 3);
      const c = new OffscreenCanvas(W, H); const x = c.getContext('2d');
      draw(x, W, H, palettes[n], kinds[n], 1000 + n * 77);
      const webp = new Uint8Array(await (await c.convertToBlob({ type: 'image/webp', quality: 0.72 })).arrayBuffer());
      const raw = x.getImageData(0, 0, W, H).data;
      const b64 = (u) => { let s = ''; for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode(...u.subarray(i, i + 0x8000)); return btoa(s); };
      sizes.push({ W, H, webp: b64(webp), raw: b64(raw) });
    }
    out.push(sizes);
  }
  return out;
}, WIDTHS);
await browser.close();

let total = 0;
for (const [n, sizes] of scenes.entries()) {
  for (const s of sizes) {
    const base = join(OUT, `scene-${n + 1}-${s.W}`);
    const webp = Buffer.from(s.webp, 'base64');
    const avif = Buffer.from(await encodeAvif({ data: new Uint8ClampedArray(Buffer.from(s.raw, 'base64')), width: s.W, height: s.H }, { quality: 50, speed: 6 }));
    writeFileSync(base + '.webp', webp);
    writeFileSync(base + '.avif', avif);
    total += webp.length + avif.length;
  }
}
console.log(`${scenes.length} sample photos x ${WIDTHS.length} widths written to tests/fixtures/demo (${Math.round(total / 1024)} KB)`);
