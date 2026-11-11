import { test, expect, type TestInfo } from './fixtures';
import { sql, nowSql } from './admin-helpers';

// Step 5, task B15: the read-only JSON API. Public stories only (never drafts, scheduled stories or trash), no
// user data, no cookies, cached like the pages; compared with what the database holds.
// The rate limit itself is raised for the test runs (every test browser is 127.0.0.1: config 'api_limit').

const origin = (info: TestInfo) => new URL((info.project.use as any).baseURL).origin;
const publicWhere = "p.status = 'published' AND p.published_at <= ?";

test('/api/posts: public stories only, newest first, with pages and at most 50 per page', async ({ page }, info) => {
  // Other tests publish stories meanwhile: the answer and the database are read again then.
  await expect(async () => {
    const res = await page.request.get('/api/posts?per=10');
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/json');
    expect(res.headers()['set-cookie'], 'no cookies').toBeUndefined();
    expect(res.headers()['access-control-allow-origin']).toBe('*');
    const data = await res.json();
    const now = nowSql();
    const total = sql(info, `SELECT COUNT(*) AS n FROM posts p WHERE ${publicWhere}`, now)[0].n;
    const newest = sql(info, `SELECT p.id FROM posts p WHERE ${publicWhere} ORDER BY p.published_at DESC, p.id DESC LIMIT 10`, now).map((r: any) => r.id);
    expect(Number(data.total)).toBe(Number(total));
    expect(data.per).toBe(10);
    expect(data.pages).toBe(Math.ceil(Number(total) / 10));
    expect(data.posts.map((p: any) => p.id)).toEqual(newest);
  }).toPass({ timeout: 30_000 });

  const one = (await (await page.request.get('/api/posts?per=1')).json()).posts[0];
  expect(Object.keys(one).sort()).toEqual(['author', 'category', 'excerpt', 'id', 'image', 'live', 'published_at', 'slug', 'summary', 'tags', 'title', 'updated_at', 'url'].sort());
  expect(one.url).toBe(`${origin(info)}/${one.category.slug}/${one.slug}`);
  expect(one.published_at).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  // At most 50 per page; page 2 continues where page 1 stopped.
  const big = await (await page.request.get('/api/posts?per=500')).json();
  expect(big.per).toBe(50);
  expect(big.posts.length).toBeLessThanOrEqual(50);
  const p1 = await (await page.request.get('/api/posts?per=5&page=1')).json();
  const p2 = await (await page.request.get('/api/posts?per=5&page=2')).json();
  expect(p2.page).toBe(2);
  expect(p2.posts.map((p: any) => p.id).some((id: number) => p1.posts.some((q: any) => q.id === id))).toBe(false);

  // Never a draft, a scheduled story or the trash: every story of every page is public in the database
  // (read again when another test trashed or published a story in between).
  const hidden = sql(info, "SELECT id FROM posts WHERE slug IN ('scheduled-sample-story-for-later', 'draft-sample-story')").map((r: any) => r.id);
  expect(hidden.length).toBe(2);
  await expect(async () => {
    const ids: number[] = [];
    for (let n = 1; n <= big.pages + 1 && n < 20; n++) {
      const pageData = await (await page.request.get(`/api/posts?per=50&page=${n}`)).json();
      ids.push(...pageData.posts.map((p: any) => p.id));
    }
    const notPublic = sql(info, `SELECT id FROM posts WHERE NOT (status = 'published' AND published_at <= ?)`, nowSql()).map((r: any) => r.id);
    expect(ids.filter((id) => notPublic.includes(id)), 'no story that is not public').toEqual([]);
    for (const id of hidden) expect(ids).not.toContain(id);
  }).toPass({ timeout: 30_000 });
});

test('/api/posts?category= and ?tag= give the same stories as the database', async ({ page }, info) => {
  await expect(async () => {
    const now = nowSql();
    const asia = sql(info, `SELECT p.id FROM posts p JOIN categories c ON c.id = p.category_id WHERE ${publicWhere} AND c.slug = 'asia'
                            ORDER BY p.published_at DESC, p.id DESC`, now).map((r: any) => r.id);
    const data = await (await page.request.get('/api/posts?category=asia&per=50')).json();
    expect(data.posts.map((p: any) => p.id)).toEqual(asia);
    expect(data.posts.every((p: any) => p.category.slug === 'asia')).toBe(true);
  }).toPass({ timeout: 30_000 });
  // A parent category includes its sub-categories (Regions -> Asia, Europe ...).
  const regions = await (await page.request.get('/api/posts?category=regions&per=50')).json();
  expect(regions.posts.some((p: any) => p.category.slug === 'asia')).toBe(true);

  const tag = sql(info, `SELECT t.slug, COUNT(*) AS n FROM tags t JOIN post_tags pt ON pt.tag_id = t.id JOIN posts p ON p.id = pt.post_id
                          WHERE ${publicWhere} GROUP BY t.id, t.slug ORDER BY n DESC, t.slug LIMIT 1`, nowSql())[0];
  await expect(async () => {
    const want = sql(info, `SELECT p.id FROM posts p JOIN post_tags pt ON pt.post_id = p.id JOIN tags t ON t.id = pt.tag_id
                            WHERE ${publicWhere} AND t.slug = ? ORDER BY p.published_at DESC, p.id DESC`, nowSql(), tag.slug).map((r: any) => r.id);
    const data = await (await page.request.get(`/api/posts?tag=${tag.slug}&per=50`)).json();
    expect(data.posts.map((p: any) => p.id)).toEqual(want);
    expect(data.posts.every((p: any) => p.tags.some((t: any) => t.slug === tag.slug))).toBe(true);
  }).toPass({ timeout: 30_000 });
  // Unknown category or tag: an empty list, not an error.
  const none = await page.request.get('/api/posts?category=no-such-category');
  expect(none.status()).toBe(200);
  expect((await none.json()).posts).toEqual([]);
});

