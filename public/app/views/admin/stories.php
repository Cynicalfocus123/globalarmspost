<?php
declare(strict_types=1);
/** @var string $tab */
/** @var string $search */
/** @var int $cat */
/** @var int $author */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $pages */
/** @var list<array<string, mixed>> $rows */
/** @var array<string, int> $counts */
/** @var list<array<string, mixed>> $categories */
/** @var list<array<string, mixed>> $authors */
$query = fn (array $change): string => '/admin/stories?' . http_build_query(array_filter(
    $change + ['tab' => $tab, 'q' => $search, 'cat' => $cat ?: null, 'author' => $author ?: null, 'sort' => $sort !== 'date' ? $sort : null, 'dir' => $dir !== 'desc' ? $dir : null],
    fn ($v) => $v !== null && $v !== '' && $v !== 'all'
));
$tabs = ['all' => ['All', $counts['all']], 'published' => ['Published', $counts['published']], 'scheduled' => ['Scheduled', $counts['scheduled']],
         'drafts' => ['Drafts', $counts['drafts']], 'trash' => ['Trash', $counts['trash']]];
$sortLink = function (string $key, string $label) use ($query, $sort, $dir): string {
    $next = $sort === $key && $dir === 'desc' ? 'asc' : 'desc';
    $mark = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a href="' . e($query(['sort' => $key, 'dir' => $next, 'page' => null])) . '">' . e($label) . $mark . '</a>';
};
$pill = ['published' => ['pub', 'Published'], 'scheduled' => ['sch', 'Scheduled'], 'draft' => ['dr', 'Draft'], 'trash' => ['tr', 'Trash']];
$back = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/stories');
?>
<div class="a-head"><h1>Stories</h1><a class="a-btn" href="/admin/stories/new">+ New story</a></div>
<nav class="a-tabs" aria-label="Story status">
  <?php foreach ($tabs as $key => [$label, $n]): ?>
  <a class="a-tab<?= $tab === $key ? ' on' : '' ?>" href="<?= e($query(['tab' => $key, 'page' => null])) ?>" data-tab="<?= e($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="n"><?= $n ?></span></a>
  <?php endforeach; ?>
</nav>
<form class="a-filters" method="get" action="/admin/stories" role="search">
  <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
  <label class="sr-only" for="f-q">Search stories</label>
  <input id="f-q" name="q" type="search" placeholder="Search headlines" value="<?= e($search) ?>">
  <label class="sr-only" for="f-cat">Category</label>
  <select id="f-cat" name="cat"><option value="">All categories</option>
    <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"<?= $cat === $c['id'] ? ' selected' : '' ?>><?= str_repeat('— ', $c['depth']) . e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <label class="sr-only" for="f-author">Author</label>
  <select id="f-author" name="author"><option value="">All authors</option>
    <?php foreach ($authors as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $author === (int) $a['id'] ? ' selected' : '' ?>><?= e((string) $a['name']) ?></option><?php endforeach; ?>
  </select>
  <button class="a-btn2" type="submit">Filter</button>
</form>
<form method="post" action="/admin/stories/bulk" class="a-bulk-form">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
  <div class="a-bulk" hidden>
    <span class="a-bulk-n">0 chosen</span>
    <?php if ($tab === 'trash'): ?>
    <button class="a-btn2" name="action" value="restore" type="submit">Restore as drafts</button>
    <?php else: ?>
    <button class="a-btn2" name="action" value="publish" type="submit">Publish</button>
    <button class="a-btn2 danger" name="action" value="trash" type="submit">Move to trash</button>
    <?php endif; ?>
  </div>
  <div class="a-table" role="table" aria-label="Stories">
    <div class="a-tr a-th" role="row">
      <span role="columnheader" class="c-check"><label class="check-all-wrap"><input type="checkbox" class="check-all" aria-label="Choose all stories on this page"><span class="show-phone" aria-hidden="true">Choose all</span></label></span>
      <span role="columnheader"><?= $sortLink('title', 'Title') ?></span>
      <span role="columnheader"><?= $sortLink('category', 'Category') ?></span>
      <span role="columnheader"><?= $sortLink('status', 'Status') ?></span>
      <span role="columnheader"><?= $sortLink('date', 'Date') ?></span>
    </div>
    <?php if ($rows === []): ?><p class="a-mute a-empty">No stories here.</p><?php endif; ?>
    <?php foreach ($rows as $r):
        $state = story_state((string) $r['status'], $r['published_at']);
        $thumb = media_thumb($r['m_path'], $r['m_widths']);
        $when = $r['published_at'] !== null ? local_dt((string) $r['published_at'])->format('j M g:i A') : '—'; ?>
    <div class="a-tr story-row" role="row" data-id="<?= (int) $r['id'] ?>" data-state="<?= e($state) ?>">
      <span role="cell" class="c-check"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Choose <?= e((string) $r['title']) ?>"></span>
      <span role="cell" class="c-title">
        <?php if ($thumb !== ''): ?><img src="<?= e($thumb) ?>" alt="" width="64" height="43" loading="lazy"><?php else: ?><span class="noimg"></span><?php endif; ?>
        <span class="c-text">
          <a class="t" href="/admin/stories/<?= (int) $r['id'] ?>"><?= e((string) $r['title']) ?></a>
          <span class="c-acts"><a href="/admin/stories/<?= (int) $r['id'] ?>">Edit</a> · <a href="/admin/preview/<?= (int) $r['id'] ?>" target="_blank" rel="noopener">View</a><?= $r['author_name'] ? ' · ' . e((string) $r['author_name']) : '' ?></span>
          <span class="c-phone"><span class="a-pill <?= $pill[$state][0] ?>"><?= $pill[$state][1] ?></span> <?= e((string) $r['cat_name']) ?> · <?= e($when) ?></span>
        </span>
      </span>
      <span role="cell" class="c-cat"><?= e((string) $r['cat_name']) ?></span>
      <span role="cell" class="c-status"><span class="a-pill <?= $pill[$state][0] ?>"><?= $pill[$state][1] ?></span></span>
      <span role="cell" class="c-date"><?= e($when) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</form>
<?php if ($pages > 1): ?>
<nav class="a-pager" aria-label="Pages">
  <?php if ($page > 1): ?><a class="a-btn2" href="<?= e($query(['page' => $page - 1])) ?>">‹ Newer</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="a-btn2" href="<?= e($query(['page' => $page + 1])) ?>">Older ›</a><?php endif; ?>
</nav>
<?php endif; ?>
