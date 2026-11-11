<?php
declare(strict_types=1);
/** @var array<string, mixed> $category */
/** @var int $page */
// Category page (approved wireframe 4): newest first, grouped by day under a plain text date heading.
$slug = (string) $category['slug'];
$perPage = max(5, min(50, (int) setting('category_per_page', '20'))); // set in the admin (Settings)
$opts = ['categories' => [$slug], 'count' => $perPage, 'page' => $page];
$posts = posts_query($opts);
$total = posts_count($opts);
$hasOlder = $page * $perPage < $total;
$mostRead = posts_query(['categories' => [$slug], 'count' => 5, 'order_by' => 'views']);
$days = [];
foreach ($posts as $p) {
    $days[day_key((string) $p['published_at'])][] = $p;
}
$parent = (string) ($category['parent_slug'] ?? '');
?>
<nav class="crumbs" aria-label="Breadcrumb">
  <a href="<?= e(site_link('home')) ?>">Home</a>
  <?php if ($parent !== ''): ?>
  <span aria-hidden="true">›</span> <a href="<?= e(site_link('category', ['slug' => $parent])) ?>"><?= e((string) $category['parent_name']) ?></a>
  <?php endif; ?>
  <span aria-hidden="true">›</span> <span aria-current="page"><?= e((string) $category['name']) ?></span>
</nav>
<div class="sec-head"><h1><?= e((string) $category['name']) ?></h1><span class="sec-sub">Latest stories</span></div>
<?php if ($posts === []): ?>
<p class="empty">No stories in <?= e((string) $category['name']) ?> yet.</p>
<?php else: ?>
<div class="cat-layout">
  <div class="cat-list">
    <?php foreach ($days as $dayPosts): $h = day_heading((string) $dayPosts[0]['published_at']); ?>
    <section class="day" aria-label="<?= e(($h['label'] !== '' ? $h['label'] . ', ' : '') . $h['date']) ?>">
      <h2 class="day-head"><?php if ($h['label'] !== ''): ?><span class="day-label"><?= e($h['label']) ?></span> · <?php endif; ?><span class="day-date"><?= e($h['date']) ?></span></h2>
      <?php foreach ($dayPosts as $p): $url = post_url($p); $m = post_media($p); ?>
      <article class="story-row">
        <div class="story-text">
          <div class="story-meta"><time datetime="<?= e(str_replace(' ', 'T', (string) $p['published_at']) . 'Z') ?>"><?= e(fmt_time((string) $p['published_at'], true)) ?></time><?php if ((string) $p['author_name'] !== ''): ?> · By <?= e((string) $p['author_name']) ?><?php endif; ?></div>
          <h3 class="story-title"><a href="<?= e($url) ?>"><?= e((string) $p['title']) ?></a></h3>
          <?php if ((string) $p['excerpt'] !== ''): ?><p class="story-excerpt"><?= e((string) $p['excerpt']) ?></p><?php endif; ?>
        </div>
        <?php if ($m !== null): ?><a class="story-media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true"><?= picture($m, '(max-width: 767px) 34vw, 220px') ?></a><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
    <?php if ($hasOlder): ?>
    <div class="older"><a class="btn" href="<?= e(site_link('category', ['slug' => $slug, 'parent' => $parent, 'page' => (string) ($page + 1)])) ?>">Older stories ›</a></div>
    <?php endif; ?>
  </div>
  <?php if ($mostRead !== []): ?>
  <aside class="cat-side" aria-label="Most read in <?= e((string) $category['name']) ?>">
    <h2 class="side-head">Most read in <?= e((string) $category['name']) ?></h2>
    <ol class="most-read">
      <?php foreach ($mostRead as $k => $p): ?>
      <li><span class="card-n" aria-hidden="true"><?= $k + 1 ?></span><a href="<?= e(post_url($p)) ?>"><?= e((string) $p['title']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </aside>
  <?php endif; ?>
</div>
<?php endif; ?>
