// Shared by build-live.mjs and verify-live.mjs.
import { readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
export const LIVE = join(ROOT, '..', 'live');

// The server's own data: never overwritten, never compared.
export const SERVER_OWNED = ['app/config.php', 'uploads/', 'storage/'];
export const isServerOwned = (rel) =>
  rel === 'app/config.php' || ((rel.startsWith('uploads/') || rel.startsWith('storage/')) && !rel.endsWith('/.htaccess'));

export function listFiles(dir, base = dir) {
  let out = [];
  let names = [];
  try { names = readdirSync(dir); } catch { return out; }
  for (const name of names) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) out = out.concat(listFiles(full, base));
    else out.push(relative(base, full).replaceAll('\\', '/'));
  }
  return out;
}

/** Files under public/ at HEAD: Map of path inside public/ -> Buffer. */
export function commitFiles(git) {
  const list = git('ls-tree', '-r', '-z', '--name-only', 'HEAD', '--', 'public').toString().split('\0').filter(Boolean);
  const files = new Map();
  for (const path of list) files.set(path.slice('public/'.length), git('show', `HEAD:${path}`));
  return files;
}
