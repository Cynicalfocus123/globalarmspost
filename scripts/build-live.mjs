// Builds live/ (what goes to Hostinger) from the files of the current git commit, in two folders:
//   live/frontend/  -> upload INTO public_html (index.php, .htaccess, assets/, uploads/, icons)
//   live/backend/   -> upload NEXT TO public_html (app/ = the code and config.php, storage/ = server data)
// Usage: npm run build:live          (needs a clean public/ folder, so live/ is exactly one commit)
//
// Hard rules: never delete anything (files only added or overwritten; stale ones are listed),
// never overwrite the server's own data (backend/app/config.php, frontend/uploads/, backend/storage/),
// and live/ uses proper addresses (backend/app/build.php link_mode 'path').
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { LIVE, SERVER_OWNED, BUILD_FILE, FRONTEND, BACKEND, isServerOwned, livePath, listFiles, commitFiles } from './live-lib.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const git = (...args) => execFileSync('git', args, { cwd: ROOT, maxBuffer: 1 << 28 });

const dirty = git('status', '--porcelain', '--', 'public').toString().trim();
if (dirty) {
  console.error('public/ has uncommitted changes. Commit first, so live/ is built from one commit:\n' + dirty);
  process.exit(1);
}
const commit = git('rev-parse', 'HEAD').toString().trim();
const files = commitFiles(git); // Map: path inside public/ -> Buffer at HEAD

let written = 0, same = 0;
const wanted = new Set([BUILD_FILE]);
for (const [rel, buf] of files) {
  const liveRel = livePath(rel);
  wanted.add(liveRel);
  if (isServerOwned(liveRel) && existsSync(join(LIVE, liveRel))) continue; // the server's own files stay
  const out = join(LIVE, liveRel);
  mkdirSync(dirname(out), { recursive: true });
  const body = liveRel === BUILD_FILE ? buildFile(commit) : buf;
  if (existsSync(out) && Buffer.compare(readFileSync(out), body) === 0) { same++; continue; }
  writeFileSync(out, body);
  written++;
}
if (!files.has('app/build.php')) {
  mkdirSync(dirname(join(LIVE, BUILD_FILE)), { recursive: true });
  writeFileSync(join(LIVE, BUILD_FILE), buildFile(commit));
  written++;
}
// Empty server folders the site writes into (uploads/ and storage/ are not in git apart from their .htaccess).
for (const dir of [`${FRONTEND}/uploads`, `${BACKEND}/storage`]) mkdirSync(join(LIVE, dir), { recursive: true });

const all = listFiles(LIVE);
const stale = all.filter((rel) => (rel.startsWith(`${FRONTEND}/`) || rel.startsWith(`${BACKEND}/`)) && !wanted.has(rel) && !isServerOwned(rel));
const outside = all.filter((rel) => !rel.startsWith(`${FRONTEND}/`) && !rel.startsWith(`${BACKEND}/`));
console.log(`live/ built from ${commit.slice(0, 7)}: ${written} written, ${same} unchanged.`);
console.log(`  live/${FRONTEND}/ -> upload INTO public_html;  live/${BACKEND}/ -> upload NEXT TO public_html.`);
console.log(`Kept as they are (server data): ${SERVER_OWNED.join(', ')}`);
if (stale.length) console.log(`\nStale files in live/ (not in this commit). Nothing was deleted; the owner decides:\n  ${stale.join('\n  ')}`);
if (outside.length) console.log(`\nFiles in live/ outside frontend/ and backend/ (the old one-folder layout, not uploaded any more). Nothing was deleted; the owner decides:\n  ${outside.length} files, e.g. ${outside.slice(0, 5).join(', ')}`);

function buildFile(sha) {
  return Buffer.from(`<?php\ndeclare(strict_types=1);\n\n// live/ copy: proper addresses (/asia, /home). Written by scripts/build-live.mjs.\nreturn ['link_mode' => 'path', 'commit' => '${sha}'];\n`);
}
