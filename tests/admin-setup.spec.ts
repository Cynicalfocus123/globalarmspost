import { test, expect } from './fixtures';
import { users, sql, login, createAdminCli } from './admin-helpers';

// Runs first, once per copy (projects setup-git / setup-live, desktop Chromium), on a fresh database with no users.
// There is no setup page on the web (owner, 2026-11-14, task B22): the first admin is made with the server command
// app/cli/create-admin.php (never hard-coded), signs in through the real form, then adds an editor.

test('no setup page; the server command makes the first admin, who signs in and adds an editor', async ({ page }, info) => {
  // The first test of the run: its time includes the cold start of the browser and of the servers' PHP
  // processes (18 s for Chromium alone was seen on a busy machine), so it gets 120 s. No check is changed.
  test.setTimeout(120_000);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM users')[0].n).toBe(0);
  // With no account the admin shows only the sign-in page; the old setup address does not exist.
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin\/login$/);
  await expect(page.locator('body')).not.toContainText(/first admin|No account exists/i);
  await page.goto('/admin/setup');
  await expect(page).toHaveURL(/\/admin\/login(\?|$)/); // the sign-in page (it may remember the address asked for)
  await expect(page.locator('h1')).toHaveText('Sign in to the newsroom');
  const post = await page.request.post('/admin/setup', { form: { name: 'X', email: 'x@example.test', password: 'abcdefghijkl', password2: 'abcdefghijkl' }, maxRedirects: 0 });
  expect(post.status()).not.toBe(200);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM users')[0].n).toBe(0);
  // The command answers 404 on the web (it lives in the backend folder, never under public_html).
  expect((await page.request.get('/app/cli/create-admin.php')).status()).not.toBe(200);

  const a = users.admin;
  // A short password is refused.
  const short = createAdminCli(info, [a.email, a.name], 'short');
  expect(short.code).not.toBe(0);
  expect(short.out).toContain('at least 10 characters');
  expect(sql(info, 'SELECT COUNT(*) AS n FROM users')[0].n).toBe(0);
  // The real one.
  const made = createAdminCli(info, [a.email, a.name], a.password);
  expect(made.code, made.out).toBe(0);
  expect(made.out).toContain(`Account made: ${a.email} (admin)`);
  const row = sql(info, 'SELECT email, name, role, password_hash FROM users')[0];
  expect(row).toMatchObject({ email: a.email, name: a.name, role: 'admin' });
  expect(row.password_hash).toMatch(/^\$(2y|argon2id?)\$/);
  expect(row.password_hash).not.toContain(a.password);
  expect(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'cli.create_user'")[0].n).toBe(1);
  // The same email again is refused without --reset.
  expect(createAdminCli(info, [a.email, a.name], a.password).code).not.toBe(0);

  await login(page, a);
  const e = users.editor;
  await page.goto('/admin/users');
  await page.fill('#u-name', e.name);
  await page.fill('#u-email', e.email);
  await page.selectOption('#u-role', 'editor');
  await page.fill('#u-pass', e.password);
  await page.click('.user-form button[type="submit"]');
  await expect(page.locator('.a-flash')).toContainText(`User ${e.email} added as editor`);
  expect(sql(info, 'SELECT role FROM users WHERE email = ?', e.email)[0].role).toBe('editor');
});
