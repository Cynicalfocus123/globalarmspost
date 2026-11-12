import { test, expect, type Page, type TestInfo } from './fixtures';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { users, sql, login, csrf, uniq, isLive, serverRoot, inLine } from './admin-helpers';

// Step 4: the page cache (task B12) and the trending tags row (task F11).
// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit, on the git/ and live/ copies.
//
// The page cache is on in the test runs, the same as on the real site (owner, 2026-11-05). Raw SQL in the
// tests clears it (tests/db.php), as every admin save does. ?cache=1 forces it on even when it is switched
// off in the admin; the response says X-Cache: HIT or MISS.
//
// Other tests run at the same time and their admin saves clear the cache too. So a cache test reads a page
// no other test asks for (a search for a word unique to this test), and "served from the cache" is checked
// again with a fresh page when a save elsewhere cleared it in between. A MISS right after our own save is
// always certain, so that check is never retried.

const cacheOn = (url: string) => url + (url.includes('?') ? '&' : '?') + 'cache=1';
/** A real public page that only this test asks for (a search for one unique word). */
const ownPage = (info: TestInfo, word: string) => `/search?q=${encodeURIComponent(uniq(info, word).replace(/\s+/g, '').toLowerCase())}`;

/** Expiry written in the cache file that holds exactly this html (this copy's site folder). */
function cacheExpiryOf(info: TestInfo, html: string): number {
  const dir = join(serverRoot(info, 'storage/'), 'storage', 'cache', 'pages');
  for (const f of readdirSync(dir).filter((n) => n.endsWith('.cache'))) {
    let raw = '';
    try { raw = readFileSync(join(dir, f), 'utf8'); } catch { continue; } // removed by a clear meanwhile
    const at = raw.indexOf('\n');
    if (at > 0 && raw.slice(at + 1) === html) return Number(raw.slice(0, at).split('|')[0]);
  }
  return 0;
}

/** Load a page twice: the first answer is rendered (MISS), the second comes from the cache (HIT) and is the same. */
async function renderedThenCached(page: Page, url: string): Promise<string> {
  const first = await page.request.get(cacheOn(url));
  expect(first.status()).toBe(200);
  expect(first.headers()['x-cache'], 'rendered the first time').toBe('MISS');
  const html = await first.text();
  const second = await page.request.get(cacheOn(url));
  expect(second.headers()['x-cache'], 'served from the cache the second time').toBe('HIT');
  expect(await second.text(), 'the cached page is the same page').toBe(html);
  return html;
}

test('a page is rendered once and then served from the cache', async ({ page }, info) => {
  // A save in another test may clear the cache between the two loads; then again with a new page.
  await expect(async () => { await renderedThenCached(page, ownPage(info, 'cached')); }).toPass({ timeout: 30_000 });
  // The homepage too: once rendered, it is served from the cache.
  await expect(async () => {
    await page.request.get(cacheOn('/'));
    const again = await page.request.get(cacheOn('/'));
    expect(again.headers()['x-cache']).toBe('HIT');
  }).toPass({ timeout: 30_000 });
});

test('admin pages, POSTs and unknown query parameters are never cached', async ({ page }) => {
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
  const url = ownPage(info, 'clear');
  await expect(async () => {
    await page.request.get(cacheOn(url));
    const cached = await page.request.get(cacheOn(url));
    expect(cached.headers()['x-cache'], 'cached before the save').toBe('HIT');
  }).toPass({ timeout: 30_000 });
  // Any save in the admin throws the cached pages away (this one creates a draft story).
  const res = await page.request.post('/admin/api/story', {
    headers: { 'X-CSRF-Token': await csrf(page) }, // every admin API call needs the session's token
    data: { title: uniq(info, 'Cache clear'), status: 'draft', category_id: sql(info, "SELECT id FROM categories WHERE slug = 'land'")[0].id, body: '<p>Clear the cache.</p>' },
  });
  expect(res.status()).toBe(200);
  const after = await page.request.get(cacheOn(url));
  expect(after.headers()['x-cache'], 'cleared by the save').toBe('MISS');
});

