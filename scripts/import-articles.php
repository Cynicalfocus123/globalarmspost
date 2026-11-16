<?php
declare(strict_types=1);

/**
 * Add the articles of a newsbot list (D:\mstar companies\bot system\out\articles.md) to the new site (owner,
 * 2026-11-16: the publishers gave permission). For each link: headline, published time, main picture and text from
 * the publisher's page, saved as a published story in the category of its list heading, with the publisher in the
 * credits line and a link to the original at the end of the text.
 *
 * Usage (in git/):
 *   php -d extension=pdo_sqlite -d extension=gd scripts/import-articles.php "D:/mstar companies/bot system/out/articles.md" [--dry-run]
 *
 * Saves into the local site in live/ (hard rule 12) through the site's own code: story_save(), tag-free;
 * media_store_source() for the picture (WebP + AVIF); clean_html() for the text. Safe to run again: an article whose
 * address (made from its headline) is already on the site is skipped.
 * Headings -> categories: LAND, AIR, SPACE, NAVAL, GLOBAL NEWS, BUSINESS by name; under REGIONS: Africa and
 * Middle East -> Middle East and Africa, Asia-Pacific -> Asia, Europe -> Europe, North America -> U.S.,
 * Latin America (no own category) -> Global news.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const HEADING_MAP = [
    'land' => 'land', 'air' => 'air', 'space' => 'space', 'naval' => 'naval', 'global news' => 'global-news', 'business' => 'business',
    'africa' => 'middle-east-africa', 'middle east' => 'middle-east-africa', 'asia-pacific' => 'asia', 'asia' => 'asia', 'south asia' => 'asia',
    'europe' => 'europe', 'north america' => 'us', 'latin america' => 'global-news',
];
const LIST_TZ = 'Asia/Bangkok'; // the newsbot list writes its times in +07

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$listFile = (string) (array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')))[0] ?? '');
if ($listFile === '' || !is_file($listFile)) {
    fwrite(STDERR, "Usage: php scripts/import-articles.php <articles.md> [--dry-run]\n");
    exit(2);
}

// ---- The local site in live/ (as scripts/import-wordpress.php)
$root = dirname(__DIR__);
$common = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --path-format=absolute --git-common-dir'));
$main = $common !== '' ? dirname($common) : $root;
$live = dirname($main) . '/live';
$config = $main . '/test-data/local-site/config.php';
if (!is_file($config) || !is_file("$live/backend/app/bootstrap.php")) {
    fwrite(STDERR, "The local site in live/ is not ready (config or build missing).\n");
    exit(1);
}
putenv('GAP_CONFIG=' . $config);
define('PUBLIC_DIR', "$live/frontend");
require "$live/backend/app/bootstrap.php";
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/content.php';
if (!function_exists('imagecreatefromstring')) {
    fwrite(STDERR, "PHP GD is not loaded (run with -d extension=gd).\n");
    exit(1);
}
migrate();

/** GET a URL like a browser would; returns [status, body, final url]. */
function fetch(string $url, bool $picture = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 45,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
        // Pictures: JPEG, PNG or WebP only (the site's converter, PHP GD, cannot read AVIF).
        CURLOPT_HTTPHEADER => [$picture ? 'Accept: image/webp,image/jpeg,image/png,image/*;q=0.5' : 'Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$status, is_string($body) ? $body : '', $final];
}

/** Download the main picture and store it with the site's own code (WebP + AVIF). Returns the media id or 0. */
function store_picture(string $url, string $title, string $source, array &$problems): int
{
    [$st, $bytes] = fetch(html_entity_decode($url), true);
    if ($st !== 200 || !in_array(image_kind($bytes), ['jpeg', 'png', 'gif', 'webp'], true)) {
        $problems[] = "picture not loaded (HTTP $st, " . (image_kind($bytes) ?: 'not a picture') . "): $url";
        return 0;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'gapimg');
    file_put_contents($tmp, $bytes);
    try {
        $id = (int) media_store_source($tmp, 'story', basename((string) parse_url($url, PHP_URL_PATH)) ?: 'picture', mb_substr($title, 0, 300))['id'];
        q('UPDATE media SET credit = ? WHERE id = ?', [mb_substr($source, 0, 200), $id]);
        return $id;
    } catch (Throwable $e) {
        $problems[] = "picture not stored ({$e->getMessage()}): $url";
        return 0;
    } finally {
        @unlink($tmp);
    }
}

function plain(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** Article objects in the page's JSON-LD (also inside @graph). @return list<array<string, mixed>> */
function jsonld_articles(DOMXPath $xp): array
{
    $out = [];
    foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $s) {
        $data = json_decode(trim($s->textContent), true);
        $stack = is_array($data) ? [$data] : [];
        while ($stack !== []) {
            $d = array_pop($stack);
            if (!is_array($d)) {
                continue;
            }
            if (array_is_list($d)) {
                array_push($stack, ...$d);
                continue;
            }
            if (isset($d['@graph'])) {
                $stack[] = $d['@graph'];
            }
            $type = implode(' ', (array) ($d['@type'] ?? []));
            if (preg_match('/Article|BlogPosting|Report/i', $type) === 1) {
                $out[] = $d;
            }
        }
    }
    return $out;
}

function meta(DOMXPath $xp, string $key): string
{
    foreach (["//meta[@property='$key']", "//meta[@name='$key']", "//meta[@itemprop='$key']"] as $q) {
        $n = $xp->query($q)?->item(0);
        if ($n instanceof DOMElement && trim($n->getAttribute('content')) !== '') {
            return trim($n->getAttribute('content'));
        }
    }
    return '';
}

/** The story's paragraphs from the page: the container with the most paragraph text. @return list<string> HTML blocks */
function body_blocks(DOMXPath $xp): array
{
    $junk = '/^(advertisement|sponsored|subscribe|sign up|read more|read also|also read|related|follow us|follow our|share this|click here|newsletter|copyright|©|all rights reserved|watch:|listen to|this story|support |we use cookies|photo:|image:|published:|track latest news|make .{2,40} a preferred source|unlock exclusive|explore leadership|popular$|trending$|most read|essential context|this article was translated)/i';
    $skip = 'ancestor::nav or ancestor::aside or ancestor::footer or ancestor::header or ancestor::form or ancestor::figure or ancestor::figcaption'
        . ' or ancestor::*[contains(@class,"related") or contains(@class,"newsletter") or contains(@class,"share") or contains(@class,"comment") or contains(@class,"promo") or contains(@class,"caption") or contains(@class,"author-bio")]';
    $candidates = iterator_to_array($xp->query('//article | //*[@itemprop="articleBody"] | //main | //*[contains(@class,"article-body") or contains(@class,"entry-content") or contains(@class,"story-body") or contains(@class,"post-content") or contains(@class,"article__body") or contains(@class,"content-body") or contains(@class,"single_content") or contains(@class,"single-content") or contains(@class,"article-content") or contains(@class,"story-content") or contains(@class,"article__content") or contains(@class,"post-body") or contains(@class,"news-content") or contains(@class,"td-post-content")]') ?: []);
    $candidates[] = $xp->query('//body')?->item(0);
    $scored = [];
    foreach ($candidates as $c) {
        if (!$c instanceof DOMElement) {
            continue;
        }
        $len = 0;
        foreach ($xp->query(".//p[not($skip)]", $c) ?: [] as $p) {
            $t = plain($p->textContent);
            if (mb_strlen($t) >= 60) {
                $len += mb_strlen($t);
            }
        }
        $scored[] = [$c, $len, $c->getElementsByTagName('*')->length];
    }
    // The whole <body> only when no story container holds real text (it also holds the sidebars' other stories).
    $inner = array_values(array_filter($scored, static fn (array $s): bool => strtolower($s[0]->nodeName) !== 'body'));
    if (max(array_column($inner, 1) ?: [0]) >= 800) {
        $scored = $inner;
    }
    $max = max(array_column($scored, 1) ?: [0]);
    if ($max === 0) {
        return [];
    }
    // The smallest container that still holds almost all of the story's text (not the whole page with its extras).
    $best = null;
    foreach ($scored as [$c, $len, $size]) {
        if ($len >= $max * 0.9 && ($best === null || $size < $best[1])) {
            $best = [$c, $size];
        }
    }
    $best = $best[0];
    $blocks = [];
    $seen = [];
    foreach ($xp->query(".//p[not($skip)] | .//h2[not($skip)] | .//h3[not($skip)] | .//li[not($skip) and ancestor::ul and not(.//a[string-length(normalize-space(.)) = string-length(normalize-space(..))])]", $best) ?: [] as $n) {
        $t = plain($n->textContent);
        $tag = strtolower($n->nodeName);
        if ($t === '' || isset($seen[$t]) || preg_match($junk, $t) === 1) {
            continue;
        }
        if ($tag === 'p' && mb_strlen($t) < 40) {
            continue;
        }
        if ($tag === 'li' && mb_strlen($t) < 30) {
            continue;
        }
        $seen[$t] = true;
        $blocks[] = match ($tag) {
            'h2', 'h3' => mb_strlen($t) <= 120 ? "<h2>" . e($t) . '</h2>' : '<p>' . e($t) . '</p>',
            'li' => '<ul><li>' . e($t) . '</li></ul>',
            default => '<p>' . e($t) . '</p>',
        };
    }
    // Headings before the first paragraph or after the last one ("Popular", "More stories") are page furniture.
    while ($blocks !== [] && str_starts_with((string) end($blocks), '<h2>')) {
        array_pop($blocks);
    }
    while ($blocks !== [] && str_starts_with($blocks[0], '<h2>')) {
        array_shift($blocks);
    }
    return $blocks;
}

function to_utc(string $when, string $fallbackTz = 'UTC'): ?string
{
    if (trim($when) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($when, new DateTimeZone($fallbackTz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function first_string(mixed $v): string
{
    if (is_string($v)) {
        return $v;
    }
    if (is_array($v)) {
        if (isset($v['url']) && is_string($v['url'])) {
            return $v['url'];
        }
        if (isset($v['name']) && is_string($v['name'])) {
            return $v['name'];
        }
        foreach ($v as $x) {
            $s = first_string($x);
            if ($s !== '') {
                return $s;
            }
        }
    }
    return '';
}

// ---- 1. Read the list
$items = [];
$heading = $sub = '';
foreach (file($listFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^## (.+?)\s*\(\d+\)\s*$/u', $line, $m) === 1) {
        $heading = strtolower(trim($m[1]));
        $sub = '';
    } elseif (preg_match('/^### (.+?)(?:\s*\(\d+\))?\s*$/u', $line, $m) === 1) {
        $sub = strtolower(trim($m[1]));
    } elseif (preg_match('/^- \[(.+)\]\((https?:\/\/[^)\s]+)\) — (.+) · (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s*$/u', $line, $m) === 1) {
        $key = $heading === 'regions' ? $sub : $heading;
        $cat = HEADING_MAP[$key] ?? '';
        // One story per link: the first heading it is listed under is its main category (the topics come before
        // REGIONS), every other heading an extra category, so it shows in each of those sections too.
        if (isset($items[$m[2]])) {
            if ($cat !== '' && $cat !== $items[$m[2]]['cat'] && !in_array($cat, $items[$m[2]]['extra'], true)) {
                $items[$m[2]]['extra'][] = $cat;
            }
            continue;
        }
        $items[$m[2]] = ['title' => trim($m[1]), 'url' => $m[2], 'source' => trim($m[3]), 'date' => $m[4], 'cat' => $cat, 'extra' => []];
    }
}
$items = array_values($items);
$cats = [];
foreach (q_all('SELECT id, slug FROM categories') as $c) {
    $cats[(string) $c['slug']] = (int) $c['id'];
}
echo count($items) . " articles in the list. Into: $config\n" . ($dry ? "DRY RUN: nothing is saved.\n" : '') . "\n";

// ---- 2. Each article
$made = $skipped = 0;
$problems = $ready = [];
foreach ($items as $it) {
    if (!isset($cats[$it['cat']])) {
        $problems[] = "no category for '{$it['title']}' ({$it['cat']})";
        continue;
    }
    [$st, $html, $final] = fetch($it['url']);
    $doc = new DOMDocument();
    if ($st !== 200 || $html === '' || !@$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING)) {
        $problems[] = "page not loaded (HTTP $st): {$it['url']}";
        continue;
    }
    $xp = new DOMXPath($doc);
    $ld = jsonld_articles($xp)[0] ?? [];
    // The list's headline, unless it is a whole sentence (over 150 characters): then the page's own headline.
    $pageTitle = plain((string) ($ld['headline'] ?? '')) ?: plain(meta($xp, 'og:title'));
    $title = mb_strlen($it['title']) > 150 && mb_strlen($pageTitle) >= 20 ? $pageTitle : $it['title'];
    $slug = substr(slugify($title), 0, 100) ?: 'story';
    $extraIds = array_values(array_unique(array_map(static fn (string $c): int => $cats[$c] ?? 0, $it['extra'])));
    $extraIds = array_values(array_filter($extraIds, static fn (int $id): bool => $id > 0 && $id !== $cats[$it['cat']]));
    $have = q_one('SELECT id, media_id, category_id FROM posts WHERE slug = ? OR title = ?', [$slug, $title]);
    if ($have !== null) {
        // Already here: nothing of it changes, except a main picture that a run before could not load, and the
        // list's other headings, which it now also shows under (extra categories it does not have yet).
        $note = '';
        foreach ($extraIds as $cid) {
            if ($cid !== (int) $have['category_id'] && q_one('SELECT post_id FROM post_categories WHERE post_id = ? AND category_id = ?', [(int) $have['id'], $cid]) === null) {
                if (!$dry) {
                    q('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)', [(int) $have['id'], $cid]);
                }
                $note .= ', + ' . array_search($cid, $cats, true);
            }
        }
        if ($have['media_id'] === null && !$dry) {
            $img = meta($xp, 'og:image') ?: meta($xp, 'twitter:image');
            $mid = $img === '' ? 0 : store_picture($img, $title, $it['source'], $problems);
            if ($mid > 0) {
                q('UPDATE posts SET media_id = ?, featured_credit = ? WHERE id = ?', [$mid, mb_substr($it['source'], 0, 200), (int) $have['id']]);
                $note = ', picture added';
            }
        }
        echo "  skip (already here as #{$have['id']}$note): $title\n";
        $skipped++;
        continue;
    }
    $published = to_utc(first_string($ld['datePublished'] ?? '') ?: meta($xp, 'article:published_time') ?: meta($xp, 'datePublished'))
        ?? to_utc($it['date'], LIST_TZ);
    $modified = to_utc(first_string($ld['dateModified'] ?? '') ?: meta($xp, 'article:modified_time')) ?? $published;
    // A page time in the future, or days away from the list's time (a site-wide date, not the story's), is not used.
    $listTime = to_utc($it['date'], LIST_TZ);
    if ($published === null || $published > now_utc() || ($listTime !== null && abs(strtotime($published) - strtotime($listTime)) > 3 * 86400)) {
        $published = $listTime;
        $modified = $listTime;
    }
    // og:image first: the JSON-LD "image" is often only a reference (#primaryimage) or the page itself.
    $image = meta($xp, 'og:image') ?: meta($xp, 'twitter:image') ?: first_string($ld['image'] ?? '');
    if ($image !== '' && !preg_match('~^https?://~', $image)) {
        $image = (string) preg_replace('~(?<!:)/+~', '/', rtrim((string) preg_replace('~^(https?://[^/]+).*$~', '$1', $final), '/') . '/' . ltrim($image, '/'));
    }
    $author = plain(first_string($ld['author'] ?? '')) ?: plain(meta($xp, 'author'));
    if (preg_match('~^(https?://|/)~', $author) === 1 || mb_strlen($author) > 80 || strcasecmp(str_replace(' ', '', $author), str_replace(' ', '', $it['source'])) === 0) {
        $author = '';
    }
    $excerpt = plain(meta($xp, 'og:description')) ?: plain((string) ($ld['description'] ?? ''));
    $blocks = [];
    $ldBody = trim((string) ($ld['articleBody'] ?? ''));
    if (mb_strlen($ldBody) > 400) {
        foreach (preg_split('/\n\s*\n|\r\n\s*\r\n|(?<=[.!?”"])\s{2,}/u', $ldBody) ?: [] as $para) {
            $t = plain($para);
            if ($t !== '') {
                $blocks[] = '<p>' . e($t) . '</p>';
            }
        }
        if (count($blocks) < 3) { // one long block: the page's own paragraphs are better
            $page = body_blocks($xp);
            $blocks = count($page) >= 3 ? $page : $blocks;
        }
    } else {
        $blocks = body_blocks($xp);
    }
    // Never the headline again.
    $blocks = array_values(array_filter($blocks, static fn (string $b): bool => plain($b) !== $title && plain($b) !== $it['title']));
    $ready[] = compact('it', 'title', 'slug', 'published', 'modified', 'image', 'author', 'excerpt', 'blocks', 'extraIds');
}

// ---- 3. A paragraph that two different articles of this list both have is a site's sidebar or box, not the story.
$seenIn = [];
foreach ($ready as $n => $r) {
    foreach (array_unique(array_map('plain', $r['blocks'])) as $t) {
        $seenIn[$t][$n] = true;
    }
}
foreach ($ready as $r) {
    extract($r);
    $blocks = array_values(array_filter($blocks, static fn (string $b): bool => count($seenIn[plain($b)] ?? []) < 2));
    $chars = mb_strlen(plain(implode(' ', $blocks)));
    if ($chars < 300) {
        $problems[] = "text not found (only $chars characters): {$it['url']}";
        continue;
    }
    $source = $it['source'];
    $blocks[] = '<p>Originally published by ' . e($source) . ': <a href="' . e($it['url']) . '">' . e($title) . '</a></p>';
    if ($dry) {
        echo "  would add [{$it['cat']}] $title\n      $published · " . ($author ?: '-') . " · $chars chars · " . (count($blocks) - 1) . ' blocks · picture: ' . ($image !== '' ? 'yes' : 'NO') . "\n";
        if (in_array('--show', $args, true)) {
            $cut = static fn (string $b): string => mb_substr(plain($b), 0, 110);
            echo '      FIRST: ' . $cut($blocks[0]) . "\n      LAST:  " . $cut($blocks[max(0, count($blocks) - 2)]) . "\n";
        }
        $made++;
        continue;
    }

    $mediaId = $image === '' ? 0 : store_picture($image, $title, $source, $problems);
    $authorId = 0;
    if ($author !== '') {
        $a = q_one('SELECT id FROM authors WHERE LOWER(name) = LOWER(?)', [$author]);
        $authorId = $a !== null ? (int) $a['id'] : (int) (author_save(['name' => $author])['id'] ?? 0);
    }
    try {
        $saved = story_save([
            'title' => $title, 'slug' => $slug, 'excerpt' => mb_substr($excerpt, 0, 1000), 'body' => implode("\n", $blocks),
            'credits' => 'Source: ' . $source, 'category_id' => $cats[$it['cat']], 'extra_category_ids' => $extraIds, 'author_id' => $authorId, 'media_id' => $mediaId,
            'featured_credit' => $mediaId > 0 ? $source : '', 'status' => 'published',
            'published_local' => utc_to_local_input($published ?? now_utc()),
        ], 0);
    } catch (Throwable $e) {
        $problems[] = "NOT SAVED: $title: " . $e->getMessage();
        continue;
    }
    $pub = $published ?? now_utc();
    q('UPDATE posts SET published_at = ?, created_at = ?, updated_at = ? WHERE id = ?', [$pub, $pub, max($pub, (string) $modified), $saved['id']]);
    echo "  added #{$saved['id']} [{$it['cat']}] $title ($pub" . ($mediaId > 0 ? ', picture' : ', NO picture') . ", $chars chars)\n";
    $made++;
}
if (!$dry) {
    cache_clear();
}
echo "\n" . ($dry ? 'Would add' : 'Added') . ": $made. Already here: $skipped. Problems: " . count($problems) . "\n";
if ($problems !== []) {
    echo '  ' . implode("\n  ", $problems) . "\n";
}
