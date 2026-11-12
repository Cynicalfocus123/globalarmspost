<?php
declare(strict_types=1);

// Front controller: every page request comes here (.htaccess on Hostinger, tests/php-router.php locally).
// This folder is the web folder. The code is in app/ here (git/ copy) or in ../backend/app (live/: frontend/ goes
// into public_html, backend/ next to it, so the code and the server's data are never inside public_html).
define('PUBLIC_DIR', __DIR__);
require is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ . '/app/bootstrap.php' : dirname(__DIR__) . '/backend/app/bootstrap.php';

send_security_headers();
header('Content-Type: text/html; charset=utf-8');

if (!is_installed()) {
    http_response_code(503);
    echo view('not_installed');
    exit;
}

$path = request_path();
$parts = $path === '/' ? [] : explode('/', substr($path, 1));
$page = max(1, (int) ($_GET['page'] ?? 1));

// Suggestions while typing in the search box: small JSON, never cached, rate limited (task B11/F7).
if (($parts[0] ?? '') === 'api' && ($parts[1] ?? '') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(search_suggest_limited((string) ($_GET['q'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Read-only JSON API (task B15): /api/posts, /api/posts/{slug}, /api/categories. No cookies, rate limited, cached.
if (($parts[0] ?? '') === 'api') {
    api_handle(array_slice($parts, 1));
}

// SEO (task F12): the sitemap and robots.txt (reserved names), and 301s from the old WordPress addresses.
if ($parts === ['sitemap.xml']) {
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    // Kept in the page cache like the pages (security review, step 5): building it reads every public story, so
    // it must not be rebuilt on every request. A save clears it; it expires at the next scheduled story.
    $key = cache_enabled() ? sha1('sitemap|' . cache_generation() . '|' . (string) json_encode(config()['db'] ?? []) . '|' . request_host()) : null;
    $hit = $key === null ? null : cache_read($key);
    if ($hit !== null) {
        header('X-Cache: HIT');
        echo $hit['html'];
        exit;
    }
    $xml = sitemap_xml();
    if ($key !== null) {
        header('X-Cache: MISS');
        cache_store($key, $xml);
    }
    echo $xml;
    exit;
}
if ($parts === ['robots.txt']) {
    header('Content-Type: text/plain; charset=utf-8');
    echo robots_txt();
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($old = wp_redirect_target($parts)) !== null) {
    header('Location: ' . $old, true, 301);
    exit;
}

// Page cache (task B12): a public page rendered once is served from storage/cache/pages until the next
// scheduled story's time. Admin pages, API responses and POSTs never get here (see cache_key()).
$cacheKey = cache_key();
if ($cacheKey !== null) {
    $hit = cache_read($cacheKey);
    if ($hit !== null) {
        if ($hit['post'] > 0) {
            count_view($hit['post']); // a served page is still a read
        }
        http_response_code($hit['status']);
        header('X-Cache: HIT');
        echo $hit['html'];
        exit;
    }
    header('X-Cache: MISS');
}

/**
 * Send a page. The git/ copy's '#' links load pages into the current one: those requests send
 * X-Partial: 1 and get only the main content (title in X-Page-Title).
 * @param array<string, mixed> $meta SEO tags (app/lib/seo.php); empty = the site's tagline, no canonical
 */
$send = function (string $title, string $body, int $status = 200, int $postId = 0, array $meta = []) use ($cacheKey): never {
    http_response_code($status);
    $partial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1';
    if ($partial) {
        header('X-Page-Title: ' . rawurlencode($title === '' ? setting('site_name', 'Global Arms Post') : $title . ' | ' . setting('site_name', 'Global Arms Post')));
        $html = $body;
    } else {
        // SEO tags (task F12): every page has its description, canonical address and Open Graph / X card tags.
        $html = view('layout', ['title' => $title, 'body' => $body, 'meta' => $meta]);
    }
    if ($cacheKey !== null) {
        cache_store($cacheKey, $html, $status, $postId);
    }
    echo $html;
    exit;
};

// Admin (same address in both copies): sign-in, stories, homepage builder ...
if (($parts[0] ?? '') === 'admin') {
    require APP_DIR . '/admin/router.php';
    admin_route(array_slice($parts, 1));
    exit;
}

if ($parts === [] || $parts === ['home']) {
    $send('', view('home'), 200, 0, seo_page_meta(absolute_url('/')));
}

// Search page: /search?q= in live/, /#search?q= in git/ (reserved name, so no category can take it).
if (count($parts) === 1 && $parts[0] === 'search') {
    $q = (string) ($_GET['q'] ?? '');
    $res = search_results($q, [
        'category' => (string) ($_GET['cat'] ?? ''),
        'from' => (string) ($_GET['from'] ?? ''),
        'to' => (string) ($_GET['to'] ?? ''),
        'sort' => (string) ($_GET['sort'] ?? 'match'),
        'page' => $page,
    ]);
    // Search pages are for visitors, not for search engines' indexes.
    $send($res['q'] === '' ? 'Search' : 'Search: ' . $res['q'], view('search', ['res' => $res]), 200, 0, seo_page_meta(public_url('search'), '', false));
}

// Tag page: /tag/usa (both copies; it is a reserved name too).
if (count($parts) === 2 && $parts[0] === 'tag') {
    $tag = q_one('SELECT id, name, slug FROM tags WHERE slug = ?', [$parts[1]]);
    if ($tag !== null) {
        $posts = posts_query(['tags' => [(string) $tag['slug']], 'count' => 20, 'page' => $page]);
        $send('Tag: ' . (string) $tag['name'], view('tag', ['tag' => $tag, 'posts' => $posts, 'page' => $page]), 200, 0,
            seo_page_meta(public_url('tag', ['slug' => (string) $tag['slug'], 'page' => (string) $page]), 'Stories about ' . (string) $tag['name'] . ' from ' . setting('site_name', 'Global Arms Post') . '.'));
    }
}

if (count($parts) === 1 && !in_array($parts[0], reserved_slugs(), true)) {
    $category = q_one('SELECT c.id, c.name, c.slug, p.name AS parent_name, p.slug AS parent_slug
                         FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.slug = ?', [$parts[0]]);
    if ($category !== null) {
        $send((string) $category['name'], view('category', ['category' => $category, 'page' => $page]), 200, 0,
            seo_page_meta(public_url('category', ['slug' => (string) $category['slug'], 'page' => (string) $page]), (string) $category['name'] . ': ' . setting('tagline')));
    }
}

// Story: /{category}/{slug} in live/, /news/{slug} for the git/ copy's '#news/{slug}' links.
if (count($parts) === 2) {
    $post = post_by_slug($parts[1]);
    if ($post !== null && ($parts[0] === 'news' || $parts[0] === $post['cat_slug'])) {
        if ($parts[0] !== 'news' || build_info()['link_mode'] === 'hash') {
            count_view((int) $post['id']);
            $send((string) $post['title'], view('article', ['p' => $post]), 200, (int) $post['id'], seo_post_meta($post));
        }
    }
}

$send('Page not found', view('not_found'), 404, 0, seo_page_meta('', '', false));
