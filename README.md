# Global Arms Post

News site for globalarmspost.com: PHP 8.1+ (no framework), MySQL or SQLite, plain JavaScript.
History, rules and design notes: [PROJECT_LOG.md](PROJECT_LOG.md). Wireframes: [docs/wireframes.html](docs/wireframes.html).

## Folders
- `public/` — the site (this becomes Hostinger `public_html` through `../live/`)
  - `app/lib/` code, `app/views/` templates, `app/migrations/` database changes, `app/cli/` command line tools
  - `app/admin/` the admin at `/admin`, with its views in `app/views/admin/`
  - `assets/` CSS, JS, fonts, logo; `assets/admin/` the admin's CSS + JS (and the jSquash AVIF encoder in `vendor/`)
  - `storage/` sessions and `storage/cache/pages/` (the page cache: generated files, and the admin can clear them)
- `scripts/` — build tools (logo, fonts, live/ build and check, task list)
- `tests/` — Playwright tests and the test server

## The reader side (step 4)
- Every story page has the full article: byline with the author's picture, Aa text size (remembered), Share (the phone's share sheet, a menu with plain links elsewhere), a photo gallery with arrows, counter and full screen, the Summary box, the credits line, topic chips that open the tag pages, and "More in {category}" and "Latest news" rows.
- **Smart search** at `/search?q=` (`/#search?q=` in the git copy): its own word index, so joined words ("ukrainewar"), word forms ("missile"/"missiles"), typos ("Did you mean …"), ranking, highlights, category and date filters, sort and pages. The header's search box shows suggestions while typing (`/api/suggest`, rate limited, no third-party code).
- **Page cache**: public pages are kept in `storage/cache/pages` and expire at the next scheduled story's time; every save in the admin clears them. Admin pages, API answers and POSTs are never cached.
- **Trending tags** row under the header, from the tags most used by recent stories.
- `/admin/settings` holds the trending tags, the cache and the **Rebuild search index** button.

## Step 5: the rest of the admin, SEO, the API and backups
- **SEO**: every public page has its title, description, canonical address, Open Graph and X card tags; story pages add NewsArticle JSON-LD. `/sitemap.xml` (home, categories, public stories with lastmod, tags) and `/robots.txt`. Old WordPress addresses (`/category/europe/`, `/2026/07/slug/`) move with a 301. Full addresses use the Site address setting (`site_url`), else the visitor's own host.
- **Menu builder** at `/admin/menu` (admins): header links to the home page, a category, tag, story, page or any web address; drag (mouse, touch, pen) or ↑ ↓ → ← to order and nest; new tab; show/hide; saved in one go and shown at once.
- **Settings**: site name, tagline, about text, site address, time zone, stories per category page. **Categories**: add, rename, new address (reserved names refused), parent, header menu, drag order. **Pictures**: alt / caption / credit, where a picture is used, delete when unused (admins). **Tags**: rename (re-indexed for search), merge, counts.
- **Trash**: "Delete for good" (admins, with a confirm).
- **Homepage builder**: picture size sliders per section and a live preview drawn by the site itself (beside the builder from 1280 px, under it below that).
- **JSON API** (read-only, public stories only, no cookies, rate limited, cached): `GET /api/posts?category=&tag=&page=&per=` (per: up to 50), `/api/posts/{slug}`, `/api/categories`.
- **Backup** at `/admin/backup` (admins): a ZIP with every table as JSON and the pictures, kept in `storage/backups` (never served), downloaded through the admin. Restore comes later.
- **GitHub Actions** (`.github/workflows/check.yml`): PHPStan level 6 (`phpstan.neon`) on every push. The Playwright suite runs on the owner's PC (it needs the Windows test tools).

## Run the tests
```bash
PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright WORKERS=6 npm test
```
On MySQL 8.4 instead of SQLite (starts and stops its own MySQL server, ports 33190/33191, nothing of the local MySQL install is touched):
```bash
GAP_DB=mysql PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright WORKERS=6 npm test
```
928 tests on the live copy (Chromium/Firefox/WebKit × phone/tablet/desktop; `COPIES=git,live` for both copies) in three phases per copy: the setup step (`admin-setup`), the site/story/admin/SEO/API/backup tests, then the publish tests (`*-publish`: `admin-publish.spec.ts` and `menu.spec.ts`) after them.
Starts the real PHP server with a fresh SQLite database for both copies (git/ with `#` links, live/ with proper addresses), loads the sample stories (test database only) and stops it after the run. The first test makes the admin account through the setup step; the test accounts are in `tests/fixtures/test-users.json` (test databases only). Browsers: `PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright`.
A passing run is **894 passed, 34 skipped, 0 failed**. The page cache is on in the tests, as on the real site. The 34 skips are by design (a database check or a scheduled-story test that runs once, tests for the phone menu or the desktop columns only, and the homepage builder drag on phones: the admin is desktop-first) and every report lists them by name.

## Look at the site locally
The Claude app's preview (`.claude/launch.json`, "live-copy") or `npm run preview` serves the live copy with sample stories at http://127.0.0.1:8091/. The admin is at http://127.0.0.1:8091/admin: sign in with the demo admin from `tests/fixtures/test-users.json` ("demo_admin"). It exists only in the preview's own test database (`tests/seed-admin.php`), never in `live/`.

## Update live/
```bash
npm run build:live
```
```bash
npm run verify:live
```
Commit first: `live/` is built from the current commit only. Nothing in `live/` is ever deleted; `app/config.php`, `uploads/` and `storage/` are never overwritten.

## Install on a server
1. Upload `live/` to `public_html`.
2. Copy `app/config.sample.php` to `app/config.php` and fill in the database.
3. Run `php app/cli/migrate.php` (an /install page comes later).
4. Open `https://globalarmspost.com/admin`. With no account yet it shows the setup step: the first account you make is the admin. More users (admins or editors) are added under Users.
5. Make sure `uploads/` and `storage/` are writable (pictures, sign-in sessions).
