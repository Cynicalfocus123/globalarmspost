<?php
declare(strict_types=1);
/** @var array<string, mixed> $p */

// Story page (task F10), top to bottom as the design rules and wireframe section 3 say: breadcrumb, headline,
// byline with the author's picture, date + updated, reading time, Aa and Share, the photo gallery (arrows,
// counter, full screen), Summary, body with pictures, credits, topic chips, share row, "More in {category}"
// and "Latest news". Everything printed here is escaped; the body is the allow-list cleaned HTML.

$m = post_media($p);
$publish = (string) $p['published_at'];
$updated = (string) $p['updated_at'] > $publish ? (string) $p['updated_at'] : '';
$summary = array_values(array_filter(array_map('trim', explode("\n", (string) $p['summary']))));
$tags = q_all('SELECT t.name, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.name', [(int) $p['id']]);
$authorMedia = null;
if ((int) ($p['author_id'] ?? 0) > 0) {
    $authorMedia = q_one('SELECT m.path, m.widths, m.has_avif, m.width, m.height, m.alt
                            FROM authors a JOIN media m ON m.id = a.media_id WHERE a.id = ?', [(int) $p['author_id']]);
}
$bodyText = search_plain((string) $p['body']);
$words = str_word_count($bodyText) + str_word_count((string) $p['title']);
$minutes = max(1, (int) ceil($words / 220));
$canonical = absolute_url(post_url($p));
$shareText = rawurlencode((string) $p['title']);
$shareUrl = rawurlencode($canonical);

// Every photo of the story, featured first, then the gallery pictures in their saved order.
$photos = [];
if ($m !== null) {
    $photos[] = ['media' => $m, 'caption' => (string) $m['caption'], 'credit' => (string) $m['credit']];
}
foreach (post_gallery((int) $p['id']) as $g) {
    $photos[] = ['media' => ['alt' => (string) ($g['alt'] !== '' ? $g['alt'] : $g['caption'])] + $g, 'caption' => (string) $g['caption'], 'credit' => (string) $g['credit']];
}
$related = posts_query(['categories' => [(string) $p['cat_slug']], 'exclude_ids' => [(int) $p['id']], 'count' => 8]);
$latest = posts_query(['exclude_ids' => [(int) $p['id']], 'count' => 6]);
?>
<article class="article" id="article" data-article>
  <nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?= e(site_link('home')) ?>">Home</a> <span aria-hidden="true">›</span>
    <a href="<?= e(category_url_of($p)) ?>"><?= e((string) $p['cat_name']) ?></a>
  </nav>
  <?php if ((int) $p['live_label'] === 1): ?><div class="live"><span class="live-dot" aria-hidden="true"></span>Live updates</div><?php endif; ?>
  <h1 class="article-title"><?= e((string) $p['title']) ?></h1>

  <div class="byline article-meta">
    <?php if ($authorMedia !== null): ?>
    <span class="byline-pic"><?= picture($authorMedia, '48px', true) ?></span>
    <?php endif; ?>
    <div class="byline-text">
      <?php if ((string) $p['author_name'] !== ''): ?><div class="byline-name">By <b><?= e((string) $p['author_name']) ?></b></div><?php endif; ?>
      <div class="byline-when mute">
        <time datetime="<?= e(str_replace(' ', 'T', $publish) . 'Z') ?>"><?= e(fmt_full($publish)) ?></time>
        <?php if ($updated !== ''): ?> · <span class="updated">Updated <?= e(fmt_full($updated)) ?></span><?php endif; ?>
        · <span class="reading"><?= $minutes ?> min read</span>
      </div>
    </div>
    <div class="art-tools">
      <button class="tool-btn aa-btn" type="button" aria-expanded="false" aria-controls="aa-menu" aria-label="Text size">Aa</button>
      <div class="aa-menu" id="aa-menu" hidden>
        <button type="button" data-size="s" aria-pressed="false">Small</button>
        <button type="button" data-size="m" aria-pressed="true">Normal</button>
        <button type="button" data-size="l" aria-pressed="false">Large</button>
      </div>
      <button class="tool-btn share-btn" type="button" aria-expanded="false" aria-controls="share-menu" data-title="<?= e((string) $p['title']) ?>" data-url="<?= e($canonical) ?>">Share</button>
      <div class="share-menu" id="share-menu" hidden>
        <a href="https://twitter.com/intent/tweet?url=<?= e($shareUrl) ?>&amp;text=<?= e($shareText) ?>" target="_blank" rel="noopener">X</a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= e($shareUrl) ?>" target="_blank" rel="noopener">Facebook</a>
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= e($shareUrl) ?>" target="_blank" rel="noopener">LinkedIn</a>
        <a href="https://wa.me/?text=<?= e($shareText) ?>%20<?= e($shareUrl) ?>" target="_blank" rel="noopener">WhatsApp</a>
        <a href="mailto:?subject=<?= e($shareText) ?>&amp;body=<?= e($shareUrl) ?>">Email</a>
        <button type="button" class="copy-link" data-url="<?= e($canonical) ?>">Copy link</button>
      </div>
    </div>
  </div>

  <?php if ($photos !== []): ?>
  <div class="gal<?= count($photos) === 1 ? ' single' : '' ?>" data-gal data-count="<?= count($photos) ?>">
    <div class="gal-track" tabindex="0" role="group" aria-roledescription="carousel" aria-label="Photos of this story">
      <?php foreach ($photos as $i => $photo): ?>
      <figure class="gal-slide" role="group" aria-roledescription="slide" aria-label="Photo <?= $i + 1 ?> of <?= count($photos) ?>">
        <?= picture($photo['media'], count($photos) > 1 ? '(max-width: 900px) 100vw, 860px' : '(max-width: 900px) 100vw, 860px', $i === 0) ?>
        <?php if ($photo['caption'] !== '' || $photo['credit'] !== ''): ?>
        <figcaption><?= e($photo['caption']) ?><?php if ($photo['credit'] !== ''): ?> <span class="mute">Photo: <?= e($photo['credit']) ?></span><?php endif; ?></figcaption>
        <?php endif; ?>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php if (count($photos) > 1): ?>
    <button class="gal-prev" type="button" aria-label="Previous photo">‹</button>
    <button class="gal-next" type="button" aria-label="Next photo">›</button>
    <span class="gal-count" aria-live="polite">1/<?= count($photos) ?></span>
    <button class="gal-expand" type="button" aria-label="Full screen">⤢</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($summary !== []): ?>
  <div class="summary"><h2>Summary</h2><ul><?php foreach ($summary as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="article-body"><?= body_html((string) $p['body']) ?></div>

  <?php if ((string) $p['credits'] !== ''): ?><p class="credits"><?= e((string) $p['credits']) ?></p><?php endif; ?>

  <?php if ($tags !== []): ?>
  <ul class="chips" aria-label="Topics">
    <?php foreach ($tags as $tag): ?><li><a class="chip" href="<?= e(site_link('tag', ['slug' => (string) $tag['slug']])) ?>"><?= e((string) $tag['name']) ?></a></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="share-row" aria-label="Share this story">
    <span class="mute">Share</span>
    <a href="https://twitter.com/intent/tweet?url=<?= e($shareUrl) ?>&amp;text=<?= e($shareText) ?>" target="_blank" rel="noopener" aria-label="Share on X">X</a>
    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= e($shareUrl) ?>" target="_blank" rel="noopener" aria-label="Share on Facebook">Facebook</a>
    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= e($shareUrl) ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn">LinkedIn</a>
    <a href="https://wa.me/?text=<?= e($shareText) ?>%20<?= e($shareUrl) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp">WhatsApp</a>
    <a href="mailto:?subject=<?= e($shareText) ?>&amp;body=<?= e($shareUrl) ?>" aria-label="Share by email">Email</a>
    <button type="button" class="copy-link" data-url="<?= e($canonical) ?>">Copy link</button>
  </div>
</article>

<?php if ($related !== []): ?>
<section class="related" aria-labelledby="more-in">
  <div class="sec-head"><h2 id="more-in">More in <?= e((string) $p['cat_name']) ?></h2>
    <a class="see-more" href="<?= e(category_url_of($p)) ?>">See more<span aria-hidden="true"> →</span></a></div>
  <div class="row-wrap">
    <button class="row-prev" type="button" aria-label="Previous stories">‹</button>
    <ul class="row-list">
      <?php foreach ($related as $r): ?>
      <li class="row-item"><?= card($r, 'card', ['sizes' => '(max-width: 767px) 76vw, 300px', 'excerpt' => false]) ?></li>
      <?php endforeach; ?>
    </ul>
    <button class="row-next" type="button" aria-label="More stories">›</button>
  </div>
</section>
<?php endif; ?>

<section class="latest" aria-labelledby="latest-h">
  <div class="sec-head"><h2 id="latest-h">Latest news</h2>
    <a class="see-more" href="<?= e(site_link('home')) ?>">See more<span aria-hidden="true"> →</span></a></div>
  <div class="grid grid-3">
    <?php foreach ($latest as $l): ?><?= card($l, 'card', ['sizes' => '(max-width: 767px) 100vw, 380px']) ?><?php endforeach; ?>
  </div>
</section>
