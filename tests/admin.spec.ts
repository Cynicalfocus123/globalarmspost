import { test, expect, type Page } from './fixtures';
import { users, sql, login, logout, addUser, csrf, totp, uniq, narrow, sheetBottom, fileThere, noSidewaysScroll, photoFile, photoFile2, avatarFile, meta } from './admin-helpers';
import { readFileSync } from 'node:fs';

// Step 3: the admin (wireframe section 5). Same 18 projects as the site tests (git/live × Chromium/Firefox/WebKit ×
// phone/tablet/desktop), after the setup project made the first admin. Nothing here makes a story public or changes
// the homepage, so the site tests can run at the same time; that part is in admin-publish.spec.ts.

const pw = 'Long-enough-password-1';

test('sign in: logo, email, password, "keep me signed in"; a wrong password is refused', async ({ page }) => {
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin\/login$/);
  await expect(page.locator('.a-auth-logo')).toBeVisible();
  await expect(page.locator('h1')).toHaveText('Sign in to the newsroom');
  await expect(page.locator('label.a-check')).toContainText('Keep me signed in on this device');
  await page.fill('#email', users.admin.email);
  await page.fill('#password', 'wrong-password-123');
  await page.click('button[type="submit"]');
  await expect(page.locator('.a-error')).toHaveText('Wrong email or password.');
  await page.fill('#password', users.admin.password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('.a-stats')).toBeVisible();
  await noSidewaysScroll(page, 'dashboard');
});

test('throttling: after 5 wrong tries in 15 minutes even the right password waits', async ({ page }, info) => {
  // Six sign-in rounds, each a full page load plus a bcrypt check: more than 45 s when 8 browsers share
  // the machine (the assertions are unchanged; only the budget for the rounds is larger).
  test.setTimeout(120_000);
  const email = `throttle.${Date.now().toString(36)}.${info.project.name}@test.localhost`;
  await login(page, users.admin);
  await addUser(page, { name: 'Throttle test', email, password: pw, role: 'editor' });
  await logout(page);
  await page.goto('/admin/login');
  for (let i = 1; i <= 5; i++) {
    await page.fill('#email', email);
    await page.fill('#password', `wrong-${i}-password`);
    await page.click('button[type="submit"]');
    await expect(page.locator('.a-error')).toHaveText(i < 5 ? 'Wrong email or password.' : 'Wrong email or password.');
  }
  await page.fill('#email', email);
  await page.fill('#password', pw);
  await page.click('button[type="submit"]');
  await expect(page.locator('.a-error')).toContainText('Too many wrong tries. Wait 15 minutes');
  await expect(page).toHaveURL(/\/admin\/login/);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM login_attempts WHERE email = ? AND ok = 0', email)[0].n).toBe(5);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM login_attempts WHERE email = ? AND ok = 1', email)[0].n).toBe(0);
  // Another account is not blocked.
  await login(page, users.admin);
});

test('"keep me signed in" keeps the admin signed in after the browser session ends', async ({ page, context }, info) => {
  await page.goto('/admin/login');
  await page.fill('#email', users.editor.email);
  await page.fill('#password', users.editor.password);
  await page.check('input[name="remember"]');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/admin$/);
  const cookie = (await context.cookies()).find((c) => c.name === 'gap_remember')!;
  expect(cookie, 'remember cookie').toBeTruthy();
  expect(cookie.httpOnly).toBe(true);
  expect(cookie.path).toBe('/admin');
  const days = (cookie.expires * 1000 - Date.now()) / 86400_000;
  expect(days).toBeGreaterThan(29);
  const [selector, secret] = decodeURIComponent(cookie.value).split(':');
  expect(sql(info, 'SELECT COUNT(*) AS n FROM remember_tokens WHERE selector = ?', selector)[0].n).toBe(1);
  expect(sql(info, 'SELECT token_hash FROM remember_tokens WHERE selector = ?', selector)[0].token_hash, 'only a hash of the secret is stored').not.toBe(secret);

  await context.clearCookies({ name: 'gap_admin' }); // the session cookie is gone, as after closing the browser
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('.a-stats')).toBeVisible();
  const next = (await context.cookies()).find((c) => c.name === 'gap_remember')!;
  expect(decodeURIComponent(next.value).split(':')[0], 'the token is swapped after each use').not.toBe(selector);
  expect(sql(info, 'SELECT expires_at < ? AS used FROM remember_tokens WHERE selector = ?', new Date().toISOString().slice(0, 19).replace('T', ' '), selector)[0].used).toBe(1);
});

