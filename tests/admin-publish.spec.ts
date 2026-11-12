import { test, expect, type Page, type TestInfo } from './fixtures';
import { users, sql, login, uniq, narrow, sheetBottom, isLive, inLine, photoFile, photoFile2, bangkokInput, noSidewaysScroll } from './admin-helpers';

// Step 3, second part: admin actions that change what visitors see (publishing, new categories, the homepage).
// These run in the "-publish" projects, after the site and admin tests of the same copy, so the site tests never
// see a half-changed homepage. Same 18 browsers and devices.

async function part(page: Page, name: 'write' | 'details' | 'photos') {
  if (narrow(page)) await page.click(`.ed-tabs [data-show="${name}"]`);
}
const publicUrl = (info: TestInfo, cat: string, slug: string) => (isLive(info) ? `/${cat}/${slug}` : `/news/${slug}`);

async function newStory(page: Page, title: string) {
  await page.goto('/admin/stories/new');
  await page.locator('#editor[data-ready]').waitFor();
  await page.fill('#ed-title', title);
  await page.locator('#ed-body').click();
  await page.keyboard.type('The story text for the publish test.');
}

test('publish a story with a new category, main photo, gallery and body picture; it shows on the site', async ({ page }, info) => {
  test.setTimeout(150_000);
  await login(page, users.admin);
  const title = uniq(info, 'Published story');
  await newStory(page, title);
  const chooser = page.waitForEvent('filechooser');
  await page.click('.ed-tool [data-picture]');
  await (await chooser).setFiles(photoFile2);
  await expect(page.locator('#ed-body figure[data-uploading]')).toHaveCount(0, { timeout: 90_000 });
  await page.locator('#ed-body figure .ed-cap').fill('Body picture caption');
  await page.locator('#ed-body figure .ed-credit').fill('Body credit');

  await part(page, 'details');
  // New category on the spot, inside Regions.
  const catName = uniq(info, 'Arctic');
  await page.click('.ed-cat-add-open');
  await page.fill('#ed-cat-name', catName);
  await page.selectOption('#ed-cat-parent', String(sql(info, "SELECT id FROM categories WHERE slug = 'regions'")[0].id));
  await page.click('.ed-cat-save');
  const catSql = 'SELECT c.id, c.slug, p.slug AS parent FROM categories c JOIN categories p ON p.id = c.parent_id WHERE c.name = ?';
  await expect.poll(() => sql(info, catSql, catName).length, 'new category saved at once').toBe(1);
  const cat = sql(info, catSql, catName);
  expect(cat[0].parent).toBe('regions');
  await expect(page.locator('.ed-cat-list input:checked')).toHaveValue(String(cat[0].id));
  await expect(page.locator('.ed-cat-list label', { hasText: catName })).toHaveClass(/depth-1/);

  // Published, with a date in the past (so it does not push the sample stories off the homepage).
  await page.check('input[name="status"][value="published"]');
  await page.fill('#ed-date', '2026-01-15T10:30');
  await expect(page.locator('.ed-save')).toHaveText('Publish');
  await page.locator('#ed-author-input').fill('Priya');
  await page.locator('#ed-author-list .a-opt', { hasText: 'Priya Nair' }).click();

  await part(page, 'photos');
  await page.setInputFiles('#ed-photo-file', photoFile);
  await expect(page.locator('.ed-main-photo img')).toHaveAttribute('src', /^\/uploads\//, { timeout: 90_000 });
  await page.fill('#ed-photo-caption', 'Main photo caption');
  await page.fill('#ed-photo-credit', 'Main credit');
  await page.setInputFiles('#ed-gallery-file', photoFile2);
  await expect(page.locator('.ed-gallery li')).toHaveCount(1, { timeout: 90_000 });
  await page.locator('.ed-gallery .g-cap').fill('Gallery caption');
  await page.locator('.ed-gallery .g-credit').fill('Gallery credit');
  await page.click('.ed-save');
  await expect(page.locator('.ed-saved')).toContainText('Published', { timeout: 15_000 });
  const id = page.url().split('/').pop()!;
  const row = sql(info, 'SELECT p.slug, p.status, p.published_at, p.featured_caption, p.featured_credit, a.name AS author, p.media_id FROM posts p JOIN authors a ON a.id = p.author_id WHERE p.id = ?', id)[0];
  expect(row).toMatchObject({ status: 'published', published_at: '2026-01-15 03:30:00', featured_caption: 'Main photo caption', featured_credit: 'Main credit', author: 'Priya Nair' });
  expect(row.media_id).toBeGreaterThan(0);
  expect(sql(info, 'SELECT caption, credit FROM post_gallery WHERE post_id = ?', id)).toEqual([{ caption: 'Gallery caption', credit: 'Gallery credit' }]);

  // The public story page.
  const url = publicUrl(info, cat[0].slug, row.slug);
  expect((await page.request.get(url)).status()).toBe(200);
  await page.goto(isLive(info) ? url : `/#news/${row.slug}`);
  await expect(page.locator('main h1')).toHaveText(title);
  await expect(page.locator('.article-meta')).toContainText('By Priya Nair');
  // Step 4: the main photo is the first slide of the photo gallery (it was a single .article-figure before).
  await expect(page.locator('[data-gal] .gal-slide').first().locator('figcaption')).toHaveText('Main photo caption Photo: Main credit');
  await expect(page.locator('[data-gal] .gal-slide').nth(1).locator('figcaption')).toHaveText('Gallery caption Photo: Gallery credit'); // the gallery photo is the second slide
  await expect(page.locator('.article-body .body-fig figcaption')).toHaveText('Body picture caption Photo: Body credit');
  // The new category has its own page.
  await page.goto(isLive(info) ? `/${cat[0].slug}` : `/#regions/${cat[0].slug}`);
  await expect(page.locator('main h1')).toHaveText(catName);
  await expect(page.locator('main')).toContainText(title);
});

test('schedule: a future date keeps the story off the site until its time', async ({ page }, info) => {
  // Saving, the dashboard, two public checks and a database update: more than 45 s when the 8 workers
  // of the other publish projects are writing at the same time (the assertions are unchanged).
  test.setTimeout(150_000);
  await login(page, users.admin);
  const title = uniq(info, 'Scheduled story');
  await newStory(page, title);
  await part(page, 'details');
  await page.fill('.ed-cat-search', 'land');
  await page.locator('.ed-cat-list label:not([hidden]) input').first().check();
  await page.check('input[name="status"][value="published"]');
  await page.fill('#ed-date', bangkokInput(Date.now() + 2 * 86400_000));
  await expect(page.locator('.ed-save')).toHaveText('Schedule');
  await expect(page.locator('.ed-state')).toContainText('Scheduled');
  await page.click('.ed-save');
  await expect(page.locator('.ed-saved')).toContainText('Scheduled', { timeout: 15_000 });
  const id = page.url().split('/').pop()!;
  const row = sql(info, 'SELECT slug, status, published_at FROM posts WHERE id = ?', id)[0];
  expect(row.status).toBe('published');
  expect(row.published_at > new Date().toISOString().replace('T', ' ')).toBe(true);
  expect((await page.request.get(publicUrl(info, 'land', row.slug))).status()).toBe(404);
  await page.goto('/admin');
  await expect(page.locator('.scheduled-list')).toContainText(title);
  // Its time comes: it is public.
  sql(info, 'UPDATE posts SET published_at = ? WHERE id = ?', '2026-01-10 08:00:00', id);
  expect((await page.request.get(publicUrl(info, 'land', row.slug))).status()).toBe(200);
});

test('move to trash from the editor takes the story off the site', async ({ page }, info) => {
  // Same save + public checks as the schedule test above, and it also opens the stories list.
  test.setTimeout(150_000);
  await login(page, users.admin);
  const title = uniq(info, 'Trash story');
  await newStory(page, title);
  await part(page, 'details');
  await page.fill('.ed-cat-search', 'naval');
  await page.locator('.ed-cat-list label:not([hidden]) input').first().check();
  await page.check('input[name="status"][value="published"]');
  await page.fill('#ed-date', '2026-01-12T09:00');
  await page.click('.ed-save');
  await expect(page.locator('.ed-saved')).toContainText('Published', { timeout: 15_000 });
  const id = page.url().split('/').pop()!;
  const slug = sql(info, 'SELECT slug FROM posts WHERE id = ?', id)[0].slug;
  expect((await page.request.get(publicUrl(info, 'naval', slug))).status()).toBe(200);
  page.once('dialog', (d) => d.accept());
  await page.click('.ed-trash');
  await expect(page).toHaveURL(/tab=trash/);
  expect(sql(info, 'SELECT status FROM posts WHERE id = ?', id)[0].status).toBe('trash');
  expect((await page.request.get(publicUrl(info, 'naval', slug))).status()).toBe(404);
  await expect(page.locator('.story-row', { hasText: title })).toHaveCount(1);
});

test('homepage builder: drag into order, category checkboxes with search, leave out, saved to home_sections', async ({ page }, info) => {
  test.skip((info.project.metadata as any).device === 'phone', 'Owner 2026-11-11: the admin is used on desktop and laptop; phones are not needed for the admin (this drag test failed on the WebKit phone only).');
  test.setTimeout(300_000);
  await inLine(info, 'homepage', async () => {
    const original = sql(info, 'SELECT id, position, layout, title, span, settings, active FROM home_sections ORDER BY id');
    await login(page, users.admin);
    await page.goto('/admin/homepage');
    await page.locator('.hb-list[data-ready]').waitFor();
    const items = page.locator('.hb-list > li');
    await expect(items).toHaveCount(original.length);
    const order = () => items.evaluateAll((ls) => ls.map((l) => (l as HTMLElement).dataset.key));
    const before = await order();

    // Open the "Land" grid section's settings (a sheet from the bottom on phones and tablets).
    const land = page.locator('.hb-list > li', { hasText: 'Land' });
    await land.locator('.hb-open').click();
    if (narrow(page)) {
      await expect.poll(() => sheetBottom(page, '#hb-sheet .a-sheet'), 'settings sheet at the bottom').toBeGreaterThan(page.viewportSize()!.height - 4);
    }
    const box = page.locator('.a-catbox[data-box="categories"]');
    await expect(box.locator('input[value="land"]')).toBeChecked();
    await expect(box.locator('input[data-all="categories"]')).not.toBeChecked();
    // Children are indented under their parent.
    const parentX = (await box.locator('label', { hasText: 'Regions' }).boundingBox())!.x;
    const childX = (await box.locator('label', { hasText: 'Asia' }).boundingBox())!.x;
    expect(await box.locator('label', { hasText: 'Asia' }).getAttribute('class')).toContain('depth-1');
    expect(childX).toBeGreaterThanOrEqual(parentX);
    const childPad = await box.locator('label', { hasText: 'Asia' }).evaluate((el) => parseFloat(getComputedStyle(el).paddingLeft));
    expect(childPad).toBeGreaterThan(10);
    // Search narrows the list.
    await box.locator('.hb-cat-search').fill('eur');
    await expect(box.locator('label[data-name]:not([hidden])')).toHaveCount(1);
    await box.locator('.hb-cat-search').fill('');
    // "All categories" clears the choice; then choose Air + Asia.
    await box.locator('input[data-all="categories"]').check();
    await expect(box.locator('input[value="land"]')).not.toBeChecked();
    await box.locator('input[value="air"]').check();
    await box.locator('input[value="asia"]').check();
    await expect(box.locator('input[data-all="categories"]')).not.toBeChecked();
    await expect(page.locator('.hb-chosen[data-for="categories"]')).toContainText('2 chosen: Air, Asia');
    // Leave out: the same list, closed until opened.
    const leave = page.locator('details:has(.a-catbox[data-box="exclude_categories"])');
    await expect(leave).not.toHaveAttribute('open', '');
    await leave.locator('summary').click();
    await leave.locator('input[value="europe"]').check();
    await expect(leave.locator('.hb-sum')).toHaveText('1 chosen');
    await page.locator('#hb-count').fill('6');
    await page.locator('#hb-skip').fill('1');
    if (narrow(page)) await page.locator('.hb-done').click();

    // Drag Land to the top (pointer drag on the ⋮⋮ grip). The settings above may have scrolled the page,
    // so the top of the list is brought into view first (the mouse can only reach what is on screen).
    await items.first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
    const grip = land.locator('.a-grip');
    const g = (await grip.boundingBox())!;
    const top = (await items.first().boundingBox())!;
    await page.mouse.move(g.x + g.width / 2, g.y + g.height / 2);
    await page.mouse.down();
    await page.mouse.move(g.x + g.width / 2, top.y + 5, { steps: 8 });
    await page.mouse.up();
    await expect(items.first()).toContainText('Land');
    // ↑ ↓ buttons move too: Land goes down one and back up.
    await items.first().locator('[data-move="1"]').click();
    await expect(items.nth(1)).toContainText('Land');
    await items.nth(1).locator('[data-move="-1"]').click();
    await expect(items.first()).toContainText('Land');
    const landKey = `s${original.find((r: any) => r.title === 'Land').id}`;
    expect(await order(), 'Land on top, the rest in the old order').toEqual([landKey, ...before.filter((k) => k !== landKey)]);

    await page.click('.hb-save');
    await expect(page.locator('.hb-status')).toContainText('Saved', { timeout: 15_000 });
    const landId = original.find((r: any) => r.title === 'Land').id;
    const saved = sql(info, 'SELECT id, position, settings FROM home_sections WHERE active = 1 ORDER BY position, id');
    expect(saved[0].id).toBe(landId);
    const s = JSON.parse(saved[0].settings);
    expect(s).toMatchObject({ categories: ['air', 'asia'], exclude_categories: ['europe'], count: 6, skip: 1, columns: 4 });
    // The homepage shows it first.
    await page.goto('/');
    await expect(page.locator('.hs').first()).toHaveClass(/hs-grid/);
    await expect(page.locator('.hs').first().locator('.sec-head h2')).toHaveText('Land');

    // Put the homepage back as it was for the next browser (test housekeeping, straight in the database).
    for (const r of original) sql(info, 'UPDATE home_sections SET position = ?, layout = ?, title = ?, span = ?, settings = ?, active = ? WHERE id = ?', r.position, r.layout, r.title, r.span, r.settings, r.active, r.id);
  });
});

// ---------- Step 5 ----------

test('homepage builder: picture size sliders and the live preview (beside on desktop, under on phones)', async ({ page }, info) => {
  test.setTimeout(300_000);
  await inLine(info, 'homepage', async () => {
    const original = sql(info, 'SELECT id, position, layout, title, span, settings, active FROM home_sections ORDER BY id');
    try {
      await login(page, users.admin);
      await page.goto('/admin/homepage');
      await page.locator('.hb-list[data-ready]').waitFor();
      const frame = page.locator('.hb-frame');
      const inner = page.frameLocator('.hb-frame');
      await expect(frame).toHaveAttribute('data-loaded', /^\d+$/, { timeout: 30_000 });
      await expect(inner.locator('.preview-note')).toContainText('Preview of the homepage');
      // Beside the builder on wide screens, under it on phones and tablets.
      const list = (await page.locator('.hb-list').boundingBox())!;
      const prev = (await page.locator('.hb-preview').boundingBox())!;
      if (page.viewportSize()!.width >= 1280) expect(prev.x, 'preview beside the builder').toBeGreaterThan(list.x + list.width);
      else expect(prev.y, 'preview under the builder').toBeGreaterThanOrEqual(list.y + list.height - 1);
      // Drawn at phone width on phones, desktop width on wider screens; the switch changes it.
      await expect(frame).toHaveAttribute('data-width', narrow(page) ? '390' : '1280');
      await page.locator('.hb-prev-size label', { hasText: narrow(page) ? 'Desktop' : 'Phone' }).click();
      await expect(frame).toHaveAttribute('data-width', narrow(page) ? '1280' : '390');
      await page.locator('.hb-prev-size label', { hasText: narrow(page) ? 'Phone' : 'Desktop' }).click();
      // The scaled frame never makes the page wider.
      await noSidewaysScroll(page, 'homepage builder with preview');

      const loaded = async () => Number(await frame.getAttribute('data-loaded'));
      // Land (a grid): photo height 1 (low and wide).
      const land = page.locator('.hb-list > li', { hasText: 'Land' });
      await land.locator('.hb-open').click();
      let seq = await loaded();
      await page.locator('#hb-photo_height').fill('1');
      await expect(page.locator('[data-out="photo_height"]')).toHaveText('1');
      await expect.poll(loaded, { timeout: 30_000 }).toBeGreaterThan(seq);
      await expect(inner.locator('section.hs-grid[aria-label="Land"]')).toHaveClass(/(^|\s)ph-1(\s|$)/);
      if (narrow(page)) await page.locator('.hb-done').click();
      // Not saved yet: the real homepage is unchanged.
      const landId = original.find((r: any) => r.title === 'Land').id;
      expect(JSON.parse(sql(info, 'SELECT settings FROM home_sections WHERE id = ?', landId)[0].settings).photo_height ?? 3).toBe(3);

      // The "Top stories" list: small photos size 5.
      const rows = page.locator('.hb-list > li').filter({ hasText: 'Photo + title rows' }).first();
      await rows.locator('.hb-open').click();
      seq = await loaded();
      await page.locator('#hb-thumb_size').fill('5');
      await expect.poll(loaded, { timeout: 30_000 }).toBeGreaterThan(seq);
      await expect(inner.locator('section.hs-list')).toHaveClass(/(^|\s)th-5(\s|$)/);
      if (narrow(page)) await page.locator('.hb-done').click();

      await page.click('.hb-save');
      await expect(page.locator('.hb-status')).toContainText('Saved', { timeout: 15_000 });
      const listId = original.find((r: any) => r.layout === 'list').id;
      expect(JSON.parse(sql(info, 'SELECT settings FROM home_sections WHERE id = ?', landId)[0].settings)).toMatchObject({ photo_height: 1, thumb_size: 3 });
      expect(JSON.parse(sql(info, 'SELECT settings FROM home_sections WHERE id = ?', listId)[0].settings)).toMatchObject({ thumb_size: 5, photo_height: 3 });

      // The homepage uses them (tablets and desktops; phones keep their one big photo + rows).
      await page.goto('/');
      const landSec = page.locator('section.hs-grid[aria-label="Land"]');
      await expect(landSec).toHaveClass(/(^|\s)ph-1(\s|$)/);
      await expect(page.locator('section.hs-list')).toHaveClass(/(^|\s)th-5(\s|$)/);
      const media = (await landSec.locator('.card-media').nth(1).boundingBox())!;
      if (page.viewportSize()!.width >= 768) {
        expect(media.width / media.height, 'Land photos are 21:9').toBeGreaterThan(2.2);
        expect(media.width / media.height).toBeLessThan(2.45);
        const thumb = (await page.locator('section.hs-list .v-row .card-media').first().boundingBox())!;
        expect(Math.round(thumb.width), 'small photos are 144 px').toBeGreaterThanOrEqual(142);
      } else {
        expect(media.width / media.height, 'phones keep their small row photos').toBeLessThan(1.6);
      }
    } finally {
      // Put the homepage back as it was for the next browser (test housekeeping, straight in the database).
      for (const r of original) sql(info, 'UPDATE home_sections SET position = ?, layout = ?, title = ?, span = ?, settings = ?, active = ? WHERE id = ?', r.position, r.layout, r.title, r.span, r.settings, r.active, r.id);
    }
  });
});

test('SEO: code typed into a headline stays text in the page, its tags and the JSON-LD', async ({ page }, info) => {
  const slug = `seo-code-${Date.now().toString(36)}-${info.project.name}`;
  const title = 'Test </script><script>window.__gapX=1</script> "quoted" & <b>bold</b>';
  const cat = sql(info, "SELECT id FROM categories WHERE slug = 'business'")[0].id;
  const media = sql(info, 'SELECT id FROM media ORDER BY id LIMIT 1')[0].id;
  // Published long ago, so it never reaches the homepage or the top of a list while it exists.
  sql(info, `INSERT INTO posts (title, slug, excerpt, summary, body, credits, author_name, category_id, media_id, status, sticky, live_label, views, published_at, updated_at, created_at)
             VALUES (?, ?, ?, '', '<p>Body.</p>', '', ?, ?, ?, 'published', 0, 0, 0, '2001-01-01 00:00:00', '2001-01-01 00:00:00', '2001-01-01 00:00:00')`,
    title, slug, 'Excerpt with </script> and <img src=x onerror=alert(1)>', 'Author </script>', cat, media);
  try {
    const res = await page.goto(isLive(info) ? `/business/${slug}` : `/news/${slug}`);
    expect(res!.status()).toBe(200);
    expect(await page.evaluate(() => (window as any).__gapX)).toBeUndefined();
    const html = await res!.text();
    expect(html).not.toContain('<script>window.__gapX');
    expect(html).not.toContain('<img src=x');
    // The tags hold the text exactly as typed (escaped in the HTML).
    await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', title);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', 'Excerpt with and'); // the tags are removed, the spaces joined
    await expect(page.locator('h1.article-title')).toHaveText(title);
    // The JSON-LD has every <, > and & as \u escapes, so it can never end its script; read back, it is the same text.
    await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(1);
    const raw = /<script type="application\/ld\+json">([\s\S]*?)<\/script>/.exec(html)![1];
    expect(raw).not.toMatch(/[<>&]/);
    expect(raw).toContain('\\u003C/script\\u003E');
    const data = JSON.parse(raw);
    expect(data.headline).toBe(title);
    expect(data.author[0].name).toBe('Author </script>');
  } finally {
    sql(info, 'DELETE FROM posts WHERE slug = ?', slug); // the test's own row
  }
});

