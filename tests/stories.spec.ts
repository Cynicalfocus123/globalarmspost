import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Step 2: stories, homepage sections, category pages, story page. Same 18 projects as site.spec.ts.
// The test servers load sample stories (tests/seed-demo.php), including one scheduled and one draft story.

const HERE = dirname(fileURLToPath(import.meta.url));
const meta = (info: TestInfo) => info.project.metadata as { copy: 'git' | 'live'; engine: string; device: string };
const isLive = (info: TestInfo) => meta(info).copy === 'live';
/** Which test server's database: "<copy>-<port>" (see tests/serve.mjs). */
const dbKey = (info: TestInfo) => `${meta(info).copy}-${new URL((info.project.use as any).baseURL).port}`;
const phone = (page: Page) => (page.viewportSize()?.width ?? 0) < 768;

function sql(copy: string, query: string, ...params: string[]): any {
  const info = JSON.parse(readFileSync(join(HERE, '..', 'test-data', `${copy}.json`), 'utf8') /* copy = "<copy>-<port>" */);
  const out = execFileSync(process.env.PHP_BIN || 'php', [...info.ext, join(HERE, 'db.php'), info.docroot, query, ...params], {
    env: { ...process.env, GAP_CONFIG: info.config },
  });
  return JSON.parse(out.toString());
}

/** Open a category page in either copy (git/ loads '#' pages into the homepage). */
async function openCategory(page: Page, info: TestInfo, slug: string, parent = '') {
  if (isLive(info)) await page.goto(`/${slug}`);
  else {
    await page.goto(`/#${parent ? parent + '/' : ''}${slug}`);
    await expect(page.locator('main h1')).not.toHaveText('Global Arms Post');
  }
}

test('homepage shows the sections from the database, in order', async ({ page }, info) => {
  await page.goto('/');
  await expect(page.locator('.hs')).toHaveCount(8);
  const layouts = await page.locator('.hs').evaluateAll((els) => els.map((el) => [...el.classList].find((c) => c.startsWith('hs-') && c !== 'hs')));
  expect(layouts).toEqual(['hs-hero-grid', 'hs-list', 'hs-overlay-list', 'hs-overlay-list', 'hs-lead-grid', 'hs-grid', 'hs-lead-list', 'hs-headlines']);
  await expect(page.locator('.hs .sec-head h2')).toHaveText(['Asia', 'Middle East and Africa', 'Europe', 'Land', 'Air', 'Most read']);
  const more = await page.locator('.hs .see-more').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  expect(more).toEqual(isLive(info) ? ['/asia', '/middle-east-africa', '/europe', '/land', '/air'] : ['/#regions/asia', '/#regions/middle-east-africa', '/#regions/europe', '/#land', '/#air']);
  const rows = sql(dbKey(info), 'SELECT layout FROM home_sections WHERE active = 1 ORDER BY position');
  expect(rows.map((r: any) => 'hs-' + r.layout.replace('_', '-'))).toEqual(layouts);
});

test('scheduled and draft stories never show', async ({ page }, info) => {
  for (const path of ['/', isLive(info) ? '/asia' : '/asia']) {
    await page.goto(path);
    await expect(page.locator('body')).not.toContainText('Scheduled sample story for later');
    await expect(page.locator('body')).not.toContainText('Draft sample story');
  }
  expect((await page.request.get('/asia/scheduled-sample-story-for-later')).status()).toBe(404);
  expect((await page.request.get('/asia/draft-sample-story')).status()).toBe(404);
});

test('a scheduled story appears at its time (saved in the database)', async ({ page }, info) => {
  test.skip(meta(info).device !== 'desktop' || meta(info).engine !== 'chromium', 'Changes the database: runs once per copy (desktop Chromium). The page check is the same on every browser and device.');
  const copy = dbKey(info);
  const slug = `timed-${Date.now()}`;
  const title = `Timed test story ${slug}`;
  const future = new Date(Date.now() + 3600_000).toISOString().slice(0, 19).replace('T', ' ');
  sql(copy, "INSERT INTO posts (title, slug, excerpt, author_name, category_id, status, published_at, updated_at, created_at) SELECT ?, ?, '', 'Test', id, 'published', ?, ?, ? FROM categories WHERE slug = 'land'", title, slug, future, future, future);
  expect(sql(copy, 'SELECT status, published_at FROM posts WHERE slug = ?', slug)[0]).toEqual({ status: 'published', published_at: future });
  await openCategory(page, info, 'land');
  await expect(page.locator('main')).not.toContainText(title);

  const past = new Date(Date.now() - 60_000).toISOString().slice(0, 19).replace('T', ' ');
  sql(copy, 'UPDATE posts SET published_at = ? WHERE slug = ?', past, slug);
  await page.reload();
  if (!isLive(info)) await expect(page.locator('main h1')).toHaveText('Land');
  await expect(page.locator('.day').first()).toContainText(title);
  await expect(page.locator('.day-head').first()).toContainText('Today');
  sql(copy, "UPDATE posts SET status = 'trash' WHERE slug = ?", slug); // out of the way of other tests
});

