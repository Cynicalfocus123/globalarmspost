<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
/** @var array<int, int> $counts */
?>
<div class="a-head"><h1>Categories</h1></div>
<p class="a-small">New categories can be added on the spot in the story editor. Ordering, renaming and menu settings come with task F17.</p>
<ul class="a-rows">
  <?php foreach ($rows as $c): ?>
  <li class="depth-<?= min(3, (int) $c['depth']) ?>"><span class="grow"><b><?= e($c['name']) ?></b> <span class="a-small">/<?= e($c['slug']) ?></span></span><span class="a-small"><?= $counts[$c['id']] ?? 0 ?> stories</span></li>
  <?php endforeach; ?>
</ul>
