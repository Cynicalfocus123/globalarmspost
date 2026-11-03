// Test server for one copy of the site. Started by Playwright (webServer) and stopped by it after the run.
// Usage: node tests/serve.mjs <git|live> <port>
//   git  = public/ as it is ('#' links)
//   live = a fresh copy of public/ in test-data/<run>/live/site with link_mode 'path' (proper addresses),
//          made the same way scripts/build-live.mjs makes live/
// Each start makes a new SQLite database and runs the real migrations.
// Several PHP processes sit behind a small proxy (see the bottom), so parallel browsers never wait.
import { cpSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { spawn, execFileSync } from 'node:child_process';
import http from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const [copy, port] = process.argv.slice(2);
// A new folder per run (never deletes anything): test-data/<run>/<copy>
const RUN = process.env.GAP_RUN || String(Date.now());
const TMP = join(ROOT, 'test-data', RUN, copy);
const PHP = process.env.PHP_BIN || 'php';

// Load pdo_sqlite when the local php.ini does not.
const mods = execFileSync(PHP, ['-m']).toString().toLowerCase();
const ext = mods.includes('pdo_sqlite') ? [] : ['-d', 'extension=pdo_sqlite'];

mkdirSync(TMP, { recursive: true });

let docroot = join(ROOT, 'public');
if (copy === 'live') {
  docroot = join(TMP, 'site');
  cpSync(join(ROOT, 'public'), docroot, {
    recursive: true,
    filter: (src) => !/[\\/]public[\\/](storage|uploads)[\\/](?!\.htaccess$)[^\\/]+$/.test(src) && !src.endsWith('config.php'),
  });
  writeFileSync(join(docroot, 'app', 'build.php'), "<?php\ndeclare(strict_types=1);\n\nreturn ['link_mode' => 'path', 'commit' => 'test'];\n");
}

const config = join(TMP, 'config.php');
const db = join(TMP, 'site.sqlite').replaceAll('\\', '/');
writeFileSync(config, `<?php\nreturn ['db' => ['driver' => 'sqlite', 'path' => '${db}'], 'debug' => true];\n`);
writeFileSync(join(ROOT, 'test-data', `${copy}.json`), JSON.stringify({ config, docroot, ext }));

const env = { ...process.env, GAP_CONFIG: config };
execFileSync(PHP, [...ext, join(docroot, 'app', 'cli', 'migrate.php')], { env, stdio: 'inherit' });

if (!existsSync(docroot)) process.exit(1);

// PHP's built-in server answers one connection at a time, and browsers open idle "preconnect"
// sockets that would block it. So a small Node proxy takes the browser connections and hands each
// request to one of several PHP processes, like a real web server (Apache + PHP) does.
const WORKERS = 6;
const backends = [];
for (let i = 0; i < WORKERS; i++) {
  const p = 20000 + (Number(port) % 1000) * 10 + i; // e.g. 8090 -> 20900..20905, 8091 -> 20910..20915
  backends.push({ port: p, proc: spawn(PHP, [...ext, '-S', `127.0.0.1:${p}`, '-t', docroot, join(ROOT, 'tests', 'php-router.php')], { env, stdio: 'ignore' }) });
}
let next = 0;
const proxy = http.createServer((req, res) => {
  const b = backends[next++ % WORKERS];
  const up = http.request({ host: '127.0.0.1', port: b.port, path: req.url, method: req.method, headers: { ...req.headers, connection: 'close' }, agent: false }, (r) => {
    res.writeHead(r.statusCode ?? 502, r.headers);
    r.pipe(res);
  });
  up.on('error', () => { if (!res.headersSent) res.writeHead(502); res.end('backend not ready'); });
  req.pipe(up);
});
proxy.listen(Number(port), '127.0.0.1');

const stop = () => { for (const b of backends) b.proc.kill(); proxy.close(); process.exit(0); };
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
for (const b of backends) b.proc.on('exit', () => stop());
