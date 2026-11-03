<?php
declare(strict_types=1);
/** @var string $site */
/** @var array{w1: int, h1: int, w2: int, h2: int} $logo */
$menu = menu_tree('header');
$items = function (array $list, int $depth) use (&$items): string {
    $html = '';
    foreach ($list as $i => $item) {
        $target = $item['new_tab'] ? ' target="_blank" rel="noopener"' : '';
        if ($item['children'] === []) {
            $html .= '<li><a href="' . e($item['href']) . '"' . $target . '>' . e($item['label']) . '</a></li>';
            continue;
        }
        $id = 'sub-' . $depth . '-' . $i . '-' . substr(md5($item['label']), 0, 6);
        $html .= '<li class="has-sub">'
            . '<a href="' . e($item['href']) . '"' . $target . '>' . e($item['label']) . '</a>'
            . '<button class="sub-toggle" type="button" aria-expanded="false" aria-controls="' . e($id) . '" aria-label="Open ' . e($item['label']) . ' menu">'
            . '<svg viewBox="0 0 12 12" width="14" height="14" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
            . '<ul class="submenu" id="' . e($id) . '">' . $items($item['children'], $depth + 1) . '</ul></li>';
    }
    return $html;
};
?>
<header class="site-header">
  <div class="logo-row">
    <?= view('logo', ['site' => $site, 'logo' => $logo, 'class' => 'logo', 'eager' => true]) ?>
  </div>
  <nav class="menu-bar" aria-label="Main">
    <div class="menu-inner">
      <button class="icon-btn menu-toggle" type="button" aria-expanded="false" aria-controls="main-menu" aria-label="Open menu">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
      </button>
      <?= view('logo', ['site' => $site, 'logo' => $logo, 'class' => 'logo logo-bar', 'eager' => true]) ?>
      <ul class="menu" id="main-menu"><?= $items($menu, 0) ?></ul>
      <div class="tools">
        <button class="icon-btn theme-toggle" type="button" aria-pressed="false" aria-label="Dark mode">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
        </button>
        <button class="icon-btn search-toggle" type="button" aria-expanded="false" aria-controls="site-search" aria-label="Search">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="m16 16 4.5 4.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        </button>
      </div>
    </div>
  </nav>
  <form class="search-box" id="site-search" role="search" action="<?= e(site_link('search')) ?>" method="get" hidden>
    <label class="sr-only" for="q">Search news</label>
    <input id="q" name="q" type="search" placeholder="Search news" autocomplete="off" maxlength="120">
    <button type="submit">Search</button>
  </form>
</header>
