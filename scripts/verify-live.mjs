// Checks that live/ matches the current git commit exactly (hard rule 3).
// Usage: npm run verify:live
// Every file in public/ at HEAD must be in live/ with the same bytes, except:
//   app/build.php  must say link_mode 'path' and this commit
//   server data    (app/config.php, uploads/, storage/) is not compared
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, LIVE, isServerOwned, listFiles, commitFiles } from './live-lib.mjs';

const git = (...args) => execFileSync('git', args, { cwd: ROOT, maxBuffer: 1 << 28 });
const commit = git('rev-parse', 'HEAD').toString().trim();
const files = commitFiles(git);
const problems = [];

for (const [rel, buf] of files) {
  if (isServerOwned(rel)) continue;
  const out = join(LIVE, rel);
  if (!existsSync(out)) { problems.push(`missing in live/: ${rel}`); continue; }
  if (rel === 'app/build.php') continue;
  if (Buffer.compare(readFileSync(out), buf) !== 0) problems.push(`different in live/: ${rel}`);
}
const build = existsSync(join(LIVE, 'app/build.php')) ? readFileSync(join(LIVE, 'app/build.php'), 'utf8') : '';
if (!build.includes("'link_mode' => 'path'")) problems.push("live/app/build.php does not use proper addresses (link_mode 'path')");
if (!build.includes(`'commit' => '${commit}'`)) problems.push(`live/ was not built from the current commit ${commit.slice(0, 7)}`);
const dirty = git('status', '--porcelain', '--', 'public').toString().trim();
if (dirty) problems.push('git public/ has uncommitted changes:\n' + dirty);

const stale = listFiles(LIVE).filter((rel) => !files.has(rel) && !isServerOwned(rel) && rel !== 'app/build.php');

if (problems.length) {
  console.error(`live/ does NOT match git ${commit.slice(0, 7)}:\n  ` + problems.join('\n  '));
  process.exit(1);
}
console.log(`live/ matches git ${commit.slice(0, 7)} (${files.size} files, proper addresses).`);
if (stale.length) console.log(`Stale files in live/ (not deleted, owner decides):\n  ${stale.join('\n  ')}`);
