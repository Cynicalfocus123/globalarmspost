<?php
declare(strict_types=1);
/** @var array<string, mixed> $user */
/** @var string $title */
/** @var string $nav */
/** @var string $body */
/** @var array{text: string, kind: string}|null $flash */
$isAdmin = $user['role'] === 'admin';
$site = setting('site_name', 'Global Arms Post');
// Left menu on desktop; on phones the same links live in the "More" sheet beside the bottom tab bar.
$items = [
    ['dashboard', '/admin', 'Dashboard', true], ['stories', '/admin/stories', 'Stories', true], ['homepage', '/admin/homepage', 'Homepage', $isAdmin],
    ['categories', '/admin/categories', 'Categories', true], ['tags', '/admin/tags', 'Tags', true], ['authors', '/admin/authors', 'Authors', true],
    ['pictures', '/admin/pictures', 'Pictures', true], ['menu', '/admin/menu', 'Menu', $isAdmin], ['users', '/admin/users', 'Users', $isAdmin],
    ['settings', '/admin/settings', 'Settings', $isAdmin],
];
$links = function () use ($items, $nav): string {
    $html = '';
    foreach ($items as [$key, $href, $label, $show]) {
        if ($show) {
            $html .= '<a class="a-nav' . ($key === $nav ? ' on' : '') . '" href="' . e($href) . '"' . ($key === $nav ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
        }
    }
    return $html;
};
$svg = fn (string $d): string => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$tab = fn (string $key, string $href, string $label, string $icon): string => '<a class="tab' . ($key === $nav ? ' on' : '') . '" href="' . e($href) . '"' . ($key === $nav ? ' aria-current="page"' : '') . '>' . $icon . '<span>' . e($label) . '</span></a>';
$inTabs = in_array($nav, ['dashboard', 'stories', 'pictures'], true);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · Admin · <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<link rel="icon" href="/favicon.ico" sizes="any">
<script src="/assets/admin/admin.js?v=<?= e(asset_dir_version('admin')) ?>" type="module"></script>
</head>
<body class="admin page-<?= e($nav) ?>">
<a class="skip" href="#a-main">Skip to content</a>
<div class="a-shell">
  <aside class="a-side" aria-label="Admin menu">
    <a class="a-logo" href="/admin"><img src="/assets/img/logo/logo-1x.webp" srcset="/assets/img/logo/logo-2x.webp 2x" width="150" height="48" alt="<?= e($site) ?>"></a>
    <nav class="a-nav-list" aria-label="Admin pages"><?= $links() ?></nav>
    <div class="a-side-foot">
      <a class="a-nav" href="<?= e(site_link('home')) ?>" target="_blank" rel="noopener">View site ↗</a>
      <a class="a-nav<?= $nav === 'account' ? ' on' : '' ?>" href="/admin/account"><?= e((string) $user['name']) ?> · <?= e((string) $user['role']) ?></a>
      <form method="post" action="/admin/logout"><?= csrf_field() ?><button class="a-nav a-linkbtn" type="submit">Sign out</button></form>
    </div>
  </aside>
  <main class="a-main" id="a-main">
    <?php if ($flash !== null): ?><div class="a-flash <?= e($flash['kind']) ?>" role="status"><?= e($flash['text']) ?></div><?php endif; ?>
    <?= $body ?>
  </main>
</div>
<nav class="a-tabbar" aria-label="Admin tabs">
  <?= $tab('dashboard', '/admin', 'Home', $svg('M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z')) ?>
  <?= $tab('stories', '/admin/stories', 'Stories', $svg('M5 4h14v16H5zM8 8h8M8 12h8M8 16h5')) ?>
  <a class="tab tab-new" href="/admin/stories/new" aria-label="New story"><?= $svg('M12 5v14M5 12h14') ?></a>
  <?= $tab('pictures', '/admin/pictures', 'Pictures', $svg('M4 5h16v14H4zM4 15l5-5 4 4 3-3 4 4M15 9h.01')) ?>
  <button class="tab<?= $inTabs ? '' : ' on' ?>" type="button" data-sheet-open="more-sheet" aria-haspopup="dialog" aria-controls="more-sheet"><?= $svg('M5 12h.01M12 12h.01M19 12h.01') ?><span>More</span></button>
</nav>
<div class="a-sheet-wrap" id="more-sheet" hidden>
  <div class="a-sheet" role="dialog" aria-modal="true" aria-labelledby="more-title">
    <div class="a-sheet-head"><b id="more-title">Menu</b><button class="a-x" type="button" data-sheet-close aria-label="Close">×</button></div>
    <nav class="a-sheet-nav" aria-label="All admin pages"><?= $links() ?>
      <a class="a-nav" href="/admin/account">Account (<?= e((string) $user['name']) ?>)</a>
      <a class="a-nav" href="<?= e(site_link('home')) ?>" target="_blank" rel="noopener">View site ↗</a>
    </nav>
    <form method="post" action="/admin/logout"><?= csrf_field() ?><button class="a-btn2 wide" type="submit">Sign out</button></form>
  </div>
</div>
</body>
</html>
