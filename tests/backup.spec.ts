import { test, expect } from './fixtures';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { users, sql, login, logout, serverRoot, fileThere, noSidewaysScroll, inLine } from './admin-helpers';

// Step 5, task B17: backups. An admin makes a ZIP (every table as JSON, MySQL or SQLite, plus uploads/) in
// storage/backups, which is never served; it is downloaded only through the admin. No restore yet.

test('an admin makes a backup: every table and the pictures in one ZIP, listed and downloaded', async ({ page }, info) => {
  test.setTimeout(360_000); // own work about 30 s, plus waiting for the other browsers' turns
  // The 9 browsers take turns (inLine): the audit-log counts below must not see another browser's backup.
  await inLine(info, 'backup', async () => {
    page.on('dialog', (d) => d.accept());
    await login(page, users.admin);
    await page.goto('/admin/backup');
    await expect(page.locator('h1')).toHaveText('Backup');
    await noSidewaysScroll(page, 'backup page');
    const before = new Set(await page.locator('.backup-row b').allTextContents());
    await page.locator('.backup-make button').click();
    await expect(page.locator('.a-flash')).toContainText('Backup made: gap-backup-', { timeout: 60_000 });
    const name = /gap-backup-\d{8}-\d{6}-[a-f0-9]{6}\.zip/.exec((await page.locator('.a-flash').textContent())!)![0];
    expect(before.has(name)).toBe(false);
    // Listed (newest first, among the backups other browsers make at the same time), with its size.
    await expect(page.locator('.backup-row', { hasText: name })).toHaveCount(1);
    await expect(page.locator('.backup-row', { hasText: name })).toContainText(/\d+(\.\d)? (KB|MB)/);
    // The file is really in storage/backups, and the audit log has it.
    expect(fileThere(info, `storage/backups/${name}`)).toBe(true);
    expect(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'backup.create' AND detail LIKE ?", `${name}%`)[0].n).toBe(1);

    // The ZIP holds backup.json, one JSON file per table and the pictures.
    const zip = readFileSync(join(serverRoot(info, 'storage/'), 'storage', 'backups', name));
    expect(zip.subarray(0, 2).toString()).toBe('PK');
    const names = zip.toString('latin1');
    // Every table the migrations make (the same list on MySQL and SQLite).
    const tables = ['audit_log', 'authors', 'categories', 'home_sections', 'media', 'menu_items', 'menus', 'migrations', 'post_gallery',
      'post_tags', 'posts', 'search_docs', 'search_post_terms', 'search_terms', 'settings', 'tags', 'users', 'views_daily'];
    for (const t of tables) expect(names, `db/${t}.json in the ZIP`).toContain(`db/${t}.json`);
    // Sign-in session data is never in a backup ("keep me signed in" token hashes, the sign-in attempt log).
    for (const t of ['remember_tokens', 'login_attempts']) expect(names, `db/${t}.json must not be in the ZIP`).not.toContain(`db/${t}.json`);
    expect(names).toContain('backup.json');
    const demo = sql(info, "SELECT path, widths FROM media WHERE path LIKE 'uploads/demo/%' ORDER BY id LIMIT 1")[0];
    expect(names).toContain(`${demo.path}-${String(demo.widths).split(',')[0]}.webp`);

    // Downloaded through the admin: the same file.
    const res = await page.request.get(`/admin/backup/download/${name}`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toBe('application/zip');
    expect(res.headers()['content-disposition']).toBe(`attachment; filename="${name}"`);
    expect(Buffer.compare(await res.body(), zip)).toBe(0);
    expect(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'backup.download' AND detail = ?", name)[0].n).toBeGreaterThanOrEqual(1);
  });
});

test('backups are never served directly and only admins can open or download them', async ({ page }, info) => {
  test.setTimeout(360_000); // own work about 30 s, plus waiting for the other browsers' turns
  // The 9 browsers take turns (inLine): the audit-log counts below must not see another browser's backup.
  await inLine(info, 'backup', async () => {
    await login(page, users.admin);
    const token = /name="_csrf" value="([^"]+)"/.exec(await (await page.request.get('/admin/backup')).text())![1];
    const made = await page.request.post('/admin/backup', { form: { _csrf: token } });
    expect(made.status()).toBe(200);
    const name = /gap-backup-\d{8}-\d{6}-[a-f0-9]{6}\.zip/.exec(await made.text())![0];
    expect(fileThere(info, `storage/backups/${name}`)).toBe(true);

    // Straight from the folder: refused, signed in or not.
    expect((await page.request.get(`/storage/backups/${name}`)).status()).toBe(403);
    // Made-up names are not found (only names the site makes are accepted). A path with '..' is refused even
    // earlier: the server (tests/php-router.php here, .htaccess on Hostinger) answers 403 for any '/.' in an address.
    for (const bad of ['gap-backup-x.zip', `${name}.part`]) {
      expect((await page.request.get(`/admin/backup/download/${encodeURIComponent(bad)}`)).status(), bad).toBe(404);
    }
    const climb = await page.request.get(`/admin/backup/download/${encodeURIComponent('../../app/config.php')}`);
    expect(climb.status(), 'a path out of storage/backups is refused').toBe(403);
    expect(await climb.text()).not.toMatch(/<\?php|'db'|driver/);
    // A POST without the form's token is refused (CSRF), and makes nothing.
    const count = () => Number(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'backup.create'")[0].n);
    const n = count();
    expect((await page.request.post('/admin/backup', { form: {} })).status()).toBe(403);
    expect(count()).toBe(n);

    // An editor gets "not allowed" for the page and the download.
    await logout(page);
    await login(page, users.editor);
    expect((await page.request.get('/admin/backup')).status()).toBe(403);
    expect((await page.request.get(`/admin/backup/download/${name}`)).status()).toBe(403);
    await page.goto('/admin');
    await expect(page.locator('.a-nav[href="/admin/backup"]')).toHaveCount(0);
    // Signed out: the sign-in page.
    await logout(page);
    const out = await page.request.get(`/admin/backup/download/${name}`, { maxRedirects: 0 });
    expect(out.status()).toBe(303);
    expect(out.headers()['location']).toContain('/admin/login');
  });
});
