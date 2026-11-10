import { test, expect } from './fixtures';
import { users, sql } from './admin-helpers';

// Runs first, once per copy (projects setup-git / setup-live, desktop Chromium), on a fresh database with no users:
// the first admin account is made through the real setup step (never hard-coded), then the admin adds an editor.

test('setup step makes the first admin, then the admin adds an editor', async ({ page }, info) => {
  // The first test of the run: its time includes the cold start of the browser and of the servers' PHP
  // processes (18 s for Chromium alone was seen on a busy machine), so it gets 120 s. No check is changed.
  test.setTimeout(120_000);
  expect(sql(info, 'SELECT COUNT(*) AS n FROM users')[0].n).toBe(0);
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin\/setup$/);
  await expect(page.locator('h1')).toHaveText('Create the first admin');

  const a = users.admin;
  await page.fill('#name', a.name);
  await page.fill('#email', a.email);
  await page.fill('#password', 'short');
  await page.fill('#password2', 'short');
  await page.locator('#password').evaluate((el: HTMLInputElement) => el.removeAttribute('minlength')); // check the server's own rule
  await page.locator('#password2').evaluate((el: HTMLInputElement) => el.removeAttribute('minlength'));
  await page.click('button[type="submit"]');
  await expect(page.locator('.a-error')).toContainText('at least 10 characters');
  expect(sql(info, 'SELECT COUNT(*) AS n FROM users')[0].n).toBe(0);

  await page.fill('#password', a.password);
  await page.fill('#password2', a.password);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('.a-flash')).toContainText('Your admin account is ready');
  const row = sql(info, 'SELECT email, name, role, password_hash FROM users')[0];
  expect(row).toMatchObject({ email: a.email, name: a.name, role: 'admin' });
  expect(row.password_hash).toMatch(/^\$(2y|argon2id?)\$/);
  expect(row.password_hash).not.toContain(a.password);

  const e = users.editor;
  await page.goto('/admin/users');
  await page.fill('#u-name', e.name);
  await page.fill('#u-email', e.email);
  await page.selectOption('#u-role', 'editor');
  await page.fill('#u-pass', e.password);
  await page.click('.user-form button[type="submit"]');
  await expect(page.locator('.a-flash')).toContainText(`User ${e.email} added as editor`);
  expect(sql(info, 'SELECT role FROM users WHERE email = ?', e.email)[0].role).toBe('editor');

  // The setup step is gone once an admin exists.
  const again = await page.request.get('/admin/setup');
  expect(again.status()).toBe(404);
  expect(sql(info, "SELECT COUNT(*) AS n FROM audit_log WHERE action = 'setup.first_admin'")[0].n).toBe(1);
});
