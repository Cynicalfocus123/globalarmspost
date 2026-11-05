<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
?>
<div class="a-head"><h1>Tags</h1></div>
<p class="a-small">New tags are added on the spot in the story editor. Renaming and merging come with task B8.</p>
<ul class="a-rows tags-list">
  <?php foreach ($rows as $t): ?>
  <li><span class="grow"><b><?= e((string) $t['name']) ?></b> <span class="a-small">/tag/<?= e((string) $t['slug']) ?></span></span><span class="a-small"><?= (int) $t['stories'] ?> stories</span></li>
  <?php endforeach; ?>
</ul>
<?php if ($rows === []): ?><p class="a-mute">No tags yet.</p><?php endif; ?>
