// Copies the self-hosted fonts from node_modules into public/assets/fonts (no font CDN).
// Usage: npm run fonts
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'public/assets/fonts');
mkdirSync(OUT, { recursive: true });
for (const [pkg, file] of [
  ['inter', 'inter-latin-wght-normal.woff2'],
  ['newsreader', 'newsreader-latin-wght-normal.woff2'],
]) {
  copyFileSync(join(ROOT, 'node_modules/@fontsource-variable', pkg, 'files', file), join(OUT, file));
  console.log('copied', file);
}
