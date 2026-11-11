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
<section class="hs<?= e($img ?? '') ?> hs-grid span-<?= $span ?>"<?= $title !== '' ? ' aria-label="' . e($title) . '"' : '' ?>>
<?= view('sections/_head', ['title' => $title, 'more' => $more]) ?>
<div class="hs-body grid cols-<?= $cols ?>">
<?php foreach ($posts as $k => $p): ?><?= card($p, 'card', ['first' => $k === 0, 'sizes' => '(max-width: 767px) 100vw, 300px']) ?><?php endforeach; ?>
</div>
</section>
