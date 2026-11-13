// Shared by the admin tests (step 3). The test servers are the real PHP server with a real SQLite database.
import { expect, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync, openSync, closeSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHmac } from 'node:crypto';

export const HERE = dirname(fileURLToPath(import.meta.url));
export const users = JSON.parse(readFileSync(join(HERE, 'fixtures', 'test-users.json'), 'utf8'));
export const meta = (info: TestInfo) => info.project.metadata as { copy: 'git' | 'live'; engine: string; device: string };
export const isLive = (info: TestInfo) => meta(info).copy === 'live';
/** Which test server's database: "<copy>-<port>" (see tests/serve.mjs). */
export const dbKey = (info: TestInfo) => `${meta(info).copy}-${new URL((info.project.use as any).baseURL).port}`;
export const serverInfo = (info: TestInfo) => JSON.parse(readFileSync(join(HERE, '..', 'test-data', `${dbKey(info)}.json`), 'utf8'));
/** Under 900 px the admin has the bottom tab bar and sheets instead of the side menu. */
export const narrow = (page: Page) => (page.viewportSize()?.width ?? 0) < 900;
/** Bottom edge of a sheet (phones and tablets), 0 while it is not shown. */
export const sheetBottom = async (page: Page, selector: string) => {
  const b = await page.locator(selector).boundingBox();
  return b ? b.y + b.height : 0;
};
/** A name unique to this test project and moment. */
export const uniq = (info: TestInfo, word: string) => `${word} ${info.project.name.replace(/-/g, ' ')} ${Date.now().toString(36)}`;

/**
 * Run the server command that makes admin accounts (app/cli/create-admin.php, task B22) on the test copy, with the
 * password piped in. Returns its exit code and output.
 */
export function createAdminCli(info: TestInfo, args: string[], password: string): { code: number; out: string } {
  const s = serverInfo(info);
  const cli = join(s.backend ?? s.docroot, 'app', 'cli', 'create-admin.php');
  try {
    const out = execFileSync(process.env.PHP_BIN || 'php', [...s.ext, cli, ...args], { input: password + '\n', env: { ...process.env, GAP_CONFIG: s.config }, stdio: ['pipe', 'pipe', 'pipe'] });
    return { code: 0, out: out.toString() };
  } catch (e: any) {
    return { code: e.status ?? 1, out: `${e.stdout ?? ''}${e.stderr ?? ''}` };
  }
}

/** Run SQL on the test copy's real database (through the site's own PHP code). */
export function sql(info: TestInfo, query: string, ...params: (string | number)[]): any {
  const s = serverInfo(info);
  const out = execFileSync(process.env.PHP_BIN || 'php', [...s.ext, join(HERE, 'db.php'), s.backend ?? s.docroot, query, ...params.map(String)], {
    env: { ...process.env, GAP_CONFIG: s.config },
  });
  return JSON.parse(out.toString());
}

/** Is this stored file (path inside the site folder) really there? */
export const fileThere = (info: TestInfo, rel: string) => existsSync(join(serverRoot(info, rel), rel));
/** Where a site file is: storage/... and app/... in the backend folder (live: backend/), the rest in the web folder. */
export const serverRoot = (info: TestInfo, rel: string) => {
  const s = serverInfo(info);
  return rel.startsWith('storage/') || rel.startsWith('app/') ? s.backend ?? s.docroot : s.docroot;
};

/** Sign in through the real form (GET for the token, POST the form) with the page's cookies. */
export async function login(page: Page, who: { email: string; password: string }, remember = false) {
  const html = await (await page.request.get('/admin/login')).text();
  const token = /name="_csrf" value="([^"]+)"/.exec(html)?.[1];
  expect(token, 'login form has a CSRF token').toBeTruthy();
  const res = await page.request.post('/admin/login', { form: { _csrf: token!, email: who.email, password: who.password, next: '/admin', ...(remember ? { remember: '1' } : {}) } });
  expect(res.status(), 'signed in').toBe(200);
  expect(res.url()).toMatch(/\/admin$/);
}

/** The CSRF token of the signed-in session (from any admin page). */
export async function csrf(page: Page): Promise<string> {
  const html = await (await page.request.get('/admin')).text();
  return /name="csrf-token" content="([^"]+)"/.exec(html)![1];
}

/** As the admin: add a user through the Users page form. */
export async function addUser(page: Page, user: { name: string; email: string; password: string; role: string }) {
  const html = await (await page.request.get('/admin/users')).text();
  const token = /name="_csrf" value="([^"]+)"/.exec(html)![1];
  const res = await page.request.post('/admin/users', { form: { _csrf: token, ...user } });
  expect(res.status()).toBe(200);
}

export async function logout(page: Page) {
  const token = await csrf(page);
  await page.request.post('/admin/logout', { form: { _csrf: token } });
}

