import { test, expect, type Page, type TestInfo } from './fixtures';
import { users, sql, login, uniq, narrow, sheetBottom, isLive, inLine, photoFile, photoFile2, bangkokInput } from './admin-helpers';

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