test('every form and API call needs the CSRF token', async ({ page }, info) => {
  await login(page, users.admin);
  const name = uniq(info, 'Csrf tag');
  for (const res of [
    await page.request.post('/admin/api/tag', { data: { name } }),
    await page.request.post('/admin/api/tag', { data: { name }, headers: { 'X-CSRF-Token': 'not-the-token' } }),
  ]) {
    expect(res.status()).toBe(403);
    expect((await res.json()).error).toContain('expired');
  }
  expect((await page.request.post('/admin/users', { form: { name: 'x', email: 'x@test.localhost', password: pw } })).status()).toBe(403);
  expect((await page.request.post('/admin/logout')).status()).toBe(403);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM tags WHERE name = ?', name)[0].n).toBe(0);
  expect(sql(info, "SELECT COUNT(*) AS n FROM users WHERE email = 'x@test.localhost'")[0].n).toBe(0);
  const ok = await page.request.post('/admin/api/tag', { data: { name }, headers: { 'X-CSRF-Token': await csrf(page) } });
  expect(ok.status()).toBe(200);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM tags WHERE name = ?', name)[0].n).toBe(1);
  // The API never answers without a sign-in.
  await logout(page);
  expect((await page.request.post('/admin/api/tag', { data: { name: 'x' }, headers: { 'X-CSRF-Token': 'x' } })).status()).toBe(403);
  expect((await page.request.get('/admin/stories', { maxRedirects: 0 })).status()).toBe(303);
});

test('roles: an editor writes stories but cannot open users or the homepage builder', async ({ page }) => {
  await login(page, users.editor);
  await page.goto('/admin/stories');
  await expect(page.locator('h1')).toHaveText('Stories');
  for (const path of ['/admin/users', '/admin/homepage', '/admin/settings']) {
    const res = await page.goto(path);
    expect(res!.status(), path).toBe(403);
    await expect(page.locator('main')).toContainText('Only admins can open this page.');
  }
  await page.goto('/admin');
  const nav = narrow(page) ? '.a-sheet-nav' : '.a-nav-list';
  await expect(page.locator(`${nav} a`, { hasText: 'Users' })).toHaveCount(0);
  await expect(page.locator(`${nav} a`, { hasText: 'Homepage' })).toHaveCount(0);
  await expect(page.locator(`${nav} a`, { hasText: 'Stories' })).toHaveCount(1);
});

test('two-step login with an authenticator app', async ({ page }, info) => {
  const email = `twostep.${Date.now().toString(36)}.${info.project.name}@test.localhost`;
  await login(page, users.admin);
  await addUser(page, { name: 'Two Step', email, password: pw, role: 'editor' });
  await logout(page);
  await login(page, { email, password: pw });
  await page.goto('/admin/account');
  await expect(page.locator('.two-step-state')).toContainText('off');
  await page.click('text=Turn on two-step login');
  const secret = (await page.locator('.totp-secret').textContent())!.replace(/\s/g, '');
  expect(secret).toMatch(/^[A-Z2-7]{32}$/);
  await expect(page.locator('a[href^="otpauth://totp/"]')).toHaveCount(1);
  await page.fill('#totp-code', '000000');
  await page.click('text=Confirm and turn on');
  await expect(page.locator('.a-error')).toContainText('not right');
  await page.fill('#totp-code', totp(secret));
  await page.click('text=Confirm and turn on');
  await expect(page.locator('.a-flash')).toContainText('Two-step login is on');
  expect(sql(info, 'SELECT totp_enabled, totp_secret FROM users WHERE email = ?', email)[0]).toEqual({ totp_enabled: 1, totp_secret: secret });

  await logout(page);
  await page.goto('/admin/login');
  await page.fill('#email', email);
  await page.fill('#password', pw);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/admin\/two-step/);
  expect((await page.request.get('/admin/stories', { maxRedirects: 0 })).status(), 'not signed in before the code').toBe(303);
  await page.fill('#code', '123456' === totp(secret) ? '654321' : '123456');
  await page.click('button[type="submit"]');
  await expect(page.locator('.a-error')).toContainText('not right');
  // The code used to turn it on cannot be used again; the next one from the app works.
  await page.fill('#code', totp(secret, Math.floor(Date.now() / 30000) + 1));
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('.a-stats')).toBeVisible();
});

/** Read the same numbers twice around the page load, so stories saved by other tests at that moment do not confuse it. */
// Other tests publish and trash stories at the same time (a bulk test can add 2 and take them away again
// while a page loads), so the page is loaded again until it matches the database.
async function stable<T>(read: () => T, load: () => Promise<void>, check: (before: T) => Promise<void>) {
  test.setTimeout(120_000);
  await expect(async () => {
    const before = read();
    await load();
    const after = read();
    expect(after, 'no other test changed the stories while the page loaded').toEqual(before);
    await check(after);
  }).toPass({ timeout: 100_000, intervals: [500] });
}

