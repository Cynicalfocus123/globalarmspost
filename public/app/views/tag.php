<?php
declare(strict_types=1);
/** @var array<string, mixed> $tag */
/** @var list<array<string, mixed>> $posts */
/** @var int $page */

// Tag page (/tag/usa): every public story with this tag, newest first. Topic chips on a story lead here.
?>
<section class="tag-page">
  <div class="sec-head"><h1>Tag: <?= e((string) $tag['name']) ?></h1>
    <a class="see-more" href="<?= e(search_url(['q' => (string) $tag['name']])) ?>">Search instead<span aria-hidden="true"> →</span></a></div>
  <?php if ($posts === []): ?>
  <p class="search-empty">No stories with this tag yet.</p>
  <?php else: ?>
  <div class="rows">
    <?php foreach ($posts as $p): ?>
    <?= card($p, 'row', ['sizes' => '(max-width: 767px) 34vw, 220px', 'excerpt' => true]) ?>
    <?php endforeach; ?>
  </div>
  <?php if ($page > 1 || count($posts) === 20): ?>
  <nav class="pager" aria-label="Tag pages">
    <?php if ($page > 1): ?><a class="pager-btn" href="<?= e(site_link('tag', ['slug' => (string) $tag['slug'], 'page' => $page - 1])) ?>" rel="prev">‹ Newer</a><?php endif; ?>
    <span class="pager-n">Page <?= $page ?></span>
    <?php if (count($posts) === 20): ?><a class="pager-btn" href="<?= e(site_link('tag', ['slug' => (string) $tag['slug'], 'page' => $page + 1])) ?>" rel="next">Older ›</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
