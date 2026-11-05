<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $more */
if ($title === '') {
    return;
}
?>
<div class="sec-head">
  <h2><?= e($title) ?></h2>
  <?php if ($more !== ''): ?><a class="see-more" href="<?= e($more) ?>">See more<span class="sr-only"> <?= e($title) ?></span></a><?php endif; ?>
</div>