const countsSql = (now: string) => [`SELECT
  SUM(CASE WHEN status = 'published' AND published_at <= ? THEN 1 ELSE 0 END) AS published,
  SUM(CASE WHEN status = 'published' AND published_at > ? THEN 1 ELSE 0 END) AS scheduled,
  SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
  SUM(CASE WHEN status = 'trash' THEN 1 ELSE 0 END) AS trash FROM posts`, now, now] as const;
const nowUtc = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

test('dashboard: counts, views today, scheduled next, recent drafts, server check', async ({ page }, info) => {
  await login(page, users.admin);
  await stable(() => sql(info, ...countsSql(nowUtc()))[0], async () => { await page.goto('/admin'); }, async (c) => {
    await expect(page.locator('[data-stat="published"] b')).toHaveText(String(c.published));
    await expect(page.locator('[data-stat="scheduled"] b')).toHaveText(String(c.scheduled));
    await expect(page.locator('[data-stat="drafts"] b')).toHaveText(String(c.drafts));
  });
  // Views today come from views_daily (site time zone); a story view adds one.
  const day = new Date(Date.now() + 7 * 3600_000).toISOString().slice(0, 10);
  const before = Number(sql(info, 'SELECT views FROM views_daily WHERE day = ?', day)[0]?.views ?? 0);
  const story = sql(info, "SELECT slug FROM posts WHERE status = 'published' AND published_at <= ? ORDER BY id LIMIT 1", nowUtc())[0].slug;
  await page.request.get(`/news/${story}`);
  await page.request.get(`/asia/${story}`);
  await expect.poll(() => Number(sql(info, 'SELECT views FROM views_daily WHERE day = ?', day)[0]?.views ?? 0)).toBeGreaterThan(before);
  await page.reload();
  const shown = Number(await page.locator('[data-stat="views"]').getAttribute('data-value'));
  expect(shown).toBeGreaterThan(before);
  // Scheduled next = the 5 soonest scheduled stories; recent drafts = the 5 last edited drafts (other tests add drafts too).
  await stable(() => [
    sql(info, "SELECT title FROM posts WHERE status = 'published' AND published_at > ? ORDER BY published_at LIMIT 5", nowUtc()).map((r: any) => r.title),
    sql(info, "SELECT title FROM posts WHERE status = 'draft' ORDER BY updated_at DESC, id DESC LIMIT 5").map((r: any) => r.title),
  ], async () => { await page.reload(); }, async ([sched, drafts]) => {
    expect(sched).toContain('Scheduled sample story for later');
    await expect(page.locator('.scheduled-list li a')).toHaveText(sched);
    await expect(page.locator('.drafts-list li a')).toHaveText(drafts);
  });
  await expect(page.locator('.a-checks')).toContainText(process.env.GAP_DB === 'mysql' ? 'Database (MySQL)' : 'Database (SQLite)');
  await expect(page.locator('.a-checks')).toContainText('GD WebP');
  await expect(page.locator('.browser-check')).toHaveClass(/ok|bad/, { timeout: 60_000 });
  if (meta(info).engine !== 'webkit') await expect(page.locator('.browser-check')).toHaveClass(/ok/);
});

test('stories list: tabs with counts, search and filters', async ({ page }, info) => {
  await login(page, users.admin);
  await stable(() => sql(info, ...countsSql(nowUtc()))[0], async () => { await page.goto('/admin/stories'); }, async (c) => {
    await expect(page.locator('[data-tab="published"] .n')).toHaveText(String(c.published));
    await expect(page.locator('[data-tab="scheduled"] .n')).toHaveText(String(c.scheduled));
    await expect(page.locator('[data-tab="drafts"] .n')).toHaveText(String(c.drafts));
    await expect(page.locator('[data-tab="trash"] .n')).toHaveText(String(c.trash));
    // Number(): MySQL gives SUM() back as text ("42"), and "42" + "21" would join the texts.
    await expect(page.locator('[data-tab="all"] .n')).toHaveText(String(Number(c.published) + Number(c.scheduled) + Number(c.drafts)));
  });
  await page.click('[data-tab="scheduled"]');
  await expect(page.locator('.story-row').first()).toHaveAttribute('data-state', 'scheduled');
  await expect(page.locator('.story-row', { hasText: 'Scheduled sample story for later' })).toHaveCount(1);
  await page.click('[data-tab="all"]');
  await page.fill('#f-q', 'sea trials');
  await page.locator('#f-q').press('Enter');
  await expect(page.locator('.story-row')).toHaveCount(1);
  await expect(page.locator('.story-row .t')).toHaveText('Navy begins sea trials of new unmanned surface vessel');
  await page.fill('#f-q', '');
  const asia = String(sql(info, "SELECT id FROM categories WHERE slug = 'asia'")[0].id);
  await page.selectOption('#f-cat', asia);
  await page.click('.a-filters button');
  await expect(page).toHaveURL(/cat=/);
  // The address changes as soon as the filter's page starts to load; read the rows only once it has loaded
  // (WebKit read them in between: "Execution context was destroyed", step 5 run 2).
  await page.waitForLoadState('load');
  await expect(page.locator('#f-cat')).toHaveValue(asia);
  const cats = narrow(page) ? await page.locator('.story-row .c-phone').allTextContents() : await page.locator('.story-row .c-cat').allTextContents();
  expect(cats.length).toBeGreaterThan(5);
  for (const c of cats) expect(c).toContain('Asia');
  await noSidewaysScroll(page, 'stories list');
});

