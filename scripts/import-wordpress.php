<?php
declare(strict_types=1);

/**
 * Bring stories from the old WordPress site (globalarmspost.com) into the new site's own database (task B18).
 *
 * Usage (in git/):
 *   php -d extension=pdo_sqlite -d extension=gd -d extension=fileinfo scripts/import-wordpress.php global-news
 *   php ... scripts/import-wordpress.php global-news --dry-run      (reads and reports, saves nothing)
 *
 * The first argument is the old site's category address (/category/global-news/). The stories go into the new
 * site's category with the same address, as their main category; the old site's other categories that exist on
 * the new site (Asia, Europe, U.S. …) become extra categories.
 *
 * Where it saves (hard rule 12): the local site in live/: live/backend/storage/site.sqlite (config
 * test-data/local-site/config.php in the main checkout) and live/frontend/uploads/. So the stories stay for good
 * and go to Hostinger with the backend and frontend ZIPs. It uses the site's own save code: story_save(),
 * author_save(), tag_create(), media_store_source() (pictures become WebP + AVIF), clean_html() for the text.
 *
 * Safe to run again: a story whose address (slug) is already on the new site is skipped, never doubled or changed.
 * Kept: headline, address, text with its links, short text, main photo (with caption), pictures in the text that
 * were uploaded to globalarmspost.com, author, tags, the original published and updated times (UTC).
 * Left out: pictures in the text that the old site showed from other websites (BBC, Reuters …): the new site never
 * shows remote pictures (clean_html), and copying them is not ours to do. They are listed at the end.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const OLD_SITE = 'https://globalarmspost.com';
// Old WordPress category address => new site category address (extra categories). Others are left out.
const CATEGORY_MAP = [
    'asia' => 'asia', 'europe' => 'europe', 'u-s' => 'us', 'us' => 'us', 'middle-east-africa' => 'middle-east-africa',
    'middle-east' => 'middle-east-africa', 'air' => 'air', 'land' => 'land', 'naval' => 'naval', 'space' => 'space',
    'business' => 'business', 'global-news' => 'global-news',
];
// The WordPress sign-in name of the owner is not shown as a byline.
const AUTHOR_MAP = ['pasitviwat' => 'Staff Reporter'];

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$oldCat = (string) (array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')))[0] ?? '');
if ($oldCat === '' || preg_match('/^[a-z0-9-]+$/', $oldCat) !== 1) {
    fwrite(STDERR, "Usage: php scripts/import-wordpress.php <old-category-address> [--dry-run]\n");
    exit(2);
}

// The local site in live/ (scripts/live-lib.mjs: live/ is next to the main checkout's git/ folder).
$root = dirname(__DIR__);
$common = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --path-format=absolute --git-common-dir'));
$main = $common !== '' ? dirname($common) : $root;
$live = dirname($main) . '/live';
$config = $main . '/test-data/local-site/config.php';
foreach ([$config => 'the local site has no config yet: start it once (npm run site)', "$live/backend/app/bootstrap.php" => 'live/ is not built: npm run build:live'] as $f => $why) {
    if (!is_file($f)) {
        fwrite(STDERR, "Missing $f ($why).\n");
        exit(1);
    }
}
putenv('GAP_CONFIG=' . $config);
define('PUBLIC_DIR', "$live/frontend");
require "$live/backend/app/bootstrap.php";
require_once APP_DIR . '/lib/auth.php';    // audit()
require_once APP_DIR . '/lib/content.php'; // story_save(), author_save(), tag_create(), media_store_source()
foreach (['imagecreatefromstring', 'imagewebp'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "PHP GD is not loaded (run with -d extension=gd).\n");
        exit(1);
    }
}
migrate();

/** GET a URL; returns [status, body, headers]. */
function fetch(string $url): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'GlobalArmsPost-import/1.0',
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
            $p = explode(':', $line, 2);
            if (count($p) === 2) {
                $headers[strtolower(trim($p[0]))] = trim($p[1]);
            }
            return strlen($line);
        },
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA); // Windows: the system's certificates (PHP has no CA file)
    }
    $body = curl_exec($ch);
    if ($body === false) {
        fwrite(STDERR, '  (' . curl_error($ch) . ") $url\n");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? $body : '', $headers];
}

