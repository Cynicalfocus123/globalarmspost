import { test, expect, type Page, type TestInfo } from './fixtures';
import { users, sql, login, logout, csrf, uniq, narrow, fileThere, noSidewaysScroll, adminApi, uploadPicture, draftStory, photoFile } from './admin-helpers';

// Step 5: the rest of the admin that does not change what visitors see (drafts and own tags and pictures only):
//   B7  "Delete for good" in the Trash tab
//   B8  tags: rename (re-indexes the stories), merge (no duplicate links), counts
//   F17 pictures: edit alt / caption / credit, where used, delete only when unused
//   and every new admin page on phones, tablets and desktops.
// Categories, the menu and the site settings change every page: they are in menu.spec.ts (publish phase).

/** Letters only (search words are letters; a number would be a separate word). */
const letters = (n = 6) => Array.from({ length: n }, () => 'bcdfghjklmnpqrstvwxz'[Math.floor(Math.random() * 20)]).join('');

/** Tick a story in the stories list and press a bulk button. */
async function bulk(page: Page, id: number, button: string) {
  await page.locator(`.story-row[data-id="${id}"] input[name="ids[]"]`).check();
  await expect(page.locator('.a-bulk')).toBeVisible();
  await page.locator('.a-bulk button', { hasText: button }).click();
}

test('trash: "Delete for good" removes the story, its tags, gallery and search rows; admins only', async ({ page }, info) => {
  test.setTimeout(90_000);
  page.on('dialog', (d) => d.accept());
  await login(page, users.admin);
  const word = `purge${letters()}`;
  const tag = (await adminApi(page, '/admin/api/tag', { name: `Purge ${letters()}` })).json;
  const media = sql(info, 'SELECT id FROM media ORDER BY id LIMIT 1')[0].id;
  const title = `${uniq(info, 'Delete for good')} ${word}`;
  const id = await draftStory(page, info, { title, tag_ids: [tag.id], gallery: [{ media_id: media, caption: 'Gallery photo', credit: '' }] });
  const keep = await draftStory(page, info, { title: `${uniq(info, 'Not in the trash')} ${word}` });
  expect((await adminApi(page, '/admin/api/stories-status', { ids: [id], action: 'trash' })).status).toBe(200);
  const rows = (table: string, col = 'post_id') => Number(sql(info, `SELECT COUNT(*) AS n FROM ${table} WHERE ${col} = ?`, id)[0].n);
  expect(rows('post_tags')).toBe(1);
  expect(rows('post_gallery')).toBe(1);
  expect(rows('search_post_terms')).toBeGreaterThan(0);
  expect(rows('search_docs')).toBe(1);

  // An editor has no such button, and the request is refused.
  await logout(page);
  await login(page, users.editor);
  await page.goto(`/admin/stories?tab=trash&q=${encodeURIComponent(title)}`);
  await expect(page.locator(`.story-row[data-id="${id}"]`)).toHaveCount(1);
  await expect(page.locator('.a-bulk button[value="delete"]')).toHaveCount(0);
  const token = /name="_csrf" value="([^"]+)"/.exec(await (await page.request.get('/admin/stories?tab=trash')).text())![1];
  expect((await page.request.post('/admin/stories/bulk', { form: { _csrf: token, action: 'delete', 'ids[]': String(id) } })).status()).toBe(403);
  expect(rows('posts', 'id')).toBe(1);
  await logout(page);

  // A story that is not in the trash is never deleted, even when its id is sent.
  await login(page, users.admin);
  const adminToken = /name="_csrf" value="([^"]+)"/.exec(await (await page.request.get('/admin/stories?tab=trash')).text())![1];
  const sent = await page.request.post('/admin/stories/bulk', { form: { _csrf: adminToken, action: 'delete', 'ids[]': String(keep), back: '/admin/stories?tab=trash' } });
  expect(await sent.text()).toContain('0 stories deleted for good.');
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM posts WHERE id = ?', keep)[0].n)).toBe(1);

  // The admin ticks it in the Trash tab and deletes it for good (after the confirm).
  await page.goto(`/admin/stories?tab=trash&q=${encodeURIComponent(title)}`);
  await bulk(page, id, 'Delete for good');
  await expect(page.locator('.a-flash')).toHaveText('1 story deleted for good.');
  await expect(page.locator(`.story-row[data-id="${id}"]`)).toHaveCount(0);
  for (const t of ['post_tags', 'post_gallery', 'search_post_terms', 'search_docs']) expect(rows(t), t).toBe(0);
  expect(rows('posts', 'id')).toBe(0);
  // The tag and the picture stay; the audit log says what was deleted.
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM tags WHERE id = ?', tag.id)[0].n)).toBe(1);
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM media WHERE id = ?', media)[0].n)).toBe(1);
  expect(sql(info, "SELECT detail FROM audit_log WHERE action = 'stories.delete' AND detail LIKE ?", `#${id} %`)[0].detail).toContain(word);
  // Search no longer finds it (it was a draft, so it never showed; the index rows are gone too).
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM search_post_terms WHERE post_id = ?', id)[0].n)).toBe(0);
});

