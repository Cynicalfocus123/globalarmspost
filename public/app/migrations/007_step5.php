<?php
declare(strict_types=1);

/**
 * Step 5: the menu builder (F5) can hide an item without removing it, and the settings the admin's Settings page
 * (F17) adds get their starting values. New columns only: nothing existing changes, nothing is removed.
 */
return function (PDO $db, string $driver): void {
    // Hidden items stay in the menu builder (and keep their place) but are not shown in the header.
    $db->exec('ALTER TABLE menu_items ADD COLUMN hidden INT NOT NULL DEFAULT 0');

    $has = $db->prepare('SELECT name FROM settings WHERE name = ?');
    $add = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
    // site_url stays unset until the admin types it (then share links, the sitemap and canonical addresses use it).
    foreach (['category_per_page' => '20'] as $name => $value) {
        $has->execute([$name]);
        $found = $has->fetch() !== false;
        $has->closeCursor();
        if (!$found) {
            $add->execute([$name, $value]);
        }
    }
};
