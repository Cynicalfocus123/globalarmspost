// Make an admin account on the owner's local site (live/, hard rule 12), or give one a new password.
// Usage (in git/, in your own terminal):  npm run admin -- you@example.com "Your Name"
//                                          npm run admin -- you@example.com --reset
// It runs live/backend/app/cli/create-admin.php with the local site's config (test-data/local-site/config.php).
// You type the password (or press Enter and one is made and shown once). The account is saved in
// live/backend/storage/site.sqlite, so it goes to Hostinger with the database in the backend ZIP.
// There is no setup page on the web (owner, 2026-11-14): this command is the only way to make the first admin.
import { spawnSync, execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { MAIN, LIVE } from './live-lib.mjs';

const config = join(MAIN, 'test-data', 'local-site', 'config.php');
const cli = join(LIVE, 'backend', 'app', 'cli', 'create-admin.php');
if (!existsSync(config)) {
  console.error('The local site has no config yet: start it once (npm run site), then run this again.');
  process.exit(1);
}
if (!existsSync(cli)) {
  console.error('live/ is not built from the current commit yet: run npm run build:live first.');
  process.exit(1);
}
const PHP = process.env.PHP_BIN || 'php';
const mods = execFileSync(PHP, ['-m']).toString().toLowerCase().split(/\s+/);
const ext = mods.includes('pdo_sqlite') ? [] : ['-d', 'extension=pdo_sqlite'];
const res = spawnSync(PHP, [...ext, cli, ...process.argv.slice(2)], {
  stdio: 'inherit',
  env: { ...process.env, GAP_CONFIG: config },
});
process.exit(res.status ?? 1);