test('a cached page expires at the next scheduled story and then shows it', async ({ page }, info) => {
  test.setTimeout(90_000);
  // Our own scheduled story: the shared sample stories stay as they are for the tests running alongside.
  const slug = `cache-timed-${info.project.name}-${Date.now()}`;
  const title = `Cache timed story ${slug}`;
  const atMs = Math.ceil((Date.now() + 15_000) / 1000) * 1000;
  const at = new Date(atMs).toISOString().slice(0, 19).replace('T', ' ');
  sql(info, "INSERT INTO posts (title, slug, excerpt, author_name, category_id, status, published_at, updated_at, created_at) SELECT ?, ?, '', 'Test', id, 'published', ?, ?, ? FROM categories WHERE slug = 'land'", title, slug, at, at, at);
  try {
    expect(sql(info, 'SELECT status, published_at FROM posts WHERE slug = ?', slug)[0]).toEqual({ status: 'published', published_at: at });
    // Rendered now, before its time: the story is not shown, and the cached copy must die by its publish time.
    // A save in another test may clear the cache at any moment: then render again and look again.
    let html = '';
    let expiry = 0;
    await expect(async () => {
      html = await renderedThenCached(page, '/land');
      expiry = cacheExpiryOf(info, html);
      expect(expiry, 'the cache file of this page is there').toBeGreaterThan(0);
    }).toPass({ timeout: 10_000 });
    expect(html).not.toContain(title);
    expect(expiry, 'expires no later than the next scheduled story').toBeLessThanOrEqual(atMs / 1000);
    expect(expiry, 'and not before now').toBeGreaterThanOrEqual(Math.floor(Date.now() / 1000));
    // Once the time has come, the page shows the story (no stale cached copy).
    await new Promise((r) => setTimeout(r, Math.max(0, atMs - Date.now() + 1200)));
    const later = await page.request.get(cacheOn('/land'));
    expect(later.status()).toBe(200);
    expect(await later.text(), 'the story is on the page at its time').toContain(title);
  } finally {
    sql(info, "UPDATE posts SET status = 'trash' WHERE slug = ?", slug); // out of the way of other tests
  }
});

// The admin test below switches the row off for a moment (a site-wide setting). Both trending tests wait in
// the same line per copy (inLine), so the row test never runs while the row is switched off.
test('the trending tags row shows the tags most used by recent stories', async ({ page }, info) => {
  await inLine(info, 'trending', async () => {
  // The same rule as the site: public stories (published, and their time has come) of the last 14 days.
  const count = Math.max(1, Math.min(20, Number(sql(info, "SELECT value AS v FROM settings WHERE name = 'trending_tags_count'")[0]?.v ?? 8)));
  const nowSql = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const rows = sql(info, `SELECT t.name, COUNT(*) AS n FROM post_tags pt JOIN tags t ON t.id = pt.tag_id JOIN posts p ON p.id = pt.post_id
                          WHERE p.status = 'published' AND p.published_at <= ? AND p.published_at >= ?
                       GROUP BY t.id, t.name ORDER BY n DESC, t.name ASC LIMIT ${count}`,
    nowSql, new Date(Date.now() - 14 * 86400_000).toISOString().slice(0, 19).replace('T', ' '));
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
});

test('an admin can turn the trending row off and on', async ({ page }, info) => {
  await login(page, users.admin);
  await inLine(info, 'trending', async () => {
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
});

test('the search index can be rebuilt from the admin and stays complete', async ({ page }, info) => {
  await login(page, users.admin);
  await page.goto('/admin/settings');
  // Other tests save stories at the same time (each save indexes its story), so the counts compared are
  // those of the sample stories, which no test changes.
  const sampleTerms = () => Number(sql(info, "SELECT COUNT(*) AS n FROM search_post_terms WHERE post_id IN (SELECT id FROM posts WHERE slug LIKE 'sample-%')")[0].n);
  const before = sampleTerms();
  expect(before).toBeGreaterThan(0);
  const lastId = Number(sql(info, 'SELECT MAX(id) AS n FROM posts')[0].n);
  await page.locator('button:has-text("Rebuild search index")').click();
  await expect(page.locator('.a-flash')).toContainText('Search index rebuilt');
  expect(sampleTerms(), 'the same index rows for the sample stories').toBe(before);
  // Every story that was there before the rebuild has its index entry (stories added by raw SQL included).
  expect(sql(info, 'SELECT p.id FROM posts p LEFT JOIN search_docs d ON d.post_id = p.id WHERE d.post_id IS NULL AND p.id <= ?', lastId), 'no story left out').toEqual([]);
  // The vocabulary counts match the index exactly.
  expect(sql(info, `SELECT t.term FROM search_terms t LEFT JOIN (SELECT term, COUNT(*) AS n FROM search_post_terms GROUP BY term) x ON x.term = t.term
                     WHERE x.n IS NULL OR x.n <> t.docs LIMIT 5`), 'vocabulary counts are exact').toEqual([]);
  // And searching still finds a sample story afterwards.
  await page.goto(isLive(info) ? '/search?q=artillery' : '/#search?q=artillery');
  await expect(page.locator('.results .result-title').first()).toContainText('Artillery');
});
