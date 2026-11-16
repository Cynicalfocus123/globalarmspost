<?php
declare(strict_types=1);

/**
 * Add stories exported from the local site (scripts/export-stories.php) to this site's database, through the site's
 * own story_save() (owner, 2026-11-16: the real site keeps its own database and admin sign-in; only stories are
 * added). Nothing is deleted or replaced: a story whose address is already here is left as it is, except that it is
 * ticked "Featured" when the file says so. Accounts, settings and every other story are never touched.
 *
 *   php backend/app/cli/import-stories.php <stories.json> [--dry-run]
 *
 * The pictures are not in the file: they come with the frontend ZIP (public_html/uploads). A picture row is added
 * once per file address; a missing picture file is reported. Runs only on the command line; the web answers 404.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// The web folder, for the picture check: next to backend/ it is public_html/ (Hostinger) or frontend/ (live/).
if (getenv('GAP_PUBLIC_DIR') === false) {
    foreach (['public_html', 'frontend'] as $web) {
        if (is_dir(dirname(__DIR__, 3) . '/' . $web)) {
            putenv('GAP_PUBLIC_DIR=' . dirname(__DIR__, 3) . '/' . $web);
            break;
        }
    }
}
require dirname(__DIR__) . '/bootstrap.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/content.php';

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$file = (string) (array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? '');
$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($data) || !is_array($data['stories'] ?? null)) {
    fwrite(STDERR, "Usage: php app/cli/import-stories.php <stories.json> [--dry-run]\n(the file from scripts/export-stories.php)\n");
    exit(2);
}

/** The picture row for this file address: the one already here, or a new one (also reports a missing file). */
function import_media(?array $m, bool $dry, array &$notes): int
{
    if ($m === null || (string) ($m['path'] ?? '') === '') {
        return 0;
    }
    $path = (string) $m['path'];
    $first = explode(',', (string) $m['widths'])[0];
    if (!is_file(PUBLIC_DIR . '/' . $path . '-' . $first . '.webp')) {
        $notes[] = "picture file missing on this server: $path-$first.webp (upload the frontend ZIP first)";
    }
    $have = q_one('SELECT id FROM media WHERE path = ?', [$path]);
    if ($have !== null || $dry) {
        return (int) ($have['id'] ?? 0);
    }
    q('INSERT INTO media (path, widths, has_avif, width, height, alt, caption, credit, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $path, (string) $m['widths'], (int) $m['has_avif'], (int) $m['width'], (int) $m['height'], (string) $m['alt'],
        $m['caption'] ?? null, (string) $m['credit'], (string) ($m['created_at'] ?? now_utc()),
    ]);
    return (int) db()->lastInsertId();
}

$cats = [];
foreach (q_all('SELECT id, slug FROM categories') as $c) {
    $cats[(string) $c['slug']] = (int) $c['id'];
}
$added = $kept = 0;
$notes = [];
echo count($data['stories']) . ' stories in the file.' . ($dry ? ' DRY RUN: nothing is saved.' : '') . "\n";
foreach ($data['stories'] as $s) {
    $title = (string) ($s['title'] ?? '');
    $have = q_one('SELECT id, featured FROM posts WHERE slug = ?', [(string) ($s['slug'] ?? '')]);
    if ($have !== null) {
        $note = '';
        if (!empty($s['featured']) && (int) $have['featured'] !== 1) {
            if (!$dry) {
                q('UPDATE posts SET featured = 1 WHERE id = ?', [(int) $have['id']]);
            }
            $note = ', now Featured';
        }
        echo "  already here as #{$have['id']}$note: $title\n";
        $kept++;
        continue;
    }
    if (!isset($cats[(string) $s['category']])) {
        $notes[] = "NOT ADDED (no category '{$s['category']}' here): $title";
        continue;
    }
    $mediaId = import_media($s['media'] ?? null, $dry, $notes);
    $gallery = [];
    foreach ((array) ($s['gallery'] ?? []) as $g) {
        $gid = import_media($g['media'] ?? null, $dry, $notes);
        if ($gid > 0) {
            $gallery[] = ['media_id' => $gid, 'caption' => (string) $g['caption'], 'credit' => (string) $g['credit']];
        }
    }
    if ($dry) {
        echo "  would add [{$s['category']}]" . (!empty($s['featured']) ? ' Featured' : '') . ": $title\n";
        $added++;
        continue;
    }
    $authorId = 0;
    if ((string) ($s['author'] ?? '') !== '') {
        $a = q_one('SELECT id FROM authors WHERE LOWER(name) = LOWER(?)', [(string) $s['author']]);
        $authorId = $a !== null ? (int) $a['id'] : (int) (author_save(['name' => (string) $s['author']])['id'] ?? 0);
    }
    $tagIds = [];
    foreach ((array) ($s['tags'] ?? []) as $name) {
        $t = q_one('SELECT id FROM tags WHERE LOWER(name) = LOWER(?)', [(string) $name]);
        $tagIds[] = $t !== null ? (int) $t['id'] : (int) tag_create((string) $name)['id'];
    }
    try {
        $saved = story_save([
            'title' => $title, 'slug' => (string) $s['slug'], 'excerpt' => (string) $s['excerpt'], 'summary' => (string) $s['summary'],
            'body' => (string) $s['body'], 'credits' => (string) $s['credits'], 'category_id' => $cats[(string) $s['category']],
            'extra_category_ids' => array_values(array_filter(array_map(static fn ($c): int => $cats[(string) $c] ?? 0, (array) ($s['extra_categories'] ?? [])))),
            'author_id' => $authorId, 'tag_ids' => $tagIds, 'media_id' => $mediaId, 'gallery' => $gallery,
            'featured_caption' => (string) ($s['featured_caption'] ?? ''), 'featured_credit' => (string) ($s['featured_credit'] ?? ''),
            'status' => (string) $s['status'] === 'published' ? 'published' : 'draft', 'sticky' => !empty($s['sticky']),
            'featured' => !empty($s['featured']), 'live_label' => !empty($s['live_label']),
            'published_local' => utc_to_local_input((string) $s['published_at']),
        ], 0);
    } catch (Throwable $e) {
        $notes[] = "NOT ADDED ($title): " . $e->getMessage();
        continue;
    }
    // The original times (story_save() stores minutes only and sets "updated" to now).
    q('UPDATE posts SET published_at = ?, created_at = ?, updated_at = ? WHERE id = ?',
        [(string) $s['published_at'], (string) ($s['created_at'] ?? $s['published_at']), (string) ($s['updated_at'] ?? $s['published_at']), $saved['id']]);
    echo "  added #{$saved['id']} [{$s['category']}]" . (!empty($s['featured']) ? ' Featured' : '') . ": $title\n";
    $added++;
}
if (!$dry) {
    cache_clear();
}
echo "\n" . ($dry ? 'Would add' : 'Added') . ": $added. Already here: $kept. Notes: " . count($notes) . "\n";
foreach ($notes as $n) {
    echo "  $n\n";
}
