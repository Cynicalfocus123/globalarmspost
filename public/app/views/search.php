<?php
declare(strict_types=1);
/** @var array<string, mixed> $res */

// Search page (tasks B11 + F7): a search box with suggestions, filters (category, date), sort (best match
// or newest), results with the matched words highlighted, and "Did you mean …" when a word was corrected.
// The same view runs in both copies: /#search?q=… in git/, /search?q=… in live/.

$q = (string) $res['q'];
$filters = $res;
$cats = public_category_tree();
$sort = (string) $res['sort'];
$resultWord = (int) $res['total'] === 1 ? 'story' : 'stories';
?>
<section class="search-page">
  <h1 class="page-title">Search</h1>

  <form class="search-form" action="<?= e(site_link('search')) ?>" method="get" role="search" data-search-form>
    <label class="sr-only" for="sq">Search the news</label>
    <input id="sq" name="q" type="search" value="<?= e($q) ?>" placeholder="Search the news" maxlength="120" autocomplete="off" data-suggest aria-describedby="search-help" aria-expanded="false">
    <button type="submit">Search</button>
    <span class="sr-only" id="search-help">Search titles, stories, tags, categories and authors</span>
    <div class="suggest-box" data-suggest-box hidden></div>
  </form>

  <form class="search-filters" action="<?= e(site_link('search')) ?>" method="get" data-search-form>
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <label>Category
      <select name="cat">
        <option value="">All categories</option>
        <?php foreach ($cats as $cat): ?>
        <option value="<?= e((string) $cat['slug']) ?>"<?= $filters['category'] === (string) $cat['slug'] ? ' selected' : '' ?>><?= str_repeat('— ', min(3, (int) $cat['depth'])) ?><?= e((string) $cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>From <input type="date" name="from" value="<?= e((string) $filters['from']) ?>" max="<?= e(gmdate('Y-m-d')) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e((string) $filters['to']) ?>" max="<?= e(gmdate('Y-m-d')) ?>"></label>
    <label>Sort
      <select name="sort">
        <option value="match"<?= $sort !== 'new' ? ' selected' : '' ?>>Best match</option>
        <option value="new"<?= $sort === 'new' ? ' selected' : '' ?>>Newest first</option>
      </select>
    </label>
    <button type="submit" class="apply">Apply</button>
  </form>

  <?php if ($res['did'] !== ''): ?>
  <p class="did-you-mean">Did you mean <a href="<?= e(search_url(['q' => (string) $res['did'], 'cat' => (string) $filters['category'], 'from' => (string) $filters['from'], 'to' => (string) $filters['to'], 'sort' => $sort])) ?>"><?= e((string) $res['did']) ?></a>?</p>
  <?php endif; ?>

  <?php if ($q === ''): ?>
  <p class="search-empty">Type a word above: a name, a place, a subject. Joined words work too (“ukrainewar”), and small spelling mistakes are fixed.</p>
  <?php elseif ((int) $res['total'] === 0): ?>
  <p class="search-empty">No stories found for “<b><?= e($q) ?></b>”.<?php if ($res['did'] !== '') : ?> Try <a href="<?= e(search_url(['q' => (string) $res['did']])) ?>"><?= e((string) $res['did']) ?></a>.<?php endif; ?></p>
  <?php else: ?>
  <p class="search-count" role="status"><?= (int) $res['total'] ?> <?= $resultWord ?> for “<b><?= e($q) ?></b>” <span class="mute">· page <?= (int) $res['page'] ?> of <?= (int) $res['pages'] ?></span></p>
  <ol class="results">
    <?php foreach ($res['rows'] as $row): ?>
    <li class="result">
      <a class="result-title" href="<?= e(post_url($row)) ?>"><?= (string) ($row['title_html'] ?? e((string) $row['title'])) ?></a>
      <div class="result-meta mute">
        <a href="<?= e(category_url_of($row)) ?>"><?= e((string) $row['cat_name']) ?></a>
        · <time datetime="<?= e(str_replace(' ', 'T', (string) $row['published_at']) . 'Z') ?>"><?= e(fmt_full((string) $row['published_at'])) ?></time>
        <?php if ((string) $row['author_name'] !== ''): ?> · By <?= e((string) $row['author_name']) ?><?php endif; ?>
      </div>
      <?php if ((string) $row['snippet'] !== ''): ?><p class="result-snippet"><?= $row['snippet'] ?></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>

  <?php if ((int) $res['pages'] > 1): ?>
  <nav class="pager" aria-label="Search pages">
    <?php if ((int) $res['page'] > 1): ?>
    <a class="pager-btn" href="<?= e(search_url($filters, (int) $res['page'] - 1)) ?>" rel="prev">‹ Newer page</a>
    <?php endif; ?>
    <span class="pager-n"><?= (int) $res['page'] ?> / <?= (int) $res['pages'] ?></span>
    <?php if ((int) $res['page'] < (int) $res['pages']): ?>
    <a class="pager-btn" href="<?= e(search_url($filters, (int) $res['page'] + 1)) ?>" rel="next">Older page ›</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
