import { test, expect, type Page, type TestInfo } from '@playwright/test';
import { sql, isLive } from './admin-helpers';

// Step 4: smart search (tasks B11 + F7). The sample stories (tests/seed-demo.php) are indexed at seed time,
// the same way the site indexes a story on save.
// Every test runs on phone, tablet and desktop in Chromium, Firefox and WebKit, on the git/ and live/ copies.

const searchUrl = (info: TestInfo, query: string, extra = '') =>
  (isLive(info) ? `/search?${query}${extra}` : `/#search?${query}${extra}`);

async function openSearch(page: Page, info: TestInfo, query: string, extra = '') {
  await page.goto(searchUrl(info, query, extra));
  await expect(page.locator('.search-page h1')).toHaveText('Search');
}

const titles = async (page: Page): Promise<string[]> =>
  (await page.locator('.results .result-title').allInnerTexts()).map((t) => t.trim());

test('a word in a headline finds the story, with the matched word highlighted', async ({ page }, info) => {
  const story = sql(info, "SELECT title FROM posts WHERE slug LIKE 'sample-%' AND title LIKE 'Artillery%'")[0];
  expect(story, 'a sample story about artillery exists').toBeTruthy();
  await openSearch(page, info, 'q=artillery');
  await expect(page.locator('.search-count')).toContainText('for');
  expect(await titles(page)).toContain(story.title);
  await expect(page.locator('.results mark').first()).toBeVisible();
  expect((await page.locator('.results mark').first().innerText()).toLowerCase()).toContain('artiller');
});

test('joined words find the same story as the two words apart', async ({ page }, info) => {
  await openSearch(page, info, 'q=minecountermeasures');
  expect(await titles(page)).toContain('Mine countermeasures squadron trains in shallow water');
  await openSearch(page, info, 'q=mine+countermeasures');
  expect(await titles(page)).toContain('Mine countermeasures squadron trains in shallow water');
});

test('word forms match: "missile", "missiles" and the singular of a plural', async ({ page }, info) => {
  await openSearch(page, info, 'q=missiles');
  const plural = await titles(page);
  expect(plural.join(' | ')).toContain('Missile defence radar');
  await openSearch(page, info, 'q=missile');
  expect(await titles(page)).toEqual(plural);
});

test('a typo is fixed and the page says "Did you mean …"', async ({ page }, info) => {
  await openSearch(page, info, 'q=artillary');
  await expect(page.locator('.did-you-mean')).toContainText('Did you mean');
  await expect(page.locator('.did-you-mean a')).toHaveText('artillery');
  expect((await titles(page)).join(' | ')).toContain('Artillery');
});

test('only public stories are found: a scheduled story and a draft never appear', async ({ page }, info) => {
  const scheduled = sql(info, "SELECT title FROM posts WHERE slug = 'scheduled-sample-story-for-later'")[0];
  const draft = sql(info, "SELECT title FROM posts WHERE slug = 'draft-sample-story'")[0];
  expect(scheduled.title).toContain('Scheduled');
  await openSearch(page, info, 'q=scheduled');
  expect((await titles(page)).join(' | ')).not.toContain(scheduled.title);
  await openSearch(page, info, 'q=draft');
  expect((await titles(page)).join(' | ')).not.toContain(draft.title);
  // …and the database agrees they exist (so the search really filtered them out, not the data).
  expect(sql(info, "SELECT COUNT(*) AS n FROM posts WHERE slug IN ('scheduled-sample-story-for-later','draft-sample-story')")[0].n).toBe(2);
});

test('filters: category and date narrow the results', async ({ page }, info) => {
  await openSearch(page, info, 'q=defence');
  const all = await titles(page);
  expect(all.join(' | ')).toContain('Defence white paper');
  await openSearch(page, info, 'q=defence&cat=asia');
  const asia = await titles(page);
  expect(asia.join(' | ')).toContain('Defence white paper');
  expect(asia.join(' | ')).not.toContain('Missile defence radar');
  // A date range in the future has nothing in it.
  await openSearch(page, info, `q=defence&from=${new Date(Date.now() + 86400000).toISOString().slice(0, 10)}`);
  await expect(page.locator('.search-empty')).toBeVisible();
});

test('sort: newest first really orders by date', async ({ page }, info) => {
  await openSearch(page, info, 'q=sample&sort=new');
  const dates = sql(info, "SELECT p.published_at FROM posts p WHERE p.slug LIKE 'sample-%' AND p.status = 'published' ORDER BY p.published_at DESC LIMIT 10").map((r: any) => r.published_at);
  const shown = await page.locator('.results .result-meta time').evaluateAll((els) => els.map((e) => e.getAttribute('datetime')));
  const sorted = [...shown].sort().reverse();
  expect(shown).toEqual(sorted);
  expect(dates.length).toBe(10);
});

test('pages: twenty sample stories are split into pages', async ({ page }, info) => {
  await openSearch(page, info, 'q=sample');
  await expect(page.locator('.search-count')).toContainText('20 stories');
  await expect(page.locator('.results .result')).toHaveCount(10);
  await page.locator('.pager .pager-btn[rel="next"]').click();
  await expect(page.locator('.search-count')).toContainText('page 2');
  await expect(page.locator('.results .result')).toHaveCount(10);
  await page.locator('.pager .pager-btn[rel="prev"]').click();
  await expect(page.locator('.search-count')).toContainText('page 1');
});

test('no results says so, and the empty search page explains itself', async ({ page }, info) => {
  await openSearch(page, info, 'q=zzzzqqq');
  await expect(page.locator('.search-empty')).toContainText('No stories found');
  await openSearch(page, info, 'q=');
  await expect(page.locator('.search-empty')).toContainText('Type a word');
});

test('suggestions endpoint: a small JSON answer, nothing for a single letter', async ({ page }) => {
  const res = await page.request.get('/api/suggest?q=artill');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('application/json');
  const data = await res.json();
  expect(data.terms).toContain('artillery');
  expect(Array.isArray(data.stories)).toBe(true);
  const one = await page.request.get('/api/suggest?q=a');
  expect((await one.json()).terms).toEqual([]);
});

test('the header search box suggests words while typing and the search page opens', async ({ page }, info) => {
  await page.goto('/');
  await page.locator('.search-toggle').click();
  const input = page.locator('#site-search input[name="q"]');
  await expect(input).toBeVisible();
  await input.fill('artill');
  await expect(page.locator('#site-search [data-suggest-box]')).toBeVisible();
  const option = page.locator('#site-search [data-suggest-box] button', { hasText: 'artillery' }).first();
  await expect(option).toBeVisible();
  await option.click();
  await expect(page.locator('.search-page h1')).toHaveText('Search');
  await expect(page.locator('.results .result-title').first()).toContainText('Artillery');
});

test('searching works by keyboard and the form keeps the live/ and git/ addresses apart', async ({ page }, info) => {
  await openSearch(page, info, 'q=');
  const form = page.locator('.search-form');
  await expect(form).toHaveAttribute('action', isLive(info) ? '/search' : '/#search');
  await page.locator('#sq').fill('artillery');
  await page.locator('#sq').press('Enter');
  await expect(page.locator('.search-count')).toContainText('artillery');
  expect((await titles(page)).join(' | ')).toContain('Artillery');
});