test('/api/posts/{slug}: one public story with its body and photos; drafts and scheduled stories are not found', async ({ page }, info) => {
  const p = sql(info, `SELECT p.id, p.slug, p.title, p.credits, c.slug AS cat FROM posts p JOIN categories c ON c.id = p.category_id
                        WHERE ${publicWhere} AND p.slug LIKE 'sample-%' AND EXISTS (SELECT 1 FROM post_gallery g WHERE g.post_id = p.id) ORDER BY p.id LIMIT 1`, nowSql())[0];
  const res = await page.request.get(`/api/posts/${p.slug}`);
  expect(res.status()).toBe(200);
  const story = (await res.json()).post;
  expect(story).toMatchObject({ id: p.id, slug: p.slug, title: p.title, credits: p.credits, url: `${origin(info)}/${p.cat}/${p.slug}` });
  expect(story.body_html).toContain('<p>');
  expect(story.body_html).not.toMatch(/<script/i);
  const gallery = sql(info, 'SELECT caption FROM post_gallery WHERE post_id = ? ORDER BY position, id', p.id).map((r: any) => r.caption);
  expect(story.gallery.map((g: any) => g.caption)).toEqual(gallery);
  expect(story.gallery[0].url).toMatch(new RegExp(`^${origin(info)}/uploads/.+\\.webp$`));
  expect((await page.request.get(story.gallery[0].url)).status()).toBe(200);
  expect((await page.request.get(story.image.url)).status()).toBe(200);

  for (const slug of ['draft-sample-story', 'scheduled-sample-story-for-later', 'no-such-story']) {
    const r = await page.request.get(`/api/posts/${slug}`);
    expect(r.status(), slug).toBe(404);
    expect((await r.json()).error).toBeTruthy();
  }
});

test('/api/categories: every category with its public story count', async ({ page }, info) => {
  await expect(async () => {
    const data = await (await page.request.get('/api/categories')).json();
    const rows = sql(info, `SELECT c.id, c.slug, (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id AND ${publicWhere}) AS n
                             FROM categories c ORDER BY c.position, c.id`, nowSql());
    expect(data.map((c: any) => c.slug)).toEqual(rows.map((r: any) => r.slug));
    expect(data.map((c: any) => c.stories)).toEqual(rows.map((r: any) => Number(r.n)));
  }).toPass({ timeout: 30_000 });
  const data = await (await page.request.get('/api/categories')).json();
  expect(data.find((c: any) => c.slug === 'asia')).toMatchObject({ parent: 'regions', url: `${origin(info)}/asia` });
});

test('the API is read-only, cached, and never sends user data', async ({ page }, info) => {
  for (const method of ['post', 'put', 'delete'] as const) {
    const res = await page.request[method]('/api/posts', { data: {} });
    expect(res.status(), method).toBe(405);
  }
  expect((await page.request.get('/api/nothing-here')).status()).toBe(404);
  // Served from the cache the second time (a save elsewhere may clear it in between: then again).
  const url = `/api/posts?per=3&page=${2 + (info.workerIndex % 5)}`;
  await expect(async () => {
    await page.request.get(url);
    const again = await page.request.get(url);
    expect(again.headers()['x-cache']).toBe('HIT');
  }).toPass({ timeout: 30_000 });
  // No emails, no accounts, no status words of the admin, in any answer.
  const emails = sql(info, "SELECT email FROM users UNION SELECT email FROM authors WHERE email <> ''").map((r: any) => r.email);
  for (const path of ['/api/posts?per=50', '/api/categories', `/api/posts/${(await (await page.request.get('/api/posts?per=1')).json()).posts[0].slug}`]) {
    const text = await (await page.request.get(path)).text();
    for (const email of emails) expect(text, `${path} has no email`).not.toContain(email);
    expect(text).not.toMatch(/password|"status"|"role"|author_id/);
  }
});