test('stories list: tick several stories to publish, move to trash, restore', async ({ page }, info) => {
  const word = `bulk${Date.now().toString(36)}${info.project.name.replace(/-/g, '')}`;
  for (const n of [1, 2]) {
    sql(info, "INSERT INTO posts (title, slug, excerpt, author_name, category_id, status, published_at, updated_at, created_at) SELECT ?, ?, '', '', id, 'draft', '2025-01-02 03:04:05', ?, ? FROM categories WHERE slug = 'business'",
      `Bulk test ${word} ${n}`, `${word}-${n}`, nowUtc(), nowUtc());
  }
  await login(page, users.admin);
  await page.goto(`/admin/stories?q=${word}`);
  await expect(page.locator('.story-row')).toHaveCount(2);
  await expect(page.locator('.a-bulk')).toBeHidden();
  await page.locator('.check-all').check();
  await expect(page.locator('.a-bulk-n')).toHaveText('2 chosen');
  await page.click('.a-bulk button[value="publish"]');
  await expect(page.locator('.a-flash')).toHaveText('2 stories published.');
  expect(sql(info, 'SELECT status, published_at FROM posts WHERE slug LIKE ? ORDER BY slug', `${word}-%`)).toEqual([
    { status: 'published', published_at: '2025-01-02 03:04:05' }, { status: 'published', published_at: '2025-01-02 03:04:05' }]);

  page.once('dialog', (d) => d.accept());
  await page.locator('.story-row input[type="checkbox"]').first().check();
  await page.locator('.story-row input[type="checkbox"]').nth(1).check();
  await page.click('.a-bulk button[value="trash"]');
  await expect(page.locator('.a-flash')).toHaveText('2 stories moved to the trash.');
  expect(sql(info, 'SELECT status FROM posts WHERE slug LIKE ?', `${word}-%`).map((r: any) => r.status)).toEqual(['trash', 'trash']);
  await page.goto(`/admin/stories?tab=trash&q=${word}`);
  await expect(page.locator('.story-row')).toHaveCount(2);
  await page.locator('.check-all').check();
  await page.click('.a-bulk button[value="restore"]');
  await expect(page.locator('.a-flash')).toHaveText('2 stories restored as drafts.');
  expect(sql(info, 'SELECT status FROM posts WHERE slug LIKE ?', `${word}-%`).map((r: any) => r.status)).toEqual(['draft', 'draft']);
});

/** On phones and tablets the editor shows one part at a time (Write / Details / Photos). */
async function part(page: Page, name: 'write' | 'details' | 'photos') {
  if (narrow(page)) await page.click(`.ed-tabs [data-show="${name}"]`);
}

