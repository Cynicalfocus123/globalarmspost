<?php
declare(strict_types=1);
/** @var array<string, mixed> $user */
/** @var array<string, int> $counts */
/** @var int $views_today */
/** @var list<array<string, mixed>> $scheduled */
/** @var list<array<string, mixed>> $drafts */
/** @var list<array{label: string, ok: bool, note: string}> $checks */
$hour = (int) (new DateTimeImmutable('now', site_tz()))->format('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$short = function (string $utc): string {
    $d = local_dt($utc);
    $today = new DateTimeImmutable('now', site_tz());
    $day = $d->format('Y-m-d') === $today->format('Y-m-d') ? 'Today' : ($d->format('Y-m-d') === $today->modify('+1 day')->format('Y-m-d') ? 'Tomorrow' : $d->format('D j M'));
    return $day . ' ' . $d->format('g:i A');
};
$num = fn (int $n): string => $n >= 10000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k' : number_format($n);
?>
<div class="a-head"><h1><span class="hide-phone"><?= e($greet) ?>, <?= e(explode(' ', (string) $user['name'])[0]) ?></span><span class="show-phone">Dashboard</span></h1><a class="a-btn" href="/admin/stories/new">+ New story</a></div>
<div class="a-stats">
  <a class="a-stat" href="/admin/stories?tab=published" data-stat="published"><b><?= e($num($counts['published'])) ?></b><span>Published</span></a>
  <a class="a-stat" href="/admin/stories?tab=scheduled" data-stat="scheduled"><b><?= e($num($counts['scheduled'])) ?></b><span>Scheduled</span></a>
  <a class="a-stat" href="/admin/stories?tab=drafts" data-stat="drafts"><b><?= e($num($counts['drafts'])) ?></b><span>Drafts</span></a>
  <div class="a-stat" data-stat="views" data-value="<?= $views_today ?>"><b><?= e($num($views_today)) ?></b><span>Views today</span></div>
</div>
<div class="a-cols">
  <section class="a-card">
    <h2 class="a-h">Scheduled next</h2>
    <?php if ($scheduled === []): ?><p class="a-mute">Nothing scheduled. Pick a future date in a story to schedule it.</p><?php endif; ?>
    <ul class="a-list scheduled-list">
      <?php foreach ($scheduled as $s): ?>
      <li><span class="a-pill sch"><?= e($short((string) $s['published_at'])) ?></span><a href="/admin/stories/<?= (int) $s['id'] ?>"><?= e((string) $s['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="a-card">
    <h2 class="a-h">Recent drafts</h2>
    <?php if ($drafts === []): ?><p class="a-mute">No drafts.</p><?php endif; ?>
    <ul class="a-list drafts-list">
      <?php foreach ($drafts as $d): ?>
      <li><span class="a-pill dr">Draft</span><a href="/admin/stories/<?= (int) $d['id'] ?>"><?= e((string) $d['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <h2 class="a-h a-mt">Server check</h2>
    <ul class="a-checks">
      <?php foreach ($checks as $c): ?>
      <li class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><span aria-hidden="true"><?= $c['ok'] ? '✓' : '!' ?></span> <?= e($c['label']) ?><?php if (!$c['ok'] && $c['note'] !== ''): ?> <small><?= e($c['note']) ?></small><?php endif; ?></li>
      <?php endforeach; ?>
      <li class="browser-check" data-check="avif"><span aria-hidden="true">…</span> AVIF in this browser</li>
    </ul>
  </section>
</div>
