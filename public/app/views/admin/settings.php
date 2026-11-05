<?php
declare(strict_types=1);
/** @var int $terms */
/** @var int $docs */
/** @var array{files: int, bytes: int} $stats */

// Settings (part of task F17) that the reader side needs: the trending tags row, the page cache, and the
// search index tools. The rest of the settings (site name, time zone, theme) come with F17.
$kb = $stats['bytes'] > 0 ? max(1, (int) round($stats['bytes'] / 1024)) : 0;
?>
<div class="a-head"><h1>Settings</h1></div>

<form method="post" action="/admin/settings" class="a-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <section class="a-card">
    <h2 class="a-h">Trending tags</h2>
    <p class="a-small">The row of topic chips under the header, from the tags most used by recent stories.</p>
    <label class="a-check"><input type="checkbox" name="trending_tags" value="1"<?= setting('trending_tags', '1') === '1' ? ' checked' : '' ?>> Show the trending tags row</label>
    <div class="a-row wrap">
      <label class="a-lbl" for="tt-count">How many tags</label>
      <input id="tt-count" name="trending_tags_count" type="number" inputmode="numeric" min="1" max="20" value="<?= e(setting('trending_tags_count', '8')) ?>">
      <label class="a-lbl" for="tt-days">From the last (days)</label>
      <input id="tt-days" name="trending_tags_days" type="number" inputmode="numeric" min="1" max="365" value="<?= e(setting('trending_tags_days', '14')) ?>">
    </div>
  </section>

  <section class="a-card">
    <h2 class="a-h">Page cache</h2>
    <p class="a-small">Public pages are saved as files and served from there. A page expires at the next
      scheduled story's time (so scheduled stories appear exactly on time) or after the time below, and every
      save in the admin clears the cache.</p>
    <label class="a-check"><input type="checkbox" name="cache_enabled" value="1"<?= setting('cache_enabled', '1') === '1' ? ' checked' : '' ?>> Use the page cache</label>
    <label class="a-lbl" for="cache-ttl">Longest time a page may be cached (seconds)</label>
    <input id="cache-ttl" name="cache_ttl" type="number" inputmode="numeric" min="15" max="3600" value="<?= e(setting('cache_ttl', '300')) ?>">
    <p class="a-small cache-stats"><?= (int) $stats['files'] ?> pages cached, <?= $kb ?> KB. Admin pages, API answers and POSTs are never cached.</p>
  </section>

  <button class="a-btn" type="submit">Save settings</button>
</form>

<section class="a-card">
  <h2 class="a-h">Search index</h2>
  <p class="a-small"><?= (int) $docs ?> stories indexed, <?= (int) $terms ?> words in the vocabulary. The index is
    updated on every save; rebuilding is only needed after importing stories or changing many names.</p>
  <form method="post" action="/admin/settings" class="a-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="rebuild">
    <button class="a-btn2" type="submit">Rebuild search index</button>
  </form>
</section>

<section class="a-card">
  <h2 class="a-h">Clear the page cache</h2>
  <p class="a-small">Every save already does this. Use it when a page looks out of date.</p>
  <form method="post" action="/admin/settings">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cache">
    <button class="a-btn2" type="submit">Clear the page cache</button>
  </form>
</section>
