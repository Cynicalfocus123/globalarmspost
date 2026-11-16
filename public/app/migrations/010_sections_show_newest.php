<?php
declare(strict_types=1);

// Owner, 2026-11-16: a homepage section shows its newest stories by date, repeats allowed; the admin turns
// "Avoid duplicates" on for a section only when wanted (Admin → Homepage). The starter sections (004) all had it on,
// so a category's newest story, already shown in the top sections, was missing from its own section.
// Turns it off in every saved section; nothing else in the settings changes.
return function (PDO $db, string $driver): void {
    $up = $db->prepare('UPDATE home_sections SET settings = ? WHERE id = ?');
    foreach ($db->query('SELECT id, settings FROM home_sections')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = json_decode((string) $row['settings'], true);
        if (!is_array($s) || empty($s['avoid_duplicates'])) {
            continue;
        }
        $s['avoid_duplicates'] = false;
        $up->execute([json_encode($s), (int) $row['id']]);
    }
};
