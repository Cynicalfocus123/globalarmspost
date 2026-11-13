<?php
declare(strict_types=1);
/** @var string $site */
/** @var array{w1: int, h1: int, w2: int, h2: int} $logo */
// Footer (task F24, owner 2026-11-14): dark band with the footer menu's columns, built in Admin → Menu → Footer menu.
// Each top-level item is a column title; the items under it are the column's links. The social icons (Settings →
// Social links) sit under the first column. Phones: one column under the other; tablets and desktops: side by side.
$columns = menu_tree('footer');
$social = social_links();
// The copyright bar under the footer (owner, 2026-11-14): "GlobalArmsPost @2024-2026. All Rights Reserved."
// Name and first year: Settings → Site.
$brand = setting('copyright_name', 'GlobalArmsPost');
$now = local_dt(now_utc())->format('Y');
$from = setting('copyright_from', '2024');
$years = $from !== '' && $from < $now ? $from . '-' . $now : $now;
$link = static fn (array $item): string => '<a href="' . e($item['href']) . '"' . ($item['new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($item['label']) . '</a>';
?>
<footer class="site-footer">
  <div class="wrap footer-cols">
    <?php foreach ($columns as $n => $col): ?>
    <section class="footer-col" aria-labelledby="footer-h-<?= (int) $n ?>">
      <h2 id="footer-h-<?= (int) $n ?>"><?= $col['href'] === '' ? e($col['label']) : $link($col) ?></h2>
      <?php if ($col['children'] !== []): ?>
      <ul>
        <?php foreach ($col['children'] as $item): ?>
        <li><?= $item['href'] === '' ? '<span>' . e($item['label']) . '</span>' : $link($item) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($n === 0 && $social !== []): ?>
      <ul class="footer-social" aria-label="Follow us">
        <?php foreach ($social as $s): ?>
        <li><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener me" aria-label="<?= e($site . ' on ' . $s['label']) ?>"><?= social_icon($s['key']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>
  <div class="footer-bottom">
    <p class="wrap"><span class="footer-brand"><?= e($brand) ?></span> @<?= e($years) ?>. All Rights Reserved.</p>
  </div>
</footer>
