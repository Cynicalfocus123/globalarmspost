// Shared by build-live.mjs and verify-live.mjs.
//
// live/ is what goes to Hostinger, in two folders (owner, 2026-11-12):
//   live/frontend/  -> upload INTO public_html: index.php, .htaccess, assets/ (CSS, JS, fonts, logo), uploads/
//                      (pictures), icons. Everything a browser loads is here; no code, no server data.
//   live/backend/   -> upload NEXT TO public_html (…/domains/globalarmspost.com/backend): app/ (all PHP code,
//                      config.php) and storage/ (cache, sign-in sessions, backups). No images or assets.
// git/public/ keeps everything in one folder; this file decides where each file of it goes.
import { readdirSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
// The main checkout (git/), also when this runs from a Claude worktree in git/.claude/worktrees/…:
// there is only one live/ folder, next to git/.
const mainRoot = () => {
  try {
    const common = execFileSync('git', ['rev-parse', '--path-format=absolute', '--git-common-dir'], { cwd: ROOT }).toString().trim();
    return dirname(common);
  } catch { return ROOT; }
};
export const MAIN = mainRoot();
export const LIVE = join(MAIN, '..', 'live');
export const FRONTEND = 'frontend';
export const BACKEND = 'backend';

/** Where a file of git/public goes inside live/: backend/app/…, backend/storage/… or frontend/…. */
export const livePath = (rel) => (rel.startsWith('app/') || rel.startsWith('storage/') ? `${BACKEND}/${rel}` : `${FRONTEND}/${rel}`);

// The server's own data: never overwritten, never compared (paths inside live/).
export const SERVER_OWNED = [`${BACKEND}/app/config.php`, `${FRONTEND}/uploads/`, `${BACKEND}/storage/`];
export const isServerOwned = (liveRel) =>
  liveRel === `${BACKEND}/app/config.php`
  || ((liveRel.startsWith(`${FRONTEND}/uploads/`) || liveRel.startsWith(`${BACKEND}/storage/`)) && !liveRel.endsWith('/.htaccess'));

export const BUILD_FILE = `${BACKEND}/app/build.php`;

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
