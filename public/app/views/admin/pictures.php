<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
/** @var array<int, int> $used */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
// Pictures (task F17): every stored picture; open one to edit its texts, see where it is used or delete it.
?>
<div class="a-head"><h1>Pictures</h1></div>
<p class="a-small">Pictures are added in the story editor (body, main photo, gallery) and for authors. Each one is stored as AVIF + WebP in several widths. <?= $total ?> pictures.</p>
<ul class="a-pics">
  <?php foreach ($rows as $m): $n = $used[(int) $m['id']] ?? 0; ?>
  <li class="pic" data-id="<?= (int) $m['id'] ?>"><a href="/admin/pictures/<?= (int) $m['id'] ?>" class="pic-link">
    <img src="<?= e(media_thumb($m['path'], $m['widths'])) ?>" alt="<?= e((string) $m['alt']) ?>" width="160" height="107" loading="lazy">
    <span class="a-small"><?= e((string) $m['alt'] !== '' ? mb_strimwidth((string) $m['alt'], 0, 40, '…') : 'No alt text') ?></span>
    <span class="a-small pic-used"><?= $n === 0 ? 'Not used' : 'Used ' . $n . ($n === 1 ? ' time' : ' times') ?> · <?= (int) $m['has_avif'] === 1 ? 'AVIF + WebP' : 'WebP' ?></span>
  </a></li>
  <?php endforeach; ?>
</ul>
<?php if ($pages > 1): ?>
<nav class="a-pager" aria-label="Pages">
  <?php if ($page > 1): ?><a class="a-btn2" href="/admin/pictures?page=<?= $page - 1 ?>">‹ Newer</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="a-btn2" href="/admin/pictures?page=<?= $page + 1 ?>">Older ›</a><?php endif; ?>
</nav>
<?php endif; ?>
