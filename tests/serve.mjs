// Test server for one copy of the site. Started by Playwright (webServer) and stopped by it after the run.
// Usage: node tests/serve.mjs <git|live|local> <port> [--demo-admin]
//   git   = public/ as it is ('#' links)
//   live  = a fresh copy of public/ in test-data/<run>/live/site with link_mode 'path' (proper addresses),
//           made the same way scripts/build-live.mjs makes live/
//   local = the owner's local site (hard rule, owner 2026-11-14): runs straight from the real live/ folder
//           (live/frontend + live/backend, built by npm run build:live). Nothing is copied and nothing is reset:
//           the database is live/backend/storage/site.sqlite and the pictures are in live/frontend/uploads, so
//           every story added there is kept and goes into the deploy ZIPs (scripts/make-zips.py). Its config is
//           test-data/local-site/config.php (made once, never inside live/). No sample stories, no demo admin:
//           the first account made at /admin/setup is the real admin that goes to Hostinger with the database.
// git and live: each start makes a new SQLite database and runs the real migrations.
// GAP_DB=mysql: a new MySQL 8.4 server instead (task P15), with its own data folder in
// test-data/<run>/<copy>/mysql-data and port 33000 + (port % 1000) (8190 -> 33190, 8191 -> 33191).
// mysqld runs with --no-defaults, so it never reads the owner's my.ini or touches D:/codex system/mysql-data,
// and it is stopped together with this server.
// Several PHP processes sit behind a small proxy (see the bottom), so parallel browsers never wait.
import { cpSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { spawn, execFileSync, spawnSync } from 'node:child_process';
import http from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { MAIN, LIVE } from '../scripts/live-lib.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const [copy, port] = process.argv.slice(2);
// A new folder per run (never deletes anything): test-data/<run>/<copy>
const RUN = process.env.GAP_RUN || String(Date.now());
// local: one fixed folder for its config and sessions, in the main checkout (also when started from a worktree).
const TMP = copy === 'local' ? join(MAIN, 'test-data', 'local-site') : join(ROOT, 'test-data', RUN, copy);
const PHP = process.env.PHP_BIN || 'php';

// Load pdo_sqlite, gd and fileinfo when the local php.ini does not. GD makes pictures on the server
// when a browser cannot make WebP (Safari); Hostinger has it on.
const mods = execFileSync(PHP, ['-m']).toString().toLowerCase().split(/\s+/);
const ext = [];
const USE_MYSQL = process.env.GAP_DB === 'mysql';
for (const name of ['pdo_sqlite', 'gd', 'fileinfo', ...(USE_MYSQL ? ['pdo_mysql'] : [])]) if (!mods.includes(name)) ext.push('-d', `extension=${name}`);
ext.push('-d', 'upload_max_filesize=20M', '-d', 'post_max_size=64M');

mkdirSync(TMP, { recursive: true });

// docroot = the web folder; backend = the folder that holds app/ and storage/.
// git copy: both are public/. live copy: the same two folders as live/ (scripts/live-lib.mjs): frontend/ is the
// web folder (public_html), backend/ sits next to it with app/ and storage/ (owner, 2026-11-12).
let docroot = join(ROOT, 'public');
let backend = docroot;
if (copy === 'live') {
  const site = join(TMP, 'site');
  docroot = join(site, 'frontend');
  backend = join(site, 'backend');
  const pub = join(ROOT, 'public');
  const serverData = (src) => /[\\/]public[\\/](storage|uploads)[\\/](?!\.htaccess$)[^\\/]+$/.test(src) || src.endsWith('config.php');
  cpSync(pub, docroot, { recursive: true, filter: (src) => !serverData(src) && !/[\\/]public[\\/](app|storage)([\\/]|$)/.test(src) });
  for (const dir of ['app', 'storage']) cpSync(join(pub, dir), join(backend, dir), { recursive: true, filter: (src) => !serverData(src) });
  writeFileSync(join(backend, 'app', 'build.php'), "<?php\ndeclare(strict_types=1);\n\nreturn ['link_mode' => 'path', 'commit' => 'test'];\n");
}

const LOCAL = copy === 'local';
if (LOCAL) {
  if (USE_MYSQL) { console.error('The local site uses SQLite (live/backend/storage/site.sqlite); GAP_DB=mysql is for test runs only.'); process.exit(1); }
  docroot = join(LIVE, 'frontend');
  backend = join(LIVE, 'backend');
  if (!existsSync(join(backend, 'app', 'bootstrap.php'))) { console.error('live/ is not built yet: run npm run build:live first.'); process.exit(1); }
  mkdirSync(join(backend, 'storage'), { recursive: true });
  mkdirSync(join(docroot, 'uploads'), { recursive: true });
}

const config = join(TMP, 'config.php');
const db = join(TMP, 'site.sqlite').replaceAll('\\', '/');
const sessions = join(TMP, 'sessions').replaceAll('\\', '/');
mkdirSync(sessions, { recursive: true });
// login_ip_limit, suggest_limit, api_limit: every test browser comes from 127.0.0.1, so the per-address limits are raised.
// The per-email limit (5 wrong tries in 15 minutes) stays as on the real site and is tested.
let mysqld = null;
let stopping = false;
const dbConfig = USE_MYSQL ? await startMysql() : `['driver' => 'sqlite', 'path' => '${db}']`;
// local: the database is live/backend/storage/site.sqlite (the same place app/config.sample.php uses on the server),
// limits as on the real site. The config is written once and then left alone.
if (LOCAL) {
  if (!existsSync(config)) {
    const liveDb = join(backend, 'storage', 'site.sqlite').replaceAll('\\', '/');
    writeFileSync(config, `<?php\nreturn ['db' => ['driver' => 'sqlite', 'path' => '${liveDb}'], 'debug' => true, 'session_path' => '${sessions}'];\n`);
  }
} else writeFileSync(config, `<?php\nreturn ['db' => ${dbConfig}, 'debug' => true, 'session_path' => '${sessions}', 'login_ip_limit' => 100000, 'suggest_limit' => 100000, 'api_limit' => 100000];\n`);
// One note per server (copy + port), so the preview and the tests never read each other's database.
writeFileSync(join(ROOT, 'test-data', `${copy}-${port}.json`), JSON.stringify({ config, docroot, backend, ext }));

const env = { ...process.env, GAP_CONFIG: config };
execFileSync(PHP, [...ext, join(backend, 'app', 'cli', 'migrate.php')], { env, stdio: 'inherit' });
// Sample stories and photos (test database only; photos go to <docroot>/uploads/demo, which git ignores).
// Never on the local site: its database is the real one.
if (!LOCAL) execFileSync(PHP, [...ext, join(ROOT, 'tests', 'seed-demo.php'), backend, docroot], { env, stdio: 'inherit' });
// Local preview only (.claude/launch.json passes --demo-admin): a demo admin to sign in with at /admin.
// The test runs never pass it, because their first test makes the first admin through the real setup step.
if (process.argv.includes('--demo-admin') && !LOCAL) execFileSync(PHP, [...ext, join(ROOT, 'tests', 'seed-admin.php'), backend], { env, stdio: 'inherit' });

if (!existsSync(docroot)) process.exit(1);

// SQLite on Windows: one connection stays open for the whole run. SQLite deletes its -wal and -shm files
// whenever the last connection closes and makes them again on the next open; with 20 short PHP processes
// that happens all the time, and when another program (a virus scanner) holds the old file at that moment,
// Windows keeps it "delete pending" and every later open fails with "disk I/O error" (seen 2026-11-10).
// With this connection open the files are never deleted during the run.
let keeper = null;
if (!USE_MYSQL) {
  keeper = spawn(PHP, [...ext, '-r', 'require $argv[1] . "/app/bootstrap.php"; db()->query("SELECT 1"); fwrite(STDOUT, "open\n"); while (true) { sleep(3600); }', backend], { env, stdio: ['ignore', 'pipe', 'inherit'] });
  await new Promise((res, rej) => { keeper.stdout.once('data', res); keeper.once('exit', () => rej(new Error('the SQLite keeper stopped'))); });
}

// PHP's built-in server answers one connection at a time, and browsers open idle "preconnect"
// sockets that would block it. So a small Node proxy takes the browser connections and hands each
// request to one of several PHP processes, like a real web server (Apache + PHP) does.
const WORKERS = 10;
const backends = [];
for (let i = 0; i < WORKERS; i++) {
  const p = 20000 + (Number(port) % 1000) * 10 + i; // e.g. 8190 -> 21900..21909, 8191 -> 21910..21919
  backends.push({ port: p, busy: 0, proc: spawn(PHP, [...ext, '-S', `127.0.0.1:${p}`, '-t', docroot, join(ROOT, 'tests', 'php-router.php')], { env, stdio: 'ignore' }) });
}
// Each request goes to the least busy PHP process (like a free Apache worker), so a slow request
// (PHP GD making AVIF for Safari uploads) does not hold up the requests queued behind it.
let next = 0;
const pick = () => {
  let best = null;
  for (let i = 0; i < WORKERS; i++) {
    const b = backends[(next + i) % WORKERS];
    if (!best || b.busy < best.busy) best = b;
  }
  next++;
  return best;
};
const proxy = http.createServer((req, res) => {
  const b = pick();
  b.busy++;
  res.on('close', () => { b.busy--; });
  const up = http.request({ host: '127.0.0.1', port: b.port, path: req.url, method: req.method, headers: { ...req.headers, connection: 'close' }, agent: false }, (r) => {
    res.writeHead(r.statusCode ?? 502, r.headers);
    r.pipe(res);
  });
  up.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end('backend not ready'); });
  req.pipe(up);
});
proxy.listen(Number(port), '127.0.0.1');

