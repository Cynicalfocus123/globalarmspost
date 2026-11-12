// Checks that live/ matches the current git commit exactly (hard rule 3).
// Usage: npm run verify:live
// Every file in public/ at HEAD must be in live/frontend/ or live/backend/ (see live-lib.mjs) with the same bytes,
// except:
//   backend/app/build.php  must say link_mode 'path' and this commit
//   server data            (backend/app/config.php, frontend/uploads/, backend/storage/) is not compared
// and no code, config or server data may be inside live/frontend/ (it is public_html).
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, LIVE, FRONTEND, BACKEND, BUILD_FILE, isServerOwned, livePath, listFiles, commitFiles } from './live-lib.mjs';

const git = (...args) => execFileSync('git', args, { cwd: ROOT, maxBuffer: 1 << 28 });
const commit = git('rev-parse', 'HEAD').toString().trim();
const files = commitFiles(git);
const problems = [];

const wanted = new Set([BUILD_FILE]);
for (const [rel, buf] of files) {
  const liveRel = livePath(rel);
  wanted.add(liveRel);
  if (isServerOwned(liveRel)) continue;
  const out = join(LIVE, liveRel);
  if (!existsSync(out)) { problems.push(`missing in live/: ${liveRel}`); continue; }
  if (liveRel === BUILD_FILE) continue;
  if (Buffer.compare(readFileSync(out), buf) !== 0) problems.push(`different in live/: ${liveRel}`);
}
const build = existsSync(join(LIVE, BUILD_FILE)) ? readFileSync(join(LIVE, BUILD_FILE), 'utf8') : '';
if (!build.includes("'link_mode' => 'path'")) problems.push(`live/${BUILD_FILE} does not use proper addresses (link_mode 'path')`);
if (!build.includes(`'commit' => '${commit}'`)) problems.push(`live/ was not built from the current commit ${commit.slice(0, 7)}`);
const dirty = git('status', '--porcelain', '--', 'public').toString().trim();
if (dirty) problems.push('git public/ has uncommitted changes:\n' + dirty);

// public_html must hold no code folder and no server data; the backend no web files.
const front = listFiles(join(LIVE, FRONTEND));
const back = listFiles(join(LIVE, BACKEND));
for (const rel of front) if (/^(app|storage)\//.test(rel) || rel.endsWith('config.php')) problems.push(`code or server data inside live/${FRONTEND}/ (public_html): ${rel}`);
for (const rel of back) if (!/^(app|storage)\//.test(rel)) problems.push(`not code or server data, but in live/${BACKEND}/: ${rel}`);
for (const rel of back) if (/\.(avif|webp|png|jpe?g|gif|ico|svg|css|js|woff2?)$/i.test(rel)) problems.push(`picture or asset in live/${BACKEND}/ (browsers cannot load it there): ${rel}`);

const stale = listFiles(LIVE).filter((rel) => (rel.startsWith(`${FRONTEND}/`) || rel.startsWith(`${BACKEND}/`)) && !wanted.has(rel) && !isServerOwned(rel));

if (problems.length) {
  console.error(`live/ does NOT match git ${commit.slice(0, 7)}:\n  ` + problems.join('\n  '));
  process.exit(1);
}
console.log(`live/ matches git ${commit.slice(0, 7)} (${files.size} files: ${front.length} in ${FRONTEND}/, ${back.length} in ${BACKEND}/; proper addresses).`);
if (stale.length) console.log(`Stale files in live/ (not deleted, owner decides):\n  ${stale.join('\n  ')}`);
