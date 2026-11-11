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
<section class="hs<?= e($img ?? '') ?> hs-lead-grid span-<?= $span ?>"<?= $title !== '' ? ' aria-label="' . e($title) . '"' : '' ?>>
<?= view('sections/_head', ['title' => $title, 'more' => $more]) ?>
<div class="hs-body split">
<?= card($posts[0], 'lead', ['first' => true, 'excerpt' => true, 'sizes' => '(max-width: 767px) 100vw, 50vw']) ?>
<?php if (count($posts) > 1): ?>
<div class="hs-group grid cols-2">
<?php foreach (array_slice($posts, 1) as $p): ?><?= card($p, 'card', ['sizes' => '(max-width: 767px) 34vw, 280px']) ?><?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</section>
