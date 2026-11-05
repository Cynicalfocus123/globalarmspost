<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
?>
<div class="a-head"><h1>Pictures</h1></div>
<p class="a-small">Pictures are added in the story editor (body, main photo, gallery) and for authors. Each one is stored as AVIF + WebP in several widths.</p>
<ul class="a-pics">
  <?php foreach ($rows as $m): ?>
  <li><img src="<?= e(media_thumb($m['path'], $m['widths'])) ?>" alt="<?= e((string) $m['alt']) ?>" width="160" height="107" loading="lazy">
    <span class="a-small"><?= e((string) $m['widths']) ?> px · <?= (int) $m['has_avif'] === 1 ? 'AVIF + WebP' : 'WebP' ?></span></li>
  <?php endforeach; ?>
</ul>
