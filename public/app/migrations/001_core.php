<?php
declare(strict_types=1);

// Settings, categories and the header menu (task B10).
return function (PDO $db, string $driver): void {
    $pk = pk($driver);
    $o = table_opts($driver);

    $db->exec("CREATE TABLE settings (
        name VARCHAR(64) PRIMARY KEY,
        value TEXT NOT NULL
    )$o");

    $db->exec("CREATE TABLE categories (
        id $pk,
        parent_id INT NULL,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE,
        position INT NOT NULL DEFAULT 0,
        created_at VARCHAR(19) NOT NULL
    )$o");

    $db->exec("CREATE TABLE menus (
        id $pk,
        location VARCHAR(40) NOT NULL UNIQUE
    )$o");

    // type: home | category | tag | page | url. target: a category/tag/page slug or a web address.
    $db->exec("CREATE TABLE menu_items (
        id $pk,
        menu_id INT NOT NULL,
        parent_id INT NULL,
        position INT NOT NULL DEFAULT 0,
        type VARCHAR(20) NOT NULL,
        label VARCHAR(120) NOT NULL,
        target VARCHAR(500) NOT NULL DEFAULT '',
        new_tab INT NOT NULL DEFAULT 0
    )$o");
    $db->exec('CREATE INDEX menu_items_menu ON menu_items (menu_id, parent_id, position)');
};
