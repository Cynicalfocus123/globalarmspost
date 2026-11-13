import { test, expect, type TestInfo } from './fixtures';
import { sql, noSidewaysScroll } from './admin-helpers';

// Task F24 (owner, 2026-11-14), reader side: the dark footer with the footer menu's three columns on every page, and
// the pages it links to (About Us, Terms of Service, Privacy Policy, Advertise, Contact Us).
// Runs on phone, tablet and desktop in Chromium, Firefox and WebKit. The admin side (Pages, the footer menu builder,
// social links) is in menu.spec.ts (publish phase), so these tests never see a half-changed footer.

const device = (info: TestInfo) => (info.project.metadata as { device: string }).device;
const COLUMNS = ['About Us', 'Term of Use', 'Contact Us'];
const LINKS = ['About Us', 'Terms Of Use', 'Privacy Policy', 'Advertise', 'Contact Us'];

test('footer: the three columns and their links on every kind of page, side by side or stacked by width, no sideways scroll', async ({ page }, info) => {
  test.setTimeout(120_000);
  const story = sql(info, `SELECT p.slug, c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id
                            WHERE p.status = 'published' AND p.published_at <= ? ORDER BY p.published_at DESC LIMIT 1`, new Date().toISOString().slice(0, 19).replace('T', ' '))[0];
  const tag = sql(info, 'SELECT t.slug FROM tags t JOIN post_tags pt ON pt.tag_id = t.id LIMIT 1')[0];
  const paths = ['/', '/land', `/${story.cat}/${story.slug}`, '/search?q=army', '/about-us', '/no-such-page'];
  if (tag) paths.push(`/tag/${tag.slug}`);
  for (const path of paths) {
    await page.goto(path);
    const footer = page.locator('.site-footer');
    await footer.scrollIntoViewIfNeeded();
    await expect(footer.locator('.footer-col h2'), `footer on ${path}`).toHaveText(COLUMNS);
    await expect(footer.locator('.footer-col li a'), `footer links on ${path}`).toHaveText(LINKS);
    await noSidewaysScroll(page, `footer on ${path}`);
  }
  // A dark band with white column titles.
  const colors = await page.locator('.site-footer').evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, h: getComputedStyle(el.querySelector('h2')!).color }));
  expect(colors).toEqual({ bg: 'rgb(18, 22, 32)', h: 'rgb(255, 255, 255)' });
  // Phones: one column under the other; tablets and desktops: the three side by side.
  const tops = await page.locator('.footer-col').evaluateAll((cols) => cols.map((c) => Math.round(c.getBoundingClientRect().top)));
  if (device(info) === 'phone') expect(tops[0]).toBeLessThan(tops[1]);
  else expect(new Set(tops).size, 'the three columns start on one line').toBe(1);
  // Every link is big enough to tap (44 px rows on phones and tablets).
  const heights = await page.locator('.footer-col li a').evaluateAll((as) => as.map((a) => a.getBoundingClientRect().height));
  for (const h of heights) expect(h).toBeGreaterThanOrEqual(device(info) === 'desktop' ? 30 : 44);
  // The copyright bar under the footer: the name in red, the years up to this year.
  const bottom = page.locator('.footer-bottom p');
  await expect(bottom).toHaveText(new RegExp(`^GlobalArmsPost @2024-${new Date().getFullYear()}\\. All Rights Reserved\\.$`));
  expect(await bottom.locator('.footer-brand').evaluate((el) => getComputedStyle(el).color)).toBe('rgb(200, 16, 46)');
});

test('pages: every footer link opens its page with title, date and text; unknown pages answer 404; the sitemap lists them', async ({ page }, info) => {
  const rows = sql(info, 'SELECT slug, title FROM pages ORDER BY id');
  expect(rows.map((r: any) => r.slug)).toEqual(['about-us', 'terms-of-service', 'privacy-policy', 'advertise', 'contact']);
  for (const label of LINKS) {
    await page.goto('/');
    const link = page.locator('.site-footer .footer-col li a', { hasText: label }).first();
    await link.scrollIntoViewIfNeeded();
    await link.click();
    await expect(page.locator('.page-doc h1')).toBeVisible();
    const h1 = ((await page.locator('.page-doc h1').textContent()) ?? '').trim();
    const slug = rows.find((r: any) => r.title === h1);
    expect(slug, `${label} opens one of the pages`).toBeTruthy();
    await expect(page.locator('.page-doc .article-meta')).toContainText('Updated');
    await expect(page.locator('.page-doc .page-body')).not.toBeEmpty();
    await expect(page).toHaveTitle(new RegExp(slug.title));
    await noSidewaysScroll(page, `page ${label}`);
  }
  expect((await page.request.get('/about-us')).status()).toBe(200);
  expect((await page.request.get('/not-a-page-at-all')).status()).toBe(404);
  const map = await (await page.request.get('/sitemap.xml')).text();
  for (const r of rows) expect(map).toContain(`/${r.slug}</loc>`);
});
