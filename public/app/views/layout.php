<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $body */
$site = setting('site_name', 'Global Arms Post');
$logo = logo_meta();
$L = '/assets/img/logo/';
?><!doctype html>
<html lang="en" data-links="<?= e(build_info()['link_mode']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title === '' ? $site : $title . ' | ' . $site) ?></title>
<meta name="description" content="<?= e(setting('tagline')) ?>">
<meta name="theme-color" content="#C8102E">
<link rel="preload" as="image" type="image/avif" imagesrcset="<?= e($L) ?>logo-1x.avif 1x, <?= e($L) ?>logo-2x.avif 2x" fetchpriority="high">
<link rel="preload" as="font" type="font/woff2" href="/assets/fonts/inter-latin-wght-normal.woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="<?= e($L) ?>favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<?= view('header', ['site' => $site, 'logo' => $logo]) ?>
<main id="main" class="wrap">
<?= $body ?>
</main>
<?= view('footer', ['site' => $site, 'logo' => $logo]) ?>
<button class="to-top" type="button" aria-label="Back to top" hidden>
  <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 15l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
</button>
</body>
</html>
