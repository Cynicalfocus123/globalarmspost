<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
/** @var array<int, int> $counts */
/** @var array<string, bool> $menu */
/** @var string $error */
// Categories (task F17): drag (or ↑ ↓) into order and save; Edit opens name, address, parent, header menu.
?>
<div class="a-head"><h1>Categories</h1><button type="button" class="a-btn cat-save" disabled>Save order</button></div>
<p class="a-small cat-status" role="status" aria-live="polite">Drag ⋮⋮ (or use ↑ ↓) to change the order of the menu, footer and lists. Sub-categories are indented.</p>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<ol class="a-sort cat-list" aria-label="Categories in order">
  <?php foreach ($rows as $i => $c): $inMenu = $menu[$c['slug']] ?? false; ?>
  <li class="a-sec depth-<?= min(3, (int) $c['depth']) ?>" data-id="<?= (int) $c['id'] ?>">
    <button type="button" class="a-grip" aria-label="Drag to move <?= e($c['name']) ?>">⋮⋮</button>
    <span class="grow"><a class="t" href="/admin/categories/<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a>
      <span class="a-small">/<?= e($c['slug']) ?> · <?= $counts[$c['id']] ?? 0 ?> <?= ($counts[$c['id']] ?? 0) === 1 ? 'story' : 'stories' ?><?= $inMenu ? ' · <span class="a-pill pub">In menu</span>' : '' ?></span></span>
    <span class="hb-move"><button type="button" class="a-btn2 sm" data-move="-1" aria-label="Move <?= e($c['name']) ?> up">↑</button><button type="button" class="a-btn2 sm" data-move="1" aria-label="Move <?= e($c['name']) ?> down">↓</button></span>
    <a class="a-btn2 sm" href="/admin/categories/<?= (int) $c['id'] ?>">Edit</a>
  </li>
  <?php endforeach; ?>
</ol>

<form method="post" action="/admin/categories" class="a-form a-card narrow a-mt cat-add">
  <?= csrf_field() ?>
  <h2 class="a-h">Add a category</h2>
  <label for="cat-new-name">Name</label><input id="cat-new-name" name="name" maxlength="120" required>
  <label for="cat-new-parent">Inside</label>
  <select id="cat-new-parent" name="parent_id"><option value="0">— Top level —</option>
    <?php foreach ($rows as $c): ?><option value="<?= (int) $c['id'] ?>"><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <button class="a-btn" type="submit">Add category</button>
</form>
