<?php
declare(strict_types=1);
/** @var array<string, mixed> $data */
// Built by assets/admin/builder.js. Section settings come from the layout registry (app/lib/sections.php).
$json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/json" id="builder-data"><?= $json ?></script>
<div class="a-head"><h1>Homepage</h1>
  <a class="a-btn2 hide-phone" href="<?= e(site_link('home')) ?>" target="_blank" rel="noopener">View homepage ↗</a>
  <button type="button" class="a-btn2 hb-add-open">+ Add section</button>
  <button type="button" class="a-btn hb-save">Save</button>
</div>
<p class="a-small hb-status" role="status" aria-live="polite">Drag ⋮⋮ (or use ↑ ↓) to change the order. Tap a section to open its settings.</p>
<div class="hb-add a-card" hidden>
  <label class="a-lbl" for="hb-add-layout">Layout of the new section</label>
  <select id="hb-add-layout"></select>
  <div class="a-row"><button type="button" class="a-btn sm hb-add">Add section</button><button type="button" class="a-btn2 sm hb-add-cancel">Cancel</button></div>
</div>
<div class="hb">
  <ol class="hb-list" aria-label="Homepage sections"></ol>
  <div class="hb-panel a-sheet-wrap as-panel" id="hb-sheet">
    <div class="a-sheet" role="dialog" aria-modal="false" aria-labelledby="hb-panel-h">
      <div class="a-sheet-head"><b id="hb-panel-h">Section settings</b><button type="button" class="a-x" data-sheet-close aria-label="Close">×</button></div>
      <div class="hb-form"></div>
    </div>
  </div>
  <section class="hb-preview a-card" aria-labelledby="hb-prev-h">
    <div class="hb-prev-head"><h2 class="a-h" id="hb-prev-h">Live preview</h2>
      <span class="a-seg hb-prev-size" role="radiogroup" aria-label="Preview width">
        <label><input type="radio" name="hb-prev-w" value="1280" checked><span>Desktop</span></label>
        <label><input type="radio" name="hb-prev-w" value="390"><span>Phone</span></label>
      </span></div>
    <p class="a-small hb-prev-note" role="status" aria-live="polite">Shows your changes before you save. Drawn by the site itself.</p>
    <div class="hb-frame-box"><iframe class="hb-frame" title="Homepage preview" src="about:blank"></iframe></div>
  </section>
</div>
