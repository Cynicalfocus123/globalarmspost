<?php
declare(strict_types=1);

// Owner, 2026-11-16: the homepage showed the same stories over and over: the top section, then "Top stories", then
// "Latest". The top section is now "Breaking news" (the stories ticked "Breaking news", posts.featured), and the
// tabbed list next to it becomes one "Latest news" list without the tabs, which skips the stories already shown in
// Breaking news ("avoid duplicates" on for that section only). Other sections are not changed.
return function (PDO $db, string $driver): void {
    $up = $db->prepare('UPDATE home_sections SET title = ?, settings = ? WHERE id = ?');
    foreach ($db->query("SELECT id, title, settings FROM home_sections WHERE layout = 'list'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = json_decode((string) $row['settings'], true);
        if (!is_array($s) || empty($s['tabs'])) {
            continue;
        }
        unset($s['tabs']);
        $s['avoid_duplicates'] = true;
        $title = trim((string) $row['title']) !== '' ? (string) $row['title'] : 'Latest news';
        $up->execute([$title, json_encode($s), (int) $row['id']]);
    }
};
