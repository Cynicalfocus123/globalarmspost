<?php
declare(strict_types=1);
/** @var list<array{name: string, bytes: int, at: int}> $rows */
/** @var bool $zip */
// Backups (task B17): a ZIP with every table as JSON and the uploads folder, kept in storage/backups (never
// served to visitors) and downloaded only through the admin. Restoring comes later.
$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
?>
<div class="a-head"><h1>Backup</h1></div>
<section class="a-card narrow">
  <h2 class="a-h">Make a backup</h2>
  <p class="a-small">One ZIP file: every database table as JSON (<?= e(db_driver() === 'mysql' ? 'MySQL' : 'SQLite') ?>) and every picture in uploads/. It is kept on the server in storage/backups, which visitors can never open. Download it to keep a copy somewhere else. Restoring from a backup comes in a later step.</p>
  <p class="a-small">It holds the user accounts (password hashes and two-step login secrets), so keep downloaded backups somewhere private. Sign-in sessions and the sign-in attempt log are left out.</p>
  <?php if (!$zip): ?>
  <p class="a-error">This server's PHP has no ZIP support (the zip extension), so backups cannot be made here.</p>
  <?php else: ?>
  <form method="post" action="/admin/backup" class="backup-make"><?= csrf_field() ?><button class="a-btn" type="submit">Make a backup now</button></form>
  <?php endif; ?>
</section>
<section class="a-card">
  <h2 class="a-h">Earlier backups</h2>
  <?php if ($rows === []): ?><p class="a-mute">No backups yet.</p><?php endif; ?>
  <ul class="a-list backup-list">
    <?php foreach ($rows as $b): ?>
    <li class="backup-row"><span class="grow"><b><?= e($b['name']) ?></b><span class="a-small"><?= e(local_dt(gmdate('Y-m-d H:i:s', $b['at']))->format('j M Y, g:i A')) ?> · <?= e($size($b['bytes'])) ?></span></span>
      <a class="a-btn2 sm" href="/admin/backup/download/<?= e(rawurlencode($b['name'])) ?>" download>Download</a></li>
    <?php endforeach; ?>
  </ul>
</section>
