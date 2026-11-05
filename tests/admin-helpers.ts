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

/** Run SQL on the test copy's real database (through the site's own PHP code). */
export function sql(info: TestInfo, query: string, ...params: (string | number)[]): any {
  const s = serverInfo(info);
  const out = execFileSync(process.env.PHP_BIN || 'php', [...s.ext, join(HERE, 'db.php'), s.docroot, query, ...params.map(String)], {
    env: { ...process.env, GAP_CONFIG: s.config },
  });
  return JSON.parse(out.toString());
}

/** Is this stored file (path inside the site folder) really there? */
export const fileThere = (info: TestInfo, rel: string) => existsSync(join(serverInfo(info).docroot, rel));

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
