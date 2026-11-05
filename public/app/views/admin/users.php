<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
/** @var string $error */
/** @var array{name: string, email: string, role: string} $old */
?>
<div class="a-head"><h1>Users</h1></div>
<ul class="a-rows users-list">
  <?php foreach ($rows as $u): ?>
  <li><span class="grow"><b><?= e((string) $u['name']) ?></b> <span class="a-small"><?= e((string) $u['email']) ?></span></span>
    <span class="a-pill <?= $u['role'] === 'admin' ? 'pub' : 'dr' ?>"><?= e((string) $u['role']) ?></span>
    <span class="a-small"><?= (int) $u['totp_enabled'] === 1 ? 'Two-step on' : '' ?></span></li>
  <?php endforeach; ?>
</ul>
<h2 class="a-h a-mt">Add a user</h2>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="/admin/users" class="a-form a-card narrow user-form">
  <?= csrf_field() ?>
  <label for="u-name">Name</label><input id="u-name" name="name" required maxlength="120" value="<?= e($old['name']) ?>">
  <label for="u-email">Email</label><input id="u-email" name="email" type="email" required value="<?= e($old['email']) ?>" autocomplete="off">
  <label for="u-role">Role</label>
  <select id="u-role" name="role"><option value="editor"<?= $old['role'] === 'editor' ? ' selected' : '' ?>>Editor: writes and publishes stories</option><option value="admin"<?= $old['role'] === 'admin' ? ' selected' : '' ?>>Admin: also homepage, users, settings</option></select>
  <label for="u-pass">Password (10 characters or more)</label><input id="u-pass" name="password" type="password" required minlength="10" autocomplete="new-password">
  <button class="a-btn" type="submit">Add user</button>
</form>
