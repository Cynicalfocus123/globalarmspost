// Builds the live/ folder (Hostinger public_html) from the files of the current git commit.
// Usage: npm run build:live          (needs a clean public/ folder, so live/ is exactly one commit)
//
// Hard rules: never delete anything (files only added or overwritten; stale ones are listed),
// never overwrite the server's own data (app/config.php, uploads/, storage/),
// and live/ uses proper addresses (app/build.php link_mode 'path').
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { LIVE, SERVER_OWNED, isServerOwned, listFiles, commitFiles } from './live-lib.mjs';

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
for (const [rel, buf] of files) {
  if (isServerOwned(rel) && existsSync(join(LIVE, rel))) continue; // the server's own files stay
  const out = join(LIVE, rel);
  mkdirSync(dirname(out), { recursive: true });
  const body = rel === 'app/build.php' ? buildFile(commit) : buf;
  if (existsSync(out) && Buffer.compare(readFileSync(out), body) === 0) { same++; continue; }
  writeFileSync(out, body);
  written++;
}
if (!files.has('app/build.php')) { writeFileSync(join(LIVE, 'app/build.php'), buildFile(commit)); written++; }

const stale = listFiles(LIVE).filter((rel) => !files.has(rel) && !isServerOwned(rel) && rel !== 'app/build.php');
console.log(`live/ built from ${commit.slice(0, 7)}: ${written} written, ${same} unchanged.`);
console.log(`Kept as they are (server data): ${SERVER_OWNED.join(', ')}`);
if (stale.length) {
  console.log(`\nStale files in live/ (not in this commit). Nothing was deleted; the owner decides:\n  ${stale.join('\n  ')}`);
}

function buildFile(sha) {
  return Buffer.from(`<?php\ndeclare(strict_types=1);\n\n// live/ copy: proper addresses (/asia, /home). Written by scripts/build-live.mjs.\nreturn ['link_mode' => 'path', 'commit' => '${sha}'];\n`);
}