test('story editor: headline, toolbar, tags and authors added on the spot (no reload), saved', async ({ page }, info) => {
  await login(page, users.admin);
  await page.goto('/admin/stories/new');
  await page.locator('#editor[data-ready]').waitFor();
  let loads = 0;
  page.on('load', () => loads++);
  const title = uniq(info, 'Editor draft');
  await page.fill('#ed-title', title);
  const bodyEl = page.locator('#ed-body');
  await bodyEl.click();
  await page.keyboard.type('First paragraph with ');
  await page.click('.ed-tool [data-cmd="bold"]');
  await page.keyboard.type('bold words');
  await page.click('.ed-tool [data-cmd="bold"]');
  await page.keyboard.type('.');
  await page.keyboard.press('Enter');
  await page.keyboard.type('A subhead');
  await page.click('.ed-tool [data-block="h2"]');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.type('Point one');
  await page.click('.ed-tool [data-cmd="insertUnorderedList"]');
  await expect(bodyEl.locator('b, strong')).toHaveText('bold words'); // browsers make <b>; the server stores <strong>
  await expect(bodyEl.locator('h2')).toHaveText('A subhead');
  await expect(bodyEl.locator('ul li')).toHaveText('Point one');

  await part(page, 'details');
  await page.fill('#ed-summary', 'Key point one\nKey point two');
  await page.fill('#ed-excerpt', 'Short card text for the editor test.');
  await page.fill('#ed-credits', 'Reporting by Test; Editing by Test');
  // Category: search, then choose.
  await page.fill('.ed-cat-search', 'asi');
  await expect(page.locator('.ed-cat-list label:not([hidden])')).toHaveCount(1);
  await page.locator('.ed-cat-list label:not([hidden]) input').check();
  // Tags: an existing one, then a new one ("Add new tag: <name>").
  const tagInput = page.locator('#ed-tag-input');
  await tagInput.fill('NAT');
  await page.locator('#ed-tag-list .a-opt', { hasText: 'NATO' }).click();
  await expect(page.locator('.ed-chips .chip')).toHaveText(['NATO ×']);
  const newTag = `Hyper ${Date.now().toString(36)}${info.project.name.replace(/-/g, '')}`;
  await tagInput.fill(newTag);
  await expect(page.locator('#ed-tag-list .a-opt.none')).toHaveText('No tag found');
  const add = page.locator('#ed-tag-list .a-add');
  await expect(add).toHaveText(`＋ Add new tag: "${newTag}"`);
  await add.click();
  await expect(page.locator('.ed-chips .chip', { hasText: newTag })).toHaveCount(1);
  await expect.poll(() => sql(info, 'SELECT COUNT(*) AS n FROM tags WHERE name = ?', newTag)[0].n, 'new tag saved at once').toBe(1);
  // It is in the picker straight away: remove the chip, search again (by the part only this test's tag has;
  // "Hyper mw3…" alone matches the other projects' tags too and the list shows 8).
  await page.locator('.ed-chips .chip', { hasText: newTag }).locator('button').click();
  await tagInput.fill(newTag.slice(6, -2));
  await expect(page.locator('#ed-tag-list .a-opt', { hasText: newTag })).toHaveCount(1);
  await page.locator('#ed-tag-list .a-opt', { hasText: newTag }).click();
  await expect(page.locator('.ed-chips .chip')).toHaveCount(2);

  // Author: "Add new author" with name, email and profile picture.
  const authorName = uniq(info, 'Jordan');
  const authorInput = page.locator('#ed-author-input');
  await authorInput.fill('zzz-no-such-author');
  await expect(page.locator('#ed-author-list .a-opt.none')).toHaveText('No other match');
  await page.locator('#ed-author-list .a-add').click();
  await expect(page.locator('#author-sheet')).toBeVisible();
  await page.fill('#ed-author-name', authorName);
  await page.fill('#ed-author-email', 'jordan@example.test');
  await page.setInputFiles('#ed-author-file', avatarFile);
  await expect(page.locator('.ed-author-pic img')).toBeVisible({ timeout: 60_000 });
  await page.click('.ed-author-save');
  await expect(page.locator('#author-sheet')).toBeHidden();
  await expect(authorInput).toHaveValue(authorName);
  const author = sql(info, 'SELECT a.id, a.email, m.widths FROM authors a JOIN media m ON m.id = a.media_id WHERE a.name = ?', authorName);
  expect(author.length, 'new author saved at once, with picture').toBe(1);
  expect(author[0].email).toBe('jordan@example.test');
  expect(author[0].widths).toBe('160,320');
  // In the picker straight away.
  await authorInput.fill(authorName.slice(0, 12));
  await expect(page.locator('#ed-author-list .a-opt', { hasText: authorName })).toHaveCount(1);
  await page.locator('#ed-author-list .a-opt', { hasText: authorName }).click();

  await page.check('#ed-sticky');
  await page.check('#ed-live');
  await expect(page.locator('.seo-title')).toHaveText(title);
  await page.click('.ed-save');
  await expect(page.locator('.ed-saved')).toContainText('Saved', { timeout: 15_000 });
  await expect(page).toHaveURL(/\/admin\/stories\/\d+$/);
  expect(loads, 'tags, author and saving happened without a page reload').toBe(0);

  const id = page.url().split('/').pop()!;
  const row = sql(info, 'SELECT p.title, p.status, p.summary, p.excerpt, p.credits, p.sticky, p.live_label, p.body, c.slug AS cat, p.author_id FROM posts p JOIN categories c ON c.id = p.category_id WHERE p.id = ?', id)[0];
  expect(row).toMatchObject({ title, status: 'draft', summary: 'Key point one\nKey point two', excerpt: 'Short card text for the editor test.', credits: 'Reporting by Test; Editing by Test', sticky: 1, live_label: 1, cat: 'asia', author_id: author[0].id });
  expect(row.body).toContain('<strong>bold words</strong>');
  expect(row.body).toContain('<h2>A subhead</h2>');
  expect(row.body).toContain('<li>Point one</li>');
  const tags = sql(info, 'SELECT t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.name', id).map((r: any) => r.name).sort();
  expect(tags).toEqual(['NATO', newTag].sort());

  // Open again: everything is still there.
  await page.reload();
  await page.locator('#editor[data-ready]').waitFor();
  await expect(page.locator('#ed-title')).toHaveValue(title);
  await expect(page.locator('#ed-body strong')).toHaveText('bold words'); // stored and opened again as <strong>
  await part(page, 'details');
  await expect(page.locator('.ed-chips .chip')).toHaveCount(2);
  await expect(page.locator('#ed-author-input')).toHaveValue(authorName);
  await expect(page.locator('.ed-cat-list input:checked')).toHaveValue(String(sql(info, "SELECT id FROM categories WHERE slug = 'asia'")[0].id));
  await noSidewaysScroll(page, 'editor');
});

