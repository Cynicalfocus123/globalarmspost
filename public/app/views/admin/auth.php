<?php
declare(strict_types=1);
/** @var string $mode login | setup | two_step | message */
/** @var string $title */
$error ??= '';
$site = setting('site_name', 'Global Arms Post');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
</head>
<body class="admin a-auth-page">
<main class="a-auth">
  <img class="a-auth-logo" src="/assets/img/logo/logo-1x.webp" srcset="/assets/img/logo/logo-2x.webp 2x" width="180" height="58" alt="<?= e($site) ?>">
  <h1><?= e($title) ?></h1>
  <?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if ($mode === 'login'): ?>
  <form method="post" action="/admin/login" class="a-form">
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <label class="a-check"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
    <div class="a-hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
    <button class="a-btn wide" type="submit">Sign in</button>
  </form>
  <p class="a-small">After 5 wrong tries, wait 15 minutes.</p>
  <?php elseif ($mode === 'two_step'): ?>
  <form method="post" action="/admin/two-step" class="a-form">
    <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <label for="code">6-digit code from your authenticator app</label>
    <input id="code" name="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required>
    <button class="a-btn wide" type="submit">Continue</button>
  </form>
  <?php elseif ($mode === 'setup'): ?>
  <p class="a-small">No account exists yet. This first account is an admin. Choose a strong password (10 characters or more).</p>
  <form method="post" action="/admin/setup" class="a-form">
    <?= csrf_field() ?>
    <label for="name">Your name</label><input id="name" name="name" required maxlength="120" value="<?= e($old['name']) ?>" autocomplete="name">
    <label for="email">Email</label><input id="email" name="email" type="email" required value="<?= e($old['email']) ?>" autocomplete="username">
    <label for="password">Password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
    <label for="password2">Password again</label><input id="password2" name="password2" type="password" required minlength="10" autocomplete="new-password">
    <button class="a-btn wide" type="submit">Create admin account</button>
  </form>
  <?php else: ?>
  <p><?= e($message) ?></p>
  <p><a class="a-btn2" href="/admin">Go to the admin</a></p>
  <?php endif; ?>
</main>
</body>
</html>
