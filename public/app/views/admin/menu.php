<?php
declare(strict_types=1);
/** @var array<string, mixed> $data */
/** @var string $location */
// Menu builder (task F5), built by assets/admin/menu.js: the header menu's items, drag (mouse, touch, pen) or ↑ ↓
// into order, → ← to put an item under the one above it (or choose its parent), links to a category, tag, story,
// page or any web address. Saved in one go; the header shows the new menu at once.
$json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/json" id="menu-data"><?= $json ?></script>
<nav class="mb-tabs" aria-label="Menus">
  <?php foreach (menu_locations() as $loc => $name): ?>
  <a href="/admin/menu<?= $loc === 'header' ? '' : '?m=' . e($loc) ?>"<?= $loc === $location ? ' aria-current="page"' : '' ?>><?= e($name) ?></a>
  <?php endforeach; ?>
</nav>
<div class="a-head"><h1><?= e(menu_locations()[$location]) ?></h1>
  <a class="a-btn2 hide-phone" href="<?= e(site_link('home')) ?>" target="_blank" rel="noopener">View site ↗</a>
  <button type="button" class="a-btn2 mb-add">+ Add item</button>
  <button type="button" class="a-btn mb-save">Save</button>
</div>
<p class="a-small mb-status" role="status" aria-live="polite">Drag ⋮⋮ (or use ↑ ↓) to change the order; → puts an item under the one above it, ← takes it out. Tap an item to change it.</p>
<?php if ($location === 'footer'): ?><p class="a-small mb-help">Footer: each top-level item is a column title (choose "Title only (no link)"); the items under it are the column's links. Social icons: Settings → Social links.</p><?php endif; ?>
<div class="hb mb">
  <ol class="hb-list mb-list" aria-label="<?= e(menu_locations()[$location]) ?> items"></ol>
  <div class="hb-panel a-sheet-wrap as-panel" id="mb-sheet">
    <div class="a-sheet" role="dialog" aria-modal="false" aria-labelledby="mb-panel-h">
      <div class="a-sheet-head"><b id="mb-panel-h">Menu item</b><button type="button" class="a-x" data-sheet-close aria-label="Close">×</button></div>
      <div class="mb-form"></div>
    </div>
  </div>
</div>
