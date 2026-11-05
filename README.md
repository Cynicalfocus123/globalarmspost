# Global Arms Post

News site for globalarmspost.com: PHP 8.1+ (no framework), MySQL or SQLite, plain JavaScript.
History, rules and design notes: [PROJECT_LOG.md](PROJECT_LOG.md). Wireframes: [docs/wireframes.html](docs/wireframes.html).

## Folders
- `public/` — the site (this becomes Hostinger `public_html` through `../live/`)
  - `app/lib/` code, `app/views/` templates, `app/migrations/` database changes, `app/cli/` command line tools
  - `assets/` CSS, JS, fonts, logo
- `scripts/` — build tools (logo, fonts, live/ build and check, task list)
- `tests/` — Playwright tests and the test server

## Run the tests
```bash
npm test
```
Starts the real PHP server with a fresh SQLite database for both copies (git/ with `#` links, live/ with proper addresses), loads the sample stories (test database only) and stops it after the run.

## Look at the site locally
The Claude app's preview (`.claude/launch.json`, "live-copy") serves the live copy with sample stories at http://127.0.0.1:8091/.

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
