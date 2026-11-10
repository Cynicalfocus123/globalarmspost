import { test, expect, type Page, type TestInfo } from './fixtures';
import { users, sql, login, logout, addUser, isLive } from './admin-helpers';

// Security (owner, 2026-11-10): nobody gets into the admin by guessing or by script, and nobody gets in
// through the public pages (articles, search, tags) or by poisoning the page cache.
// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit, on the git/ and live/ copies,
// against the real PHP server and database; what was saved is read back from the database.

const pw = 'Long-enough-password-1';
const newEmail = (info: TestInfo, word: string) => `${word}.${Date.now().toString(36)}.${info.project.name}@test.localhost`;
const nowSql = (msAgo = 0) => new Date(Date.now() - msAgo).toISOString().slice(0, 19).replace('T', ' ');

/** Post the real sign-in form (with its CSRF token) and return the answer. */
async function postLogin(page: Page, fields: Record<string, string>) {
  const html = await (await page.request.get('/admin/login')).text();
  const token = /name="_csrf" value="([^"]+)"/.exec(html)?.[1];
  expect(token, 'sign-in form has a CSRF token').toBeTruthy();
  return page.request.post('/admin/login', { form: { _csrf: token!, next: '/admin', ...fields }, maxRedirects: 0 });
}

/** A new editor account made by the admin (signed out again afterwards). */
async function newEditor(page: Page, info: TestInfo, word: string) {
  const email = newEmail(info, word);
  await login(page, users.admin);
  await addUser(page, { name: `Security ${word}`, email, password: pw, role: 'editor' });
  await logout(page);
  return email;
}

test('sign in: one account has a cap from all addresses together (a botnet), not only per address', async ({ page }, info) => {
  const email = await newEditor(page, info, 'botnet');
  // 20 wrong tries from 20 different addresses in the last minutes, as a script spread over many machines
  // would make them (each address alone stays under its own limit of 5).
  for (let i = 1; i <= 20; i++) {
    sql(info, 'INSERT INTO login_attempts (email, ip, ok, at) VALUES (?, ?, 0, ?)', email, `203.0.113.${i}`, nowSql(60_000));
  }
  expect(sql(info, 'SELECT COUNT(DISTINCT ip) AS n FROM login_attempts WHERE email = ? AND ok = 0', email)[0].n).toBe(20);
  // Now even the right password is refused for that account, from this address too.
  const res = await postLogin(page, { email, password: pw });
  expect(res.status()).toBe(429);
  expect(await res.text()).toContain('Too many wrong tries');
  expect(sql(info, 'SELECT COUNT(*) AS n FROM login_attempts WHERE email = ? AND ok = 1', email)[0].n).toBe(0);
  // Another account is not blocked.
  await login(page, users.admin);
});

test('sign in: a script that fills the hidden honeypot field is refused, counted and logged', async ({ page }, info) => {
  const email = await newEditor(page, info, 'honeypot');
  // The field is in the form but people never see it.
  await page.goto('/admin/login');
  await expect(page.locator('input[name="website"]')).toHaveCount(1);
  await expect(page.locator('input[name="website"]')).not.toBeInViewport();
  // A script fills every field, the right password included: refused with the normal answer.
  const res = await postLogin(page, { email, password: pw, website: 'http://spam.example' });
  expect(res.status()).toBe(401);
  expect(await res.text()).toContain('Wrong email or password.');
  // Not signed in.
  const admin = await page.request.get('/admin', { maxRedirects: 0 });
  expect(admin.status()).toBe(303);
  expect(admin.headers()['location']).toContain('/admin/login');
  // Saved: a wrong try (so the throttling counts it) and an audit line.
  expect(sql(info, 'SELECT COUNT(*) AS n FROM login_attempts WHERE email = ? AND ok = 0', email)[0].n).toBe(1);
  expect(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'login.bot' AND detail = ?", email)[0].n).toBe(1);
  // The same person with the field empty gets in.
  const ok = await postLogin(page, { email, password: pw });
  expect(ok.status()).toBe(303);
});

test('a password change signs out every other session and every "keep me signed in" cookie', async ({ page, browser }, info) => {
  const email = await newEditor(page, info, 'pwchange');
  // Device A (this page) and device B (another browser session, with "keep me signed in").
  expect((await postLogin(page, { email, password: pw })).status()).toBe(303);
  const other = await browser.newContext();
  const b = await other.newPage();
  try {
    expect((await postLogin(b, { email, password: pw, remember: '1' })).status()).toBe(303);
    expect((await b.request.get('/admin', { maxRedirects: 0 })).status()).toBe(200);
    const userId = sql(info, 'SELECT id FROM users WHERE email = ?', email)[0].id;
    expect(sql(info, 'SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id = ? AND expires_at > ?', userId, nowSql())[0].n).toBe(1);
    // A changes the password on the Account page.
    const html = await (await page.request.get('/admin/account')).text();
    const token = /name="_csrf" value="([^"]+)"/.exec(html)![1];
    const newPw = 'Another-long-password-2';
    const changed = await page.request.post('/admin/account/password', { form: { _csrf: token, password: pw, new_password: newPw }, maxRedirects: 0 });
    expect(changed.status()).toBe(303);
    // Saved: the new password works, the old hash is gone, no "keep me signed in" token is valid any more.
    expect(sql(info, 'SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id = ? AND expires_at > ?', userId, nowSql())[0].n).toBe(0);
    // A stays signed in; B (session cookie and remember cookie) is signed out.
    expect((await page.request.get('/admin', { maxRedirects: 0 })).status()).toBe(200);
    const bAfter = await b.request.get('/admin', { maxRedirects: 0 });
    expect(bAfter.status()).toBe(303);
    expect(bAfter.headers()['location']).toContain('/admin/login');
    // B can sign in again with the new password only.
    expect((await postLogin(b, { email, password: pw })).status()).toBe(401);
    expect((await postLogin(b, { email, password: newPw })).status()).toBe(303);
  } finally {
    await other.close();
  }
});

