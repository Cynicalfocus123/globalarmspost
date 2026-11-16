<?php
declare(strict_types=1);

// Featured stories (owner, 2026-11-16): the big top-left homepage section (hero + cards) shows only the stories an
// admin ticks as "Featured" in the story editor, not simply the newest ones. New column posts.featured (0/1), and
// the saved hero sections get the section setting featured_only = true (Admin → Homepage can turn it off).
return function (PDO $db, string $driver): void {
    $db->exec('ALTER TABLE posts ADD COLUMN featured INT NOT NULL DEFAULT 0');
    $up = $db->prepare('UPDATE home_sections SET settings = ? WHERE id = ?');
    foreach ($db->query("SELECT id, settings FROM home_sections WHERE layout IN ('hero', 'hero_grid')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = json_decode((string) $row['settings'], true);
        $s = is_array($s) ? $s : [];
        $s['featured_only'] = true;
        $up->execute([json_encode($s), (int) $row['id']]);
    }
};