test('story editor: pictures anywhere in the body (toolbar, paste, drop), each with caption + credit', async ({ page }, info) => {
  test.setTimeout(150_000);
  await login(page, users.admin);
  await page.goto('/admin/stories/new');
  await page.locator('#editor[data-ready]').waitFor();
  await page.fill('#ed-title', uniq(info, 'Pictures draft'));
  await page.locator('#ed-body').click();
  await page.keyboard.type('Text before the pictures.');
  // 1. Toolbar button.
  const chooser = page.waitForEvent('filechooser');
  await page.click('.ed-tool [data-picture]');
  await (await chooser).setFiles(photoFile);
  const figs = page.locator('#ed-body figure');
  const ready = async (n: number) => {
    await expect(figs).toHaveCount(n);
    await expect(page.locator('#ed-body figure[data-uploading], #ed-body figure[data-failed]')).toHaveCount(0, { timeout: 90_000 });
    await expect(page.locator('#ed-body figure[data-media=""]')).toHaveCount(0);
  };
  await ready(1);
  await page.keyboard.type('Text after the first picture.');
  // 2. Paste a picture.
  const bytes = [...readFileSync(photoFile2)];
  await page.locator('#ed-body').evaluate((el, data) => {
    const dt = new DataTransfer();
    dt.items.add(new File([new Uint8Array(data)], 'pasted.webp', { type: 'image/webp' }));
    const ev = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
    // Firefox gives a script-made paste event an empty clipboard (a real paste has the picture), so the
    // event carries the picture the way a real paste does.
    if (!ev.clipboardData?.files.length) Object.defineProperty(ev, 'clipboardData', { value: dt });
    el.dispatchEvent(ev);
  }, bytes);
  await ready(2);
  // 3. Drop a picture on the drop zone.
  await page.locator('.ed-drop').evaluate((el, data) => {
    const dt = new DataTransfer();
    dt.items.add(new File([new Uint8Array(data)], 'dropped.webp', { type: 'image/webp' }));
    for (const type of ['dragover', 'drop']) el.dispatchEvent(new DragEvent(type, { dataTransfer: dt, bubbles: true, cancelable: true }));
  }, bytes);
  await ready(3);

  const ids = await figs.evaluateAll((f) => f.map((x) => (x as HTMLElement).dataset.media));
  // Captions, credits, full width / inline, move, remove.
  await figs.nth(1).locator('.ed-cap').fill('Second caption');
  await figs.nth(1).locator('.ed-credit').fill('Photo Desk');
  await figs.nth(1).locator('[data-act="inline"]').click();
  await expect(figs.nth(1)).toHaveClass(/fig-inline/);
  await figs.nth(2).locator('.ed-cap').fill('Third caption');
  await figs.nth(2).locator('.ed-credit').fill('Agency');
  // "Up" moves the third picture one block up (past text too), until it is above the second picture.
  for (let i = 0; i < 6; i++) {
    const at = await figs.evaluateAll((fs) => fs.findIndex((f) => (f.querySelector('.ed-cap') as HTMLInputElement).value === 'Third caption'));
    if (at <= 1) break;
    await figs.nth(at).locator('[data-act="up"]').click();
  }
  await expect(figs.nth(1).locator('.ed-cap')).toHaveValue('Third caption');
  await figs.nth(0).locator('[data-act="remove"]').click();
  await expect(figs).toHaveCount(2);
  await part(page, 'details');
  await page.locator('.ed-cat-list input').first().check();
  await page.click('.ed-save');
  await expect(page.locator('.ed-saved')).toContainText('Saved', { timeout: 15_000 });
  const id = page.url().split('/').pop()!;

  const body: string = sql(info, 'SELECT body FROM posts WHERE id = ?', id)[0].body;
  const stored = [...body.matchAll(/<figure data-media="(\d+)" data-size="(\w+)" data-caption="([^"]*)" data-credit="([^"]*)"><\/figure>/g)].map((m) => m.slice(1));
  expect(stored).toEqual([[ids[2], 'full', 'Third caption', 'Agency'], [ids[1], 'inline', 'Second caption', 'Photo Desk']]);
  expect(body).toContain('Text before the pictures.');
  for (const mid of [ids[1], ids[2]]) {
    const m = sql(info, 'SELECT path, widths, has_avif, width, height FROM media WHERE id = ?', mid!)[0];
    expect(m.widths).toBe('480,960');
    expect([m.width, m.height]).toEqual([960, 640]);
    for (const w of [480, 960]) {
      expect(fileThere(info, `${m.path}-${w}.webp`), `${m.path}-${w}.webp saved`).toBe(true);
      if (m.has_avif === 1) expect(fileThere(info, `${m.path}-${w}.avif`)).toBe(true);
    }
    // Chromium and Firefox make AVIF + WebP in the browser; WebKit sends the picture and PHP GD makes them.
    if (meta(info).engine !== 'webkit') expect(m.has_avif, 'AVIF made in the browser').toBe(1);
  }
  // The story page (preview) shows the body pictures with their captions and credits.
  await page.goto(`/admin/preview/${id}`);
  const shown = page.locator('.article-body figure.body-fig');
  await expect(shown).toHaveCount(2);
  await expect(shown.nth(0).locator('figcaption')).toHaveText('Third caption Photo: Agency');
  await expect(shown.nth(1)).toHaveClass(/fig-inline/);
  await expect(shown.nth(1).locator('figcaption')).toHaveText('Second caption Photo: Photo Desk');
  await expect(shown.nth(0).locator('source[type="image/webp"], img')).not.toHaveCount(0);
  await expect.poll(() => shown.nth(0).locator('img').evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth)).toBeGreaterThan(0);
});

