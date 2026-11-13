import { test, expect, type Page, type TestInfo } from './fixtures';
import { users, sql, login, narrow, inLine, isLive, nowSql, noSidewaysScroll } from './admin-helpers';

// Step 5, publish phase (after the site and admin tests, like admin-publish.spec.ts): admin changes every visitor
// sees on every page.
//   F5   the menu builder: links to a category, tag, story or web address, nesting, order, hide, new tab
//   F17  categories (name, address, parent, header menu, order) and site settings (name, tagline, site address,
//        time zone, stories per category page), and F12's use of the site address
// The 9 browsers take turns (inLine 'menu'): each change is checked on the real header and pages, then put back.

/** Letters only, no vowels (so no real word, and never "eur" or "asia" that other tests search for). */
const letters = (n = 6) => Array.from({ length: n }, () => 'bcdfghjklmnpqrstvwxz'[Math.floor(Math.random() * 20)]).join('');

type Row = Record<string, any>;
const headerItems = (info: TestInfo): Row[] => sql(info, `SELECT i.id, i.parent_id, i.position, i.type, i.label, i.target, i.new_tab, i.hidden
  FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.location = 'header' ORDER BY i.id`);

/** Put the header menu back exactly as it was (test housekeeping, straight in the database). */
function restoreMenu(info: TestInfo, original: Row[]) {
  const ids = original.map((r) => Number(r.id));
  for (const r of headerItems(info)) if (!ids.includes(Number(r.id))) sql(info, 'DELETE FROM menu_items WHERE id = ?', r.id);
  for (const r of original) {
    sql(info, 'UPDATE menu_items SET position = ?, type = ?, label = ?, target = ?, new_tab = ?, hidden = ? WHERE id = ?', r.position, r.type, r.label, r.target, r.new_tab, r.hidden, r.id);
    if (r.parent_id === null) sql(info, 'UPDATE menu_items SET parent_id = NULL WHERE id = ?', r.id);
    else sql(info, 'UPDATE menu_items SET parent_id = ? WHERE id = ?', r.parent_id, r.id);
  }
}

/** On phones and tablets the item's settings are a sheet: close it with Done. */
const done = async (page: Page) => { if (narrow(page)) await page.locator('.mb-done').click(); };

