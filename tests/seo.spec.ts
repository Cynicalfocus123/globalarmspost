import { test, expect, type Page, type TestInfo } from './fixtures';
import { sql, nowSql } from './admin-helpers';

// Step 5, task F12: SEO. Every public page has its title, description, canonical address, Open Graph and X card
// tags; story pages add the NewsArticle JSON-LD; /sitemap.xml and /robots.txt; old WordPress addresses move with
// a 301. Read-only: the sample stories of the test database, checked against what the database holds.
// (A headline with code in it, and site_url, change what visitors see: they are in admin-publish.spec.ts and
// menu.spec.ts, in the publish phase.)

const origin = (info: TestInfo) => new URL((info.project.use as any).baseURL).origin;
const metaContent = (page: Page, attr: 'name' | 'property', key: string) => page.locator(`meta[${attr}="${key}"]`).getAttribute('content');

/** A public sample story with a main photo, as the database has it. */
function sampleStory(info: TestInfo) {
  return sql(info, `SELECT p.id, p.slug, p.title, p.excerpt, p.published_at, p.updated_at, c.slug AS cat, c.name AS cat_name,
                           COALESCE(a.name, p.author_name) AS author, m.path, m.widths, m.width, m.height
                      FROM posts p JOIN categories c ON c.id = p.category_id LEFT JOIN authors a ON a.id = p.author_id JOIN media m ON m.id = p.media_id
                     WHERE p.status = 'published' AND p.published_at <= ? AND p.slug LIKE 'sample-%' ORDER BY p.id LIMIT 1`, nowSql())[0];
}

test('story page: title, description, canonical, Open Graph, X card and NewsArticle JSON-LD from the database', async ({ page }, info) => {
  const p = sampleStory(info);
  const url = `${origin(info)}/${p.cat}/${p.slug}`;
  await page.goto(`/${p.cat}/${p.slug}`);
  await expect(page).toHaveTitle(new RegExp(`^${p.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\| `));
  expect(await metaContent(page, 'name', 'description')).toBe(p.excerpt);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', url);
  expect(await metaContent(page, 'property', 'og:type')).toBe('article');
  expect(await metaContent(page, 'property', 'og:title')).toBe(p.title);
  expect(await metaContent(page, 'property', 'og:url')).toBe(url);
  expect(await metaContent(page, 'property', 'og:site_name')).toBeTruthy();
  const largest = Math.max(...String(p.widths).split(',').map(Number));
  const image = `${origin(info)}/${p.path}-${largest}.webp`;
  expect(await metaContent(page, 'property', 'og:image')).toBe(image);
  expect(Number(await metaContent(page, 'property', 'og:image:width'))).toBe(largest);
  expect(Number(await metaContent(page, 'property', 'og:image:height'))).toBe(Math.round((p.height * largest) / p.width));
  const published = p.published_at.replace(' ', 'T') + 'Z';
  expect(await metaContent(page, 'property', 'article:published_time')).toBe(published);
  expect(await metaContent(page, 'property', 'article:section')).toBe(p.cat_name);
  expect(await metaContent(page, 'name', 'twitter:card')).toBe('summary_large_image');
  expect(await metaContent(page, 'name', 'twitter:image')).toBe(image);
  // The picture really is there.
  expect((await page.request.get(image)).status()).toBe(200);

  const ld = page.locator('script[type="application/ld+json"]');
  await expect(ld).toHaveCount(1);
  const data = JSON.parse((await ld.textContent())!);
  expect(data).toMatchObject({
    '@context': 'https://schema.org', '@type': 'NewsArticle', headline: p.title, description: p.excerpt,
    datePublished: published, url, mainEntityOfPage: { '@id': url }, articleSection: p.cat_name, image: [image],
    author: [{ '@type': 'Person', name: p.author }], publisher: { '@type': 'Organization' },
  });
  expect(data.dateModified >= data.datePublished).toBe(true);
  // The logo in the JSON-LD is a real file.
  expect((await page.request.get(data.publisher.logo.url)).status()).toBe(200);
  // Share links use the same proper address.
  await expect(page.locator('.share-btn')).toHaveAttribute('data-url', url);
});

test('home, category and tag pages: canonical and description; search and missing pages are not indexed', async ({ page }, info) => {
  // Home: the tagline (another test may change it for a moment, so the page and the database are read together).
  await expect(async () => {
    await page.goto('/');
    const tagline = sql(info, "SELECT value FROM settings WHERE name = 'tagline'")[0].value;
    expect(await metaContent(page, 'name', 'description')).toBe(tagline);
  }).toPass({ timeout: 20_000 });
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${origin(info)}/`);
  expect(await metaContent(page, 'property', 'og:type')).toBe('website');
  expect(await metaContent(page, 'name', 'twitter:card')).toBe('summary');
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
  await expect(page.locator('meta[name="robots"]')).toHaveCount(0);

  // Category page, also page 2.
  await page.goto('/asia');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${origin(info)}/asia`);
  expect(await metaContent(page, 'name', 'description')).toMatch(/^Asia: /);
  expect(await metaContent(page, 'property', 'og:title')).toBe('Asia');
  await page.goto('/asia?page=2');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${origin(info)}/asia?page=2`);

  // Tag page.
  const tag = sql(info, `SELECT t.slug, t.name FROM tags t JOIN post_tags pt ON pt.tag_id = t.id JOIN posts p ON p.id = pt.post_id
                          WHERE p.status = 'published' AND p.published_at <= ? ORDER BY t.id LIMIT 1`, nowSql())[0];
  await page.goto(`/tag/${tag.slug}`);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${origin(info)}/tag/${tag.slug}`);
  expect(await metaContent(page, 'name', 'description')).toContain(tag.name);

  // Search results and missing pages: for visitors, not for search engines.
  await page.goto('/search?q=frigate');
  expect(await metaContent(page, 'name', 'robots')).toBe('noindex, follow');
  const missing = await page.goto('/no-such-page-here');
  expect(missing!.status()).toBe(404);
  expect(await metaContent(page, 'name', 'robots')).toBe('noindex, follow');
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(0);
});

