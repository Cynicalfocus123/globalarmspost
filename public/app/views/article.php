<?php
declare(strict_types=1);
/** @var array<string, mixed> $p */
// Basic story page so links work. The full article page (gallery, Aa, share, related rows) is task F10.
$m = post_media($p);
$updated = (string) $p['updated_at'] > (string) $p['published_at'] ? (string) $p['updated_at'] : '';
$summary = array_values(array_filter(array_map('trim', explode("\n", (string) $p['summary']))));
?>
<article class="article">
  <nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?= e(site_link('home')) ?>">Home</a> <span aria-hidden="true">›</span>
    <a href="<?= e(category_url_of($p)) ?>"><?= e((string) $p['cat_name']) ?></a>
  </nav>
  <h1 class="article-title"><?= e((string) $p['title']) ?></h1>
  <div class="article-meta">
    <?php if ((string) $p['author_name'] !== ''): ?><div>By <b><?= e((string) $p['author_name']) ?></b></div><?php endif; ?>
    <div class="mute"><time datetime="<?= e(str_replace(' ', 'T', (string) $p['published_at']) . 'Z') ?>"><?= e(fmt_full((string) $p['published_at'])) ?></time><?php if ($updated !== ''): ?> · Updated <?= e(fmt_full($updated)) ?><?php endif; ?></div>
  </div>
  <?php if ($m !== null): ?>
  <figure class="article-figure">
    <?= picture($m, '(max-width: 900px) 100vw, 860px', true) ?>
    <?php if ((string) $m['caption'] !== '' || (string) $m['credit'] !== ''): ?><figcaption><?= e((string) $m['caption']) ?><?php if ((string) $m['credit'] !== ''): ?> <span class="mute">Photo: <?= e((string) $m['credit']) ?></span><?php endif; ?></figcaption><?php endif; ?>
  </figure>
  <?php endif; ?>
  <?php if ($summary !== []): ?>
  <div class="summary"><h2>Summary</h2><ul><?php foreach ($summary as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <div class="article-body">
    <?php // Body is shown as plain paragraphs until the HTML allow-list cleaner (task B5) is built. ?>
    <?php foreach (preg_split('/\R{2,}/', trim((string) $p['body'])) ?: [] as $para): if (trim($para) === '') continue; ?>
    <p><?= e(trim($para)) ?></p>
    <?php endforeach; ?>
  </div>
  <?php if ((string) $p['credits'] !== ''): ?><p class="credits"><?= e((string) $p['credits']) ?></p><?php endif; ?>
</article>