function text(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function is_own_picture(string $src): bool
{
    $host = strtolower((string) parse_url($src, PHP_URL_HOST));
    return in_array($host, ['globalarmspost.com', 'www.globalarmspost.com'], true) && str_contains((string) parse_url($src, PHP_URL_PATH), '/wp-content/uploads/');
}

/** Download a picture of the old site and store it with the site's own code. Returns the media id or 0. */
function store_picture(string $src, string $alt, string $caption, array &$problems): int
{
    static $done = [];
    if (isset($done[$src])) {
        return $done[$src];
    }
    [$status, $bytes] = fetch($src);
    if ($status !== 200 || $bytes === '') {
        $problems[] = "picture not loaded ($status): $src";
        return $done[$src] = 0;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'gapimg');
    file_put_contents($tmp, $bytes);
    try {
        $m = media_store_source($tmp, 'story', basename((string) parse_url($src, PHP_URL_PATH)), mb_substr($alt, 0, 300));
        if ($caption !== '') {
            q('UPDATE media SET caption = ? WHERE id = ?', [mb_substr($caption, 0, 600), (int) $m['id']]);
        }
        return $done[$src] = (int) $m['id'];
    } catch (Throwable $e) {
        $problems[] = 'picture not stored (' . $e->getMessage() . "): $src";
        return $done[$src] = 0;
    } finally {
        @unlink($tmp);
    }
}

/**
 * The old body as the editor stores it: own pictures become <figure data-media="ID" …>, pictures from other
 * websites are left out. clean_html() in story_save() does the rest of the cleaning.
 * @param list<string> $remote
 */
function convert_body(string $html, array &$remote, array &$problems): string
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?><div id="gap-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($doc);
    foreach (iterator_to_array($xp->query('//img') ?: []) as $img) {
        /** @var DOMElement $img */
        $fig = $img;
        for ($n = $img->parentNode; $n instanceof DOMElement && $n->getAttribute('id') !== 'gap-root'; $n = $n->parentNode) {
            if (strtolower($n->tagName) === 'figure') {
                $fig = $n;
                break;
            }
        }
        $src = html_entity_decode($img->getAttribute('src'), ENT_QUOTES | ENT_HTML5);
        $caption = '';
        if ($fig !== $img) {
            foreach ($fig->getElementsByTagName('figcaption') as $fc) {
                $caption = text($doc->saveHTML($fc) ?: '');
            }
        }
        $replacement = null;
        if (is_own_picture($src)) {
            $id = store_picture($src, text($img->getAttribute('alt')), $caption, $problems);
            if ($id > 0) {
                $replacement = $doc->createElement('figure');
                $replacement->setAttribute('data-media', (string) $id);
                $replacement->setAttribute('data-size', 'full');
                $replacement->setAttribute('data-caption', $caption);
                $replacement->setAttribute('data-credit', '');
            }
        } else {
            $remote[] = $src;
        }
        $parent = $fig->parentNode;
        if ($parent === null) {
            continue;
        }
        if ($replacement !== null) {
            $parent->replaceChild($replacement, $fig);
        } else {
            $parent->removeChild($fig);
        }
    }
    $root = $doc->getElementById('gap-root');
    $out = '';
    foreach ($root?->childNodes ?? [] as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

// ---- 1. The new site's categories
$cats = [];
foreach (q_all('SELECT id, slug FROM categories') as $c) {
    $cats[(string) $c['slug']] = (int) $c['id'];
}
$mainSlug = CATEGORY_MAP[$oldCat] ?? $oldCat;
if (!isset($cats[$mainSlug])) {
    fwrite(STDERR, "The new site has no category with the address '$mainSlug'. Add it in Admin → Categories first.\n");
    exit(1);
}
$mainId = $cats[$mainSlug];

// ---- 2. The old site's stories in that category (WordPress REST API, public data)
[$st, $body] = fetch(OLD_SITE . '/wp-json/wp/v2/categories?slug=' . rawurlencode($oldCat));
$found = json_decode($body, true);
if ($st !== 200 || !is_array($found) || !isset($found[0]['id'])) {
    fwrite(STDERR, "The old site has no category '$oldCat' (HTTP $st).\n");
    exit(1);
}
$wpCat = (int) $found[0]['id'];
$posts = [];
for ($page = 1, $pages = 1; $page <= $pages; $page++) {
    [$st, $body, $h] = fetch(OLD_SITE . "/wp-json/wp/v2/posts?categories=$wpCat&per_page=100&page=$page&_embed=1&orderby=date&order=asc");
    $list = json_decode($body, true);
    if ($st !== 200 || !is_array($list)) {
        fwrite(STDERR, "Could not read page $page of the old stories (HTTP $st).\n");
        exit(1);
    }
    $pages = max(1, (int) ($h['x-wp-totalpages'] ?? 1));
    array_push($posts, ...$list);
}
echo 'Old site, category ' . $oldCat . ': ' . count($posts) . ' stories (' . ($found[0]['count'] ?? '?') . " counted by WordPress).\n";
echo 'Into: ' . $config . "\n" . ($dry ? "DRY RUN: nothing is saved.\n" : '') . "\n";

// ---- 3. Each story through the site's own save code
$made = $skipped = 0;
$problems = $remoteAll = [];
foreach ($posts as $p) {
    $slug = slugify(rawurldecode((string) ($p['slug'] ?? '')));
    $title = text((string) ($p['title']['rendered'] ?? ''));
    if ($slug === '' || $title === '') {
        $problems[] = 'no headline or address: WordPress post ' . ($p['id'] ?? '?');
        continue;
    }
    $have = q_one('SELECT id, category_id FROM posts WHERE slug = ?', [$slug]);
    if ($have !== null) {
        // Already on the new site (brought over by hand): nothing of it is changed, it only also shows in this category.
        $note = '';
        if ((int) $have['category_id'] !== $mainId && q_one('SELECT post_id FROM post_categories WHERE post_id = ? AND category_id = ?', [(int) $have['id'], $mainId]) === null) {
            if (!$dry) {
                q('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)', [(int) $have['id'], $mainId]);
            }
            $note = ", now also in $mainSlug";
        }
        echo "  skip (already here as #{$have['id']}$note): $title\n";
        $skipped++;
        continue;
    }
    $terms = $p['_embedded']['wp:term'] ?? [];
    $extra = [];
    $tagNames = [];
    foreach ($terms as $group) {
        foreach ((array) $group as $t) {
            if (($t['taxonomy'] ?? '') === 'category' && isset(CATEGORY_MAP[$t['slug'] ?? ''], $cats[CATEGORY_MAP[$t['slug']]])) {
                $extra[] = $cats[CATEGORY_MAP[$t['slug']]];
            } elseif (($t['taxonomy'] ?? '') === 'post_tag') {
                $tagNames[] = text((string) $t['name']);
            }
        }
    }
    $authorName = text((string) ($p['_embedded']['author'][0]['name'] ?? ''));
    $authorName = AUTHOR_MAP[$authorName] ?? $authorName;
    $fm = $p['_embedded']['wp:featuredmedia'][0] ?? null;
    $remote = [];
    if ($dry) {
        echo "  would add: $title (" . substr((string) $p['date_gmt'], 0, 10) . ", $authorName, " . count($tagNames) . ' tags, ' . (is_array($fm) ? 'main photo' : 'no photo') . ")\n";
        $made++;
        continue;
    }

    $mediaId = 0;
    $caption = '';
    if (is_array($fm) && is_string($fm['source_url'] ?? null)) {
        $caption = text((string) ($fm['caption']['rendered'] ?? ''));
        $mediaId = is_own_picture($fm['source_url']) ? store_picture($fm['source_url'], text((string) ($fm['alt_text'] ?? '')) ?: $title, $caption, $problems) : 0;
    }
    $bodyHtml = convert_body((string) ($p['content']['rendered'] ?? ''), $remote, $problems);
    foreach ($remote as $r) {
        $remoteAll[] = "$title: $r";
    }
    $authorId = 0;
    if ($authorName !== '') {
        $a = q_one('SELECT id FROM authors WHERE LOWER(name) = LOWER(?)', [$authorName]);
        $authorId = $a !== null ? (int) $a['id'] : (int) (author_save(['name' => $authorName])['id'] ?? 0);
    }
    $tagIds = [];
    foreach (array_unique($tagNames) as $tn) {
        try {
            $tagIds[] = tag_create($tn)['id'];
        } catch (InputError $e) {
            $problems[] = "tag '$tn' left out: " . $e->getMessage();
        }
    }
    $publishedUtc = str_replace('T', ' ', substr((string) $p['date_gmt'], 0, 19));
    $modifiedUtc = str_replace('T', ' ', substr((string) ($p['modified_gmt'] ?? $p['date_gmt']), 0, 19));
    try {
        $saved = story_save([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => preg_replace('/\s*(\[(…|&hellip;|\.\.\.)\]|…)\s*$/u', '', text((string) ($p['excerpt']['rendered'] ?? ''))),
            'body' => $bodyHtml,
            'category_id' => $mainId,
            'extra_category_ids' => array_values(array_unique($extra)),
            'author_id' => $authorId,
            'media_id' => $mediaId,
            'featured_caption' => $caption,
            'tag_ids' => $tagIds,
            'status' => 'published',
            'published_local' => utc_to_local_input($publishedUtc),
        ], 0);
    } catch (Throwable $e) {
        $problems[] = "NOT SAVED: $title: " . $e->getMessage();
        continue;
    }
    // The exact old times (the editor's field has no seconds; "updated" is when it changed on the old site, not now).
    q('UPDATE posts SET published_at = ?, created_at = ?, updated_at = ? WHERE id = ?', [$publishedUtc, $publishedUtc, max($publishedUtc, $modifiedUtc), $saved['id']]);
    echo "  added #{$saved['id']}: $title ($publishedUtc" . ($mediaId > 0 ? ', photo' : '') . ")\n";
    $made++;
}
if (!$dry) {
    cache_clear(); // story_save() indexes each story for search and clears the cache; once more at the end
}

echo "\n" . ($dry ? 'Would add' : 'Added') . ": $made. Already here (skipped): $skipped.\n";
if ($remoteAll !== []) {
    echo "\nPictures from other websites left out (" . count($remoteAll) . "):\n  " . implode("\n  ", $remoteAll) . "\n";
}
if ($problems !== []) {
    echo "\nProblems (" . count($problems) . "):\n  " . implode("\n  ", $problems) . "\n";
    exit(1);
}
