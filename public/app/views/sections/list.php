<?php
declare(strict_types=1);
/** @var int $id */
/** @var string $title */
/** @var int $span */
/** @var string $more */
/** @var list<array<string, mixed>> $posts */
/** @var array<string, list<array<string, mixed>>> $tabs */
/** @var array<string, mixed> $s */
/** @var string $img picture size classes from the homepage builder ('' = standard) */
$cols = max(2, min(6, (int) ($s['columns'] ?? 4)));
?>
<section class="hs<?= e($img ?? '') ?> hs-list span-<?= $span ?>"<?= $title !== '' ? ' aria-label="' . e($title) . '"' : '' ?>>
<?= view('sections/_head', ['title' => $title, 'more' => $more]) ?>
<?php if ($tabs !== []): ?>
<div class="tabs" role="tablist">
<?php $i = 0; foreach ($tabs as $name => $_): ?>
<button class="tab" type="button" role="tab" id="tab-<?= $id ?>-<?= $i ?>" aria-controls="panel-<?= $id ?>-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>"><?= e($name) ?></button>
<?php $i++; endforeach; ?>
</div>
<?php $i = 0; foreach ($tabs as $name => $list): ?>
<div class="hs-body" role="tabpanel" id="panel-<?= $id ?>-<?= $i ?>" aria-labelledby="tab-<?= $id ?>-<?= $i ?>"<?= $i > 0 ? ' hidden' : '' ?>>
<?php foreach ($list as $k => $p): ?><?= card($p, 'row', ['first' => $k === 0, 'sizes' => '(max-width: 767px) 100vw, 96px']) ?><?php endforeach; ?>
</div>
<?php $i++; endforeach; ?>
<?php else: ?>
<div class="hs-body">
<?php foreach ($posts as $k => $p): ?><?= card($p, 'row', ['first' => $k === 0, 'sizes' => '(max-width: 767px) 100vw, 96px']) ?><?php endforeach; ?>
</div>
<?php endif; ?>
</section>