// ---------- Owner, 2026-11-13: extra categories, the big Saved message, homepage section names ----------

test('extra categories: main Land, also in Air; the story shows in both and the big green Saved message appears', async ({ page }, info) => {
  test.setTimeout(150_000);
  await login(page, users.admin);
  const title = uniq(info, 'Two category story');
  await newStory(page, title);
  await part(page, 'details');
  const landId = sql(info, "SELECT id FROM categories WHERE slug = 'land'")[0].id;
  const airId = sql(info, "SELECT id FROM categories WHERE slug = 'air'")[0].id;
  await page.locator(`.ed-cat-list input[value="${landId}"]`).check();
  // The main category is ticked and locked in "Also show in"; Air is ticked as an extra one.
  await expect(page.locator(`.ed-cat-extra input[value="${landId}"]`)).toBeChecked();
  await expect(page.locator(`.ed-cat-extra input[value="${landId}"]`)).toBeDisabled();
  await page.locator(`.ed-cat-extra input[value="${airId}"]`).check();
  await page.check('input[name="status"][value="published"]');
  await page.fill('#ed-date', '2026-01-14T09:00');
  await page.click('.ed-save');
  const toast = page.locator('#a-toast');
  await expect(toast).toBeVisible({ timeout: 15_000 });
  await expect(toast).toHaveAttribute('data-kind', 'ok');
  await expect(toast).toContainText('Story saved and published');
  const look = await toast.evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, size: parseFloat(getComputedStyle(el.querySelector('b')!).fontSize), w: el.getBoundingClientRect().width }));
  expect(look.bg, 'green').toBe('rgb(21, 128, 61)');
  expect(look.size, 'big text').toBeGreaterThanOrEqual(20);
  const id = page.url().split('/').pop()!;
  const row = sql(info, 'SELECT slug, category_id FROM posts WHERE id = ?', id)[0];
  expect(row.category_id).toBe(landId);
  expect(sql(info, 'SELECT category_id FROM post_categories WHERE post_id = ?', id)).toEqual([{ category_id: airId }]);
  // The address keeps the main category; the story is listed under both categories.
  expect((await page.request.get(publicUrl(info, 'land', row.slug))).status()).toBe(200);
  await page.goto(`/admin/stories?cat=${airId}`);
  await expect(page.locator('main')).toContainText(title);
  if (isLive(info)) {
    await page.goto('/air');
    await expect(page.locator('main')).toContainText(title);
    await page.goto('/land');
    await expect(page.locator('main')).toContainText(title);
  }
  // Opened again, the editor shows Air ticked; unticked and saved, the story leaves Air.
  await page.goto(`/admin/stories/${id}`);
  await page.locator('#editor[data-ready]').waitFor();
  await part(page, 'details');
  await expect(page.locator(`.ed-cat-extra input[value="${airId}"]`)).toBeChecked();
  await page.locator(`.ed-cat-extra input[value="${airId}"]`).uncheck();
  await page.click('.ed-save');
  await expect(page.locator('#a-toast')).toContainText('Story saved and published', { timeout: 15_000 });
  expect(sql(info, 'SELECT category_id FROM post_categories WHERE post_id = ?', id)).toEqual([]);
  // A save that fails shows the same box in red.
  await page.fill('#ed-title', '');
  await page.click('.ed-save');
  await expect(page.locator('#a-toast')).toHaveAttribute('data-kind', 'error');
  await expect(page.locator('#a-toast')).toContainText('Story not saved');
  // A page form (author) shows the big green message after it is sent.
  const author = sql(info, "SELECT id FROM authors WHERE name = 'Mei Lin'")[0].id;
  await page.goto(`/admin/authors/${author}`);
  await page.click('button[type="submit"]:has-text("Save author")');
  const flash = page.locator('.a-flash');
  await expect(flash).toHaveText('Author saved.');
  const flashLook = await flash.evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, size: parseFloat(getComputedStyle(el).fontSize) }));
  expect(flashLook.bg).toBe('rgb(21, 128, 61)');
  expect(flashLook.size).toBeGreaterThanOrEqual(18);
});

