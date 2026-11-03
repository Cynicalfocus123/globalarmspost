import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

// Step 1: page shell, header, footer, logo, links for both copies, database.
// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit, on the git/ and live/ copies.

const meta = (info: TestInfo) => info.project.metadata as { copy: 'git' | 'live'; engine: string; device: string };
const isLive = (info: TestInfo) => meta(info).copy === 'live';
const wide = (page: Page) => (page.viewportSize()?.width ?? 0) >= 1024;

/** Run SQL on the test copy's real database (through the site's own PHP code). */
function sql(copy: string, query: string, ...params: string[]): any {
  const info = JSON.parse(readFileSync(join(HERE, '..', 'test-data', `${copy}.json`), 'utf8'));
  const out = execFileSync(process.env.PHP_BIN || 'php', [...info.ext, join(HERE, 'db.php'), info.docroot, query, ...params], {
    env: { ...process.env, GAP_CONFIG: info.config },
  });
  return JSON.parse(out.toString());
}

async function noSidewaysScroll(page: Page) {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll, 'page must not scroll sideways').toBeLessThanOrEqual(client);
}

test('homepage loads with security headers and no third-party scripts', async ({ page }) => {
  const res = await page.goto('/');
  expect(res?.status()).toBe(200);
  const h = res!.headers();
  expect(h['content-security-policy']).toContain("default-src 'self'");
  expect(h['content-security-policy']).toContain("frame-ancestors 'none'");
  expect(h['x-content-type-options']).toBe('nosniff');
  expect(h['x-frame-options']).toBe('DENY');
  expect(h['referrer-policy']).toBe('strict-origin-when-cross-origin');
  const external = await page.evaluate(() => [...document.scripts].map((s) => s.src).filter((s) => s && !s.startsWith(location.origin)));
  expect(external).toEqual([]);
  await expect(page).toHaveTitle('Global Arms Post');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

test('header is the centred logo with the red menu below, no date or social links', async ({ page }) => {
  await page.goto('/');
  const header = page.locator('.site-header');
  const text = (await header.innerText()).toLowerCase();
  for (const word of ['facebook', 'rss', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', '2026']) {
    expect(text, `header must not show "${word}"`).not.toContain(word);
  }
  const logo = page.locator(wide(page) ? '.logo-row .logo img' : '.logo-bar img');
  await expect(logo).toBeVisible();
  const box = (await logo.boundingBox())!;
  const vw = page.viewportSize()!.width;
  expect(Math.abs(box.x + box.width / 2 - vw / 2), 'logo is centred').toBeLessThan(3);
  const bar = (await page.locator('.menu-bar').boundingBox())!;
  if (wide(page)) expect(bar.y, 'menu bar sits under the logo').toBeGreaterThanOrEqual(box.y + box.height);
  const red = await page.locator('.menu-bar').evaluate((el) => getComputedStyle(el).backgroundColor);
  expect(red).toBe('rgb(200, 16, 46)');
  const pageBg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  expect(pageBg).toBe('rgb(255, 255, 255)');
});

test('logo is sharp, light and not stretched', async ({ page }) => {
  await page.goto('/');
  const img = page.locator(wide(page) ? '.logo-row img' : '.logo-bar img');
  await expect(img).toHaveAttribute('width', '240');
  await expect(img).toHaveAttribute('height', '77');
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth)).toBeGreaterThan(0);
  const r = await img.evaluate((el: HTMLImageElement) => ({ src: el.currentSrc, nw: el.naturalWidth, nh: el.naturalHeight, w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }));
  expect(r.src).toMatch(/logo-(1x|2x)\.(avif|webp)$|logo\.png$/);
  expect(Math.abs(r.w / r.h - r.nw / r.nh), 'aspect ratio kept').toBeLessThan(0.03);
  const preload = page.locator('link[rel="preload"][as="image"]');
  await expect(preload).toHaveAttribute('imagesrcset', /logo-1x\.avif 1x, .*logo-2x\.avif 2x/);
  for (const [path, type] of [['/assets/img/logo/logo-2x.avif', 'image/avif'], ['/assets/img/logo/logo-2x.webp', 'image/webp'], ['/assets/img/logo/logo.png', 'image/png'], ['/favicon.ico', ''], ['/apple-touch-icon.png', 'image/png'], ['/site.webmanifest', 'application/manifest+json']]) {
    const res = await page.request.get(path);
    expect(res.status(), path).toBe(200);
    if (type) expect(res.headers()['content-type'], path).toContain(type);
  }
  const avif = await page.request.get('/assets/img/logo/logo-1x.avif');
  expect((await avif.body()).length, 'header logo stays small').toBeLessThan(15_000);
});

test('menu comes from the database, in order', async ({ page }, info) => {
  await page.goto('/');
  const top = page.locator('#main-menu > li > a');
  if (!wide(page)) await page.locator('.menu-toggle').click();
  await expect(top).toHaveText(['Home', 'Land', 'Air', 'Space', 'Naval', 'Global news', 'Regions', 'Business']);
  const rows = sql(meta(info).copy, "SELECT i.label FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.location = 'header' AND i.parent_id IS NULL ORDER BY i.position");
  expect(rows.map((r: any) => r.label)).toEqual((await top.allTextContents()).map((t) => t.trim()));
});

test('links: git copy uses # links, live copy uses proper addresses', async ({ page }, info) => {
  await page.goto('/');
  const hrefs = await page.locator('.site-header a, .site-footer a').evaluateAll((as) => as.map((a) => a.getAttribute('href') ?? ''));
  const get = async (label: string) => page.locator('#main-menu a', { hasText: new RegExp(`^${label}$`) }).first().getAttribute('href');
  if (isLive(info)) {
    // Only the "Skip to content" link may use a fragment; it is not a page address.
    expect(hrefs.filter((h) => h.includes('#'))).toEqual([]);
    expect(await get('Home')).toBe('/home');
    expect(await get('Land')).toBe('/land');
    expect(await get('Asia')).toBe('/asia');
    expect(await get('Middle East and Africa')).toBe('/middle-east-africa');
    await expect(page.locator('#site-search')).toHaveAttribute('action', '/search');
  } else {
    expect(await get('Home')).toBe('/#home');
    expect(await get('Land')).toBe('/#land');
    expect(await get('Asia')).toBe('/#regions/asia');
    await expect(page.locator('#site-search')).toHaveAttribute('action', '/#search');
  }
});

test('Regions dropdown opens by click or tap and lists the regions', async ({ page }) => {
  await page.goto('/');
  if (!wide(page)) {
    await page.locator('.menu-toggle').click();
    await expect(page.locator('.menu-toggle')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#main-menu')).toBeVisible();
  }
  const toggle = page.locator('.has-sub > .sub-toggle').first();
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  const sub = page.locator('.has-sub .submenu').first();
  await expect(sub).toBeVisible();
  await expect(sub.locator('a')).toHaveText(['U.S.', 'Asia', 'Europe', 'Middle East and Africa']);
  // Every menu target is big enough for a finger.
  const small = await page.locator('#main-menu a:visible, .sub-toggle:visible').evaluateAll((els) => els.filter((el) => el.getBoundingClientRect().height < 40).length);
  expect(small).toBe(0);
  await page.mouse.move(5, (page.viewportSize()?.height ?? 800) - 5); // away from the menu (desktop also opens on hover)
  await page.keyboard.press('Escape');
  await expect(sub).toBeHidden();
});

test('a region link opens its page (live) or its section link (git)', async ({ page }, info) => {
  await page.goto('/');
  if (!wide(page)) await page.locator('.menu-toggle').click();
  await page.locator('.has-sub > .sub-toggle').first().click();
  await page.locator('.submenu a', { hasText: 'Asia' }).click();
  if (isLive(info)) {
    await expect(page).toHaveURL(/\/asia$/);
    await expect(page.locator('h1')).toHaveText('Asia');
    await expect(page.locator('.crumbs')).toContainText('Home');
    await expect(page.locator('.crumbs')).toContainText('Regions');
    await expect(page).toHaveTitle('Asia | Global Arms Post');
  } else {
    await expect(page).toHaveURL(/\/#regions\/asia$/);
  }
});

test('phone menu opens and closes', async ({ page }, info) => {
  test.skip(wide(page), 'Desktop shows the full menu bar, there is no menu button (the dropdown test covers desktop).');
  await page.goto('/');
  const btn = page.locator('.menu-toggle');
  await expect(page.locator('#main-menu')).toBeHidden();
  await btn.click();
  await expect(page.locator('#main-menu')).toBeVisible();
  await expect(btn).toHaveAttribute('aria-label', 'Close menu');
  await btn.click();
  await expect(page.locator('#main-menu')).toBeHidden();
});

test('no sideways scroll from 320 to 1920 px', async ({ page }) => {
  for (const width of [320, 360, 390, 768, 1024, 1280, 1440, 1920]) {
    await page.setViewportSize({ width, height: 900 });
    for (const path of ['/', '/asia', '/no-such-page']) {
      await page.goto(path);
      await noSidewaysScroll(page);
    }
  }
});

test('dark mode switches and is remembered', async ({ page }) => {
  await page.goto('/');
  const btn = page.locator('.theme-toggle');
  await btn.click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await expect(btn).toHaveAttribute('aria-pressed', 'true');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe('rgb(17, 18, 20)');
  await page.locator('.theme-toggle').click();
  await expect(page.locator('html')).not.toHaveAttribute('data-theme', 'dark');
});

test('search box opens and closes', async ({ page }) => {
  await page.goto('/');
  const form = page.locator('#site-search');
  await expect(form).toBeHidden();
  await page.locator('.search-toggle').click();
  await expect(form).toBeVisible();
  await expect(page.locator('#q')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(form).toBeHidden();
});

test('red back-to-top button shows after scrolling and goes to the top', async ({ page }) => {
  await page.goto('/');
  const btn = page.locator('.to-top');
  await expect(btn).toBeHidden();
  await page.evaluate(() => { const d = document.createElement('div'); d.style.height = '3000px'; document.querySelector('main')!.append(d); window.scrollTo(0, 1500); });
  await expect(btn).toBeVisible();
  expect(await btn.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(200, 16, 46)');
  await btn.click();
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
});

test('footer shows the logo, about text and category links', async ({ page }) => {
  await page.goto('/');
  const footer = page.locator('.site-footer');
  await footer.scrollIntoViewIfNeeded();
  await expect(footer.locator('img')).toBeVisible();
  await expect(footer).toContainText('Global Arms Post covers defence');
  await expect(footer.locator('.footer-cats a')).toHaveCount(10);
});

test('pages and protected folders answer with the right status', async ({ page }, info) => {
  expect((await page.request.get('/no-such-page')).status()).toBe(404);
  expect((await page.request.get('/app/config.sample.php')).status()).toBe(403);
  expect((await page.request.get('/app/build.php')).status()).toBe(403);
  expect((await page.request.get('/storage/')).status()).toBe(403);
  expect((await page.request.get('/home')).status()).toBe(200);
  expect((await page.request.get('/asia')).status()).toBe(200);
  const res = await page.request.get('/admin');
  expect(res.status(), 'admin is not built yet; reserved name, not a category').toBe(404);
  void info;
});

test('database: migrations ran and a saved setting shows on the page', async ({ page }, info) => {
  test.skip(meta(info).device !== 'desktop' || meta(info).engine !== 'chromium', 'Server and database check: the same on every browser and device, so it runs once per copy (desktop Chromium). The other tests read this same database on every browser and device.');
  const copy = meta(info).copy;
  const migrations = sql(copy, 'SELECT id FROM migrations ORDER BY id').map((r: any) => r.id);
  expect(migrations).toEqual(['001_core', '002_starter_content']);
  expect(sql(copy, 'SELECT COUNT(*) AS n FROM categories')[0].n).toBe(11);
  const regions = sql(copy, "SELECT c.slug FROM categories c JOIN categories p ON p.id = c.parent_id WHERE p.slug = 'regions' ORDER BY c.position").map((r: any) => r.slug);
  expect(regions).toEqual(['us', 'asia', 'europe', 'middle-east-africa']);

  const value = `Tagline saved by test ${Date.now()}`;
  expect(sql(copy, "UPDATE settings SET value = ? WHERE name = 'tagline'", value).changed).toBe(1);
  expect(sql(copy, "SELECT value FROM settings WHERE name = 'tagline'")[0].value).toBe(value);
  await page.goto('/');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', value);

});