test('story HTML goes through the allow-list cleaner', async ({ page }, info) => {
  await login(page, users.admin);
  const token = await csrf(page);
  const cat = sql(info, "SELECT id FROM categories WHERE slug = 'land'")[0].id;
  const res = await page.request.post('/admin/api/story', {
    headers: { 'X-CSRF-Token': token },
    data: { title: uniq(info, 'Cleaner test'), status: 'draft', category_id: cat,
      body: '<p onclick="steal()" style="color:red">Hello <b>bold</b><script>alert(1)</script></p><img src="https://evil.example/x.png"><a href="javascript:alert(1)">bad link</a> <a href="https://example.com" target="_blank">good link</a><iframe src="https://evil.example"></iframe><style>p{}</style><figure data-media="999999" data-size="huge" onload="x()"><img src="https://evil.example/y.png"></figure>' },
  });
  expect(res.status()).toBe(200);
  const { id } = await res.json();
  const body: string = sql(info, 'SELECT body FROM posts WHERE id = ?', id)[0].body;
  expect(body).toBe('<p>Hello <strong>bold</strong></p><p>bad link <a href="https://example.com" rel="noopener">good link</a></p><figure data-media="999999" data-size="full" data-caption="" data-credit=""></figure>');
  await page.goto(`/admin/preview/${id}`);
  const html = await page.locator('.article-body').innerHTML();
  expect(html).not.toMatch(/script|onclick|evil|iframe|javascript:/);
  expect(html).not.toContain('<figure'); // a picture that is not in the media table is not shown
});

test('authors page: list with story counts; add and edit an author', async ({ page }, info) => {
  await login(page, users.editor);
  await page.goto('/admin/authors');
  for (const name of ['Alex Morgan', 'Sam Rivera', 'Priya Nair', 'Daniel Okafor', 'Mei Lin']) {
    const row = page.locator('.author-row', { hasText: name });
    await expect(row).toHaveCount(1);
    const n = sql(info, "SELECT COUNT(*) AS n FROM posts p JOIN authors a ON a.id = p.author_id WHERE a.name = ? AND p.status <> 'trash'", name)[0].n;
    expect(n).toBeGreaterThan(0);
    await expect(row.locator('.n-stories')).toHaveText(`${n} stories`);
  }
  await page.click('text=+ New author');
  const name = uniq(info, 'Casey');
  await page.fill('#a-name', name);
  await page.fill('#a-email', 'casey@example.test');
  await page.setInputFiles('.author-pic-file', avatarFile);
  await expect(page.locator('.author-pic-status')).toContainText('Picture ready', { timeout: 60_000 });
  await page.click('.author-form button[type="submit"]');
  await expect(page.locator('.a-flash')).toHaveText('Author saved.');
  const a = sql(info, 'SELECT id, email, media_id FROM authors WHERE name = ?', name)[0];
  expect(a.email).toBe('casey@example.test');
  expect(a.media_id).toBeGreaterThan(0);
  await page.fill('#a-name', `${name} Jr`);
  await page.click('.author-form button[type="submit"]');
  await expect(page.locator('.a-flash')).toHaveText('Author saved.');
  expect(sql(info, 'SELECT name FROM authors WHERE id = ?', a.id)[0].name).toBe(`${name} Jr`);
  await page.goto('/admin/authors');
  await expect(page.locator('.author-row', { hasText: `${name} Jr` })).toHaveCount(1);
});

