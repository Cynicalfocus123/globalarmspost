<?php
declare(strict_types=1);
/** @var array<string, mixed>|null $pg */
/** @var string $error */
// One page (task F24): title, address and the text, written with the same toolbar as a story (assets/admin/pages.js
// copies the text into the form on Save). The server cleans it with the story's allow-list (clean_html()).
$pg = $pg ?? ['id' => 0, 'title' => '', 'slug' => '', 'body' => '', 'updated_at' => ''];
$id = (int) ($pg['id'] ?? 0);
?>
<div class="a-head"><a class="a-back" href="/admin/pages">‹ Pages</a><h1><?= $id > 0 ? 'Edit page' : 'New page' ?></h1>
  <?php if ($id > 0 && (string) $pg['slug'] !== ''): ?><a class="a-btn2 hide-phone" href="<?= e(site_link('page', ['slug' => (string) $pg['slug']])) ?>" target="_blank" rel="noopener">View page ↗</a><?php endif; ?>
</div>
<?php if ($error !== ''): ?><p class="a-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" action="/admin/pages/<?= $id > 0 ? $id : 'new' ?>" class="a-form a-card page-form" id="page-form">
  <?= csrf_field() ?>
  <label for="pg-title">Title</label><input id="pg-title" name="title" required maxlength="200" value="<?= e((string) $pg['title']) ?>">
  <label for="pg-slug">Address (after the site address, like about-us; empty = made from the title)</label>
  <input id="pg-slug" name="slug" maxlength="120" value="<?= e((string) $pg['slug']) ?>" autocapitalize="off" spellcheck="false">
  <span class="a-lbl" id="pg-body-h">Text</span>
  <div class="ed-tool" role="toolbar" aria-label="Text tools" aria-controls="pg-body">
    <button type="button" data-cmd="bold" aria-label="Bold"><b>B</b></button>
    <button type="button" data-cmd="italic" aria-label="Italic"><i>I</i></button>
    <button type="button" data-block="h2" aria-label="Subhead">H2</button>
    <button type="button" data-block="h3" aria-label="Small subhead">H3</button>
    <button type="button" data-cmd="insertUnorderedList" aria-label="Bullet list">• List</button>
    <button type="button" data-cmd="insertOrderedList" aria-label="Numbered list">1. List</button>
    <button type="button" data-link aria-label="Link">🔗</button>
  </div>
  <div id="pg-body" class="ed-body page-ed-body" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="pg-body-h" data-placeholder="Write the page…"><?= (string) $pg['body'] ?></div>
  <textarea name="body" id="pg-body-field" hidden><?= e((string) $pg['body']) ?></textarea>
  <div class="a-row"><button class="a-btn" type="submit">Save page</button></div>
</form>
<?php if ($id > 0): ?>
<form method="post" action="/admin/pages/<?= $id ?>" class="a-form page-delete" data-confirm="Delete the page <?= e((string) $pg['title']) ?>? Menu links to it disappear. This cannot be undone.">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <button class="a-btn2 danger" type="submit">Delete page</button>
</form>
<?php endif; ?>
