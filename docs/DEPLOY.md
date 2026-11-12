# Deploy to Hostinger

`live/` (built by `npm run build:live`, checked by `npm run verify:live`) has two folders. Nothing is shared between them: every picture, stylesheet, script and font is in `frontend/`; all code and the server's own data are in `backend/`.

```
live/
├─ frontend/   → upload INTO public_html
│   ├─ index.php, .htaccess
│   ├─ assets/     CSS, JS, fonts, logo, the admin's CSS + JS
│   ├─ uploads/    pictures (the admin adds them here)
│   └─ favicon.ico, apple-touch-icon.png, site.webmanifest
└─ backend/    → upload NEXT TO public_html, as a folder called "backend"
    ├─ app/        all PHP code, views, migrations; config.php (you make it, step 3)
    └─ storage/    page cache, sign-in sessions, backups (the site writes here)
```

On Hostinger the two sit side by side, for example:

```
/home/u123456789/domains/globalarmspost.com/
├─ public_html/   ← the contents of live/frontend/
└─ backend/       ← the contents of live/backend/
```

`public_html/index.php` finds the code in `../backend/`, so the folder must be called `backend` and sit next to `public_html`. Visitors can only reach `public_html`: the code, `config.php` (database password), backups and sessions can never be downloaded.

## Steps

1. **PHP**: hPanel → Advanced → PHP Configuration: PHP 8.1 or newer, with the extensions `pdo_mysql`, `gd`, `zip`, `mbstring`, `dom`, `fileinfo` (Hostinger has them on by default).
2. **Database**: hPanel → Databases → MySQL Databases: make a database and a user (write down the database name, user and password).
3. **Upload** (File Manager or FTP):
   - everything inside `live/frontend/` into `public_html/` (the hidden `.htaccess` too);
   - make a folder `backend` next to `public_html`, and upload everything inside `live/backend/` into it.
4. **Config**: in `backend/app/`, copy `config.sample.php` to `config.php` and fill in the MySQL part (`'driver' => 'mysql'`, `host` = `localhost`, `name`, `user`, `pass`). Keep `'debug' => false`.
5. **Tables**: run the migrations once:
   - with SSH: `php ~/domains/globalarmspost.com/backend/app/cli/migrate.php` (it says which migrations ran);
   - without SSH: hPanel → Advanced → Cron Jobs → a "PHP" job with the same path, set to run once in the next minute; remove the cron job after it ran.
6. **HTTPS**: hPanel → Security → SSL: turn on the free certificate. `.htaccess` already sends every visitor to https.
7. **First admin**: open `https://globalarmspost.com/admin`. With no account yet it shows the setup step: the first account you make is the admin.
8. **Settings** (`/admin/settings`): set the **Site address** to exactly the form you want people and Google to see (`https://www.globalarmspost.com` or `https://globalarmspost.com`; point the other one at it in hPanel → Domains → Redirects), the site name, tagline and time zone.
9. **Check**: the admin dashboard's server check must show every line green (PHP, Database (MySQL), Uploads folder, Storage folder, GD, HTTPS).
10. **Backup**: after the stories are in, `/admin/backup` → "Make a backup now" → Download, and keep the file somewhere private (it holds the user accounts).

## Updating later

Build and check `live/` from the new commit, then upload `live/frontend/` over `public_html/` and `live/backend/` over `backend/`. Never overwrite the server's own data: `backend/app/config.php`, `backend/storage/` and `public_html/uploads/`. Run step 5 (migrations) again when a commit adds a migration; the PROJECT_LOG entry says so.