test('menu builder: links to a web address, tag and story; nest, order, hide, new tab; saved and in the header at once', async ({ page }, info) => {
  test.setTimeout(300_000);
  page.on('dialog', (d) => d.accept());
  await inLine(info, 'menu', async () => {
    const original = headerItems(info);
    try {
      await login(page, users.admin);
      await page.goto('/admin/menu');
      await page.locator('.mb-list[data-ready]').waitFor();
      const items = page.locator('.mb-list > li[data-key]');
      await expect(items).toHaveCount(original.length);
      // Sub-items are indented under their parent (Regions -> U.S. ...).
      await expect(page.locator('.mb-list > li', { hasText: 'U.S.' })).toHaveClass(/mb-d-1/);
      const form = page.locator('.mb-form');
      const row = (text: string) => page.locator('.mb-list > li', { has: page.locator('.mb-label', { hasText: text }) });

      // 1. A web address. A script address is refused when saving.
      const extLabel = `Ext ${letters()}`;
      await page.click('.mb-add');
      await form.locator('#mb-type').selectOption('url');
      await form.locator('#mb-target').fill('javascript:alert(1)');
      await form.locator('#mb-label').fill(extLabel);
      await form.locator('input[data-f="new_tab"]').check();
      await done(page);
      await page.click('.mb-save');
      await expect(page.locator('.mb-status')).toContainText('a web address must start with https://, http://, / or mailto:');
      expect(headerItems(info).length, 'nothing saved').toBe(original.length);
      await row(extLabel).locator('.hb-open').click();
      await form.locator('#mb-target').fill('https://example.com/step5');
      await done(page);

      // 2. A tag (its name becomes the text by itself).
      const tag = sql(info, 'SELECT slug, name FROM tags ORDER BY name LIMIT 1')[0];
      await page.click('.mb-add');
      await form.locator('#mb-type').selectOption('tag');
      await expect(form.locator('#mb-target')).toHaveValue(tag.slug);
      await expect(form.locator('#mb-label')).toHaveValue(tag.name);
      await done(page);

      // 3. A story, found by searching its headline, with its own short text; then put under the tag item (→).
      const story = sql(info, `SELECT p.id, p.title, p.slug, c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id
                                WHERE p.status = 'published' AND p.published_at <= ? AND p.slug LIKE 'sample-%' ORDER BY p.id LIMIT 1`, nowSql())[0];
      const storyLabel = `Story ${letters()}`;
      await page.click('.mb-add');
      await form.locator('#mb-type').selectOption('story');
      await form.locator('#mb-story-q').fill(story.title.split(' ').slice(0, 4).join(' '));
      await form.locator(`.mb-story-opt[data-id="${story.id}"]`).click();
      await expect(form.locator('.mb-story-chosen')).toContainText(story.title);
      await form.locator('#mb-label').fill(storyLabel);
      await done(page);
      await row(storyLabel).locator('[data-act="in"]').click();
      await expect(row(storyLabel)).toHaveClass(/mb-d-1/);

      // 4. Hide Business.
      await row('Business').locator('.hb-open').click();
      await form.locator('input[data-f="show"]').uncheck();
      await done(page);
      await expect(row('Business')).toHaveClass(/\boff\b/);

      // 5. Drag the web address to the top (pointer drag on ⋮⋮), then ↓ and ↑ with the buttons. On phones and
      // tablets the list is taller than the screen: the item is held at the top edge and the page scrolls by
      // itself until the item is first (the pointer can only reach what is on screen).
      const grip = row(extLabel).locator('.a-grip');
      await grip.scrollIntoViewIfNeeded();
      const g = (await grip.boundingBox())!;
      await page.mouse.move(g.x + g.width / 2, g.y + g.height / 2);
      await page.mouse.down();
      await page.mouse.move(g.x + g.width / 2, 4, { steps: 10 });
      await expect.poll(async () => {
        await page.mouse.move(g.x + g.width / 2, 3); // still held at the top edge
        return (await items.first().locator('.mb-label').textContent())?.trim();
      }, { timeout: 20_000, message: 'the page scrolled up while the item was held at the top edge' }).toBe(extLabel);
      await page.mouse.up();
      await expect(items.first().locator('.mb-label')).toHaveText(extLabel);
      await items.first().locator('[data-act="down"]').click();
      await expect(items.nth(1).locator('.mb-label')).toHaveText(extLabel);
      await items.nth(1).locator('[data-act="up"]').click();
      await expect(items.first().locator('.mb-label')).toHaveText(extLabel);
      // Home and its place: Home is second now, still at the top level.
      await expect(items.nth(1).locator('.mb-label')).toHaveText('Home');

      await page.click('.mb-save');
      await expect(page.locator('.mb-status')).toContainText('Saved', { timeout: 15_000 });

      // Saved in the database.
      const saved = headerItems(info);
      const top0 = saved.filter((r) => r.parent_id === null).sort((a, b) => a.position - b.position);
      const ext = saved.find((r) => r.label === extLabel)!;
      expect(top0[0].id, 'the web address is first').toBe(ext.id);
      expect(ext).toMatchObject({ type: 'url', target: 'https://example.com/step5', new_tab: 1, hidden: 0 });
      const tagItem = saved.find((r) => r.type === 'tag' && r.target === tag.slug && Number(r.id) > Math.max(...original.map((o) => Number(o.id))))!;
      expect(tagItem).toBeTruthy();
      expect(tagItem.parent_id).toBeNull();
      const storyItem = saved.find((r) => r.label === storyLabel)!;
      expect(storyItem).toMatchObject({ type: 'story', target: String(story.id), parent_id: tagItem.id });
      expect(saved.find((r) => r.label === 'Business')!.hidden).toBe(1);
      expect(Number(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'menu.save'")[0].n)).toBeGreaterThan(0);

      // The header shows it at once (the page cache was cleared by the save).
      await page.goto('/');
      const menu = page.locator('#main-menu');
      const first = menu.locator(':scope > li').first().locator(':scope > a');
      await expect(first).toHaveText(extLabel);
      await expect(first).toHaveAttribute('href', 'https://example.com/step5');
      await expect(first).toHaveAttribute('target', '_blank');
      await expect(first).toHaveAttribute('rel', 'noopener');
      await expect(menu.locator(':scope > li > a', { hasText: 'Business' })).toHaveCount(0);
      const tagLi = menu.locator(':scope > li.has-sub', { has: page.locator(':scope > a', { hasText: tag.name }) });
      await expect(tagLi.locator(':scope > a')).toHaveAttribute('href', isLive(info) ? `/tag/${tag.slug}` : `/#tag/${tag.slug}`);
      await expect(tagLi.locator('.submenu a')).toHaveText(storyLabel);
      await expect(tagLi.locator('.submenu a')).toHaveAttribute('href', isLive(info) ? `/${story.cat}/${story.slug}` : `/#news/${story.slug}`);
      // The submenu opens by tap or click like Regions.
      if (!(page.viewportSize()!.width >= 1100)) await page.locator('.menu-toggle').click();
      await tagLi.locator('.sub-toggle').click();
      await expect(tagLi.locator('.submenu a')).toBeVisible();
    } finally {
      restoreMenu(info, original);
    }
  });
});

test('categories: add, rename, new address, parent, header menu and order; reserved addresses refused', async ({ page }, info) => {
  test.setTimeout(240_000);
  await inLine(info, 'menu', async () => {
    const originalMenu = headerItems(info);
    const positions = sql(info, 'SELECT id, position FROM categories ORDER BY id');
    try {
      await login(page, users.admin);
      // Add (top level).
      const name = `Polar ${letters()}`;
      await page.goto('/admin/categories');
      await page.locator('.cat-list[data-ready]').waitFor();
      await page.fill('#cat-new-name', name);
      await page.locator('.cat-add button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText(`Category ${name} added.`);
      const cat = sql(info, 'SELECT id, slug, parent_id FROM categories WHERE name = ?', name)[0];
      expect(cat.parent_id).toBeNull();

      // Edit: a reserved address and a taken one are refused.
      await page.goto(`/admin/categories/${cat.id}`);
      for (const [slug, words] of [['search', 'kept for the site'], ['admin', 'kept for the site'], ['asia', 'Another category already uses']]) {
        await page.fill('#cat-slug', slug);
        await page.locator('.cat-form button[type="submit"]').click();
        await expect(page.locator('.a-error')).toContainText(words);
        expect(sql(info, 'SELECT slug FROM categories WHERE id = ?', cat.id)[0].slug).toBe(cat.slug);
      }
      // Rename, new address, inside Regions, shown in the header menu.
      const regions = sql(info, "SELECT id FROM categories WHERE slug = 'regions'")[0].id;
      const newName = `Arctic ${letters()}`;
      const newSlug = `arctic-${letters()}`;
      await page.fill('#cat-name', newName);
      await page.fill('#cat-slug', newSlug);
      await page.selectOption('#cat-parent', String(regions));
      await page.check('input[name="in_menu"]');
      await page.locator('.cat-form button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText(`Category ${newName} saved.`);
      expect(sql(info, 'SELECT name, slug, parent_id FROM categories WHERE id = ?', cat.id)[0]).toEqual({ name: newName, slug: newSlug, parent_id: regions });
      const item = sql(info, "SELECT label, hidden, parent_id FROM menu_items WHERE type = 'category' AND target = ?", newSlug);
      expect(item).toEqual([{ label: newName, hidden: 0, parent_id: null }]);
      // Its page is at the new address only, and the header links to it.
      if (isLive(info)) {
        expect((await page.request.get(`/${newSlug}`)).status()).toBe(200);
        expect((await page.request.get(`/${cat.slug}`)).status()).toBe(404);
      }
      await page.goto('/');
      await expect(page.locator(`#main-menu a`, { hasText: newName })).toHaveCount(1);

      // Out of the header menu again: the item is hidden, not removed.
      await page.goto(`/admin/categories/${cat.id}`);
      await page.uncheck('input[name="in_menu"]');
      await page.locator('.cat-form button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText(`Category ${newName} saved.`);
      expect(sql(info, "SELECT hidden FROM menu_items WHERE type = 'category' AND target = ?", newSlug)).toEqual([{ hidden: 1 }]);
      await page.goto('/');
      await expect(page.locator(`#main-menu a`, { hasText: newName })).toHaveCount(0);

      // Order: it is among the last regions; ↑ puts it before the region above it; Save order.
      await page.goto('/admin/categories');
      await page.locator('.cat-list[data-ready]').waitFor();
      const li = page.locator(`.cat-list li[data-id="${cat.id}"]`);
      await expect(li).toHaveClass(/depth-1/);
      // ↓ works only when another region follows (the publish test in another browser may add one after it).
      const regionAfter = await li.evaluate((el) => !!el.nextElementSibling && el.nextElementSibling.classList.contains('depth-1'));
      await expect(li.locator('[data-move="1"]')).toBeEnabled({ enabled: regionAfter });
      const above = Number(await li.evaluate((el) => (el.previousElementSibling as HTMLElement).dataset.id));
      expect(sql(info, 'SELECT parent_id FROM categories WHERE id = ?', above)[0].parent_id, 'the row above is a region too').toBe(regions);
      await li.locator('[data-move="-1"]').click();
      const order = () => page.locator('.cat-list li[data-id]').evaluateAll((ls) => ls.map((l) => (l as HTMLElement).dataset.id));
      const ids = await order();
      expect(ids.indexOf(String(cat.id))).toBe(ids.indexOf(String(above)) - 1);
      await page.click('.cat-save');
      await expect(page.locator('.cat-status')).toHaveText('Order saved. The menu, footer and lists use it now.');
      const pos = (id: number) => Number(sql(info, 'SELECT position FROM categories WHERE id = ?', id)[0].position);
      expect(pos(cat.id)).toBeLessThan(pos(above));
      // The saved order is the page's order. (Another browser's publish test may add a category meanwhile: only
      // the categories this page showed are compared.)
      expect(sql(info, 'SELECT id FROM categories ORDER BY position, id').map((r: any) => String(r.id)).filter((id: string) => ids.includes(id))).toEqual(ids);
      // The site lists the categories in the saved order (the categories API, as the footer did before task F24).
      const listed = ((await (await page.request.get('/api/categories')).json()) as any[]).map((c: any) => c.name);
      const aboveName = sql(info, 'SELECT name FROM categories WHERE id = ?', above)[0].name;
      expect(listed.indexOf(newName)).toBe(listed.indexOf(aboveName) - 1);
    } finally {
      for (const r of positions) sql(info, 'UPDATE categories SET position = ? WHERE id = ?', r.position, r.id);
      restoreMenu(info, originalMenu);
    }
  });
});

test('site settings: name, tagline, site address, time zone and stories per page are saved and used', async ({ page }, info) => {
  test.setTimeout(180_000);
  await inLine(info, 'menu', async () => {
    const keys = ['site_name', 'tagline', 'about', 'site_url', 'timezone', 'category_per_page'];
    const original: Record<string, string | null> = {};
    for (const k of keys) original[k] = sql(info, 'SELECT value FROM settings WHERE name = ?', k)[0]?.value ?? null;
    try {
      await login(page, users.admin);
      await page.goto('/admin/settings');
      const name = `GAP test ${letters()}`;
      await page.fill('#s-name', name);
      await page.fill('#s-tagline', 'Tagline from the settings test');
      await page.fill('#s-url', 'https://news.example.test/');
      await page.selectOption('#s-tz', 'Asia/Jakarta'); // the same offset as Bangkok: scheduled tests elsewhere keep their times
      await page.fill('#s-per', '5');
      await page.locator('.site-form button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText('Site settings saved. The page cache was cleared.');
      const now = () => Object.fromEntries(keys.map((k) => [k, sql(info, 'SELECT value FROM settings WHERE name = ?', k)[0]?.value]));
      expect(now()).toMatchObject({ site_name: name, tagline: 'Tagline from the settings test', site_url: 'https://news.example.test', timezone: 'Asia/Jakarta', category_per_page: '5' });
      expect(Number(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'settings.site' AND detail LIKE ?", `${name}%`)[0].n)).toBe(1);

      // Wrong values are refused and change nothing.
      for (const [field, value, words] of [['#s-url', 'ftp://files.example.test', 'The site address must look like'], ['#s-url', 'https://news.example.test/page', 'The site address must look like'], ['#s-per', '3', 'between 5 and 50']]) {
        await page.goto('/admin/settings');
        await page.fill(field, value);
        await page.evaluate(() => { for (const el of document.querySelectorAll('.site-form input')) el.removeAttribute('min'); });
        await page.locator('.site-form button[type="submit"]').click();
        await expect(page.locator('.a-flash.warn')).toContainText(words);
      }
      expect(now()).toMatchObject({ site_url: 'https://news.example.test', category_per_page: '5' });

      // The pages use them: title, description, canonical address, robots.txt, sitemap, story tags, the API.
      await page.goto('/');
      await expect(page).toHaveTitle(name);
      await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', 'Tagline from the settings test');
      await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://news.example.test/');
      expect(await (await page.request.get('/robots.txt')).text()).toContain('Sitemap: https://news.example.test/sitemap.xml');
      expect(await (await page.request.get('/sitemap.xml')).text()).toContain('<loc>https://news.example.test/</loc>');
      const story = sql(info, `SELECT p.slug, c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id
                                WHERE p.status = 'published' AND p.published_at <= ? AND p.slug LIKE 'sample-%' ORDER BY p.id LIMIT 1`, nowSql())[0];
      await page.goto(`/${story.cat}/${story.slug}`);
      const url = `https://news.example.test/${story.cat}/${story.slug}`;
      await expect(page.locator('meta[property="og:url"]')).toHaveAttribute('content', url);
      await expect(page.locator('meta[property="og:site_name"]')).toHaveAttribute('content', name);
      expect(JSON.parse((await page.locator('script[type="application/ld+json"]').textContent())!).url).toBe(url);
      expect((await (await page.request.get('/api/posts?per=1')).json()).posts[0].url).toMatch(/^https:\/\/news\.example\.test\//);
      // Five stories per category page, then "Older stories".
      await page.goto('/regions');
      await expect(page.locator('.story-row')).toHaveCount(5);
      await expect(page.locator('.older a')).toBeVisible();
    } finally {
      for (const k of keys) {
        if (original[k] === null) sql(info, 'DELETE FROM settings WHERE name = ?', k);
        else sql(info, 'UPDATE settings SET value = ? WHERE name = ?', original[k]!, k);
      }
    }
  });
});

/* ---------- Task F24 (owner, 2026-11-14): pages, the footer menu, social links ---------- */

const footerItems = (info: TestInfo): Row[] => sql(info, `SELECT i.id, i.parent_id, i.type, i.label, i.target, i.hidden
  FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.location = 'footer' ORDER BY i.position, i.id`);
const pageHref = (info: TestInfo, slug: string) => (isLive(info) ? `/${slug}` : `/#${slug}`);

test('pages: add a page with the text toolbar, it opens at its address; scripts removed; taken addresses refused; a new address moves its menu links; delete', async ({ page }, info) => {
  test.setTimeout(180_000);
  page.on('dialog', (d) => d.accept());
  await inLine(info, 'menu', async () => {
    const slug = `tp-${letters()}`;
    const title = `Test page ${letters()}`;
    const footerBefore = footerItems(info);
    try {
      await login(page, users.admin);
      await page.goto('/admin/pages');
      for (const t of ['About Us', 'Terms of Service', 'Privacy Policy', 'Advertise With Us', 'Contact Us']) await expect(page.locator('.page-row .t', { hasText: t })).toHaveCount(1);
      await page.click('a[href="/admin/pages/new"]');
      await page.locator('#page-form[data-ready]').waitFor();
      await page.fill('#pg-title', title);
      await page.fill('#pg-slug', slug);
      // The toolbar: type, select the words, Bold.
      await page.locator('#pg-body').click();
      await page.keyboard.type('Toolbar words');
      await page.locator('#pg-body').evaluate((el) => { const r = document.createRange(); r.selectNodeContents(el); const s = getSelection()!; s.removeAllRanges(); s.addRange(r); });
      await page.locator('.ed-tool [data-cmd="bold"]').click();
      await expect(page.locator('#pg-body b, #pg-body strong')).toHaveText('Toolbar words');
      // A subhead, a list, and code that must never reach the page.
      await page.locator('#pg-body').evaluate((el) => el.insertAdjacentHTML('beforeend', '<h2>Our team</h2><ul><li>Point one</li></ul><script>window.bad=1</script><p onclick="window.bad=2">Click text</p><img src=x onerror="window.bad=3">'));
      await page.locator('#page-form button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText('Page saved. The site shows it now.');
      const row = sql(info, 'SELECT id, title, body FROM pages WHERE slug = ?', slug)[0];
      expect(row.title).toBe(title);
      expect(row.body).toMatch(/<(b|strong)>Toolbar words<\/(b|strong)>/);
      expect(row.body).toContain('<h2>Our team</h2>');
      expect(row.body).toContain('<li>Point one</li>');
      expect(row.body).not.toMatch(/script|onclick|onerror|window\.bad/);
      await expect(page).toHaveURL(new RegExp(`/admin/pages/${row.id}$`));

      // The page on the site.
      expect((await page.request.get(`/${slug}`)).status()).toBe(200);
      await page.goto(pageHref(info, slug));
      await expect(page.locator('.page-doc h1')).toHaveText(title);
      await expect(page.locator('.page-doc .page-body h2')).toHaveText('Our team');
      expect(await page.evaluate(() => (window as any).bad)).toBeUndefined();

      // Taken addresses are refused: a category's, a reserved name, another page's.
      for (const [taken, words] of [['land', 'A category already uses the address /land'], ['admin', 'is kept for the site'], ['contact', 'Another page already uses the address /contact']]) {
        await page.goto(`/admin/pages/${row.id}`);
        await page.locator('#page-form[data-ready]').waitFor();
        await page.fill('#pg-slug', taken);
        await page.locator('#page-form button[type="submit"]').click();
        await expect(page.locator('.a-error')).toContainText(words);
        expect(sql(info, 'SELECT slug FROM pages WHERE id = ?', row.id)[0].slug).toBe(slug);
      }

      // A footer link to it follows a new address.
      const col = footerBefore.find((r) => r.parent_id === null)!;
      sql(info, "INSERT INTO menu_items (menu_id, parent_id, position, type, label, target) SELECT menu_id, ?, 99, 'page', 'Test link', ? FROM menu_items WHERE id = ?", col.id, slug, col.id);
      await page.goto('/');
      await expect(page.locator('.site-footer a', { hasText: 'Test link' })).toHaveAttribute('href', pageHref(info, slug));
      const slug2 = `${slug}-new`;
      await page.goto(`/admin/pages/${row.id}`);
      await page.locator('#page-form[data-ready]').waitFor();
      await page.fill('#pg-slug', slug2);
      await page.locator('#page-form button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText('Page saved. The site shows it now.');
      expect(sql(info, "SELECT target FROM menu_items WHERE label = 'Test link'")[0].target).toBe(slug2);
      await page.goto('/');
      await expect(page.locator('.site-footer a', { hasText: 'Test link' })).toHaveAttribute('href', pageHref(info, slug2));

      // Delete (after the confirm): the page is gone and the footer no longer links to it.
      await page.goto(`/admin/pages/${row.id}`);
      await page.locator('#page-form[data-ready]').waitFor();
      await page.locator('.page-delete button').click();
      await expect(page.locator('.a-flash')).toHaveText(`Page ${title} deleted.`);
      expect(sql(info, 'SELECT id FROM pages WHERE id = ?', row.id)).toEqual([]);
      expect((await page.request.get(`/${slug2}`)).status()).toBe(404);
      await page.goto('/');
      await expect(page.locator('.site-footer a', { hasText: 'Test link' })).toHaveCount(0);
    } finally {
      sql(info, "DELETE FROM menu_items WHERE label = 'Test link'");
      sql(info, 'DELETE FROM pages WHERE slug LIKE ?', `${slug}%`);
    }
  });
});

test('footer menu: a new column with a link to a page in the menu builder; social links in Settings show their icons; put back', async ({ page }, info) => {
  test.setTimeout(180_000);
  page.on('dialog', (d) => d.accept());
  await inLine(info, 'menu', async () => {
    const before = footerItems(info);
    const headerCount = headerItems(info).length;
    const insta = sql(info, "SELECT value FROM settings WHERE name = 'social_instagram'")[0]?.value ?? null;
    const col = `Follow ${letters()}`;
    const link = `Write ${letters()}`;
    try {
      await login(page, users.admin);
      await page.goto('/admin/menu?m=footer');
      await page.locator('.mb-list[data-ready]').waitFor();
      await expect(page.locator('.a-head h1')).toHaveText('Footer menu');
      await expect(page.locator('.mb-tabs a[aria-current="page"]')).toHaveText('Footer menu');
      await expect(page.locator('.mb-list > li[data-key]')).toHaveCount(before.length);
      const form = page.locator('.mb-form');
      // A column title (no link).
      await page.click('.mb-add');
      await form.locator('#mb-type').selectOption('text');
      await form.locator('#mb-label').fill(col);
      await done(page);
      // A link to the Contact Us page, inside that column.
      await page.click('.mb-add');
      await form.locator('#mb-type').selectOption('page');
      await form.locator('#mb-target').selectOption('contact');
      await form.locator('#mb-label').fill(link);
      await form.locator('#mb-parent').selectOption({ label: col });
      await done(page);
      await page.click('.mb-save');
      await expect(page.locator('.mb-status')).toContainText('The footer shows the new menu now.');
      await expect(page.locator('#a-toast')).toContainText('Footer menu saved');
      const after = footerItems(info);
      expect(after.length).toBe(before.length + 2);
      const colRow = after.find((r) => r.label === col)!;
      expect(colRow).toMatchObject({ type: 'text', target: '', parent_id: null });
      expect(after.find((r) => r.label === link)).toMatchObject({ type: 'page', target: 'contact', parent_id: colRow.id });
      expect(headerItems(info).length, 'the header menu is not touched').toBe(headerCount);
      await page.goto('/');
      await expect(page.locator('.site-footer .footer-col h2', { hasText: col })).toHaveCount(1);
      await expect(page.locator('.site-footer a', { hasText: link })).toHaveAttribute('href', pageHref(info, 'contact'));
      await noSidewaysScroll(page, 'footer with four columns');

      // Social links: a wrong address is refused; an https address shows the icon under the first column.
      await page.goto('/admin/settings');
      await page.evaluate(() => { for (const el of document.querySelectorAll('.site-form input[type="url"]')) el.setAttribute('type', 'text'); });
      await page.fill('#s-social-instagram', 'javascript:alert(1)');
      await page.locator('.site-form button[type="submit"]').click();
      await expect(page.locator('.a-flash.warn')).toContainText('The Instagram address must start with https://');
      await page.goto('/admin/settings');
      await page.fill('#s-social-instagram', 'https://www.instagram.com/gap.test');
      await page.locator('.site-form button[type="submit"]').click();
      await expect(page.locator('.a-flash')).toHaveText('Site settings saved. The page cache was cleared.');
      expect(sql(info, "SELECT value FROM settings WHERE name = 'social_instagram'")[0].value).toBe('https://www.instagram.com/gap.test');
      await page.goto('/');
      const icon = page.locator('.footer-col').first().locator('.footer-social a');
      await expect(icon).toHaveAttribute('href', 'https://www.instagram.com/gap.test');
      await expect(icon).toHaveAttribute('aria-label', /Instagram/);
      await expect(icon.locator('svg')).toBeVisible();
    } finally {
      const ids = before.map((r) => Number(r.id));
      for (const r of footerItems(info)) if (!ids.includes(Number(r.id))) sql(info, 'DELETE FROM menu_items WHERE id = ?', r.id);
      if (insta === null) sql(info, "DELETE FROM settings WHERE name = 'social_instagram'");
      else sql(info, "UPDATE settings SET value = ? WHERE name = 'social_instagram'", insta);
    }
  });
});
