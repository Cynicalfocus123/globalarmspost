<?php
declare(strict_types=1);
/** @var list<array<string, mixed>> $rows */
?>
<div class="a-head"><h1>Authors</h1><a class="a-btn" href="/admin/authors/new">+ New author</a></div>
<ul class="a-rows authors-list">
  <?php foreach ($rows as $a): $pic = media_thumb($a['path'], $a['widths']); ?>
  <li class="author-row" data-id="<?= (int) $a['id'] ?>">
    <?php if ($pic !== ''): ?><img class="a-av med" src="<?= e($pic) ?>" alt="" width="40" height="40"><?php else: ?><span class="a-av med" aria-hidden="true"><?= e(mb_substr((string) $a['name'], 0, 1)) ?></span><?php endif; ?>
    <span class="grow"><a class="t" href="/admin/authors/<?= (int) $a['id'] ?>"><?= e((string) $a['name']) ?></a><span class="a-small"><?= e((string) $a['email']) ?></span></span>
    <span class="a-small n-stories"><?= (int) $a['stories'] ?> <?= (int) $a['stories'] === 1 ? 'story' : 'stories' ?></span>
    <a class="a-btn2 sm" href="/admin/authors/<?= (int) $a['id'] ?>">Edit</a>
  </li>
  <?php endforeach; ?>
</ul>
<?php if ($rows === []): ?><p class="a-mute">No authors yet.</p><?php endif; ?>
