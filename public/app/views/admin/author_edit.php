<?php
declare(strict_types=1);
/** @var array<string, mixed>|null $author */
/** @var string $error */
$a = $author ?? ['id' => 0, 'name' => '', 'email' => '', 'bio' => '', 'media_id' => 0, 'avatar' => ''];
$id = (int) ($a['id'] ?? 0);
?>
<div class="a-head"><a class="a-back" href="/admin/authors">‹ Authors</a><h1><?= $id > 0 ? 'Edit author' : 'New author' ?></h1></div>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="/admin/authors/<?= $id > 0 ? $id : 'new' ?>" class="a-form a-card narrow author-form">
  <?= csrf_field() ?>
  <input type="hidden" name="media_id" value="<?= (int) ($a['media_id'] ?? 0) ?>" class="author-media">
  <div class="a-row">
    <?php if ((string) ($a['avatar'] ?? '') !== ''): ?><img class="a-av big author-pic-preview" src="<?= e((string) $a['avatar']) ?>" alt="" width="64" height="64"><?php else: ?><span class="a-av big author-pic-preview" aria-hidden="true">＋</span><?php endif; ?>
    <button type="button" class="a-btn2 sm author-pic-pick">Choose profile picture</button>
    <input type="file" class="author-pic-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
  </div>
  <p class="a-small author-pic-status" role="status" aria-live="polite"></p>
  <label for="a-name">Name</label><input id="a-name" name="name" required maxlength="120" value="<?= e((string) $a['name']) ?>">
  <label for="a-email">Email</label><input id="a-email" name="email" type="email" maxlength="190" value="<?= e((string) ($a['email'] ?? '')) ?>">
  <label for="a-bio">Short bio</label><textarea id="a-bio" name="bio" rows="3" maxlength="2000"><?= e((string) ($a['bio'] ?? '')) ?></textarea>
  <button class="a-btn" type="submit">Save author</button>
</form>
