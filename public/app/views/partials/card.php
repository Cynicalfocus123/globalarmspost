<?php
declare(strict_types=1);
/** @var array<string, mixed> $p */
/** @var string $variant */
/** @var array<string, mixed> $opt */
$url = post_url($p);
$media = post_media($p);
$showMedia = $media !== null && $variant !== 'headline'; // 'title' rows hide the photo on desktop only (CSS)
$classes = 'card v-' . $variant . (!empty($opt['first']) ? ' is-first' : '') . ($showMedia ? '' : ' no-media');
$date = (string) $p['published_at'];
?>
<article class="<?= e($classes) ?>">
  <?php if ($variant === 'headline'): ?><span class="card-n" aria-hidden="true"><?= (int) ($opt['n'] ?? 0) ?></span><?php endif; ?>
  <?php if ($showMedia): ?>
  <a class="card-media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true"><?= picture($media, (string) ($opt['sizes'] ?? '(max-width: 767px) 34vw, 320px'), !empty($opt['eager'])) ?></a>
  <?php endif; ?>
  <div class="card-body">
    <?php if ((int) $p['live_label'] === 1): ?><div class="live"><span class="live-dot" aria-hidden="true"></span>Live updates</div><?php endif; ?>
    <h3 class="card-title"><a href="<?= e($url) ?>"><?= e((string) $p['title']) ?></a></h3>
    <?php if (!empty($opt['excerpt']) && (string) $p['excerpt'] !== ''): ?><p class="card-excerpt"><?= e((string) $p['excerpt']) ?></p><?php endif; ?>
    <div class="card-meta"><?php if ((string) $p['author_name'] !== ''): ?><span class="by">By <?= e((string) $p['author_name']) ?></span><?php endif; ?><time datetime="<?= e(str_replace(' ', 'T', $date) . 'Z') ?>"><?= e(fmt_date($date)) ?></time></div>
  </div>
</article>