const stop = () => { stopping = true; for (const b of backends) b.proc.kill(); if (keeper) keeper.kill(); stopMysql(); proxy.close(); process.exit(0); };
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
for (const b of backends) b.proc.on('exit', () => stop());

// ---------- MySQL test mode (GAP_DB=mysql, task P15) ----------
function stopMysql() {
  if (mysqld && mysqld.exitCode === null) mysqld.kill();
}

async function startMysql() {
  const base = (process.env.MYSQL_BASE || 'D:/codex system/tools/mysql').replaceAll('\\', '/');
  const bin = (name) => join(base, 'bin', name);
  const data = join(TMP, 'mysql-data').replaceAll('\\', '/');
  const mport = 33000 + (Number(port) % 1000);
  // A new, empty data folder for this run (root with no password, only on 127.0.0.1).
  execFileSync(bin('mysqld.exe'), ['--no-defaults', '--initialize-insecure', `--basedir=${base}`, `--datadir=${data}`], { stdio: 'inherit' });
  mysqld = spawn(bin('mysqld.exe'), [
    '--no-defaults', `--basedir=${base}`, `--datadir=${data}`, `--port=${mport}`, '--bind-address=127.0.0.1',
    '--mysqlx=OFF', '--skip-log-bin', '--character-set-server=utf8mb4', '--collation-server=utf8mb4_0900_ai_ci',
    '--max-connections=500', // 20 PHP processes plus the tests' own helpers; the default strict sql_mode stays
  ], { stdio: 'ignore' });
  mysqld.on('exit', (code) => { if (!stopping) { console.error(`mysqld stopped (exit ${code})`); process.exit(1); } });
  process.on('exit', stopMysql);
  // Wait until it takes connections, then make the database.
  const client = (q) => spawnSync(bin('mysql.exe'), ['--no-defaults', '-uroot', '-h127.0.0.1', `-P${mport}`, '-e', q], { encoding: 'utf8' });
  const until = Date.now() + 120_000;
  for (;;) {
    const r = client('SELECT 1');
    if (r.status === 0) break;
    if (Date.now() > until) throw new Error('mysqld did not start: ' + (r.stderr || ''));
    await new Promise((res) => setTimeout(res, 500));
  }
  const made = client('CREATE DATABASE gap CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
  if (made.status !== 0) throw new Error('could not create the database: ' + made.stderr);
  return `['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => ${mport}, 'name' => 'gap', 'user' => 'root', 'pass' => '']`;
}
