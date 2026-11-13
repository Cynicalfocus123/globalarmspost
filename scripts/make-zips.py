"""Makes the two deploy ZIPs from live/ (hard rule, owner 2026-11-14: the ZIPs always carry the latest stories).

Usage: python scripts/make-zips.py        (npm run zips)

  globalarmspost-frontend-<commit>.zip  -> unzip INTO public_html: index.php, .htaccess, assets/, icons and
                                           uploads/ with every picture of the stories.
  globalarmspost-backend-<commit>.zip   -> unzip NEXT TO public_html (it holds the folder backend/): app/ (code,
                                           no config.php) and storage/site.sqlite, the database with every story,
                                           category, tag, author and admin account of the local site.

The database is copied with SQLite's own backup, so the copy is whole even while the local site runs.
Never in a ZIP: config.php, sign-in sessions, the page cache, backups, rate-limit files.
Written to ../Claude outputs/ (an older ZIP of the same commit is replaced; nothing else is touched).
"""
import os
import re
import sqlite3
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# The main checkout, also when this runs from a Claude worktree (git/.claude/worktrees/...).
MAIN = ROOT.split(os.sep + '.claude' + os.sep + 'worktrees' + os.sep)[0]
LIVE = os.path.join(os.path.dirname(MAIN), 'live')
OUT = os.path.join(os.path.dirname(MAIN), 'Claude outputs')
TMP = 'D:/dev/tmp/gap-zips'

build = open(os.path.join(LIVE, 'backend', 'app', 'build.php'), encoding='utf-8').read()
m = re.search(r"'commit' => '([0-9a-f]{7,40})'", build)
if not m:
    sys.exit('live/backend/app/build.php has no commit: run npm run build:live first.')
sha = m.group(1)[:7]


def walk(base):
    for root, _dirs, files in os.walk(base):
        for name in files:
            full = os.path.join(root, name)
            yield full, os.path.relpath(full, base).replace(os.sep, '/')


def check(path, count):
    with zipfile.ZipFile(path) as z:
        if z.testzip() is not None:
            sys.exit(f'{path} is damaged')
    print(f'{os.path.basename(path)}: {count} files, {os.path.getsize(path) // 1024} KB')


os.makedirs(OUT, exist_ok=True)
os.makedirs(TMP, exist_ok=True)

# Frontend: everything in live/frontend (the code files and the pictures in uploads/).
front = os.path.join(OUT, f'globalarmspost-frontend-{sha}.zip')
n = 0
with zipfile.ZipFile(front, 'w', zipfile.ZIP_DEFLATED) as z:
    for full, rel in walk(os.path.join(LIVE, 'frontend')):
        if rel.endswith('config.php'):
            continue
        z.write(full, rel)
        n += 1
pictures = sum(1 for _f, rel in walk(os.path.join(LIVE, 'frontend', 'uploads')) if not rel.endswith('.htaccess'))
check(front, n)

# Backend: app/ without config.php, storage/.htaccess and a copy of the database.
back = os.path.join(OUT, f'globalarmspost-backend-{sha}.zip')
db = os.path.join(LIVE, 'backend', 'storage', 'site.sqlite')
n = 0
stories = None
with zipfile.ZipFile(back, 'w', zipfile.ZIP_DEFLATED) as z:
    for full, rel in walk(os.path.join(LIVE, 'backend', 'app')):
        if rel == 'config.php':
            continue
        z.write(full, 'backend/app/' + rel)
        n += 1
    ht = os.path.join(LIVE, 'backend', 'storage', '.htaccess')
    if os.path.exists(ht):
        z.write(ht, 'backend/storage/.htaccess')
        n += 1
    if os.path.exists(db):
        copy = os.path.join(TMP, f'site-{sha}.sqlite')
        src = sqlite3.connect(db)
        dst = sqlite3.connect(copy)
        src.backup(dst)
        stories = dst.execute("SELECT COUNT(*) FROM posts WHERE status = 'published'").fetchone()[0]
        dst.execute('PRAGMA journal_mode = DELETE')
        dst.close()
        src.close()
        z.write(copy, 'backend/storage/site.sqlite')
        n += 1
check(back, n)
print(f'Stories in the database: {stories if stories is not None else "none yet (no live/backend/storage/site.sqlite)"}; pictures in uploads/: {pictures}')