test('no story shows twice on the homepage (avoid duplicates)', async ({ page }) => {
  await page.goto('/');
  // Visible cards outside "Most read" (that section allows repeats) and outside hidden tab panels.
  const hrefs = await page.locator('.hs:not(.hs-headlines) .hs-body:not([hidden]) .card-title a').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  expect(hrefs.length).toBeGreaterThan(20);
  expect(new Set(hrefs).size).toBe(hrefs.length);
});

test('Top stories / Latest tabs switch', async ({ page }) => {
  await page.goto('/');
  const tabs = page.locator('.hs-list [role="tab"]');
  await expect(tabs).toHaveText(['Top stories', 'Latest']);
  const panels = page.locator('.hs-list [role="tabpanel"]');
  await expect(panels.nth(0)).toBeVisible();
  await expect(panels.nth(1)).toBeHidden();
  await tabs.nth(1).click();
  await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
  await expect(panels.nth(1)).toBeVisible();
  await expect(panels.nth(0)).toBeHidden();
  await tabs.nth(1).press('ArrowLeft');
  await expect(panels.nth(0)).toBeVisible();
});

test('phones: each section is one big story, then rows with the photo on the right', async ({ page }) => {
  test.skip(!phone(page), 'Phone layout only. Tablet and desktop show the grid layouts (covered by the section and picture tests).');
  await page.goto('/');
  for (const sel of ['.hs-hero-grid', '.hs-overlay-list', '.hs-lead-grid', '.hs-grid', '.hs-lead-list']) {
    const sec = page.locator(sel).first();
    const secBox = (await sec.boundingBox())!;
    const first = (await sec.locator('.card.is-first .card-media').boundingBox())!;
    expect(first.width, `${sel}: big first photo`).toBeGreaterThan(secBox.width * 0.95);
    const rows = sec.locator('.card:not(.is-first)');
    const n = await rows.count();
    expect(n, `${sel}: has rows`).toBeGreaterThan(0);
    for (let i = 0; i < n; i++) {
      const media = (await rows.nth(i).locator('.card-media').boundingBox())!;
      const title = (await rows.nth(i).locator('.card-title').boundingBox())!;
      expect(media.x, `${sel} row ${i}: photo on the right`).toBeGreaterThan(title.x + title.width - 2);
      expect(media.width, `${sel} row ${i}: small photo`).toBeLessThan(secBox.width * 0.4);
    }
  }
});

test('desktop: section layouts use their columns', async ({ page }) => {
  test.skip((page.viewportSize()?.width ?? 0) < 1100, 'Desktop layout only (12-column grid from 1100 px). The phone test covers phones; tablet stacks sections full width.');
  await page.goto('/');
  const ys = await page.locator('.hs-hero-grid .grid .card').evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
  expect(new Set(ys).size, 'hero_grid cards sit in one row').toBe(1);
  const hero = (await page.locator('.hs-hero-grid').boundingBox())!;
  const list = (await page.locator('.hs-list').boundingBox())!;
  expect(list.x, 'list (span 4) sits next to hero_grid (span 8)').toBeGreaterThan(hero.x + hero.width);
  const lead = (await page.locator('.hs-lead-grid .v-lead').boundingBox())!;
  const grid = (await page.locator('.hs-lead-grid .grid').boundingBox())!;
  expect(grid.x, 'lead_grid: 2x2 cards right of the lead').toBeGreaterThan(lead.x + lead.width);
});

test('pictures load as AVIF/WebP with sizes set and are not stretched', async ({ page }) => {
  await page.goto('/');
  const imgs = page.locator('.hs .card-media img');
  expect(await imgs.count()).toBeGreaterThan(20);
  const first = imgs.first();
  await expect.poll(() => first.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth)).toBeGreaterThan(0);
  const info = await first.evaluate((el: HTMLImageElement) => ({ src: el.currentSrc, fit: getComputedStyle(el).objectFit, w: el.getAttribute('width'), h: el.getAttribute('height'), lazy: el.loading }));
  expect(info.src).toMatch(/scene-\d+-(480|960|1600)\.(avif|webp)$/);
  expect(info.fit).toBe('cover');
  expect([info.w, info.h]).toEqual(['1600', '1067']);
  const lazy = await imgs.evaluateAll((els) => (els as HTMLImageElement[]).filter((el) => el.loading === 'lazy').length);
  expect(lazy, 'pictures below the top load lazily').toBeGreaterThan(10);
});

