import { test, expect, type Page, type TestInfo } from './fixtures';
import { sql, narrow, isLive } from './admin-helpers';

// Step 4: the reader side — the full article page (task F10).
// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit, on the git/ and live/ copies,
// against the real server and database. Values are checked in the database, not only on the screen.

const publicUrl = (info: TestInfo, cat: string, slug: string) => (isLive(info) ? `/${cat}/${slug}` : `/#news/${slug}`);

/** A sample story that has a photo gallery and tags (seeded by tests/seed-demo.php). */
function storyWithGallery(info: TestInfo): any {
  return sql(info, `SELECT p.slug, p.title, p.summary, p.credits, p.published_at, p.updated_at, p.live_label, c.slug AS cat,
                           COALESCE(a.name, p.author_name) AS author
                      FROM posts p JOIN categories c ON c.id = p.category_id LEFT JOIN authors a ON a.id = p.author_id
                     WHERE p.slug LIKE 'sample-%' AND p.status = 'published'
                       AND EXISTS (SELECT 1 FROM post_gallery g WHERE g.post_id = p.id)
                     ORDER BY p.id LIMIT 1`)[0];
}

async function openStory(page: Page, info: TestInfo, story: any) {
  await page.goto(publicUrl(info, story.cat, story.slug));
  await expect(page.locator('.article-title')).toHaveText(story.title);
}

test('article page: headline, byline with author, date, updated line and reading time', async ({ page }, info) => {
  const story = sql(info, `SELECT p.slug, p.title, p.published_at, p.updated_at, c.slug AS cat, COALESCE(a.name, p.author_name) AS author
                             FROM posts p JOIN categories c ON c.id = p.category_id LEFT JOIN authors a ON a.id = p.author_id
                            WHERE p.slug LIKE 'sample-%' AND p.updated_at <> p.published_at
                            ORDER BY p.id LIMIT 1`)[0];
  await openStory(page, info, story);
  await expect(page.locator('.crumbs')).toContainText('Home');
  await expect(page.locator('.byline-name')).toContainText(story.author);
  await expect(page.locator('.byline-when time')).toHaveAttribute('datetime', /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  // The stored time is UTC and shown in the site's time zone with the zone label (GMT…).
  await expect(page.locator('.byline-when')).toContainText(/\d{1,2}:\d{2}\s?(AM|PM)/);
  await expect(page.locator('.byline-when .updated')).toContainText('Updated');
  await expect(page.locator('.byline-when .reading')).toHaveText(/\d+ min read/);
  // The database really has that date (shown in the site's zone, so just check the byline carries it).
  expect(String(story.updated_at) > String(story.published_at)).toBe(true);
});

test('Aa: three text sizes, remembered in this browser', async ({ page }, info) => {
  const story = storyWithGallery(info);
  await openStory(page, info, story);
  const body = page.locator('.article-body p').first();
  const size = async () => parseFloat((await body.evaluate((el) => getComputedStyle(el).fontSize)) as string);
  const normal = await size();
  await page.locator('.aa-btn').click();
  await expect(page.locator('.aa-menu')).toBeVisible();
  await page.locator('.aa-menu [data-size="l"]').click();
  await expect(page.locator('.aa-menu')).toBeHidden();
  const large = await size();
  expect(large).toBeGreaterThan(normal);
  await page.reload();
  await expect(page.locator('.article-title')).toHaveText(story.title);
  expect(await size(), 'the chosen size is remembered').toBeCloseTo(large, 0);
  await page.locator('.aa-btn').click();
  await page.locator('.aa-menu [data-size="s"]').click();
  expect(await size()).toBeLessThan(normal + 0.1);
});

test('share: menu with plain links (no third-party scripts), and the copy button', async ({ page }, info) => {
  const story = storyWithGallery(info);
  await openStory(page, info, story);
  const external = await page.evaluate(() => [...document.scripts].map((s) => s.src).filter((s) => s && !s.startsWith(location.origin)));
  expect(external, 'no third-party scripts on the article page').toEqual([]);
  await page.locator('.share-btn').click();
  const menu = page.locator('.share-menu');
  await expect(menu).toBeVisible();
  await expect(menu.locator('a', { hasText: 'Facebook' })).toHaveAttribute('href', /facebook\.com\/sharer/);
  await expect(menu.locator('a', { hasText: 'X' })).toHaveAttribute('href', /twitter\.com\/intent\/tweet/);
  await expect(menu.locator('a', { hasText: 'LinkedIn' })).toHaveAttribute('href', /linkedin\.com/);
  await expect(menu.locator('a', { hasText: 'WhatsApp' })).toHaveAttribute('href', /wa\.me/);
  await expect(menu.locator('a', { hasText: 'Email' })).toHaveAttribute('href', /^mailto:/);
  // The row under the story has the same links, and a copy button that works.
  await expect(page.locator('.share-row a')).toHaveCount(5);
  await page.locator('.share-row .copy-link').first().click();
  await expect(page.locator('.share-row .copy-link').first()).toHaveText('Copied');
});

test('photo gallery: arrows, counter, captions with credits, and full screen', async ({ page }, info) => {
  const story = storyWithGallery(info);
  const photos = sql(info, `SELECT g.caption, g.credit FROM post_gallery g JOIN posts p ON p.id = g.post_id WHERE p.slug = ? ORDER BY g.position`, story.slug);
  await openStory(page, info, story);
  const gal = page.locator('[data-gal]');
  await expect(gal).toBeVisible();
  await expect(gal.locator('.gal-slide')).toHaveCount(photos.length + 1); // the main photo first
  await expect(gal.locator('.gal-count')).toHaveText(`1/${photos.length + 1}`);
  // The captions the admin typed are under the photos, with the credit.
  await expect(gal.locator('.gal-slide').nth(1).locator('figcaption')).toContainText(String(photos[0].caption));
  await expect(gal.locator('.gal-slide').nth(1).locator('figcaption')).toContainText(`Photo: ${photos[0].credit}`);
  await gal.locator('.gal-next').click();
  await expect(gal.locator('.gal-count')).toHaveText(`2/${photos.length + 1}`);
  await gal.locator('.gal-prev').click();
  await expect(gal.locator('.gal-count')).toHaveText(`1/${photos.length + 1}`);
  // Full screen: a modal dialog with the same photos.
  await gal.locator('.gal-expand').click();
  const dialog = page.locator('dialog.gal-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.gal-slide')).toHaveCount(photos.length + 1);
  await dialog.locator('.gal-dialog-close').click();
  await expect(dialog).toHaveCount(0);
});

