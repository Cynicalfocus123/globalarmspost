<?php
declare(strict_types=1);

// Sample stories for the TEST database only (never the real site). Run by tests/serve.mjs after the migrations.
// Usage: GAP_CONFIG=... php tests/seed-demo.php <backend> [<web folder>]   (git copy: both are public/)
// Times are relative to now, so "Today" / "Yesterday" headings always show. Adds one scheduled
// (future) story and one draft that must NOT appear anywhere.
$backend = $argv[1] ?? '';
define('PUBLIC_DIR', $argv[2] ?? $backend); // the photos go to the web folder's uploads/
require $backend . '/app/bootstrap.php';

if ((int) (q_one('SELECT COUNT(*) AS n FROM posts')['n'] ?? 0) > 0) {
    echo "Sample stories already there.\n";
    return;
}

// Pictures: copy the drawn sample photos into uploads/demo/.
$src = __DIR__ . '/fixtures/demo';
$dst = PUBLIC_DIR . '/uploads/demo';
if (!is_dir($dst)) {
    mkdir($dst, 0775, true);
}
foreach (array_merge(glob($src . '/*.avif') ?: [], glob($src . '/*.webp') ?: []) as $file) {
    copy($file, $dst . '/' . basename($file));
}
$media = [];
$credits = ['Sample photo', 'Sample photo / Studio', 'Sample photo / Pool'];
for ($n = 1; $n <= 12; $n++) {
    q('INSERT INTO media (path, widths, has_avif, width, height, alt, caption, credit, created_at) VALUES (?, ?, 1, 1600, 1067, ?, ?, ?, ?)', [
        'uploads/demo/scene-' . $n, '480,960,1600', 'Sample photo ' . $n, 'Sample caption for photo ' . $n . '.', $credits[$n % 3], now_utc(),
    ]);
    $media[] = (int) db()->lastInsertId();
}

