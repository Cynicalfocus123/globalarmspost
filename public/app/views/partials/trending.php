<?php
declare(strict_types=1);
/** @var list<array{name: string, slug: string, n: int}> $tags */

// Trending tags row under the header (task F11): the tags most used by recent stories. The admin decides
// whether it shows and how many tags it has (Settings → Trending tags).
if ($tags === []):
    return;
endif;
?>
<nav class="trending" aria-label="Trending topics">
  <span class="trending-label">Trending</span>
  <ul class="trending-list">
    <?php foreach ($tags as $tag): ?>
    <li><a class="chip" href="<?= e(site_link('tag', ['slug' => (string) $tag['slug']])) ?>"><?= e((string) $tag['name']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
