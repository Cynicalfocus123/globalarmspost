<?php
declare(strict_types=1);
/** @var array<string, mixed> $data */
// The editor is built by assets/admin/editor.js from this data; the HTML here is the frame.
$s = $data['story'];
$json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/json" id="editor-data"><?= $json ?></script>
<form class="ed" id="editor" novalidate>
  <div class="ed-bar">
    <a class="a-back" href="/admin/stories">‹ <span class="hide-phone">Stories</span></a>
    <span class="ed-saved" role="status" aria-live="polite"><?= $s === null ? 'Not saved yet' : 'Saved' ?></span>
    <a class="a-btn2 ed-preview" href="<?= $s === null ? '#' : '/admin/preview/' . (int) $s['id'] ?>" target="_blank" rel="noopener"<?= $s === null ? ' hidden' : '' ?>>Preview</a>
    <button class="a-btn ed-save" type="submit">Save draft</button>
  </div>
  <div class="ed-tabs" role="tablist" aria-label="Editor parts">
    <button type="button" role="tab" class="a-tab on" aria-selected="true" data-show="write">Write</button>
    <button type="button" role="tab" class="a-tab" aria-selected="false" data-show="details">Details</button>
    <button type="button" role="tab" class="a-tab" aria-selected="false" data-show="photos">Photos</button>
  </div>
  <p class="a-error ed-error" role="alert" hidden></p>
  <div class="ed-grid">
    <div class="ed-main">
      <div data-pane="write">
        <label class="sr-only" for="ed-title">Headline</label>
        <textarea id="ed-title" class="ed-title" rows="1" maxlength="300" placeholder="Headline goes here" required></textarea>
        <div class="ed-tool" role="toolbar" aria-label="Text tools" aria-controls="ed-body">
          <button type="button" data-cmd="bold" aria-label="Bold"><b>B</b></button>
          <button type="button" data-cmd="italic" aria-label="Italic"><i>I</i></button>
          <button type="button" data-cmd="underline" aria-label="Underline"><u>U</u></button>
          <button type="button" data-block="h2" aria-label="Subhead">H2</button>
          <button type="button" data-block="h3" aria-label="Small subhead">H3</button>
          <button type="button" data-cmd="insertUnorderedList" aria-label="Bullet list">• List</button>
          <button type="button" data-cmd="insertOrderedList" aria-label="Numbered list">1. List</button>
          <button type="button" data-block="blockquote" aria-label="Quote">❝</button>
          <button type="button" data-link aria-label="Link">🔗</button>
          <button type="button" data-picture aria-label="Add picture">🖼</button>
        </div>
        <div id="ed-body" class="ed-body" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Story text" data-placeholder="Write the story…"></div>
        <button type="button" class="a-drop ed-drop" data-picture>＋ Drop a picture here, paste one, or press 🖼 in the toolbar · it goes where the cursor is</button>
        <input type="file" id="ed-body-file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
        <p class="a-small ed-convert" role="status" aria-live="polite" hidden></p>
      </div>
      <div data-pane="details">
        <div class="a-box"><label class="a-h" for="ed-summary">Summary (one bullet per line)</label><textarea id="ed-summary" rows="4" maxlength="3000"></textarea></div>
        <div class="a-box"><label class="a-h" for="ed-excerpt">Short summary for cards (excerpt)</label><textarea id="ed-excerpt" rows="2" maxlength="1000"></textarea></div>
        <div class="a-box"><label class="a-h" for="ed-credits">Credits line</label><input id="ed-credits" maxlength="300" placeholder="Reporting by … Editing by …"></div>
      </div>
    </div>
    <div class="ed-side">
      <div data-pane="details">
        <section class="a-box" aria-labelledby="pub-h">
          <h2 class="a-h" id="pub-h">Publish</h2>
          <div class="a-seg" role="radiogroup" aria-label="Status">
            <label><input type="radio" name="status" value="draft" checked><span>Draft</span></label>
            <label><input type="radio" name="status" value="published"><span>Published</span></label>
          </div>
          <label class="a-lbl" for="ed-date">Date and time (future = scheduled) · <?= e($data['tz']) ?></label>
          <input id="ed-date" type="datetime-local" step="60">
          <p class="a-small ed-state" aria-live="polite"></p>
          <label class="a-check"><input type="checkbox" id="ed-sticky"> Pin to top (sticky)</label>
          <label class="a-check"><input type="checkbox" id="ed-live"> Show "Live updates" label</label>
          <button type="button" class="a-linkbtn danger ed-trash"<?= $s === null ? ' hidden' : '' ?>>Move to trash</button>
        </section>
        <section class="a-box" aria-labelledby="cat-h">
          <h2 class="a-h" id="cat-h">Category</h2>
          <input type="search" class="ed-cat-search" placeholder="Search categories…" aria-label="Search categories">
          <p class="a-lbl" id="cat-main-h">Main category <span class="a-mute">(gives the story's address)</span></p>
          <div class="ed-cat-list" role="radiogroup" aria-labelledby="cat-h cat-main-h"></div>
          <p class="a-lbl" id="cat-extra-h">Also show in <span class="a-mute">(tick as many as you like)</span></p>
          <div class="ed-cat-extra" role="group" aria-labelledby="cat-extra-h"></div>
          <button type="button" class="a-linkbtn ed-cat-add-open">+ Add new category</button>
          <div class="ed-cat-add a-mini" hidden>
            <label class="a-lbl" for="ed-cat-name">New category name</label><input id="ed-cat-name" maxlength="120">
            <label class="a-lbl" for="ed-cat-parent">Inside</label><select id="ed-cat-parent"></select>
            <div class="a-row"><button type="button" class="a-btn sm ed-cat-save">Add category</button><button type="button" class="a-btn2 sm ed-cat-cancel">Cancel</button></div>
          </div>
        </section>
        <section class="a-box" aria-labelledby="tag-h">
          <h2 class="a-h" id="tag-h">Tags</h2>
          <div class="ed-chips" aria-live="polite"></div>
          <div class="combo">
            <input id="ed-tag-input" class="combo-input" placeholder="Type a tag…" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ed-tag-list" aria-labelledby="tag-h" maxlength="120">
            <div class="a-sugg" id="ed-tag-list" role="listbox" hidden></div>
          </div>
          <p class="a-small">A new tag is saved at once and added to this story.</p>
        </section>
        <section class="a-box" aria-labelledby="author-h">
          <h2 class="a-h" id="author-h">Author</h2>
          <div class="combo">
            <input id="ed-author-input" class="combo-input" placeholder="Search authors…" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ed-author-list" aria-labelledby="author-h" maxlength="120">
            <div class="a-sugg" id="ed-author-list" role="listbox" hidden></div>
          </div>
          <div class="ed-author-new a-sheet-wrap as-panel" id="author-sheet" hidden>
            <div class="a-sheet a-mini" role="dialog" aria-modal="false" aria-labelledby="author-new-h">
              <div class="a-sheet-head"><b id="author-new-h">New author</b><button type="button" class="a-x" data-sheet-close aria-label="Close">×</button></div>
              <div class="a-row">
                <button type="button" class="a-av big ed-author-pic" aria-label="Choose profile picture">＋</button>
                <span class="a-small">Profile picture<br>(becomes AVIF + WebP)</span>
                <input type="file" id="ed-author-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
              </div>
              <label class="a-lbl" for="ed-author-name">Name</label><input id="ed-author-name" maxlength="120">
              <label class="a-lbl" for="ed-author-email">Email</label><input id="ed-author-email" type="email" maxlength="190">
              <p class="a-error ed-author-error" role="alert" hidden></p>
              <div class="a-row"><button type="button" class="a-btn sm ed-author-save">Save author</button><button type="button" class="a-btn2 sm" data-sheet-close>Cancel</button></div>
            </div>
          </div>
          <p class="a-small">A new author is saved at once, chosen for this story and added to the Authors list.</p>
        </section>
        <section class="a-box" aria-labelledby="seo-h">
          <h2 class="a-h" id="seo-h">Search preview</h2>
          <div class="ed-seo"><span class="seo-url"></span><b class="seo-title"></b><span class="seo-desc"></span></div>
          <label class="a-lbl" for="ed-slug">Address (slug)</label><input id="ed-slug" maxlength="200" placeholder="made from the headline">
        </section>
      </div>
      <div data-pane="photos">
        <section class="a-box" aria-labelledby="photo-h">
          <h2 class="a-h" id="photo-h">Main photo + gallery</h2>
          <div class="ed-main-photo"></div>
          <div class="a-row"><button type="button" class="a-btn2 sm ed-photo-pick">Choose main photo</button><button type="button" class="a-linkbtn danger ed-photo-remove" hidden>Remove</button></div>
          <input type="file" id="ed-photo-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
          <label class="a-lbl" for="ed-photo-caption">Caption</label><input id="ed-photo-caption" maxlength="600">
          <label class="a-lbl" for="ed-photo-credit">Photo credit</label><input id="ed-photo-credit" maxlength="200">
          <h3 class="a-h a-mt">Gallery</h3>
          <ol class="ed-gallery"></ol>
          <button type="button" class="a-linkbtn ed-gallery-add">+ Add gallery photos</button>
          <input type="file" id="ed-gallery-file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
        </section>
      </div>
    </div>
  </div>
</form>