$cat = [];
foreach (q_all('SELECT id, slug FROM categories') as $c) {
    $cat[(string) $c['slug']] = (int) $c['id'];
}
$tag = [];
foreach (['USA' => 'usa', 'Ukraine' => 'ukraine', 'F-35' => 'f-35', 'NATO' => 'nato', 'Drones' => 'drones'] as $name => $slug) {
    q('INSERT INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]);
    $tag[$slug] = (int) db()->lastInsertId();
}

$authors = ['Alex Morgan', 'Sam Rivera', 'Priya Nair', 'Daniel Okafor', 'Mei Lin'];
$authorId = [];
foreach ($authors as $name) {
    q('INSERT INTO authors (name, slug, email, created_at) VALUES (?, ?, ?, ?)', [$name, strtolower(str_replace(' ', '-', $name)), strtolower(str_replace(' ', '.', $name)) . '@example.test', now_utc()]);
    $authorId[$name] = (int) db()->lastInsertId();
}
$stories = [
    ['asia', 'Navy begins sea trials of new unmanned surface vessel', 1, 1, ['drones']],
    ['us', 'Army awards contract for next-generation interceptor', 0, 0, ['usa']],
    ['europe', 'Defence ministers agree on joint air-defence purchase', 0, 1, ['nato']],
    ['middle-east-africa', 'Coastal patrol fleet adds four fast-response boats', 0, 0, []],
    ['air', 'Fighter squadron completes first long-range training sortie', 0, 0, ['f-35']],
    ['land', 'New wheeled howitzer passes cold-weather firing tests', 0, 0, []],
    ['space', 'Launch puts two navigation satellites into orbit', 0, 0, []],
    ['naval', 'Shipyard lays keel for second frigate of new class', 0, 0, []],
    ['business', 'Defence supplier reports record order backlog', 0, 0, []],
    ['global-news', 'Arms control talks resume after six-month pause', 0, 0, []],
    ['asia', 'Joint exercise tests island air-defence network', 0, 0, ['drones']],
    ['europe', 'Border units receive first batch of counter-drone systems', 0, 0, ['drones', 'nato']],
    ['us', 'Air base upgrades runway lighting for night operations', 0, 0, ['usa']],
    ['middle-east-africa', 'Peacekeeping mission extends mandate by one year', 0, 0, []],
    ['air', 'Tanker aircraft fleet reaches full operating capability', 0, 0, []],
    ['land', 'Infantry brigade trials lighter body armour', 0, 0, []],
    ['asia', 'Submarine rescue drill held with three partner navies', 0, 0, []],
    ['europe', 'Parliament approves multi-year defence budget plan', 0, 0, ['nato']],
    ['naval', 'Destroyer completes maintenance period ahead of schedule', 0, 0, []],
    ['space', 'Ground station network expands to cover polar orbits', 0, 0, []],
    ['business', 'Two engine makers sign maintenance partnership', 0, 0, []],
    ['global-news', 'Report tracks rise in global military spending', 0, 0, ['usa']],
    ['asia', 'Coast guard commissions new offshore patrol vessel', 0, 0, []],
    ['europe', 'Training centre opens for armoured vehicle crews', 0, 0, ['ukraine']],
    ['us', 'Navy tests new torpedo guidance software at sea', 0, 0, ['usa']],
    ['middle-east-africa', 'Air force graduates first class of drone pilots', 0, 0, ['drones']],
    ['air', 'Trainer jet programme moves into production phase', 0, 0, []],
    ['land', 'Logistics unit tests autonomous supply trucks', 0, 0, ['drones']],
    ['asia', 'Defence white paper sets out five-year priorities', 0, 0, []],
    ['europe', 'Rail network upgraded to move heavy equipment faster', 0, 0, ['nato', 'ukraine']],
    ['naval', 'Mine countermeasures squadron trains in shallow water', 0, 0, []],
    ['us', 'Missile defence radar completes software upgrade', 0, 0, ['usa']],
    ['middle-east-africa', 'Regional forces hold joint search-and-rescue drill', 0, 0, []],
    ['asia', 'Helicopter fleet gets new night-vision systems', 0, 0, []],
    ['europe', 'Field hospital units practise rapid deployment', 0, 0, ['ukraine']],
    ['space', 'Space command opens new satellite tracking centre', 0, 0, []],
    ['business', 'Shipbuilder hires 800 workers for frigate work', 0, 0, []],
    ['land', 'Artillery school adds simulator training course', 0, 0, []],
    ['asia', 'Maritime patrol aircraft logs record flight hours', 0, 0, []],
    ['europe', 'Allied air policing mission rotates new squadron', 0, 0, ['nato', 'f-35']],
    ['global-news', 'Humanitarian corridor talks continue for second week', 0, 0, []],
    ['air', 'Air show cancelled due to weather, organisers say', 0, 0, []],
];
$excerpt = 'A short sample summary of the story, written to show how the excerpt looks on the homepage and on category pages.';
$summary = "First key point of the story.\nSecond key point, a little longer to show wrapping.\nThird key point.";
$body = "This is a sample story for testing the layout. It is not real news.\n\nThe second paragraph adds more text so the story page shows how long articles read.\n\nThe third paragraph ends the sample.";
$now = time();
foreach ($stories as $i => [$slugCat, $title, $live, $sticky, $tags]) {
    // Newest first: about 5 hours apart, so the first few days each get several stories.
    $published = gmdate('Y-m-d H:i:s', $now - 600 - $i * 5 * 3600);
    $updated = $i % 7 === 0 ? gmdate('Y-m-d H:i:s', $now - 300 - $i * 5 * 3600 + 1800) : $published;
    q('INSERT INTO posts (title, slug, excerpt, summary, body, credits, author_name, author_id, category_id, media_id, status, sticky, live_label, views, published_at, updated_at, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $title, 'sample-' . ($i + 1) . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'),
        $excerpt, $summary, $body, 'Reporting by ' . $authors[$i % 5] . '; Editing by ' . $authors[($i + 2) % 5],
        $authors[$i % 5], $authorId[$authors[$i % 5]], $cat[$slugCat], $media[$i % 12], 'published', $sticky, $live, (97 * ($i + 3)) % 1000, $published, $updated, $published,
    ]);
    $id = (int) db()->lastInsertId();
    foreach ($tags as $t) {
        q('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$id, $tag[$t]]);
    }
    // The first three stories get a photo gallery, so the article page has photos to slide through (task F10).
    if ($i < 3) {
        foreach ([1, 2] as $pos => $offset) {
            q('INSERT INTO post_gallery (post_id, media_id, position, caption, credit) VALUES (?, ?, ?, ?, ?)',
                [$id, $media[($i + $offset) % 12], $pos, 'Sample gallery caption ' . ($pos + 1), 'Sample photo desk']);
        }
    }
}

// The search index (task B11) is built from the sample stories, as it is on the real site.
$indexed = search_reindex_all();
echo $indexed['posts'] . ' sample stories indexed for search (' . $indexed['terms'] . " words).\n";

// Must never show: a story scheduled for later, and a draft.
foreach ([['Scheduled sample story for later', 'published', gmdate('Y-m-d H:i:s', $now + 2 * 86400)], ['Draft sample story', 'draft', gmdate('Y-m-d H:i:s', $now - 60)]] as [$title, $status, $at]) {
    q('INSERT INTO posts (title, slug, excerpt, author_name, category_id, media_id, status, published_at, updated_at, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$title, strtolower(str_replace(' ', '-', $title)), $excerpt, 'Alex Morgan', $cat['asia'], $media[0], $status, $at, $at, $at]);
}
echo count($stories) . " sample stories added (plus 1 scheduled and 1 draft that must not show).\n";
