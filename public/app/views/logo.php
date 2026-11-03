<?php
declare(strict_types=1);
/** @var string $site */
/** @var array{w1: int, h1: int, w2: int, h2: int} $logo */
/** @var string $class */
/** @var bool $eager */
$L = '/assets/img/logo/';
?><a class="<?= e($class) ?>" href="<?= e(site_link('home')) ?>" aria-label="<?= e($site) ?> home"><picture>
<source type="image/avif" srcset="<?= e($L) ?>logo-1x.avif 1x, <?= e($L) ?>logo-2x.avif 2x">
<source type="image/webp" srcset="<?= e($L) ?>logo-1x.webp 1x, <?= e($L) ?>logo-2x.webp 2x">
<img src="<?= e($L) ?>logo.png" width="<?= $logo['w1'] ?>" height="<?= $logo['h1'] ?>" alt="<?= e($site) ?>"<?= $eager ? ' fetchpriority="high"' : ' loading="lazy"' ?> decoding="async">
</picture></a>
