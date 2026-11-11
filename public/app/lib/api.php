<?php
declare(strict_types=1);

/**
 * Read-only JSON API (task B15), for a future app or newsletter:
 *
 *   GET /api/posts?category=&tag=&page=&per=   public stories, newest first (per: 1-50, default 20)
 *   GET /api/posts/{slug}                      one public story, with its cleaned body and photos
 *   GET /api/categories                        every category with its public story count
 *
 * Never drafts, scheduled stories, trash or anything about users (no emails, no accounts). No cookies are read or
 * set (the admin session is never started here). Rate limited per address like /api/suggest (config 'api_limit',
 * per minute) and kept in the page cache, which a save clears and which expires at the next scheduled story.
 */

/** @param list<string> $parts the path after /api */
function api_handle(array $parts): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *'); // public data only, and no cookies are ever used here
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET');
        api_send(['error' => 'This API is read-only. Use GET.'], 405);
    }
    $limit = max(1, (int) (config()['api_limit'] ?? 120));
    if (!rate_limit_ok('api', $limit)) {
        header('Retry-After: 60');
        api_send(['error' => 'Too many requests. Please wait a minute.'], 429);
    }
    $route = $parts[0] ?? '';
    $known = ($route === 'posts' && count($parts) <= 2) || ($route === 'categories' && count($parts) === 1);
    if (!$known) {
        api_send(['error' => 'Unknown API address. Try /api/posts, /api/posts/{slug} or /api/categories.'], 404);
    }

    // Only the parameters the API knows are part of the cache key, so made-up ones cannot fill the cache.
    $query = [
        'category' => mb_substr((string) ($_GET['category'] ?? ''), 0, 120),
        'tag' => mb_substr((string) ($_GET['tag'] ?? ''), 0, 120),
        'page' => (string) max(1, min(1000, (int) ($_GET['page'] ?? 1))),
        'per' => (string) max(1, min(50, (int) ($_GET['per'] ?? 20))),
    ];
    $generation = cache_generation();
    $key = null;
    if (cache_enabled()) {
        $db = config()['db'] ?? [];
        $site = is_array($db) ? json_encode([$db['driver'] ?? '', $db['path'] ?? '', $db['host'] ?? '', $db['port'] ?? '', $db['name'] ?? '']) : '';
        $key = sha1(implode('|', ['api', $generation, (string) $site, request_host(), implode('/', $parts), http_build_query($query)]));
        $hit = cache_read($key);
        if ($hit !== null) {
            http_response_code($hit['status']);
            header('X-Cache: HIT');
            header('Cache-Control: public, max-age=60');
            echo $hit['html'];
            exit;
        }
        header('X-Cache: MISS');
    }

    [$data, $status] = match (true) {
        $route === 'categories' => [api_categories(), 200],
        isset($parts[1]) => api_post($parts[1]),
        default => [api_posts($query), 200],
    };
    $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($key !== null && $status === 200) {
        cache_store($key, $json, 200);
    }
    header('Cache-Control: ' . ($status === 200 ? 'public, max-age=60' : 'no-store'));
    http_response_code($status);
    echo $json;
    exit;
}

function api_send(mixed $data, int $status): never
{
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param array{category: string, tag: string, page: string, per: string} $q
 * @return array<string, mixed>
 */
function api_posts(array $q): array
{
    $page = (int) $q['page'];
    $per = (int) $q['per'];
    $opts = ['count' => $per, 'page' => $page];
    if ($q['category'] !== '') {
        $opts['categories'] = [$q['category']];
    }
    if ($q['tag'] !== '') {
        $opts['tags'] = [$q['tag']];
    }
    $rows = posts_query($opts);
    $total = posts_count($opts);
    $tags = api_tags_of(array_map(static fn (array $p): int => (int) $p['id'], $rows));
    return [
        'page' => $page,
        'per' => $per,
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
        'posts' => array_map(static fn (array $p): array => api_post_row($p, $tags[(int) $p['id']] ?? []), $rows),
    ];
}

/** @return array{0: array<string, mixed>, 1: int} */
function api_post(string $slug): array
{
    $p = post_by_slug($slug);
    if ($p === null) {
        return [['error' => 'No public story has this address.'], 404];
    }
    $row = api_post_row($p, api_tags_of([(int) $p['id']])[(int) $p['id']] ?? []);
    $row['body_html'] = body_html((string) $p['body']);
    $row['credits'] = (string) $p['credits'];
    $row['gallery'] = array_map(static function (array $g): array {
        $img = seo_image($g);
        return ['url' => $img['url'] ?? '', 'width' => $img['width'] ?? 0, 'height' => $img['height'] ?? 0,
                'caption' => (string) $g['caption'], 'credit' => (string) $g['credit']];
    }, post_gallery((int) $p['id']));
    return [['post' => $row], 200];
}

/** @return list<array<string, mixed>> */
function api_categories(): array
{
    $counts = [];
    foreach (q_all("SELECT category_id, COUNT(*) AS n FROM posts WHERE status = 'published' AND published_at <= ? GROUP BY category_id", [now_utc()]) as $r) {
        $counts[(int) $r['category_id']] = (int) $r['n'];
    }
    $out = [];
    foreach (q_all('SELECT c.id, c.name, c.slug, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY c.position, c.id') as $c) {
        $out[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug'],
                  'parent' => $c['parent'] === null ? null : (string) $c['parent'],
                  'url' => public_url('category', ['slug' => (string) $c['slug']]), 'stories' => $counts[(int) $c['id']] ?? 0];
    }
    return $out;
}

/**
 * @param list<int> $ids
 * @return array<int, list<array{name: string, slug: string}>>
 */
function api_tags_of(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $out = [];
    $rows = q_all('SELECT pt.post_id, t.name, t.slug FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.name', $ids);
    foreach ($rows as $r) {
        $out[(int) $r['post_id']][] = ['name' => (string) $r['name'], 'slug' => (string) $r['slug']];
    }
    return $out;
}

/**
 * The public fields of a story. Nothing else is ever sent (no status, no author email, no ids of users).
 * @param array<string, mixed> $p
 * @param list<array{name: string, slug: string}> $tags
 * @return array<string, mixed>
 */
function api_post_row(array $p, array $tags): array
{
    $m = post_media($p);
    $img = seo_image($m);
    return [
        'id' => (int) $p['id'],
        'title' => (string) $p['title'],
        'slug' => (string) $p['slug'],
        'url' => public_url('article', ['category' => (string) $p['cat_slug'], 'slug' => (string) $p['slug']]),
        'category' => ['slug' => (string) $p['cat_slug'], 'name' => (string) $p['cat_name']],
        'author' => (string) $p['author_name'] !== '' ? (string) $p['author_name'] : null,
        'excerpt' => (string) $p['excerpt'],
        'summary' => array_values(array_filter(array_map('trim', explode("\n", (string) $p['summary'])))),
        'tags' => $tags,
        'live' => (int) $p['live_label'] === 1,
        'published_at' => seo_time((string) $p['published_at']),
        'updated_at' => seo_time(max((string) $p['published_at'], (string) $p['updated_at'])),
        'image' => $img === null || $m === null ? null : $img + ['caption' => (string) $m['caption'], 'credit' => (string) $m['credit']],
    ];
}
