# Global Arms Post

News site for globalarmspost.com: PHP 8.1+ (no framework), MySQL or SQLite, plain JavaScript.
History, rules and design notes: [PROJECT_LOG.md](PROJECT_LOG.md). Wireframes: [docs/wireframes.html](docs/wireframes.html).

## Folders
- `public/` — the site (this becomes Hostinger `public_html` through `../live/`)
  - `app/lib/` code, `app/views/` templates, `app/migrations/` database changes, `app/cli/` command line tools
  - `app/admin/` the admin at `/admin`, with its views in `app/views/admin/`
  - `assets/` CSS, JS, fonts, logo; `assets/admin/` the admin's CSS + JS (and the jSquash AVIF encoder in `vendor/`)
- `scripts/` — build tools (logo, fonts, live/ build and check, task list)
- `tests/` — Playwright tests and the test server

## Run the tests
```bash
PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright WORKERS=8 npm test
```
848 tests on 18 projects (git/live × Chromium/Firefox/WebKit × phone/tablet/desktop) in three phases per copy: the setup step (`admin-setup`), the site/story/admin tests, then the publish tests (`*-publish`) after them.
Starts the real PHP server with a fresh SQLite database for both copies (git/ with `#` links, live/ with proper addresses), loads the sample stories (test database only) and stops it after the run. The first test makes the admin account through the setup step; the test accounts are in `tests/fixtures/test-users.json` (test databases only). Browsers: `PLAYWRIGHT_BROWSERS_PATH=D:/dev/playwright`.
A passing run is **786 passed, 62 skipped, 0 failed**; the suite is run 4 times in a row. The 62 skips are by design (a database check or a scheduled-story test that runs once per copy, tests for the phone menu or the desktop columns only) and every report lists them by name.

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
