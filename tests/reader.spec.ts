import { test, expect, type TestInfo } from '@playwright/test';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { users, sql, login, uniq, isLive, serverInfo } from './admin-helpers';

// Step 4: the page cache (task B12) and the trending tags row (task F11).
// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit, on the git/ and live/ copies.
//
// The cache is off while debug is on (local work and these test runs change the database directly), so the
// tests turn it on for one page with ?cache=1 — the same code path the live site uses, marked by X-Cache.

const cacheOn = (url: string) => url + (url.includes('?') ? '&' : '?') + 'cache=1';

/** Expiry written in the newest cache file of this copy's site folder. */
function newestCacheExpiry(info: TestInfo): number {
  const dir = join(serverInfo(info).docroot, 'storage', 'cache', 'pages');
  const files = readdirSync(dir).filter((f) => f.endsWith('.cache')).map((f) => join(dir, f));
  expect(files.length, 'a cache file exists').toBeGreaterThan(0);
  files.sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);
  const head = readFileSync(files[0], 'utf8').split('\n')[0];
  return Number(head.split('|')[0]);
}

test('a page is rendered once and then served from the cache', async ({ page }) => {
  const first = await page.request.get(cacheOn('/'));
  expect(first.status()).toBe(200);
  expect(first.headers()['x-cache']).toBe('MISS');
  const html = await first.text();
  const second = await page.request.get(cacheOn('/'));
  expect(second.headers()['x-cache']).toBe('HIT');
  expect(await second.text()).toBe(html);
});

test('admin pages, POSTs and unknown query parameters are never cached', async ({ page }, info) => {
  await login(page, users.admin);
  const admin = await page.request.get(cacheOn('/admin/stories'));
  expect(admin.status()).toBe(200);
  expect(admin.headers()['x-cache']).toBeUndefined();
  // A random parameter is not a page we cache (cache poisoning would be too easy).
  const odd = await page.request.get(cacheOn('/?utm_source=newsletter'));
  expect(odd.headers()['x-cache']).toBeUndefined();
});

test('an admin save clears the cache', async ({ page }, info) => {
  await login(page, users.admin);
  await page.request.get(cacheOn('/'));
  const cached = await page.request.get(cacheOn('/'));
  expect(cached.headers()['x-cache'], 'cached before the save').toBe('HIT');
  // Any save in the admin throws the cached pages away (this one creates a draft story).
  const res = await page.request.post('/admin/api/story', {
    data: { title: uniq(info, 'Cache clear'), status: 'draft', category_id: sql(info, "SELECT id FROM categories WHERE slug = 'land'")[0].id, body: '<p>Clear the cache.</p>' },
  });
  expect(res.status()).toBe(200);
  const after = await page.request.get(cacheOn('/'));
  expect(after.headers()['x-cache'], 'cleared by the save').toBe('MISS');
});

test('a cached homepage expires at the next scheduled story and then shows it', async ({ page }, info) => {
  test.setTimeout(90_000);
  const scheduled = sql(info, "SELECT id, title, published_at FROM posts WHERE slug = 'scheduled-sample-story-for-later'")[0];
  const original = String(scheduled.published_at);
  const soon = new Date(Date.now() + 5_000).toISOString().slice(0, 19).replace('T', ' ');
  try {
    sql(info, 'UPDATE posts SET published_at = ? WHERE id = ?', soon, scheduled.id);
    await page.request.get(cacheOn('/'));
    // The cached page must die exactly when the scheduled story goes live, not later.
    const expiry = newestCacheExpiry(info);
    const at = Math.floor(new Date(soon.replace(' ', 'T') + 'Z').getTime() / 1000);
    expect(Math.abs(expiry - at), 'expiry is the next scheduled story time').toBeLessThanOrEqual(2);
    // Still cached right now…
    const now = await page.request.get(cacheOn('/'));
    expect(now.headers()['x-cache']).toBe('HIT');
    await expect.poll(async () => {
      const after = await page.request.get(cacheOn('/'));
      return after.headers()['x-cache'];
    }, { timeout: 30_000, intervals: [1000] }).toBe('MISS');
    // …and the story the schedule released is on the page now.
    const html = await (await page.request.get(cacheOn('/'))).text();
    expect(html).toContain(String(scheduled.title));
    expect(sql(info, "SELECT status FROM posts WHERE id = ?", scheduled.id)[0].status).toBe('published');
  } finally {
    sql(info, 'UPDATE posts SET published_at = ? WHERE id = ?', original, scheduled.id);
    await page.request.get(cacheOn('/')); // leave a fresh copy for the next test
  }
});