test('sitemap.xml: home, every category, public stories with lastmod and tags; never drafts or scheduled stories', async ({ page }, info) => {
  // Other tests save sample stories meanwhile (a new updated_at): the sitemap and the database are read again then.
  await expect(async () => { await sitemapMatches(page, info); }).toPass({ timeout: 30_000 });
});

async function sitemapMatches(page: Page, info: TestInfo) {
  const now = nowSql();
  const stories = sql(info, `SELECT p.slug, c.slug AS cat, p.published_at, p.updated_at FROM posts p JOIN categories c ON c.id = p.category_id
                              WHERE p.status = 'published' AND p.published_at <= ? AND p.slug LIKE 'sample-%'`, now);
  const hidden = sql(info, "SELECT slug FROM posts WHERE slug IN ('scheduled-sample-story-for-later', 'draft-sample-story')");
  expect(stories.length).toBeGreaterThan(30);
  expect(hidden.length, 'the sample scheduled story and draft exist').toBe(2);
  const res = await page.request.get('/sitemap.xml');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('application/xml');
  const xml = await res.text();
  expect(xml.startsWith('<?xml version="1.0" encoding="UTF-8"?>')).toBe(true);
  expect(xml).toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');
  const entries = new Map([...xml.matchAll(/<url><loc>([^<]+)<\/loc>(?:<lastmod>([^<]+)<\/lastmod>)?<\/url>/g)].map((m) => [m[1], m[2] ?? '']));
  const o = origin(info);
  expect(entries.has(`${o}/`)).toBe(true);
  for (const c of sql(info, 'SELECT slug FROM categories')) expect(entries.has(`${o}/${c.slug}`), `category ${c.slug}`).toBe(true);
  // lastmod = the later of published and updated, but never in the future: a stored time after the moment the
  // sitemap was made shows that moment instead (the first sample story has an "updated" time 25 minutes ahead).
  const after = nowSql().replace(' ', 'T') + 'Z';
  const before = now.replace(' ', 'T') + 'Z';
  for (const s of stories) {
    const loc = `${o}/${s.cat}/${s.slug}`;
    expect(entries.has(loc), `story ${s.slug}`).toBe(true);
    const last = (s.updated_at > s.published_at ? s.updated_at : s.published_at).replace(' ', 'T') + 'Z';
    const got = entries.get(loc)!;
    if (last <= before) expect(got, `lastmod of ${s.slug}`).toBe(last);
    else {
      // The sitemap is cached (it may have been made a little before this test asked): never in the future, and
      // not before the story was published.
      const pub = s.published_at.replace(' ', 'T') + 'Z';
      expect(got >= pub && got <= after, `lastmod of ${s.slug} (stored ${last}, in the future) is the moment the sitemap was made: ${got}`).toBe(true);
    }
  }
  for (const h of hidden) expect(xml, `${h.slug} must never be in the sitemap`).not.toContain(h.slug);
  const tag = sql(info, `SELECT t.slug FROM tags t JOIN post_tags pt ON pt.tag_id = t.id JOIN posts p ON p.id = pt.post_id
                          WHERE p.status = 'published' AND p.published_at <= ? AND p.slug LIKE 'sample-%' LIMIT 1`, now)[0];
  expect(entries.has(`${o}/tag/${tag.slug}`)).toBe(true);
  // Nothing from the admin, no '#' addresses, and every lastmod is a full UTC time.
  for (const [loc, last] of entries) {
    expect(loc).not.toMatch(/#|\/admin/);
    if (last) expect(last).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  }
}

test('the sitemap is kept in the page cache, so it is not rebuilt on every request', async ({ page }) => {
  // A save elsewhere may clear the cache between the two requests: then again.
  await expect(async () => {
    await page.request.get('/sitemap.xml');
    const again = await page.request.get('/sitemap.xml');
    expect(again.headers()['x-cache']).toBe('HIT');
  }).toPass({ timeout: 30_000 });
});

test('robots.txt keeps search engines out of /admin and names the sitemap', async ({ page }, info) => {
  const res = await page.request.get('/robots.txt');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('text/plain');
  const text = await res.text();
  expect(text).toContain('User-agent: *');
  expect(text).toMatch(/^Disallow: \/admin$/m);
  expect(text).toContain(`Sitemap: ${origin(info)}/sitemap.xml`);
});

test('old WordPress addresses move to the new ones with a 301', async ({ page }, info) => {
  const p = sampleStory(info);
  for (const [old, to] of [
    ['/category/europe/', '/europe'],
    ['/category/regions/asia/', '/asia'],
    [`/2026/07/${p.slug}/`, `/${p.cat}/${p.slug}`],
    [`/2026/07/15/${p.slug}/`, `/${p.cat}/${p.slug}`],
  ]) {
    const res = await page.request.get(old, { maxRedirects: 0 });
    expect(res.status(), old).toBe(301);
    expect(res.headers()['location'], old).toBe(to);
  }
  // An old address of a story that is not public (the sample draft) is just not found.
  expect((await page.request.get('/2026/07/draft-sample-story/', { maxRedirects: 0 })).status()).toBe(404);
  expect((await page.request.get('/category/no-such-category/', { maxRedirects: 0 })).status()).toBe(404);
});
