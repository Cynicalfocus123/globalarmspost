<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
// Tags (task B8): story counts, rename (name and address) and merge into another tag.
?>
<div class="a-head"><h1>Tags</h1></div>
<p class="a-small">New tags are added on the spot in the story editor. Renaming re-indexes the stories for search; merging moves every story to the other tag.</p>
<ul class="a-rows tags-list">
  <?php foreach ($rows as $t): $id = (int) $t['id']; ?>
  <li class="tag-row" data-id="<?= $id ?>">
    <span class="grow"><b><?= e((string) $t['name']) ?></b> <span class="a-small">/tag/<?= e((string) $t['slug']) ?></span></span>
    <span class="a-small n-stories"><?= (int) $t['stories'] ?> <?= (int) $t['stories'] === 1 ? 'story' : 'stories' ?></span>
    <details class="a-more">
      <summary class="a-btn2 sm">Edit</summary>
      <div class="a-card a-more-box">
        <form method="post" action="/admin/tags" class="a-form tag-rename">
          <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= $id ?>">
          <label for="tag-name-<?= $id ?>">Name</label><input id="tag-name-<?= $id ?>" name="name" maxlength="120" required value="<?= e((string) $t['name']) ?>">
          <label for="tag-slug-<?= $id ?>">Address</label><input id="tag-slug-<?= $id ?>" name="slug" maxlength="120" value="<?= e((string) $t['slug']) ?>">
          <button class="a-btn2" type="submit">Rename</button>
        </form>
        <?php if (count($rows) > 1): ?>
        <form method="post" action="/admin/tags" class="a-form tag-merge" data-confirm="Merge “<?= e((string) $t['name']) ?>” into the chosen tag? Its stories move there and “<?= e((string) $t['name']) ?>” is removed.">
          <?= csrf_field() ?><input type="hidden" name="action" value="merge"><input type="hidden" name="id" value="<?= $id ?>">
          <label for="tag-into-<?= $id ?>">Merge into</label>
          <select id="tag-into-<?= $id ?>" name="into">
            <?php foreach ($rows as $o): if ((int) $o['id'] === $id) { continue; } ?><option value="<?= (int) $o['id'] ?>"><?= e((string) $o['name']) ?></option><?php endforeach; ?>
          </select>
          <button class="a-btn2 danger" type="submit">Merge</button>
        </form>
        <?php endif; ?>
      </div>
    </details>
  </li>
  <?php endforeach; ?>
</ul>
<?php if ($rows === []): ?><p class="a-mute">No tags yet.</p><?php endif; ?>