test('the public pages print what visitors type as text, never as code (search, tags, stories)', async ({ page }, info) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.addInitScript(() => { (window as any).__hit = 0; });
  const attack = `<img src=x onerror="window.__hit=1"><script>window.__hit=2</script>"'`;
  const q = encodeURIComponent(attack);
  await page.goto(isLive(info) ? `/search?q=${q}` : `/#search?q=${q}`);
  await expect(page.locator('.search-page h1')).toHaveText('Search');
  // The words are shown as text in the page and in the box, and nothing ran.
  await expect(page.locator('#sq')).toHaveValue(attack);
  expect(await page.locator('.search-page img[src="x"], .search-page script').count()).toBe(0);
  expect(await page.evaluate(() => (window as any).__hit)).toBe(0);
  // The server answer itself has the text escaped.
  const raw = await (await page.request.get(`/search?q=${q}`)).text();
  expect(raw).not.toContain('<img src=x onerror');
  expect(raw).toContain('&lt;img src=x onerror=');
  // Odd addresses are a plain "not found", never an error page or code.
  for (const path of [`/tag/${encodeURIComponent('<script>x</script>')}`, '/news/%3Cscript%3E', "/asia/' OR '1'='1", '/app/config.php', '/storage/', '/tests/db.php']) {
    const r = await page.request.get(path);
    expect([403, 404], `${path}: refused or not found`).toContain(r.status());
    const body = await r.text();
    expect(body, `${path}: no PHP error`).not.toMatch(/Fatal error|Warning:|SQLSTATE|Stack trace/);
    expect(body).not.toContain('<script>x</script>');
  }
  expect(errors).toEqual([]);
});

test('page cache: a made-up Host header can never put another site into the pages people get', async ({ page }, info) => {
  const story = sql(info, `SELECT p.slug, c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id
                           WHERE p.slug LIKE 'sample-%' AND p.status = 'published' ORDER BY p.id LIMIT 1`)[0];
  const url = isLive(info) ? `/${story.cat}/${story.slug}` : `/news/${story.slug}`;
  const port = new URL((info.project.use as any).baseURL).port;
  // An attacker asks for the story with their own host name, and with a host that carries code.
  const evil = await page.request.get(url, { headers: { host: `evil.example:${port}` } });
  expect(evil.status()).toBe(200);
  const odd = await page.request.get(url, { headers: { host: '"><script>alert(1)</script>' } });
  expect(await odd.text()).not.toContain('<script>alert(1)</script>');
  // A normal visitor right after gets the page with this site's own addresses only.
  for (let i = 0; i < 2; i++) {
    const html = await (await page.request.get(url)).text();
    expect(html).not.toContain('evil.example');
    expect(html).not.toContain('<script>alert(1)</script>');
    expect(html).toContain(`data-url="http://127.0.0.1:${port}/`);
  }
});
