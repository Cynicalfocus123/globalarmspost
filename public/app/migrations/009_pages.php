<?php
declare(strict_types=1);

/**
 * Pages (task F24, owner 2026-11-14): About Us, Terms of Service, Privacy Policy, Advertise, Contact Us and any page
 * the admin adds, at /{slug} like a category. And the footer menu: three columns (About Us, Term of Use, Contact Us)
 * built in the menu builder like the header ("Footer menu"). New tables and rows only; nothing existing changes.
 * The pages start with a short line; the real texts are written in Admin → Pages.
 */
return function (PDO $db, string $driver): void {
    $pk = pk($driver);
    $o = table_opts($driver);
    $db->exec("CREATE TABLE pages (
        id $pk,
        title VARCHAR(200) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE,
        body MEDIUMTEXT NULL,
        created_at VARCHAR(19) NOT NULL,
        updated_at VARCHAR(19) NOT NULL
    )$o");

    $now = now_utc();
    $starter = '<p>This page has no text yet. An admin writes it in Admin → Pages.</p>';
    $taken = $db->prepare('SELECT id FROM categories WHERE slug = ?');
    $page = $db->prepare('INSERT INTO pages (title, slug, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
    $slugs = [];
    foreach (['about-us' => 'About Us', 'terms-of-service' => 'Terms of Service', 'privacy-policy' => 'Privacy Policy',
              'advertise' => 'Advertise With Us', 'contact' => 'Contact Us'] as $slug => $title) {
        $taken->execute([$slug]);
        $clash = $taken->fetch() !== false;
        $taken->closeCursor();
        $use = $clash ? $slug . '-page' : $slug; // a category already has the address
        $page->execute([$title, $use, $starter, $now, $now]);
        $slugs[$slug] = $use;
    }

    $db->prepare('INSERT INTO menus (location) VALUES (?)')->execute(['footer']);
    $menu = (int) $db->lastInsertId();
    $item = $db->prepare('INSERT INTO menu_items (menu_id, parent_id, position, type, label, target) VALUES (?, ?, ?, ?, ?, ?)');
    $pos = 0;
    // Top-level items are the column titles (no link); the items under them are the links.
    foreach ([
        'About Us' => ['About Us' => 'about-us'],
        'Term of Use' => ['Terms Of Use' => 'terms-of-service', 'Privacy Policy' => 'privacy-policy'],
        'Contact Us' => ['Advertise' => 'advertise', 'Contact Us' => 'contact'],
    ] as $column => $links) {
        $item->execute([$menu, null, $pos++, 'text', $column, '']);
        $parent = (int) $db->lastInsertId();
        foreach ($links as $label => $slug) {
            $item->execute([$menu, $parent, $pos++, 'page', $label, $slugs[$slug]]);
        }
    }
};