test('homepage builder: the two top sections are called Featured and Latest and top stories; an admin name is saved', async ({ page }, info) => {
  test.setTimeout(150_000);
  await inLine(info, 'homepage', async () => {
    const original = sql(info, 'SELECT id, position, layout, title, span, settings, active FROM home_sections ORDER BY id');
    try {
      await login(page, users.admin);
      await page.goto('/admin/homepage');
      await page.locator('.hb-list[data-ready]').waitFor();
      const names = await page.locator('.hb-list > li .hb-open b').allTextContents();
      expect(names.slice(0, 2)).toEqual(['Featured', 'Latest and top stories']);
      expect(names.filter((n) => n === 'Latest stories'), 'no two sections with the same made-up name').toHaveLength(0);
      // A name of its own: shown in the list at once, saved in the section's settings, never on the site.
      await page.locator('.hb-list > li').first().locator('.hb-open').click();
      await page.locator('#hb-name').fill('Top of the page');
      await expect(page.locator('.hb-list > li').first().locator('.hb-open b')).toHaveText('Top of the page');
      if (narrow(page)) await page.locator('.hb-done').click();
      await page.click('.hb-save');
      await expect(page.locator('#a-toast')).toContainText('Homepage saved', { timeout: 15_000 });
      const first = sql(info, 'SELECT settings FROM home_sections WHERE active = 1 ORDER BY position, id')[0];
      expect(JSON.parse(first.settings).name).toBe('Top of the page');
      await page.goto('/');
      await expect(page.locator('main')).not.toContainText('Top of the page');
    } finally {
      // Put the homepage back as it was for the next browser (test housekeeping, straight in the database).
      for (const r of original) sql(info, 'UPDATE home_sections SET position = ?, layout = ?, title = ?, span = ?, settings = ?, active = ? WHERE id = ?', r.position, r.layout, r.title, r.span, r.settings, r.active, r.id);
    }
  });
});
