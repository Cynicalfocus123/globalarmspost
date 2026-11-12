# Global Arms Post website

News site for globalarmspost.com: a PHP frontend plus an admin backend. Repo: https://github.com/Cynicalfocus123/globalarmspost

Full history, status and the wireframe notes are in `PROJECT_LOG.md`. Read it before starting work.

## Hard rules (same as PROJECT_LOG.md; keep both in sync)
1. **Never delete anything.** Sync only adds or overwrites. List stale files so the owner can decide. Any deletion needs the owner's yes to that exact deletion first. Exception: old task list Excel files with a version in the name are removed (rule 7).
2. **Two separate folders**, both updated on every change:
   - `git/`: source, pushed to GitHub.
   - `live/`: what goes to Hostinger, built from `git/public/` with proper addresses (rule 9), in two folders (owner, 2026-11-12): `live/frontend/` → **into** `public_html` (index.php, .htaccess, assets, uploads, icons), `live/backend/` → **next to** it (`app/` code + config.php, `storage/`). No asset in backend/, no code in frontend/. Never overwrite `backend/app/config.php`, `frontend/uploads/` or `backend/storage/`. Upload steps: `docs/DEPLOY.md`.
3. **A change is done only when** it is committed and pushed, `live/` is rebuilt from that commit, and `npm run verify:live` says they match. `git/` and `live/` are always updated at the same time. If only one is updated, the task is not done.
4. Every update shows a wireframe, the GitHub link, the changes, and the Actions run.
5. **Every time a task is updated or finished, update all `.md` files** (`PROJECT_LOG.md`, `CLAUDE.md`, `README.md` and any other `.md`) in the same change, so they always match the code.
6. **Show every code change in the Claude app's diff panel** (split view, right side) before committing. Work inside `git/` (the repo root), mark new files with `git add -N`, then open the diff pane.
7. **Keep the task list Excel up to date.** Edit `docs/tasks.json`, bump `version`, and run `python scripts/build-tasks.py`. It updates the one file `../Claude outputs/Global Arms Post task list.xlsx` in place. **Never make a new Excel file**: only update, change and delete inside the same file (owner's hard rule, 2026-11-05). The version number is inside the file, not in its name. Statuses:
   - **Not started:** not begun.
   - **Pending:** started but not finished.
   - **Done:** finished **and approved by the owner** (code also tested, committed and pushed), and then only on the Done tab. Nothing is Done without the owner's approval.
8. **Every prompt starts with reading the hard rules** in `PROJECT_LOG.md` and this file, and the work follows them.
9. **`live/` is a plain folder next to `git/` (not on GitHub) and has exactly the code of `git/`**, backend and frontend, split into `live/frontend/` and `live/backend/` (rule 2).
   - `git/` uses `#` links (`/#asia`).
   - `live/` never does: every page has a proper address (`globalarmspost.com/home`, `/asia`, `/europe`, `/{category}/{slug}`, `/tag/usa`, `/search?q=`).
   - Every link goes through one `site_link()` function (PHP has a built-in `link()`). The full URL map and the reserved names are in `PROJECT_LOG.md`.
10. **Every change looks and works right on all modern phones, tablets and desktops**: text, images, menus, admin, everything.
    - Browsers: Chrome, Edge, Safari, Firefox.
    - Widths: 320–1920 px. No sideways scroll, cut-off text or stretched images. Menus work by touch.
    - Tested on phone, tablet and desktop in Chromium, Firefox and WebKit. A break on any of them means not done.
    - **The admin is desktop-first** (owner, 2026-11-11): on desktop and laptop it must never fail; phones and tablets are not needed for the admin (an admin test failing only there is skipped with that written reason). The public site still has to pass on phones, tablets and desktops.
11. **Build first, then test** (2026-11-05). No trial or partial test runs while building. Finish all the code of the step, then run the tests: one full run of the live copy; if it is good, stop (owner, 2026-11-10/11).

## Code standards (owner's requirements; details in PROJECT_LOG.md)
Every change keeps the code **customizable** (settings in the admin, theme tokens), **future proof** (migrations, strict types, standard PHP, JSON API), **fast and easy to extend** (server rendering, cache, layout registry, PHPStan + tests in CI), and **safe and secure**:
- prepared statements, escaped output, an HTML allow-list
- CSRF, hashed passwords, login throttling (5 per account + address, 20 per account from all addresses, 50 per address), a sign-in honeypot, sign-out everywhere on a password change, optional TOTP, roles
- uploads checked by content
- security headers, no third-party scripts, audit log

## Testing
- `npm test` runs on the **live copy only** (owner, 2026-11-10: `live/` is what goes to the real server; `COPIES=git,live` runs both): 9 projects (Chromium/Firefox/WebKit × phone/tablet/desktop) against the real PHP server (`tests/serve.mjs`) and SQLite (or MySQL 8.4 with `GAP_DB=mysql`), loaded with sample stories (`tests/seed-demo.php`, test database only), in three phases per copy:
  1. `setup-<copy>`: `tests/admin-setup.spec.ts` makes the first admin through the real `/admin/setup` step and adds an editor (accounts in `tests/fixtures/test-users.json`, test only).
  2. `<copy>-<engine>-<device>`: `site.spec.ts`, `stories.spec.ts`, `article.spec.ts`, `search.spec.ts`, `reader.spec.ts` (page cache, trending tags, search index tools), `admin.spec.ts`, `security.spec.ts` (botnet cap, honeypot, sign-out on password change, code typed into pages, Host header cache poisoning), and since step 5 `seo.spec.ts`, `api.spec.ts`, `backup.spec.ts`, `admin-more.spec.ts` (delete for good, tags, pictures, the new admin pages at every width, editors' limits).
  3. `<copy>-<engine>-<device>-publish`: `admin-publish.spec.ts` (publishing, new categories, homepage builder with sliders and live preview, code in a headline) (since 2026-11-13 also extra categories, the big Saved box, section names) and `menu.spec.ts` (menu builder, categories, site settings; they take turns with `inLine('menu')`), after phase 2, so the site tests never see a half-changed site.
- Local preview (`npm run preview` or `.claude/launch.json`, port 8091) adds a demo admin (`tests/seed-admin.php`, login in `tests/fixtures/test-users.json` → `demo_admin`); test runs never do.
- Test ports 8190/8191; the preview (`.claude/launch.json`) uses 8091. Each run writes new folders in `test-data/` and `test-results/`; nothing is deleted. Playwright browsers: `PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright`.
- The test server (`tests/serve.mjs`) runs 10 PHP processes per copy behind a small proxy that sends each request to the **least busy** one, so a slow picture (PHP GD) never blocks the requests behind it. `expect` timeout is 10 s because many browsers at once can make one answer take seconds. The admin scripts mark `html[data-ui="ready"]` when the admin modules have run, and the tests wait for it before tapping.
- SQLite is in WAL mode with `busy_timeout = 5000` and `synchronous = NORMAL` (`app/lib/db.php`): PDO's 60 s default meant a busy database held a request — and its PHP session lock — for a minute, which reads as a hang.
- Test phone, tablet AND desktop (Chromium, Firefox, WebKit; 320–1920 px), against the real running PHP server and database. Check that values were really saved, not just status codes.
- Run the full suite (`PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright WORKERS=6 npm test`; 6 workers, owner 2026-11-05: with 8, seven Chromium browsers starting at once took 40 s on this PC). 946 tests; a passing run is **912 passed, 34 skipped, 0 failed** (MySQL, 2026-11-13; the 34 skips are listed in `PROJECT_LOG.md`, step 5). **One good full run is enough** (owner, 2026-11-10/11): no repeat runs. If phase 2 has a failure Playwright skips the publish phase; then run it on its own with `PUBLISH_ONLY=1 npx playwright test --project='live-*-publish'`, so every test gets a result before anything is called done.
- `tests/fixtures.ts` is the `test` every spec imports: a worker still shutting down after 60 s ends itself (a browser's "closed" answer that never came froze two runs), and each worker opens one warm-up page on its own 120 s budget. Run temp files: `D:/dev/tmp/gap-playwright/<run>`.
- **MySQL mode** (task P15): `GAP_DB=mysql PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright WORKERS=6 npm test`. `tests/serve.mjs` makes a new MySQL 8.4 data folder in `test-data/<run>/<copy>/mysql-data` (`mysqld --no-defaults --initialize-insecure`, never the owner's `my.ini` or data), starts it on port 33000 + (port % 1000) = 33190/33191 (strict `sql_mode`, utf8mb4), makes database `gap` and stops mysqld with the server. `webServer` timeout 180 s.
- Test runs keep their temporary files (browser profiles) in `test-data/<run>/tmp` on D:, never on C: (`playwright.config.ts`).
- Heavy tests get a longer budget for their own work, never weaker assertions: "phones and tablets" 180 s (36 page loads), "throttling" 120 s (six sign-in rounds), publish "schedule" and "move to trash" 150 s.
- Step 4 tests: `tests/seed-demo.php` gives the first three sample stories a photo gallery and builds the search index at seed time. The page cache is **on in the test runs**, the same as on the real site (owner, 2026-11-05). `tests/db.php` clears it after every raw SQL write, as an admin save does; `?cache=1` forces it on for one page and responses say `X-Cache: HIT|MISS`. The cache tests use a page only they ask for and retry when another test's save cleared the cache in between. Tests that switch a site-wide setting (the trending row) wait in `inLine()` with the tests that read it.
- The first test (`admin-setup`) has 120 s: its time includes the cold start of the browser and the servers.
- SQLite on Windows: `tests/serve.mjs` keeps one database connection open for the whole run, so SQLite never deletes and re-creates its `-wal`/`-shm` files between requests (a virus scanner holding the old file left it "delete pending" and every open failed with "disk I/O error"); `db()` also retries that error up to 5 times.
- Don't stop a run half-way: its test servers stay behind on 8190/8191 and 21900-21919 and block the next run.
- Each report lists every skipped test by name with its reason. Give the owner a progress line about every 6 minutes during a run, and report each failure when it happens.

## Design
- All wireframes live in `docs/wireframes.html`. Update it with every design change.
- White page background everywhere, with no gray bands. Section header: serif title, divider, **See more** button. Red back-to-top button.
- Homepage layouts: `hero`, `hero_grid`, `list`, `grid`, `overlay_list`, `lead_grid`, `lead_list`, `headlines` (described in `PROJECT_LOG.md` → Design rules). In the builder each section has a "Name in the admin" (settings `name`, admin only); unnamed: hero layouts = Featured, the tabbed list = Latest and top stories (2026-11-13).
- Categories (2026-11-13): one **main** category per story (`posts.category_id`: address, breadcrumb, "More in") plus any number of **extra** ones in `post_categories` (migration 008). Every category filter goes through `category_match_sql()`, every count through `category_story_counts()` (`app/lib/posts.php`).
- Every admin save shows a big green box (`notify()` in `assets/admin/ui.js`, or `.a-flash` after a page form); red when not saved. It never takes a click.
- Logo: the globe + soldier + jet logo (`site image/logo/globalarmspost-logo-original.webp`) in header and footer, served trimmed and transparent as AVIF + WebP 1x/2x with a PNG fallback (task F18).
- Header (2026-11-05): one white row, logo left, dark menu text, icons right; red only as an accent; search is a small box under the icon. No date bar, no social links. Built in the admin (any links, submenus under any parent).
- Phones: every section is one big rounded photo story, then headline + date rows with a small photo on the right. No grids on phones.
- Category page: posts grouped by day under a plain text date heading ("Today · Tuesday, 3 November 2026", no calendar box); each story shows its time. Smart search: own word index; joined or spaced words, word forms, typo fixing, ranking, highlights (details in `PROJECT_LOG.md`).
- Admin (`/admin`, step 3, wireframe 5): setup step for the first admin, sign-in (throttling, keep me signed in, optional two-step code), roles admin/editor, dashboard, stories list, story editor (pictures anywhere in the body, tags/categories/authors added on the spot), authors, homepage builder. Side menu from 900 px; under 900 px a bottom tab bar and bottom sheets.
- Article page: headline, author, date + updated, Aa and Share buttons, photo gallery with captions, Summary bullet box, body, credits line, topic chips, share row, then "More in {category}" and "Latest news" (full list in `PROJECT_LOG.md` → Design rules → Article page).
- Reader side (step 4, approved 2026-11-11): the article page above, tag pages (`/tag/{slug}`), the search page (`/search?q=` live, `/#search?q=` git) with filters, sort, pages, highlights and "Did you mean", suggestions under the header search box from `/api/suggest`, the trending tags row under the header, and the page cache (`storage/cache/pages`, expires at the next scheduled story, cleared by every admin save). Settings in the admin: trending tags, cache, "Rebuild search index".
- Step 5 (wireframe section 8): menu builder `/admin/menu` (admins; links to category, tag, story, page or web address; drag with edge auto-scroll, nest, hide, new tab), Settings (site name, tagline, about, site address `site_url`, time zone, stories per category page), Categories (rename, address, parent, header menu for admins, order), Pictures (texts, where used, delete when unused), Tags (rename, merge), Trash "Delete for good" (admins), homepage builder picture sliders + live preview, Backup `/admin/backup` (admins; ZIP in `storage/backups`, never served).
- SEO (`app/lib/seo.php`): title, description, canonical, Open Graph + X card on every page, NewsArticle JSON-LD on stories, `/sitemap.xml` (cached), `/robots.txt`, 301s from old WordPress addresses. Full addresses use `site_url`, else `request_host()`; `site_link(..., 'path')` / `public_url()` give proper addresses in both copies.
- JSON API (`app/lib/api.php`): `/api/posts`, `/api/posts/{slug}`, `/api/categories`; public stories only, no cookies, rate limited (`api_limit`), cached.
- GitHub Actions: `.github/workflows/check.yml` runs PHPStan level 6 (`phpstan.neon`) on every push; the Playwright suite runs on the owner's PC.

## Stack
- PHP 8.1+ with no framework, PDO MySQL (Hostinger) or SQLite, plain JavaScript. No Node on the server.
- Code lives in `public/app/lib/`, views in `public/app/views/`, assets in `public/assets/`. The admin: `public/app/admin/router.php`, `public/app/views/admin/`, `public/assets/admin/` (served with no-cache, its modules import each other).
- All dates are stored in UTC as `Y-m-d H:i:s`. A post is public when `status = 'published' AND published_at <= now`.
- Pictures are AVIF + WebP made in the admin's browser (jSquash). PHP GD is the fallback.
- Local tools are on D: (`D:\codex system\tools\php`, `nodejs`, `mysql`). Playwright browsers are in `D:/dev/playwright`.
