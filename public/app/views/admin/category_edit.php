<?php
declare(strict_types=1);
/** @var array<string, mixed> $cat */
/** @var list<array<string, mixed>> $parents */
/** @var bool $in_menu */
/** @var int $stories */
/** @var string $error */
$id = (int) $cat['id'];
?>
<div class="a-head"><a class="a-back" href="/admin/categories">‹ Categories</a><h1>Edit category</h1></div>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="/admin/categories/<?= $id ?>" class="a-form a-card narrow cat-form">
  <?= csrf_field() ?>
  <label for="cat-name">Name</label><input id="cat-name" name="name" maxlength="120" required value="<?= e((string) $cat['name']) ?>">
  <label for="cat-slug">Address (letters, numbers and -)</label><input id="cat-slug" name="slug" maxlength="120" value="<?= e((string) $cat['slug']) ?>" aria-describedby="cat-slug-help">
  <span class="a-small" id="cat-slug-help">The page is at /<?= e((string) $cat['slug']) ?>. A new address also updates the menu and homepage sections. Names the site uses itself (admin, search, tag, api …) are kept free.</span>
  <label for="cat-parent">Inside</label>
  <select id="cat-parent" name="parent_id"><option value="0">— Top level —</option>
    <?php foreach ($parents as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($cat['parent_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <?php if (($user['role'] ?? '') === 'admin'): ?>
  <label class="a-check a-mt"><input type="checkbox" name="in_menu" value="1"<?= $in_menu ? ' checked' : '' ?>> Show in the header menu</label>
  <?php else: ?>
  <p class="a-small a-mt cat-menu-note"><?= $in_menu ? 'Shown in the header menu.' : 'Not in the header menu.' ?> Only admins change the header menu.</p>
  <?php endif; ?>
  <p class="a-small"><?= $stories ?> <?= $stories === 1 ? 'story' : 'stories' ?> in this category. The menu builder has more options (submenus, labels).</p>
  <button class="a-btn" type="submit">Save category</button>
</form>
