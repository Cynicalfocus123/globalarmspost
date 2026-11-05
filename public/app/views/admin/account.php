<?php
declare(strict_types=1);
/** @var array<string, mixed> $user */
/** @var string $error */
/** @var string $secret */
/** @var string $uri */
?>
<div class="a-head"><h1>Account</h1></div>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="a-card narrow">
  <h2 class="a-h">Two-step login</h2>
  <?php if ((int) $user['totp_enabled'] === 1): ?>
  <p class="two-step-state">Two-step login is <b>on</b>. Signing in needs your password and a code from your authenticator app.</p>
  <form method="post" action="/admin/account/two-step-off" class="a-form">
    <?= csrf_field() ?>
    <label for="off-pass">Your password, to turn it off</label><input id="off-pass" name="password" type="password" required autocomplete="current-password">
    <button class="a-btn2 danger" type="submit">Turn two-step login off</button>
  </form>
  <?php elseif ($secret === ''): ?>
  <p class="two-step-state">Two-step login is <b>off</b>. Turn it on to also ask for a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password …).</p>
  <form method="post" action="/admin/account/two-step-start"><?= csrf_field() ?><button class="a-btn" type="submit">Turn on two-step login</button></form>
  <?php else: ?>
  <p>1. In your authenticator app, add an account and type this key (or open the link on your phone):</p>
  <p class="a-key"><code class="totp-secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></p>
  <p><a class="a-small" href="<?= e($uri) ?>">Open in an authenticator app</a></p>
  <form method="post" action="/admin/account/two-step-confirm" class="a-form">
    <?= csrf_field() ?>
    <label for="totp-code">2. Type the 6-digit code the app shows</label>
    <input id="totp-code" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code">
    <button class="a-btn" type="submit">Confirm and turn on</button>
  </form>
  <?php endif; ?>
</section>
<section class="a-card narrow">
  <h2 class="a-h">Change password</h2>
  <form method="post" action="/admin/account/password" class="a-form">
    <?= csrf_field() ?>
    <label for="cur-pass">Current password</label><input id="cur-pass" name="password" type="password" required autocomplete="current-password">
    <label for="new-pass">New password (10 characters or more)</label><input id="new-pass" name="new_password" type="password" required minlength="10" autocomplete="new-password">
    <button class="a-btn2" type="submit">Change password</button>
  </form>
</section>