test('category page: grouped by day with plain date headings, time on every story', async ({ page }, info) => {
  await openCategory(page, info, 'asia', 'regions');
  await expect(page.locator('main h1')).toHaveText('Asia');
  const heads = page.locator('.day-head');
  expect(await heads.count()).toBeGreaterThan(2);
  await expect(heads.first()).toHaveText(/^Today · \w+day, \d{1,2} \w+ \d{4}$/);
  const texts = await heads.allTextContents();
  const dates = texts.map((t) => Date.parse(t.replace(/^(Today|Yesterday) · /, '').replace(/^\w+day, /, '')));
  expect(dates.every((d, i) => i === 0 || d < dates[i - 1]), 'newest day first').toBe(true);
  const times = await page.locator('.story-row .story-meta time').allTextContents();
  expect(times.length).toBe(await page.locator('.story-row').count());
  for (const t of times) expect(t).toMatch(/^\d{1,2}:\d{2} (AM|PM) GMT\+7$/);
  // Same rows in the database (public Asia stories), same order.
  const rows = sql(dbKey(info), "SELECT p.title FROM posts p JOIN categories c ON c.id = p.category_id WHERE c.slug = 'asia' AND p.status = 'published' AND p.published_at <= ? ORDER BY p.published_at DESC LIMIT 20", new Date().toISOString().slice(0, 19).replace('T', ' '));
  await expect(page.locator('.story-title')).toHaveText(rows.map((r: any) => r.title));
  await expect(page.locator('.most-read li')).toHaveCount(5);
  await expect(page.locator('.day-head').first().locator('xpath=..')).not.toHaveAttribute('class', /calendar/);
});

test('category page: Older stories opens the next page', async ({ page }, info) => {
  await openCategory(page, info, 'regions');
  const older = page.locator('.older a');
  await expect(older).toHaveAttribute('href', isLive(info) ? '/regions?page=2' : '/#regions?page=2');
  const firstPage = await page.locator('.story-title').allTextContents();
  expect(firstPage.length).toBe(20);
  await older.click();
  await expect(page.locator('.story-title').first()).not.toHaveText(firstPage[0]);
  const second = await page.locator('.story-title').allTextContents();
  expect(second.filter((t) => firstPage.includes(t))).toEqual([]);
});

test('story page opens from the homepage and counts the view', async ({ page }, info) => {
  await page.goto('/');
  // A sample story: another test's "timed" story can be the newest for a moment and is then moved to the trash.
  const link = page.locator('.hs-hero-grid .card-title a[href*="sample-"]').first();
  const title = (await link.textContent())!.trim();
  const href = (await link.getAttribute('href'))!;
  expect(href).toMatch(isLive(info) ? /^\/[a-z-]+\/[a-z0-9-]+$/ : /^\/#news\/[a-z0-9-]+$/);
  const slug = href.split('/').pop()!;
  const before = Number(sql(dbKey(info), 'SELECT views FROM posts WHERE slug = ?', slug)[0].views);
  await link.click();
  await expect(page.locator('main h1')).toHaveText(title);
  await expect(page.locator('.article-meta')).toContainText('By ');
  await expect(page.locator('.article-meta time')).toHaveText(/^\w+ \d{1,2}, \d{4} \d{1,2}:\d{2} (AM|PM) GMT\+7$/);
  await expect(page.locator('.summary li')).toHaveCount(3);
  await expect.poll(() => Number(sql(dbKey(info), 'SELECT views FROM posts WHERE slug = ?', slug)[0].views)).toBeGreaterThan(before);
  if (isLive(info)) await expect(page).toHaveTitle(`${title} | Global Arms Post`);
});

test('links: live copy uses proper story and category addresses, git copy uses # links', async ({ page }, info) => {
  await page.goto('/');
  const hrefs = await page.locator('main a').evaluateAll((as) => as.map((a) => a.getAttribute('href') ?? ''));
  if (isLive(info)) {
    expect(hrefs.filter((h) => h.includes('#'))).toEqual([]);
    expect(hrefs.some((h) => /^\/asia\/sample-/.test(h))).toBe(true);
  } else {
    expect(hrefs.filter((h) => !h.startsWith('/#'))).toEqual([]);
  }
});

test('no sideways scroll on story pages from 320 to 1920 px', async ({ page }, info) => {
  await page.goto('/');
  // A sample story (another test's "timed" story can be the newest for a moment, then goes to the trash).
  const href = (await page.locator('.card-title a[href*="sample-"]').first().getAttribute('href'))!;
  for (const width of [320, 390, 768, 1100, 1440, 1920]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(href);
    if (!isLive(info)) await expect(page.locator('main h1.article-title')).toBeVisible();
    const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(scroll, `${width}px`).toBeLessThanOrEqual(client);
  }
});
