# Global Arms Post website — project log

Tracks every change, where it lives, and whether `live/` matches `git/`.
Newest entry first. Wireframes: [docs/wireframes.html](docs/wireframes.html)

## Links
- Repository: https://github.com/Cynicalfocus123/globalarmspost
- Commits: https://github.com/Cynicalfocus123/globalarmspost/commits/main
- GitHub Actions: https://github.com/Cynicalfocus123/globalarmspost/actions (not set up yet)

## Hard rules
1. **Never delete anything.** Sync only adds or overwrites. Stale files are listed so a person can decide. Any deletion needs the owner's yes to that exact deletion first. **One exception (owner's rule, 2026-11-03):** old versions of the task list Excel are deleted every time a new one is built (rule 7).
2. **Two separate folders**, both updated on every change:
   - `git/`: source code. This is the repo pushed to GitHub.
   - `live/`: the Hostinger mirror for `public_html`. It is the contents of `git/public/`. Sync never overwrites the server's own data: `app/config.php`, `uploads/`, `storage/`.
3. **A change is done only when** it is committed and pushed, `live/` is rebuilt from that commit, and `npm run verify:live` says they match. **`git/` and `live/` are always updated at the same time** with every change or addition. If only one of them is updated, the task is not done. (Owner's rule, 2026-11-03.)
4. Every update shows a wireframe, the GitHub link, the changes, and the Actions run.
5. **Every time a task is updated or finished, update all `.md` files** (`PROJECT_LOG.md`, `CLAUDE.md`, `README.md` and any other `.md` in the project) in the same change, so they always match the code. (Owner's rule, 2026-11-03.)
6. **Show every code change in the Claude app's diff panel** (split view, right side) before it is committed. The Claude session works inside `git/`, so the app finds the repository. New files are marked with `git add -N` so they show in the diff too. (Owner's rule, 2026-11-03.)
7. **Keep the task list Excel up to date** (`Claude outputs/Global Arms Post task list <date> v<N>.xlsx`, built from `docs/tasks.json` by `python scripts/build-tasks.py`). Every new task goes in. Each rebuild gets a new version number. **Only the latest Excel is kept:** every build deletes the older task list versions in `Claude outputs/` (owner's rule, 2026-11-03). Status rules (owner's rule, 2026-11-03):
   - **Not started:** not begun yet.
   - **Pending:** started but not finished, or waiting for something.
   - **Done:** finished **and approved by the owner** (code also tested, committed and pushed). It is then removed from Not started and Pending and appears only on the Done tab.
8. **Every prompt starts with reading the hard rules** in `PROJECT_LOG.md` and `CLAUDE.md`, and the work follows them. (Owner's rule, 2026-11-03.)
9. **`live/` is not on GitHub.** It is a plain folder next to `git/`, and it mirrors `git/` exactly: every backend and frontend change is in both. Links differ by copy (owner's rule, 2026-11-03):
   - **`git/`:** in-page links use `#`, like One Taste (e.g. `/#asia`).
   - **`live/`:** never uses `#` links. Every page has its own proper address: `globalarmspost.com/home`, `globalarmspost.com/asia`, `globalarmspost.com/europe` and so on (see the URL map).
   - All links go through one `site_link()` function (`public/app/lib/link.php`), so the two copies can never drift apart. (PHP already has a built-in `link()`, so ours is named `site_link()`.)
10. **Every change looks and works right on all modern phones, tablets and desktops** (owner's rule, 2026-11-03). This covers text, images, menus, buttons, admin pages, everything.
    - **Browsers:** Chrome, Edge, Safari (Mac + iPhone/iPad) and Firefox, current versions.
    - **Widths:** 320 px up to 1920 px. No sideways scroll, no cut-off or overlapping text, no squashed or stretched images.
    - **Menus:** usable by touch on phones and tablets.
    - **Tests:** every change is tested on phone, tablet and desktop, in Chromium, Firefox and WebKit (Safari's engine). A change that breaks on any of them is not done.

## Folder layout
```
global arm post new site/
├─ git/    ← source + this log (GitHub repo); links use # (/#asia)
│  └─ public/   ← the site itself (PHP)
└─ live/   ← NOT on GitHub; mirrors git/ exactly, with proper addresses (/asia, /home);
             upload its contents to Hostinger public_html (npm run build:live)
```

## URL map (git → live)
| Page | git copy | live copy (globalarmspost.com) |
|---|---|---|
| Home | `/#home` (and `/`) | `/home` (and `/`) |
| Homepage section | `/#asia`, `/#europe` … | `/asia`, `/europe` … (the category page) |
| Category page | `/#asia` → category page | `/asia`, `/middle-east-africa`, `/land`, `/air` … |
| Subcategory | `/#regions/asia` | `/asia` (own address; breadcrumb shows Regions › Asia) |
| Tag page | `/#tag/usa` | `/tag/usa` |
| Article | `/#news/{slug}` | `/{category}/{slug}`, e.g. `/europe/us-lift-sanctions-turkey` |
| Search | `/#search?q=` | `/search?q=ukraine+war` |
| Contact, about … | `/#contact` | `/contact`, `/about` |
| Admin | `/admin` (no `#`, same in both) | `/admin` |

- In `live/`, category addresses sit at the top level (`/asia`, not `/category/asia`). Some names are reserved, so a category can't take them: `home`, `admin`, `api`, `search`, `tag`, `install`, `assets`, `uploads`, `feed`, `sitemap.xml`, `robots.txt`.
- Old WordPress links (e.g. `/category/europe/`, `/2026/07/slug/`) get a 301 redirect to the new addresses.

## How to make a change (every time)
1. Code the change in `git/`.
2. Run the full test suite on phone, tablet and desktop (Chromium, Firefox, WebKit) 4 times. Stop when all 4 runs pass. List every skipped test by name with its reason.
3. Update every `.md` file (rule 5).
4. Show the changes in the diff panel (rule 6).
5. Commit, rebuild `live/`, verify it matches, push.

Commands (run in `git/`):
- `npm test` — full suite, all browsers and devices, both copies (real PHP server + SQLite, started and stopped by Playwright). `ENGINES=chromium npm test` runs one engine.
- `npm run build:live` — rebuild `live/` from the current commit (commit first).
- `npm run verify:live` — check `live/` matches the commit.
- `npm run build:logo` — rebuild the logo and icons from `site image/logo/`.
- `npm run tasks` — rebuild the task list Excel from `docs/tasks.json`.
- Local PHP needs `pdo_sqlite`; the test server loads it by itself if `php.ini` does not.

## Planned stack
- PHP 8.1+ with no framework, MySQL (Hostinger) or SQLite, plain JavaScript. Runs on any shared host: upload, open `/install`.
- Public pages are rendered on the server and kept in a page cache. Each cached page expires at the next scheduled post, so scheduled posts show at their exact time.
- Pictures are converted to AVIF + WebP (480 / 960 / 1600 px) in the admin's browser. PHP GD is only a fallback, because many shared hosts' PHP cannot write AVIF.
- Admin: post editor (bold, italic, headings, links, lists, pasted pictures), categories and tags added on the spot, draft / published / scheduled / trash, homepage section builder. Each section has: layout, span, columns × rows, image size sliders, tabs, include/exclude categories, tags, order by, ascending/descending, skip count, sticky posts first, avoid duplicates. Each category gets its own page: `globalarmspost.com/{slug}` in `live/` (e.g. `/asia`), `/#{slug}` in `git/`.

## Code standards (owner's 4 requirements, 2026-11-03)
Every change must keep the code:
1. **Customizable**
   - Site name, colors, fonts, menu, homepage sections and article parts are set in the admin, not in code.
   - Colors and fonts are CSS variables (theme tokens).
2. **Future proof**
   - Numbered database migrations, so new features add columns without losing data.
   - PHP 8.1+ with strict types and only standard PHP (no framework to outgrow). Works with MySQL or SQLite.
   - A read-only JSON API (`/api/posts`) for a future app or newsletter.
3. **Fast, modern, easy to extend**
   - Pages are rendered on the server, with a page cache, AVIF/WebP pictures, self-hosted fonts and almost no JS.
   - New homepage layouts plug in through a layout registry: one template + one settings file each, and the admin form builds itself from the settings file.
   - Static analysis (PHPStan) and the Playwright tests run on every push in GitHub Actions.
4. **Safe and secure**
   - Database: prepared statements only.
   - Output: everything is escaped on output, and post HTML goes through an allow-list.
   - Login and sessions: CSRF tokens on every form and API call; bcrypt/argon2 passwords; hardened session cookies; login throttling; optional two-step login (TOTP); editor and admin roles.
   - Uploads: checked by file content; PHP can't run in `uploads/`.
   - Headers: CSP, HSTS, X-Frame-Options, Referrer-Policy.
   - No third-party scripts on public pages.
   - An audit log of admin actions, and a backup and export tool.

## Design rules
- **White page background everywhere** (owner's rule, 2026-11-03). No gray bands behind sections. Dark mode is only for visitors who switch it on.
- Section header: serif title, a thin vertical divider, and a **See more** button on the right that opens the category page.
- Red back-to-top button (bottom right), shown after scrolling.
- Homepage section layouts:

  | Layout | Look | Example |
  |---|---|---|
  | `hero` | dark text panel left, photo right | top story |
  | `hero_grid` | hero, then cards under it | front page lead |
  | `list` | small photo + title rows, optional tabs | Top stories |
  | `grid` | cards, columns × rows | Asia 4 across |
  | `overlay_list` | full-width photo with the title centred on it over a dark fade, byline under the title, then photo + title rows | Asia, Middle East and Africa |
  | `lead_grid` | big lead story on the left (photo, large title, excerpt, byline), 2 × 2 grid of cards on the right | Europe |
  | `lead_list` | lead story + title list | |
  | `headlines` | numbered titles, no photos | |

  On phones every section uses one calm pattern (owner, 2026-11-03): one big rounded photo with a large headline and the date, then rows of headline + date with a small rounded photo on the right, split by thin lines. No grids or cramped columns on phones. An optional red "Live updates" label per post.
- **Category page** (`/asia`, `/land` …; approved 2026-11-03):
  - Breadcrumb, category title, newest posts first.
  - Posts are grouped by day under a plain text date heading, e.g. "**Today** · Tuesday, 3 November 2026" ("Today" / "Yesterday" in red). No calendar box. The heading stays in view while scrolling that day.
  - Each story row: time + author (red), headline, excerpt, photo on the right. The whole row is the link (no Read more button).
  - Sidebar "Most read in {category}" (set in the admin; moves under the list on phones). "Older stories" button at the end.
- **Article page**, top to bottom (white page, same header and red menu):
  1. Breadcrumb (Home › category).
  2. Big headline.
  3. **By author**, then publish date and time with the time zone. "Updated …" shows when the post was edited after publishing. Reading time.
  4. **Aa** button (3 text sizes, remembered in the browser) and **Share** button (the phone's share sheet on phones; a menu on desktop).
  5. **Photo gallery:** the featured photo plus extra photos, with ‹ › arrows, a "1/2" counter and an expand button for full screen. Each photo has the **caption the admin types under it**, plus a photo credit.
  6. **Summary box:** the bullet points the admin writes (one per line), on a light gray box inside the white page.
  7. **Body:** paragraphs, bold, italic, links, subheads, bullet and numbered lists, photos with captions.
  8. **Credits line** ("Reporting by … Editing by …"), optional.
  9. **Topics:** the post's tags as chips, linking to the tag pages.
  10. **Share row:** X, Facebook, LinkedIn, WhatsApp, email, copy link. Plain links, no third-party scripts.
  11. **More in {category}:** posts from the same category, in a row with ‹ › arrows (swipe on phones).
  12. **Latest news:** the newest posts site-wide, with a See more button.
- Post editor fields for this: Summary (one bullet per line), Featured photo caption and credit, Extra gallery photos (each with a caption), Credits line.
- **Logo** (owner's choice, 2026-11-03): the globe + soldier + jet "GLOBALARMSPOST.com" logo, in the header and the footer.
  - Source: `site image/logo/globalarmspost-logo-original.webp` (1536 × 1024, 182 KB, wide white border).
  - Speed plan:
    - trim the white border
    - transparent background, so it also works in dark mode
    - AVIF + WebP at 1x and 2x for sharp screens, plus a small PNG fallback
    - width and height set, so nothing jumps
    - header logo preloaded
  - Favicon and app icons are made from the globe. Done in step 1 (task F18).
- **Header** (like globalarmspost.com today):
  - Layout: the logo centred on top, the red category menu bar below it, dark mode + search buttons. Regions opens a white rounded dropdown (U.S., Asia, Europe, Middle East and Africa).
  - Logo position: **centred** (owner, 2026-11-03).
  - **No date bar and no social links** above the logo (owner, 2026-11-03). The header is just the logo, then the categories.
  - **Header builder in the admin:** add anything to the header:
    - menu links to categories, tags, posts or any web address
    - the logo
    - dark mode and search buttons, on or off
  - Submenus can go under any parent item. Drag to reorder and nest.
  - Stored in `menus` + `menu_items` (parent, position, type, label, link, new tab).
- **Smart search** (owner: "extremely smart and robust"):
  - Uses its own word index, built on every save and working on MySQL and SQLite.
  - Searches title, body, excerpt, summary, tags, categories and author.
  - Joined or spaced words match each other: "ukrainewar" finds "ukraine war". Joined words are split using the index's own vocabulary.
  - A single word works.
  - Word forms match (missile / missiles, strike / strikes).
  - Typos are fixed ("ukrane" finds "ukraine"), with a "Did you mean" line.
  - Ranking: title and tag matches first, whole-phrase matches higher, newer articles slightly higher.
  - Results show a snippet with the matched words highlighted, filters (category, date) and sort (best match / newest). Suggestions appear while typing.
  - The admin has a "Rebuild search index" button.

---

## Change log

### 2026-11-03 — New rule: keep only the latest task list Excel
- Owner's rule: delete the old task list Excel files every time, leaving only the latest. Added to hard rules 1 and 7.
- Deleted (owner's request): task list v1–v9 in `Claude outputs/`. Kept: v10, then replaced by v11 for this change.
- `scripts/build-tasks.py` now deletes older versions after it saves the new one.

### 2026-11-03 — Step 1 approved
- The owner approved step 1 (commit `4f56b4d`). Task list v10: F18 logo, F4 header, F6 phone menu, B10 menu data, B14 migrations, P10 live build + verify, P14 `site_link()`, P8 test suite, P11 first push and P7 (approval to build) are **Done**.
- Split out so Done stays true: the 301 redirects from old WordPress links stay in F12; testing on MySQL is the new task **P15** (Not started).
- Still Pending: P1–P6 (project docs, research, stack, code standards, setup, task list) and F11 (site look, partly built). B13 (installer) and B16 (two-step login, audit log) are partly started in step 1 but still Not started overall.

### 2026-11-03 — Phase 1, step 1: page shell, header, footer, logo
- **Logo (F18)**, built by `npm run build:logo` from the original (kept as it is):
  - white border trimmed (1536 × 1024 → 1403 × 448), background made transparent, so it works on white, dark and red
  - header logo: original 182 KB (186,658 bytes) → **AVIF 1x 5.1 KB, AVIF 2x 11.9 KB**, WebP 1x 9.3 KB, WebP 2x 26.7 KB, PNG fallback 30.4 KB
  - width + height set (240 × 77), header logo preloaded, favicon.ico (16/32/48), favicon-32, apple-touch-icon, 192/512 app icons, `site.webmanifest`
- **Header (F4, F6):** centred logo, red category menu below it from the database, Regions dropdown, dark mode and search buttons. Phones and tablets (under 1024 px): red bar with menu button, centred logo, dark mode, search; the menu opens as a panel with tap-to-open submenus.
- **Footer:** logo, about text (setting), category links, ©. Red back-to-top button.
- **Core code** (`public/app/`): bootstrap, PDO database (SQLite or MySQL), numbered migrations (`001_core`, `002_starter_content`), settings, menu tree, views, security headers (CSP, nosniff, X-Frame-Options, Referrer-Policy, HSTS on https), `.htaccess` (HTTPS, front controller, app/ and storage/ blocked, no scripts in uploads/).
- **Links:** one `site_link()` function (PHP already has a built-in `link()`, so the name differs). `app/build.php` says which copy: `hash` in git/, `path` in live/.
- **live/ tools:** `npm run build:live` (from the current commit only; never deletes; keeps `app/config.php`, `uploads/`, `storage/`; lists stale files) and `npm run verify:live`.
- **Tests:** `tests/site.spec.ts`, 15 tests × 18 projects (git/live × Chromium/Firefox/WebKit × phone/tablet/desktop) against the real PHP server and a real SQLite database. **Full suite run 4 times: 248 passed, 22 skipped, 0 failed each time.** Skipped (by name):
  - *Phone menu opens and closes* — the 6 desktop projects: desktop has no menu button; the Regions dropdown test covers desktop.
  - *Database: migrations ran and a saved setting shows on the page* — 16 projects: a server check that is the same everywhere, so it runs once per copy (desktop Chromium); every other test reads that same database on all 18 projects.
- The test server (`tests/serve.mjs`) puts 6 PHP processes behind a small Node proxy, because PHP's built-in server answers one connection at a time and browsers' idle preconnect sockets blocked it.
- Firefox 157 and WebKit 27.2 for Playwright downloaded to `D:/dev/playwright` (owner's OK).
- Test runs write to new folders each time (`test-data/<run>/`, `test-results/<run>/`), so nothing earlier is ever deleted.
- MySQL: the code supports it, but this step was tested on SQLite only.

### 2026-11-03 — Phase 0: wireframes approved
- The owner approved all wireframes in `docs/wireframes.html` after these changes:
  - Header: the date and the social links above the logo are removed. The header is the centred logo with the red category menu below it.
  - New wireframe 4, **category page**: posts grouped by day under a plain text date heading (no calendar box), so every story's date is clear. Made to look different from the current site's timeline.
  - All phone wireframes now use one lead story + headline/date rows with a small photo on the right (no grids).
- Logo position: **centred** (the owner first said left, then chose centred).
- **No WordPress import.** The stories will be scraped from the current site later (task B18).
- Task list v6: F1, F2, F3 and the new F19 (category page wireframe) are **Done**.
- **The 6 draft backend files were removed** (owner's yes, 2026-11-03): `public/app/lib/db.php`, `schema.php`, `helpers.php`, `auth.php`, `sanitize.php`, `media.php`. They were never committed. Phase 1 writes them fresh. Task list v7: B1–B6 back to Not started.
- Still open: the logo (F18); the GitHub remote, the `live/` build and `npm run verify:live` (P10); the go-ahead for Phase 1.

### 2026-11-03 — New logo for header and footer (step 1)
- The owner sent the new logo (globe, soldier, jet, GLOBALARMSPOST.com) for the header and footer. It must be optimized for speed.
- Saved the original as `site image/logo/globalarmspost-logo-original.webp` (outside `git/`, like One Taste's `site image/`).
- New task **F18** (Not started, part of step 1): trimmed, transparent, AVIF + WebP 1x/2x + PNG fallback, preloaded, favicon from the globe. Task list v5.
- Nothing committed. No site code changed.

### 2026-11-03 — Hard rule 10: correct on every modern phone, tablet and desktop
- **New rule 10:** every change (text, images, menus, everything) must look and work right on all modern phones, tablets and desktops.
  - Browsers: Chrome, Edge, Safari, Firefox.
  - Widths: 320–1920 px.
  - Tests: phone, tablet and desktop in Chromium, Firefox and WebKit.
- Test task P8 updated to match (task list v4).
- Nothing committed. No site code changed.

### 2026-11-03 — Hard rule 9: live/ mirrors git/ with proper addresses
- **New rule 9:**
  - `live/` is a plain folder, not on GitHub. It mirrors `git/` exactly.
  - `git/` uses `#` links (`/#asia`).
  - `live/` never does: every page has its own address (`globalarmspost.com/home`, `/asia`, `/europe`).
- New **URL map** section (git → live). Category pages are now `/asia` instead of `/category/asia`, and articles are `/{category}/{slug}`.
- Wireframes and the task list now use the new addresses (task list v3).
- Nothing committed. No site code changed.

### 2026-11-03 — Hard rule 3 made stricter; hard rule 8 added
- **Rule 3:** `git/` and `live/` are always updated at the same time with every change. If only one is updated, the task is not done.
- **New rule 8:** every prompt starts with reading the hard rules in the `.md` files.
- Open question for the owner: `live/` has no GitHub repository of its own (it is the Hostinger copy). Should `live/` also be pushed somewhere, e.g. its own repo or a `live` branch?
- Nothing committed. No site code changed.

### 2026-11-03 — Task list v2: nothing is Done until the owner approves
- The owner pointed out that nothing can be Done yet, because the wireframes and plan are not approved. The 9 items v1 marked Done are now **Pending**:
  - hard rules and .md files
  - research
  - stack choice
  - code standards
  - git setup
  - the task list
  - the 3 wireframes
- **Rule from now on:** a task is Done only after the owner approves it (and, for code, after it is tested, committed and pushed).
- Count is now **0 Done, 17 Pending, 31 Not started**: `Claude outputs/Global Arms Post task list 2026-11-03 v2.xlsx`. v1 is kept (nothing deleted).
- Nothing committed. No site code changed.

### 2026-11-03 — Task list Excel; header builder and smart search planned
- **New task list Excel** in the same layout as the CoreCart list: `Claude outputs/Global Arms Post task list 2026-11-03 v1.xlsx`.
  - Tabs: Frontend, Backend, Project (open tasks only), then Done, Pending, Not started.
  - Status colors and a dropdown, same as CoreCart.
  - Built from `docs/tasks.json` by `scripts/build-tasks.py`.
  - Count: **9 Done, 8 Pending, 31 Not started.**
- **New hard rule 7:** keep the task list up to date. Not started = not begun. Pending = started, not finished. Done = only on the Done tab.
- Added as **Pending:** F4, a header like the current site (logo row, red menu, Regions dropdown). Logo position to be confirmed (right on today's site, centred in the wireframe).
- Added as **Not started:**
  - F5 + B10: header builder (anything in the header, submenus under any parent)
  - F7 + B11: smart search (joined or spaced words, single words, word forms, typo fixing, ranking, highlights)
  - B18: import the current WordPress posts (suggested, owner to decide)
- Nothing committed. No site code changed, so no tests.

### 2026-11-03 — All wireframes in one file
- New `docs/wireframes.html` holds every wireframe so far, desktop and phone, on one page:
  1. homepage
  2. category blocks (Asia, Middle East and Africa, Europe)
  3. article page
- It opens in any browser with no server. Each future design change updates this file (hard rule 4).
- Nothing committed. No site code changed, so no tests.

### 2026-11-03 — Code standards set
- The owner's 4 requirements (customizable, future proof, fast and extendable, safe and secure) are written down as **Code standards**, with how the code meets each.
- Added to the plan because of them:
  - database migrations
  - a layout registry (each new layout is one template + one settings file)
  - theme tokens editable in the admin
  - a read-only JSON API
  - PHPStan + tests in GitHub Actions
  - security headers (CSP, HSTS)
  - optional two-step login
  - an audit log
  - backup and export
- Nothing committed. No site code changed, so no tests.

### 2026-11-03 — Article page design
- Wireframe shown for the article (detail) page, desktop and phone, following the owner's reference layout. Full list under Design rules → Article page:
  - headline, author, date + updated date
  - Aa (text size) and Share buttons
  - photo gallery with captions under each photo
  - Summary bullet box
  - body with bullet lists and captioned photos
  - credits line, topic chips, share row
  - "More in {category}" and "Latest news" at the bottom
- Planned database additions:
  - on `posts`: `summary` (bullet points), `featured_caption`, `featured_credit`, `credits`
  - a new table `post_gallery` (post, picture, position, caption) for extra photos
- Nothing committed. No site code changed, so no tests.

### 2026-11-03 — Hard rule 6: code changes shown in the diff panel
- **New hard rule 6:** every code change is shown in the Claude app's diff panel (right side) before it is committed (owner's request).
- The panel said "Changes need a Git repository" because the session ran in `global arm post new site/`, one level above the repo. The session now works in `git/`.
- Added `.gitignore` (`node_modules/`, uploads, storage data, `app/config.php`, test output) so the diff shows only real code.
- Files are marked with `git add -N` so new files show in the diff. Nothing committed.

### 2026-11-03 — White background; category block wireframes
- **White page background** on the whole site (owner's request). The Middle East and Africa block sits on white too, not on a gray band.
- Wireframes shown for 3 blocks, desktop and phone:
  - **Asia:** new layout `overlay_list`, a full-width photo with the title centred on it, then photo + title rows.
  - **Middle East and Africa:** the same layout. With "avoid duplicates" on, a post already shown higher on the page is skipped.
  - **Europe:** new layout `lead_grid`, a lead story on the left and a 2 × 2 grid on the right.
- Every block has the serif title, a divider and a **See more** button to its category page. Red back-to-top button added.
- Nothing committed. No code changed, so no tests.

### 2026-11-03 — Design stage; hard rule 5 added
- **New hard rule 5:** every time a task is updated or finished, all `.md` files are updated too (owner's request). Also written in `CLAUDE.md`.
- Homepage wireframe shown to the owner. Desktop, top to bottom: date bar, banner logo, red category menu (Home, Land, Air, Space, Naval, Global news, Regions ▾, Business, dark mode, search), trending tags, then the sections:
  1. Hero + grid (span 8)
  2. Top stories list with tabs (span 4)
  3. Asia grid, 4 columns
  4. Europe lead + list, skip 1
  5. Numbered headlines
  On phones every section is full width, in the same order.
- Core code shown for review: section query engine, section settings, scheduling and cache, editor paste handling, picture pipeline, adding categories on the spot.
- Already written in `git/` (not committed):
  - `public/app/lib/`: `db.php`, `schema.php`, `helpers.php`, `auth.php`, `sanitize.php`, `media.php` (removed later the same day at the owner's request; see the Phase 0 entry)
  - `package.json` (dev tools: Playwright, jSquash AVIF, esbuild, Inter + Newsreader fonts)
  - `node_modules/`
  - an empty git repo on `main`
- **Waiting on the owner:** approval of the wireframe and code, and whether to keep the files above as the start of the build.
- Nothing committed or pushed. No tests run yet (no runnable site).