test('sample stories keep working with the authors table', async ({ page }, info) => {
  expect(sql(info, "SELECT COUNT(*) AS n FROM posts WHERE slug LIKE 'sample-%' AND author_id IS NULL")[0].n).toBe(0);
  const p = sql(info, "SELECT p.slug, a.name, c.slug AS cat FROM posts p JOIN authors a ON a.id = p.author_id JOIN categories c ON c.id = p.category_id WHERE p.slug LIKE 'sample-3-%'")[0];
  await page.goto(meta(info).copy === 'live' ? `/${p.cat}/${p.slug}` : `/#news/${p.slug}`);
  await expect(page.locator('.article-meta')).toContainText(`By ${p.name}`);
  await expect(page.locator('.article-body p').first()).toContainText('This is a sample story');
});

test('phones and tablets: bottom tab bar, More sheet, editor tabs; desktop: side menu; no sideways scroll', async ({ page }) => {
  // 6 widths x 6 admin pages = 36 page loads, each with a sideways-scroll check: more than 45 s on
  // WebKit at phone size when 8 browsers run at once (measured 21 s idle, 50 s+ under load).
  test.setTimeout(180_000);
  await login(page, users.admin);
  await page.goto('/admin');
  await page.locator('html[data-ui="ready"]').waitFor({ state: 'attached' });
  if (narrow(page)) {
    await expect(page.locator('.a-tabbar')).toBeVisible();
    await expect(page.locator('.a-side')).toBeHidden();
    await expect(page.locator('.a-tabbar .tab')).toHaveCount(5);
    await page.locator('.a-tabbar [data-sheet-open="more-sheet"]').tap();
    await expect(page.locator('#more-sheet .a-sheet')).toBeVisible();
    // Polled: the sheet slides up for a moment after it opens.
    await expect.poll(() => sheetBottom(page, '#more-sheet .a-sheet'), 'sheet sits at the bottom').toBeGreaterThan(page.viewportSize()!.height - 4);
    await page.locator('#more-sheet .a-x').tap();
    await expect(page.locator('#more-sheet')).toBeHidden();
    await page.locator('.a-tabbar .tab', { hasText: 'Stories' }).tap();
    await expect(page).toHaveURL(/\/admin\/stories$/);
    await page.goto('/admin/stories/new');
  await page.locator('#editor[data-ready]').waitFor();
    await expect(page.locator('.ed-tabs')).toBeVisible();
    await expect(page.locator('#ed-body')).toBeVisible();
    await expect(page.locator('.ed-cat-search')).toBeHidden();
    await page.locator('.ed-tabs [data-show="details"]').tap();
    await expect(page.locator('.ed-cat-search')).toBeVisible();
    await expect(page.locator('#ed-body')).toBeHidden();
    await page.locator('.ed-tabs [data-show="photos"]').tap();
    await expect(page.locator('.ed-photo-pick')).toBeVisible();
    // New author opens as a sheet from the bottom.
    await page.locator('.ed-tabs [data-show="details"]').tap();
    await page.locator('#ed-author-input').fill('nobody');
    await page.locator('#ed-author-list .a-add').tap();
    await expect(page.locator('#author-sheet .a-sheet')).toBeVisible();
    await expect.poll(() => sheetBottom(page, '#author-sheet .a-sheet')).toBeGreaterThan(page.viewportSize()!.height - 4);
  } else {
    await expect(page.locator('.a-side')).toBeVisible();
    await expect(page.locator('.a-tabbar')).toBeHidden();
    await page.goto('/admin/stories/new');
  await page.locator('#editor[data-ready]').waitFor();
    await expect(page.locator('.ed-tabs')).toBeHidden();
    await expect(page.locator('#ed-body')).toBeVisible();
    await expect(page.locator('.ed-cat-search')).toBeVisible();
    await expect(page.locator('.ed-photo-pick')).toBeVisible();
  }
  for (const width of [320, 390, 768, 1100, 1440, 1920]) {
    await page.setViewportSize({ width, height: 900 });
    for (const path of ['/admin', '/admin/stories', '/admin/stories/new', '/admin/homepage', '/admin/authors', '/admin/account']) {
      await page.goto(path);
      await noSidewaysScroll(page, `${path} at ${width}px`);
    }
  }
});