/** Authenticator-app code (RFC 6238, as the app makes it). */
export function totp(secret: string, step = Math.floor(Date.now() / 30000)): string {
  const alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secret.replace(/\s/g, '').toUpperCase()) bits += alpha.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const msg = Buffer.alloc(8);
  msg.writeBigUInt64BE(BigInt(step));
  const h = createHmac('sha1', key).update(msg).digest();
  const o = h[19] & 0xf;
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000)).padStart(6, '0');
}

/** Sample pictures to upload (drawn test photos, no real pictures). */
export const photoFile = join(HERE, 'fixtures', 'demo', 'scene-2-960.webp'); // 960 × 640: becomes 480 + 960
export const photoFile2 = join(HERE, 'fixtures', 'demo', 'scene-5-960.webp');
export const avatarFile = join(HERE, 'fixtures', 'demo', 'scene-7-480.webp'); // becomes 160 + 320

/** Wait in line per copy (no files are ever deleted): each caller takes the next ticket, then waits for the one before. */
export async function inLine<T>(info: TestInfo, name: string, fn: () => Promise<T>): Promise<T> {
  const dir = join(HERE, '..', 'test-data', process.env.GAP_RUN || 'run', `${meta(info).copy}-${name}-line`);
  mkdirSync(dir, { recursive: true });
  let n = 1;
  for (;; n++) {
    try { closeSync(openSync(join(dir, `ticket-${n}`), 'wx')); break; } catch { /* taken */ }
  }
  const until = Date.now() + 240_000;
  while (n > 1 && !existsSync(join(dir, `done-${n - 1}`)) && Date.now() < until) await new Promise((r) => setTimeout(r, 250));
  try {
    return await fn();
  } finally {
    writeFileSync(join(dir, `done-${n}`), '');
  }
}

export async function noSidewaysScroll(page: Page, what: string) {
  await page.waitForLoadState('load'); // styles are in
  const { scroll, client, wide } = await page.evaluate(() => {
    const client = document.documentElement.clientWidth;
    // What sticks out, to say in the failure message.
    const wide = [...document.querySelectorAll('body *')].filter((el) => el.getBoundingClientRect().right > client + 1 && getComputedStyle(el).position !== 'fixed')
      .slice(0, 5).map((el) => `${el.tagName.toLowerCase()}.${[...el.classList].join('.')} (${Math.round(el.getBoundingClientRect().right)})`);
    return { scroll: document.documentElement.scrollWidth, client, wide };
  });
  expect(scroll, `${what}: no sideways scroll; too wide: ${wide.join(', ')}`).toBeLessThanOrEqual(client);
}

/** Local site time (Asia/Bangkok, GMT+7) as a datetime-local value. */
export const bangkokInput = (ms: number) => new Date(ms + 7 * 3600_000).toISOString().slice(0, 16);

/* ---------- Step 5 ---------- */

/** "now" in the database's format (UTC, Y-m-d H:i:s). */
export const nowSql = (msFromNow = 0) => new Date(Date.now() + msFromNow).toISOString().slice(0, 19).replace('T', ' ');

/** POST JSON to the admin API as the signed-in user (with the session's CSRF token); returns status and JSON. */
export async function adminApi(page: Page, path: string, body: unknown): Promise<{ status: number; json: any }> {
  const token = await csrf(page);
  const res = await page.request.post(path, { data: body, headers: { 'X-CSRF-Token': token, Accept: 'application/json' } });
  let json: any = null;
  const text = await res.text();
  try { json = JSON.parse(text); } catch { /* not JSON: the whole answer goes into the failure message */ }
  if (json === null) throw new Error(`${path} answered ${res.status()} with something that is not JSON:\n${text.slice(0, 3000)}`);
  return { status: res.status(), json };
}

/** Upload a picture through the admin API the way Safari does (the server makes the sizes with GD). */
export async function uploadPicture(page: Page, file: string, alt = ''): Promise<any> {
  const token = await csrf(page);
  const res = await page.request.post('/admin/api/media', {
    headers: { 'X-CSRF-Token': token, Accept: 'application/json' },
    multipart: { kind: 'photo', name: 'step5-test', alt, source: { name: 'step5-test.webp', mimeType: 'image/webp', buffer: readFileSync(file) } },
  });
  expect(res.status(), 'picture uploaded').toBe(200);
  return res.json();
}

/** A draft story saved through the admin API (drafts never show on the site, so other tests are not affected). */
export async function draftStory(page: Page, info: TestInfo, fields: Record<string, unknown>): Promise<number> {
  const cat = sql(info, "SELECT id FROM categories WHERE slug = 'business'")[0].id;
  const res = await adminApi(page, '/admin/api/story', { status: 'draft', category_id: cat, body: '<p>Step 5 test story.</p>', ...fields });
  expect(res.status, JSON.stringify(res.json)).toBe(200);
  return res.json.id;
}
