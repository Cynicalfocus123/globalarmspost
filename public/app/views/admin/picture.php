<?php
declare(strict_types=1);
/** @var array<string, mixed> $user */
/** @var array<string, mixed> $m */
/** @var list<array{what: string, label: string, href: string}> $usage */
/** @var string $error */
$id = (int) $m['id'];
$widths = array_values(array_filter(array_map('intval', explode(',', (string) $m['widths']))));
sort($widths);
$big = '/' . (string) $m['path'] . '-' . $widths[min(1, count($widths) - 1)] . '.webp';
?>
<div class="a-head"><a class="a-back" href="/admin/pictures">‹ Pictures</a><h1>Picture</h1></div>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<div class="a-cols">
  <div>
    <img class="pic-big" src="<?= e($big) ?>" alt="<?= e((string) $m['alt']) ?>" width="<?= (int) $m['width'] ?>" height="<?= (int) $m['height'] ?>">
    <p class="a-small"><?= e(implode(', ', $widths)) ?> px wide · <?= (int) $m['has_avif'] === 1 ? 'AVIF + WebP' : 'WebP' ?> · added <?= e(fmt_date((string) $m['created_at'])) ?></p>
  </div>
  <form method="post" action="/admin/pictures/<?= $id ?>" class="a-form a-card pic-form">
    <?= csrf_field() ?>
    <label for="pic-alt">Alt text (what the picture shows, for screen readers)</label><input id="pic-alt" name="alt" maxlength="300" value="<?= e((string) $m['alt']) ?>">
    <label for="pic-caption">Caption (used when a story has no caption of its own)</label><textarea id="pic-caption" name="caption" rows="2" maxlength="600"><?= e((string) $m['caption']) ?></textarea>
    <label for="pic-credit">Credit</label><input id="pic-credit" name="credit" maxlength="200" value="<?= e((string) $m['credit']) ?>">
    <button class="a-btn" type="submit">Save picture</button>
  </form>
</div>
<section class="a-card a-mt pic-usage">
  <h2 class="a-h">Where it is used</h2>
  <?php if ($usage === []): ?>
  <p class="a-mute">Nowhere. It can be deleted.</p>
  <?php else: ?>
  <ul class="a-list"><?php foreach ($usage as $u): ?><li><span class="a-pill"><?= e($u['what']) ?></span><a href="<?= e($u['href']) ?>"><?= e($u['label']) ?></a></li><?php endforeach; ?></ul>
  <p class="a-small">A picture can only be deleted when nothing uses it (stories in the trash count too).</p>
  <?php endif; ?>
</section>
<?php if ($usage === [] && $user['role'] === 'admin'): ?>
<form method="post" action="/admin/pictures/<?= $id ?>" class="pic-delete" data-confirm="Delete this picture for good? Its files are removed too. This cannot be undone.">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <button class="a-btn2 danger" type="submit">Delete picture</button>
</form>
<?php endif; ?>
