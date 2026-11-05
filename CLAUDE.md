# Global Arms Post website

News site for globalarmspost.com: a PHP frontend plus an admin backend. Repo: https://github.com/Cynicalfocus123/globalarmspost

Full history, status and the wireframe notes are in `PROJECT_LOG.md`. Read it before starting work.

## Hard rules (same as PROJECT_LOG.md; keep both in sync)
1. **Never delete anything.** Sync only adds or overwrites. List stale files so the owner can decide. Any deletion needs the owner's yes to that exact deletion first. Exception: old task list Excel files with a version in the name are removed (rule 7).
2. **Two separate folders**, both updated on every change:
   - `git/`: source, pushed to GitHub.
   - `live/`: the Hostinger `public_html` mirror, built from `git/public/` with proper addresses (rule 9). Never overwrite `app/config.php`, `uploads/` or `storage/`.
3. **A change is done only when** it is committed and pushed, `live/` is rebuilt from that commit, and `npm run verify:live` says they match. `git/` and `live/` are always updated at the same time. If only one is updated, the task is not done.
4. Every update shows a wireframe, the GitHub link, the changes, and the Actions run.
5. **Every time a task is updated or finished, update all `.md` files** (`PROJECT_LOG.md`, `CLAUDE.md`, `README.md` and any other `.md`) in the same change, so they always match the code.
6. **Show every code change in the Claude app's diff panel** (split view, right side) before committing. Work inside `git/` (the repo root), mark new files with `git add -N`, then open the diff pane.
7. **Keep the task list Excel up to date.** Edit `docs/tasks.json`, bump `version`, and run `python scripts/build-tasks.py`. It updates the one file `../Claude outputs/Global Arms Post task list.xlsx` in place. **Never make a new Excel file**: only update, change and delete inside the same file (owner's hard rule, 2026-11-05). The version number is inside the file, not in its name. Statuses:
   - **Not started:** not begun.
   - **Pending:** started but not finished.
   - **Done:** finished **and approved by the owner** (code also tested, committed and pushed), and then only on the Done tab. Nothing is Done without the owner's approval.
8. **Every prompt starts with reading the hard rules** in `PROJECT_LOG.md` and this file, and the work follows them.
9. **`live/` is a plain folder next to `git/` (not on GitHub) and mirrors `git/` exactly**, backend and frontend.
   - `git/` uses `#` links (`/#asia`).
   - `live/` never does: every page has a proper address (`globalarmspost.com/home`, `/asia`, `/europe`, `/{category}/{slug}`, `/tag/usa`, `/search?q=`).
   - Every link goes through one `site_link()` function (PHP has a built-in `link()`). The full URL map and the reserved names are in `PROJECT_LOG.md`.
10. **Every change looks and works right on all modern phones, tablets and desktops**: text, images, menus, admin, everything.
    - Browsers: Chrome, Edge, Safari, Firefox.
    - Widths: 320–1920 px. No sideways scroll, cut-off text or stretched images. Menus work by touch.
    - Tested on phone, tablet and desktop in Chromium, Firefox and WebKit. A break on any of them means not done.
11. **Build first, then test** (2026-11-05). No trial or partial test runs while building. Finish all the code of the step, then run the tests (the 4 full runs).

## Code standards (owner's requirements; details in PROJECT_LOG.md)
Every change keeps the code **customizable** (settings in the admin, theme tokens), **future proof** (migrations, strict types, standard PHP, JSON API), **fast and easy to extend** (server rendering, cache, layout registry, PHPStan + tests in CI), and **safe and secure**:
- prepared statements, escaped output, an HTML allow-list
- CSRF, hashed passwords, login throttling, optional TOTP, roles
- uploads checked by content
- security headers, no third-party scripts, audit log

## Testing
- `npm test` runs on 18 projects (git/live × Chromium/Firefox/WebKit × phone/tablet/desktop) against the real PHP server (`tests/serve.mjs`) and SQLite, loaded with sample stories (`tests/seed-demo.php`, test database only), in three phases per copy:
  1. `setup-<copy>`: `tests/admin-setup.spec.ts` makes the first admin through the real `/admin/setup` step and adds an editor (accounts in `tests/fixtures/test-users.json`, test only).
  2. `<copy>-<engine>-<device>`: `site.spec.ts`, `stories.spec.ts`, `admin.spec.ts`.
  3. `<copy>-<engine>-<device>-publish`: `admin-publish.spec.ts` (publishing, new categories, homepage builder), after phase 2, so the site tests never see a half-changed homepage.
- Local preview (`npm run preview` or `.claude/launch.json`, port 8091) adds a demo admin (`tests/seed-admin.php`, login in `tests/fixtures/test-users.json` → `demo_admin`); test runs never do.
- Test ports 8190/8191; the preview (`.claude/launch.json`) uses 8091. Each run writes new folders in `test-data/` and `test-results/`; nothing is deleted. Playwright browsers: `PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright`.
- The test server (`tests/serve.mjs`) runs 10 PHP processes per copy behind a small proxy that sends each request to the **least busy** one, so a slow picture (PHP GD) never blocks the requests behind it. `expect` timeout is 10 s because 8 workers on three engines can make one answer take seconds. The admin scripts mark `html[data-ui="ready"]` when the admin modules have run, and the tests wait for it before tapping.
- SQLite is in WAL mode with `busy_timeout = 5000` and `synchronous = NORMAL` (`app/lib/db.php`): PDO's 60 s default meant a busy database held a request — and its PHP session lock — for a minute, which reads as a hang.
- Test phone, tablet AND desktop (Chromium, Firefox, WebKit; 320–1920 px), against the real running PHP server and database. Check that values were really saved, not just status codes.
- Run the full suite 4 times (`PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright WORKERS=8 npm test`). Stop when all 4 runs pass: 786 passed, 62 skipped, 0 failed per run.
- Heavy tests get a longer budget for their own work, never weaker assertions: "phones and tablets" 180 s (36 page loads), "throttling" 120 s (six sign-in rounds), publish "schedule" and "move to trash" 150 s.
- Each report lists every skipped test by name with its reason.

## Design
- All wireframes live in `docs/wireframes.html`. Update it with every design change.
- White page background everywhere, with no gray bands. Section header: serif title, divider, **See more** button. Red back-to-top button.
- Homepage layouts: `hero`, `hero_grid`, `list`, `grid`, `overlay_list`, `lead_grid`, `lead_list`, `headlines` (described in `PROJECT_LOG.md` → Design rules).
- Logo: the globe + soldier + jet logo (`site image/logo/globalarmspost-logo-original.webp`) in header and footer, served trimmed and transparent as AVIF + WebP 1x/2x with a PNG fallback (task F18).
- Header (2026-11-05): one white row, logo left, dark menu text, icons right; red only as an accent; search is a small box under the icon. No date bar, no social links. Built in the admin (any links, submenus under any parent).
- Phones: every section is one big rounded photo story, then headline + date rows with a small photo on the right. No grids on phones.
- Category page: posts grouped by day under a plain text date heading ("Today · Tuesday, 3 November 2026", no calendar box); each story shows its time. Smart search: own word index; joined or spaced words, word forms, typo fixing, ranking, highlights (details in `PROJECT_LOG.md`).
- Admin (`/admin`, step 3, wireframe 5): setup step for the first admin, sign-in (throttling, keep me signed in, optional two-step code), roles admin/editor, dashboard, stories list, story editor (pictures anywhere in the body, tags/categories/authors added on the spot), authors, homepage builder. Side menu from 900 px; under 900 px a bottom tab bar and bottom sheets.
- Article page: headline, author, date + updated, Aa and Share buttons, photo gallery with captions, Summary bullet box, body, credits line, topic chips, share row, then "More in {category}" and "Latest news" (full list in `PROJECT_LOG.md` → Design rules → Article page).

## Stack
- PHP 8.1+ with no framework, PDO MySQL (Hostinger) or SQLite, plain JavaScript. No Node on the server.
- Code lives in `public/app/lib/`, views in `public/app/views/`, assets in `public/assets/`. The admin: `public/app/admin/router.php`, `public/app/views/admin/`, `public/assets/admin/` (served with no-cache, its modules import each other).
- All dates are stored in UTC as `Y-m-d H:i:s`. A post is public when `status = 'published' AND published_at <= now`.
- Pictures are AVIF + WebP made in the admin's browser (jSquash). PHP GD is the fallback.
- Local tools are on D: (`D:\codex system\tools\php`, `nodejs`, `mysql`). Playwright browsers are in `D:/dev/playwright`.
