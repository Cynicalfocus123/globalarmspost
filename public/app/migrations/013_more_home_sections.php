<?php
declare(strict_types=1);

// Owner, 2026-11-17: U.S., Global news, Naval, Space and Business had no homepage section, so their stories never
// showed on the homepage. One section each is added at the end (after Air + Most read), only for a category that
// exists and is not in any section yet; the admin can move, change or hide them in the homepage builder.
return function (PDO $db, string $driver): void {
    $used = [];
    foreach ($db->query('SELECT settings FROM home_sections')->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $s = json_decode((string) $json, true);
        foreach ((array) (is_array($s) ? ($s['categories'] ?? []) : []) as $slug) {
            $used[(string) $slug] = true;
        }
    }
    $pos = (int) $db->query('SELECT COALESCE(MAX(position), 0) FROM home_sections')->fetchColumn();
    $find = $db->prepare('SELECT name FROM categories WHERE slug = ?');
    $ins = $db->prepare('INSERT INTO home_sections (position, layout, title, span, settings) VALUES (?, ?, ?, ?, ?)');
    foreach ([
        ['us', 'overlay_list', 6, ['count' => 4]],
        ['global-news', 'overlay_list', 6, ['count' => 4]],
        ['naval', 'grid', 12, ['count' => 4, 'columns' => 4]],
        ['space', 'lead_list', 8, ['count' => 5]],
        ['business', 'headlines', 4, ['count' => 5]],
    ] as [$slug, $layout, $span, $settings]) {
        if (isset($used[$slug])) {
            continue;
        }
        $find->execute([$slug]);
        $name = $find->fetchColumn();
        if ($name === false) {
            continue;
        }
        $ins->execute([++$pos, $layout, (string) $name, $span, json_encode(['categories' => [$slug]] + $settings + ['avoid_duplicates' => false])]);
    }
};