test('tags: rename re-indexes the stories, merge moves them with no duplicates, counts on the Tags page', async ({ page }, info) => {
  test.setTimeout(90_000);
  page.on('dialog', (d) => d.accept());
  await login(page, users.admin);
  const a = (await adminApi(page, '/admin/api/tag', { name: `Alpha${letters()}` })).json;
  const b = (await adminApi(page, '/admin/api/tag', { name: `Bravo${letters()}` })).json;
  const s1 = await draftStory(page, info, { title: uniq(info, 'Tag story one'), tag_ids: [a.id] });
  const s2 = await draftStory(page, info, { title: uniq(info, 'Tag story two'), tag_ids: [a.id, b.id] });
  const termsOf = (post: number, prefix: string) => Number(sql(info, 'SELECT COUNT(*) AS n FROM search_post_terms WHERE post_id = ? AND term LIKE ?', post, `${prefix}%`)[0].n);
  const aStem = a.name.toLowerCase().slice(0, 8);
  expect(termsOf(s1, aStem), 'the tag name is in the search index').toBe(1);

  await page.goto('/admin/tags');
  const rowA = page.locator(`.tag-row[data-id="${a.id}"]`);
  await expect(rowA.locator('.n-stories')).toHaveText('2 stories');
  await expect(page.locator(`.tag-row[data-id="${b.id}"] .n-stories`)).toHaveText('1 story');
  await noSidewaysScroll(page, 'tags page');

  // A name another tag already has is refused (merge is the way for that).
  await rowA.locator('summary').click();
  await rowA.locator('input[name="name"]').fill(b.name);
  await rowA.locator('.tag-rename button').click();
  await expect(page.locator('.a-flash.warn')).toContainText('Another tag is already called');
  expect(sql(info, 'SELECT name FROM tags WHERE id = ?', a.id)[0].name).toBe(a.name);

  // Rename: name and address; the stories are indexed again under the new name.
  const newName = `Renamed${letters()}`;
  const newSlug = `renamed-${letters()}`;
  await page.locator(`.tag-row[data-id="${a.id}"] summary`).click();
  await page.locator(`.tag-row[data-id="${a.id}"] input[name="name"]`).fill(newName);
  await page.locator(`.tag-row[data-id="${a.id}"] input[name="slug"]`).fill(newSlug);
  await page.locator(`.tag-row[data-id="${a.id}"] .tag-rename button`).click();
  await expect(page.locator('.a-flash')).toHaveText(`Tag renamed to ${newName}.`);
  expect(sql(info, 'SELECT name, slug FROM tags WHERE id = ?', a.id)[0]).toEqual({ name: newName, slug: newSlug });
  const newStem = newName.toLowerCase().slice(0, 8);
  for (const s of [s1, s2]) {
    expect(termsOf(s, newStem), 'new name indexed').toBe(1);
    expect(termsOf(s, aStem), 'old name gone from the index').toBe(0);
  }
  expect(Number(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'tag.rename' AND detail LIKE ?", `${a.name} -> ${newName}%`)[0].n)).toBe(1);

  // Merge the renamed tag into Bravo: both stories have Bravo once, the first tag is gone.
  await page.locator(`.tag-row[data-id="${a.id}"] summary`).click();
  await page.locator(`.tag-row[data-id="${a.id}"] select[name="into"]`).selectOption(String(b.id));
  await page.locator(`.tag-row[data-id="${a.id}"] .tag-merge button`).click();
  await expect(page.locator('.a-flash')).toHaveText(`Tags merged into ${b.name} (1 story moved).`);
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM tags WHERE id = ?', a.id)[0].n)).toBe(0);
  for (const s of [s1, s2]) {
    expect(sql(info, 'SELECT tag_id FROM post_tags WHERE post_id = ?', s).map((r: any) => Number(r.tag_id))).toEqual([Number(b.id)]);
    expect(termsOf(s, newStem), 'merged tag no longer indexed').toBe(0);
    expect(termsOf(s, b.name.toLowerCase().slice(0, 8))).toBe(1);
  }
  await expect(page.locator(`.tag-row[data-id="${a.id}"]`)).toHaveCount(0);
  await expect(page.locator(`.tag-row[data-id="${b.id}"] .n-stories`)).toHaveText('2 stories');
});

test('pictures: edit alt, caption and credit; see where a picture is used; delete only when unused', async ({ page }, info) => {
  test.setTimeout(120_000);
  page.on('dialog', (d) => d.accept());
  await login(page, users.admin);
  const m = await uploadPicture(page, photoFile, 'Uploaded by the pictures test');
  const widths = String(m.widths).split(',');
  const files = widths.map((w) => `${m.path}-${w}.webp`);
  for (const f of files) expect(fileThere(info, f), f).toBe(true);

  await page.goto('/admin/pictures');
  await expect(page.locator(`.pic[data-id="${m.id}"] .pic-used`)).toContainText('Not used');
  // Used in a story's gallery: listed, and it cannot be deleted.
  const title = uniq(info, 'Picture user');
  const storyId = await draftStory(page, info, { title, gallery: [{ media_id: m.id, caption: 'x', credit: '' }] });
  await page.goto(`/admin/pictures/${m.id}`);
  await noSidewaysScroll(page, 'picture page');
  await expect(page.locator('.pic-usage li')).toHaveCount(1);
  await expect(page.locator('.pic-usage li')).toContainText('Gallery');
  await expect(page.locator('.pic-usage li a')).toHaveText(`${title} (draft)`);
  await expect(page.locator('.pic-delete')).toHaveCount(0);
  const token = await csrf(page);
  const refused = await page.request.post(`/admin/pictures/${m.id}`, { form: { _csrf: token, action: 'delete' } });
  expect(await refused.text()).toContain('still used, so it cannot be deleted');
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM media WHERE id = ?', m.id)[0].n)).toBe(1);

  // Edit the texts.
  await page.fill('#pic-alt', 'A ship at sea (test)');
  await page.fill('#pic-caption', 'Caption from the Pictures page');
  await page.fill('#pic-credit', 'Test photo desk');
  await page.locator('.pic-form button[type="submit"]').click();
  await expect(page.locator('.a-flash')).toHaveText('Picture saved.');
  expect(sql(info, 'SELECT alt, caption, credit FROM media WHERE id = ?', m.id)[0]).toEqual({ alt: 'A ship at sea (test)', caption: 'Caption from the Pictures page', credit: 'Test photo desk' });

  // No longer used: an editor still cannot delete it; the admin can, and its files go too.
  const cat = sql(info, "SELECT id FROM categories WHERE slug = 'business'")[0].id;
  expect((await adminApi(page, '/admin/api/story', { id: storyId, title, status: 'draft', category_id: cat, body: '<p>No picture now.</p>', gallery: [] })).status).toBe(200);
  await logout(page);
  await login(page, users.editor);
  await page.goto(`/admin/pictures/${m.id}`);
  await expect(page.locator('.pic-usage')).toContainText('Nowhere');
  await expect(page.locator('.pic-delete')).toHaveCount(0);
  expect((await page.request.post(`/admin/pictures/${m.id}`, { form: { _csrf: await csrf(page), action: 'delete' } })).status()).toBe(403);
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM media WHERE id = ?', m.id)[0].n)).toBe(1);
  await logout(page);
  await login(page, users.admin);
  await page.goto(`/admin/pictures/${m.id}`);
  await page.locator('.pic-delete button').click();
  await expect(page.locator('.a-flash')).toContainText('Picture deleted (');
  await expect(page).toHaveURL(/\/admin\/pictures$/);
  expect(Number(sql(info, 'SELECT COUNT(*) AS n FROM media WHERE id = ?', m.id)[0].n)).toBe(0);
  for (const f of files) expect(fileThere(info, f), `${f} removed`).toBe(false);
  expect(Number(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'media.delete' AND detail LIKE ?", `#${m.id} %`)[0].n)).toBe(1);
  // The sample pictures (used by stories) are still all there.
  const demo = sql(info, "SELECT path, widths FROM media WHERE path LIKE 'uploads/demo/%' ORDER BY id LIMIT 1")[0];
  expect(fileThere(info, `${demo.path}-${String(demo.widths).split(',')[0]}.webp`)).toBe(true);
});

test('the new admin pages fit phones, tablets and desktops (no sideways scroll, touch-size buttons)', async ({ page }, info) => {
  test.setTimeout(180_000);
  await login(page, users.admin);
  const asia = sql(info, "SELECT id FROM categories WHERE slug = 'asia'")[0].id;
  const pic = sql(info, 'SELECT id FROM media ORDER BY id LIMIT 1')[0].id;
  const own = page.viewportSize()!;
  // Each device checks its own width; phones also 320 px and desktops also 1920 px (together: 320-1920 px).
  const widths = own.width < 600 ? [320, own.width] : own.width >= 1200 ? [own.width, 1920] : [own.width];
  for (const width of widths) {
    await page.setViewportSize({ width, height: own.height });
    for (const path of ['/admin/menu', '/admin/categories', `/admin/categories/${asia}`, '/admin/tags', '/admin/pictures', `/admin/pictures/${pic}`,
      '/admin/settings', '/admin/backup', '/admin/stories?tab=trash', '/admin/homepage']) {
      await page.goto(path);
      await page.locator('html[data-ui="ready"]').waitFor();
      await noSidewaysScroll(page, `${path} at ${width} px`);
      const small = await page.evaluate(() => {
        const coarse = matchMedia('(pointer: coarse)').matches;
        const min = coarse ? 44 : 36;
        return [...document.querySelectorAll('.a-main .a-btn, .a-main .a-btn2, .a-main .a-grip, .a-main summary.a-btn2')]
          .filter((el) => (el as HTMLElement).offsetParent !== null && el.getBoundingClientRect().height > 0)
          .filter((el) => el.getBoundingClientRect().height < min - 0.5)
          .map((el) => `${el.className} "${(el.textContent || '').trim().slice(0, 20)}" ${Math.round(el.getBoundingClientRect().height)}px`).slice(0, 5);
      });
      expect(small, `${path} at ${width} px: buttons big enough to tap`).toEqual([]);
    }
    if (width < 900) {
      // Under 900 px: the bottom tab bar, and the More sheet lists the new pages.
      await expect(page.locator('.a-tabbar')).toBeVisible();
      await expect(page.locator('#more-sheet .a-nav[href="/admin/backup"]')).toHaveCount(1);
      await expect(page.locator('#more-sheet .a-nav[href="/admin/menu"]')).toHaveCount(1);
    } else {
      await expect(page.locator('.a-side .a-nav[href="/admin/backup"]')).toBeVisible();
    }
  }
  await page.setViewportSize(own);
  expect(narrow(page) === own.width < 900).toBe(true);
});

test('editors: no menu builder, settings or backup; the API refuses them', async ({ page }, info) => {
  await login(page, users.editor);
  for (const path of ['/admin/menu', '/admin/settings', '/admin/backup', '/admin/homepage/preview']) {
    expect((await page.request.get(path)).status(), path).toBe(403);
  }
  for (const [path, body] of [['/admin/api/menu', { items: [] }], ['/admin/api/homepage-draft', { sections: [] }]] as const) {
    const res = await adminApi(page, path, body);
    expect(res.status, path).toBe(403);
    expect(res.json.error).toBe('Only admins can open this page.');
  }
  // Editors may still open the categories, tags and pictures pages.
  for (const path of ['/admin/categories', '/admin/tags', '/admin/pictures']) expect((await page.request.get(path)).status(), path).toBe(200);
  // But they cannot change the header menu through a category (security review, step 5): Asia's menu item stays
  // shown even when an editor saves Asia with the menu box left out.
  const asia = sql(info, "SELECT id, name, slug, parent_id FROM categories WHERE slug = 'asia'")[0];
  await page.goto(`/admin/categories/${asia.id}`);
  await expect(page.locator('input[name="in_menu"]')).toHaveCount(0);
  await expect(page.locator('.cat-menu-note')).toContainText('Only admins change the header menu.');
  const html = await (await page.request.get(`/admin/categories/${asia.id}`)).text();
  const token = /name="_csrf" value="([^"]+)"/.exec(html)![1];
  const res = await page.request.post(`/admin/categories/${asia.id}`, { form: { _csrf: token, name: asia.name, slug: asia.slug, parent_id: String(asia.parent_id) } });
  expect(res.status()).toBe(200);
  expect(sql(info, "SELECT hidden FROM menu_items WHERE type = 'category' AND target = 'asia'").map((r: any) => r.hidden)).toEqual([0]);
});
