<?php
declare(strict_types=1);

// Starter settings, categories and header menu, like globalarmspost.com today. All editable in the admin later.
return function (PDO $db, string $driver): void {
    $now = now_utc();
    $set = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
    foreach ([
        'site_name' => 'Global Arms Post',
        'tagline' => 'Defence and security news from around the world',
        'about' => 'Global Arms Post covers defence, security and the arms industry worldwide.',
        'timezone' => 'Asia/Bangkok',
    ] as $name => $value) {
        $set->execute([$name, $value]);
    }

    $cat = $db->prepare('INSERT INTO categories (parent_id, name, slug, position, created_at) VALUES (?, ?, ?, ?, ?)');
    $ids = [];
    $pos = 0;
    foreach ([
        ['Land', 'land', null], ['Air', 'air', null], ['Space', 'space', null], ['Naval', 'naval', null],
        ['Global news', 'global-news', null], ['Regions', 'regions', null],
        ['U.S.', 'us', 'regions'], ['Asia', 'asia', 'regions'], ['Europe', 'europe', 'regions'],
        ['Middle East and Africa', 'middle-east-africa', 'regions'], ['Business', 'business', null],
    ] as [$name, $slug, $parent]) {
        $cat->execute([$parent === null ? null : $ids[$parent], $name, $slug, $pos++, $now]);
        $ids[$slug] = (int) $db->lastInsertId();
    }

    $db->prepare('INSERT INTO menus (location) VALUES (?)')->execute(['header']);
    $menu = (int) $db->lastInsertId();
    $item = $db->prepare('INSERT INTO menu_items (menu_id, parent_id, position, type, label, target) VALUES (?, ?, ?, ?, ?, ?)');
    $add = function (?int $parent, int $position, string $type, string $label, string $target) use ($item, $menu, $db): int {
        $item->execute([$menu, $parent, $position, $type, $label, $target]);
        return (int) $db->lastInsertId();
    };
    $add(null, 0, 'home', 'Home', '');
    $p = 1;
    foreach (['land' => 'Land', 'air' => 'Air', 'space' => 'Space', 'naval' => 'Naval', 'global-news' => 'Global news'] as $slug => $label) {
        $add(null, $p++, 'category', $label, $slug);
    }
    $regions = $add(null, $p++, 'category', 'Regions', 'regions');
    $c = 0;
    foreach (['us' => 'U.S.', 'asia' => 'Asia', 'europe' => 'Europe', 'middle-east-africa' => 'Middle East and Africa'] as $slug => $label) {
        $add($regions, $c++, 'category', $label, $slug);
    }
    $add(null, $p, 'category', 'Business', 'business');
};
