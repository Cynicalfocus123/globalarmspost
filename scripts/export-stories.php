<?php
declare(strict_types=1);

/**
 * Copy stories from the local site (live/) to the real site without touching its database or sign-in (owner,
 * 2026-11-16: the server keeps its own database and admin). Step 1, on this PC: write the stories to one JSON file.
 * Step 2, on the server: php backend/app/cli/import-stories.php <file> (see that file).
 *
 * Usage (in git/):
 *   php -d extension=pdo_sqlite scripts/export-stories.php --featured        every story ticked "Featured"
 *   php -d extension=pdo_sqlite scripts/export-stories.php 393 394 398       these story numbers
 * Writes "Claude outputs/stories-<date>.json". The pictures themselves are not in it: they go up with the frontend
 * ZIP (uploads/); the server's import checks that each picture file is there.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array_slice($argv, 1);
$ids = array_values(array_filter(array_map('intval', $args)));
$featured = in_array('--featured', $args, true);
if ($ids === [] && !$featured) {
    fwrite(STDERR, "Usage: php scripts/export-stories.php --featured | <story id> [<story id> ...]\n");
    exit(2);
}

$root = dirname(__DIR__);
$common = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --path-format=absolute --git-common-dir'));
$main = $common !== '' ? dirname($common) : $root;
$db = new PDO('sqlite:' . dirname($main) . '/live/backend/storage/site.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$rows = function (string $sql, array $p = []) use ($db): array {
    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
};
$media = static function (?array $m): ?array {
    return $m === null ? null : array_intersect_key($m, array_flip(['path', 'widths', 'has_avif', 'width', 'height', 'alt', 'caption', 'credit', 'created_at']));
};

$where = $ids !== [] ? 'p.id IN (' . implode(',', $ids) . ')' : "p.featured = 1 AND p.status = 'published'";
$out = [];
foreach ($rows("SELECT p.*, c.slug AS cat, a.name AS author FROM posts p JOIN categories c ON c.id = p.category_id
                 LEFT JOIN authors a ON a.id = p.author_id WHERE $where ORDER BY p.published_at") as $p) {
    $id = (int) $p['id'];
    $m = $p['media_id'] !== null ? ($rows('SELECT * FROM media WHERE id = ?', [$p['media_id']])[0] ?? null) : null;
    $out[] = [
        'title' => $p['title'], 'slug' => $p['slug'], 'excerpt' => $p['excerpt'], 'summary' => $p['summary'], 'body' => $p['body'],
        'credits' => $p['credits'], 'author' => (string) ($p['author'] ?? $p['author_name']), 'category' => $p['cat'],
        'extra_categories' => array_column($rows('SELECT c.slug FROM post_categories pc JOIN categories c ON c.id = pc.category_id WHERE pc.post_id = ?', [$id]), 'slug'),
        'tags' => array_column($rows('SELECT t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ?', [$id]), 'name'),
        'status' => $p['status'], 'sticky' => (int) $p['sticky'], 'featured' => (int) ($p['featured'] ?? 0), 'live_label' => (int) $p['live_label'],
        'featured_caption' => $p['featured_caption'], 'featured_credit' => $p['featured_credit'],
        'published_at' => $p['published_at'], 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
        'media' => $media($m),
        'gallery' => array_map(static fn (array $g): array => ['caption' => (string) $g['g_caption'], 'credit' => (string) $g['g_credit'], 'media' => $media($g)],
            $rows('SELECT g.caption AS g_caption, g.credit AS g_credit, m.* FROM post_gallery g JOIN media m ON m.id = g.media_id WHERE g.post_id = ? ORDER BY g.position', [$id])),
    ];
}
$file = dirname($main) . '/Claude outputs/stories-' . date('Y-m-d-His') . '.json';
file_put_contents($file, json_encode(['version' => 1, 'stories' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo count($out) . " stories written to $file\n";
foreach ($out as $s) {
    echo "  {$s['published_at']}  [{$s['category']}" . ($s['extra_categories'] !== [] ? ' + ' . implode(', ', $s['extra_categories']) : '') . ']' . ($s['featured'] ? ' Featured' : '') . "  {$s['title']}\n";
}