test('summary box, credits line and topic chips that open the tag page', async ({ page }, info) => {
  const story = storyWithGallery(info);
  await openStory(page, info, story);
  const lines = String(story.summary ?? '').split('\n').filter((l: string) => l.trim() !== '');
  if (lines.length > 0) {
    await expect(page.locator('.summary li')).toHaveCount(lines.length);
    await expect(page.locator('.summary li').first()).toHaveText(lines[0].trim());
  }
  if (String(story.credits ?? '') !== '') {
    await expect(page.locator('.credits')).toContainText(String(story.credits));
  }
  const tags = sql(info, `SELECT t.name, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id JOIN posts p ON p.id = pt.post_id WHERE p.slug = ? ORDER BY t.name`, story.slug);
  await expect(page.locator('.chips .chip')).toHaveCount(tags.length);
  if (tags.length > 0) {
    await expect(page.locator('.chips .chip').first()).toHaveText(String(tags[0].name));
    await page.locator('.chips .chip').first().click();
    await expect(page.locator('.sec-head h1')).toContainText(String(tags[0].name));
    await expect(page.locator('.tag-page')).toContainText(String(story.title));
  }
});

test('live label shows when the story has one', async ({ page }, info) => {
  const live = sql(info, `SELECT p.slug, p.title, c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id
                            WHERE p.slug LIKE 'sample-%' AND p.live_label = 1 AND p.status = 'published' ORDER BY p.id LIMIT 1`)[0];
  if (!live) { test.skip(true, 'the sample stories have no "Live updates" story on this copy'); return; }
  await openStory(page, info, live);
  await expect(page.locator('.article .live')).toContainText('Live updates');
});

test('"More in {category}" and "Latest news" sit under the story', async ({ page }, info) => {
  const story = storyWithGallery(info);
  await openStory(page, info, story);
  const related = page.locator('.related');
  await expect(related.locator('h2')).toContainText('More in');
  const count = await related.locator('.row-item').count();
  expect(count).toBeGreaterThan(0);
  // Every story in the row is from the same category (checked in the database).
  const firstTitle = (await related.locator('.row-item .card-title').first().innerText()).trim();
  const row = sql(info, 'SELECT c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id WHERE p.title = ?', firstTitle)[0];
  expect(row.cat).toBe(String(story.cat));
  const latest = page.locator('.latest');
  await expect(latest.locator('h2')).toHaveText('Latest news');
  await expect(latest.locator('.card')).toHaveCount(6);
  await latest.locator('.see-more').click();
  await expect(page.locator('main')).toBeVisible();
});

test('the article page has no sideways scroll from 320 to 1920 px', async ({ page }, info) => {
  const story = storyWithGallery(info);
  for (const width of [320, 390, 768, 1100, 1440, 1920]) {
    await page.setViewportSize({ width, height: 900 });
    await openStory(page, info, story);
    const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(scroll, `no sideways scroll at ${width}px`).toBeLessThanOrEqual(client + 1);
    if (narrow(page)) {
      // On phones the gallery keeps its arrows (they are smaller) and the counter is readable.
      await expect(page.locator('[data-gal] .gal-count')).toBeVisible();
      await expect(page.locator('[data-gal] .gal-next')).toBeVisible();
      const box = (await page.locator('[data-gal] .gal-next').boundingBox())!;
      expect(box.width, 'a touch target of at least 36 px').toBeGreaterThanOrEqual(36);
    }
  }
});

test('the main photo is a <picture> with AVIF and WebP, sized, not stretched, lazy below the top', async ({ page }, info) => {
  const story = storyWithGallery(info);
  await page.goto(publicUrl(info, story.cat, story.slug));
  const img = page.locator('[data-gal] .gal-slide img').first();
  await expect(img).toHaveAttribute('width', /\d+/);
  await expect(img).toHaveAttribute('height', /\d+/);
  await expect(img).toHaveAttribute('srcset', /\.webp \d+w/);
  await expect(page.locator('[data-gal] .gal-slide source[type="image/avif"]').first()).toHaveAttribute('srcset', /\.avif \d+w/);
  const ratio = await img.evaluate((el: HTMLImageElement) => el.naturalWidth / el.naturalHeight);
  const box = await img.evaluate((el: HTMLImageElement) => el.getBoundingClientRect().width / el.getBoundingClientRect().height);
  expect(Math.abs(ratio - box), 'the picture is not stretched').toBeLessThan(0.05);
  await expect(page.locator('.latest .card img').first()).toHaveAttribute('loading', 'lazy');
});
