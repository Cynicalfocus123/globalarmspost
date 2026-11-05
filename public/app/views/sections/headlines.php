<?php
declare(strict_types=1);
/** @var int $id */
/** @var string $title */
/** @var int $span */
/** @var string $more */
/** @var list<array<string, mixed>> $posts */
/** @var array<string, list<array<string, mixed>>> $tabs */
/** @var array<string, mixed> $s */
$cols = max(2, min(6, (int) ($s['columns'] ?? 4)));
?>
<section class="hs hs-headlines span-<?= $span ?>"<?= $title !== '' ? ' aria-label="' . e($title) . '"' : '' ?>>
<?= view('sections/_head', ['title' => $title, 'more' => $more]) ?>
<ol class="hs-body headlines">
<?php foreach ($posts as $k => $p): ?><li><?= card($p, 'headline', ['n' => $k + 1]) ?></li><?php endforeach; ?>
</ol>
</section>
