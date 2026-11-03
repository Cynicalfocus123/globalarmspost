<?php
declare(strict_types=1);
/** @var array<string, mixed> $category */
// Step 1: title and breadcrumb only. The day-grouped story list (task F9) comes in a later step.
?>
<nav class="crumbs" aria-label="Breadcrumb">
  <a href="<?= e(site_link('home')) ?>">Home</a>
  <?php if (!empty($category['parent_slug'])): ?>
  <span aria-hidden="true">›</span> <a href="<?= e(site_link('category', ['slug' => (string) $category['parent_slug']])) ?>"><?= e((string) $category['parent_name']) ?></a>
  <?php endif; ?>
  <span aria-hidden="true">›</span> <span aria-current="page"><?= e((string) $category['name']) ?></span>
</nav>
<div class="sec-head"><h1><?= e((string) $category['name']) ?></h1></div>
<p class="empty">No stories in <?= e((string) $category['name']) ?> yet.</p>