test('the trending tags row shows the tags most used by recent stories', async ({ page }, info) => {
  const rows = sql(info, `SELECT t.name, COUNT(*) AS n FROM post_tags pt JOIN tags t ON t.id = pt.tag_id JOIN posts p ON p.id = pt.post_id
                          WHERE p.status = 'published' AND p.published_at >= ?
                       GROUP BY t.id, t.name ORDER BY n DESC, t.name ASC LIMIT ?`,
    new Date(Date.now() - 14 * 86400_000).toISOString().slice(0, 19).replace('T', ' '),
    Number(sql(info, "SELECT value AS v FROM settings WHERE name = 'trending_tags_count'")[0]?.v ?? 8));
  await page.goto('/');
  const row = page.locator('.trending');
  await expect(row).toBeVisible();
  const chips = row.locator('.chip');
  expect(await chips.count()).toBeGreaterThan(0);
  expect((await chips.first().innerText()).trim()).toBe(String(rows[0].name));
  // A chip opens the tag page for that tag, with its stories.
  await chips.first().click();
  await expect(page.locator('.tag-page h1')).toContainText(String(rows[0].name));
  await expect(page.locator('.tag-page .v-row, .tag-page .card').first()).toBeVisible();
});

test('an admin can turn the trending row off and on', async ({ page }, info) => {
  await login(page, users.admin);
  await page.goto('/admin/settings');
  await expect(page.locator('h1')).toHaveText('Settings');
  const box = page.locator('input[name="trending_tags"]');
  await box.uncheck();
  await page.locator('button:has-text("Save settings")').click();
  await expect(page.locator('.a-flash')).toContainText('Settings saved');
  expect(sql(info, "SELECT value FROM settings WHERE name = 'trending_tags'")[0].value).toBe('0');
  await page.goto('/');
  await expect(page.locator('.trending')).toHaveCount(0);
  // Back on, so the other browsers see the row again.
  await page.goto('/admin/settings');
  await page.locator('input[name="trending_tags"]').check();
  await page.locator('button:has-text("Save settings")').click();
  await expect(page.locator('.a-flash')).toContainText('Settings saved');
  expect(sql(info, "SELECT value FROM settings WHERE name = 'trending_tags'")[0].value).toBe('1');
  await page.goto('/');
  await expect(page.locator('.trending')).toBeVisible();
});

test('the search index can be rebuilt from the admin and stays complete', async ({ page }, info) => {
  await login(page, users.admin);
  await page.goto('/admin/settings');
  const before = Number(sql(info, 'SELECT COUNT(*) AS n FROM search_post_terms')[0].n);
  expect(before).toBeGreaterThan(0);
  await page.locator('button:has-text("Rebuild search index")').click();
  await expect(page.locator('.a-flash')).toContainText('Search index rebuilt');
  const after = Number(sql(info, 'SELECT COUNT(*) AS n FROM search_post_terms')[0].n);
  expect(after).toBe(before);
  const documented = Number(sql(info, 'SELECT COUNT(*) AS n FROM posts')[0].n);
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM search_docs')[0].n)).toBe(documented);
  // And searching still finds a sample story afterwards.
  await page.goto(isLive(info) ? '/search?q=artillery' : '/#search?q=artillery');
  await expect(page.locator('.results .result-title').first()).toContainText('Artillery');
});
