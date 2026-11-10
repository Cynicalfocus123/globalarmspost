<?php
declare(strict_types=1);

/**
 * Page cache (task B12). A public page is rendered once and then served from one file in storage/cache/pages.
 *
 *   - a cached page expires at the next scheduled story's time, so a scheduled story appears at its exact
 *     time even though the page came from the cache (plus a short time to live, set in the admin);
 *   - every admin save clears the cache (cache_clear() is called from story_save(), stories_set_status(),
 *     tag_create(), category_create(), author_save(), the homepage builder and the settings page);
 *   - cache_clear() also starts a new generation, so a page that was being rendered from the old data while
 *     the cache was cleared is never served;
 *   - admin pages, API responses, POSTs and anything with an unexpected query parameter are never cached;
 *   - the file name is a hash, so nothing outside storage/cache/pages can ever be written or read.
 *
 * Only generated files in storage/cache/pages are ever removed (never project or upload files).
 */

function cache_enabled(): bool
{
    // ?cache=1 forces the cache on for one page, even when it is switched off in the admin.
    if (isset($_GET['cache']) && $_GET['cache'] === '1') {
        return true;
    }
    // The same on every copy: the live site, local work and the test runs (owner, 2026-11-05). Tests that
    // change the database directly clear the cache through tests/db.php, as an admin save does.
    return setting('cache_enabled', '1') === '1';
}

function cache_dir(): string
{
    return PUBLIC_DIR . '/storage/cache/pages';
}

/**
 * The cache key for this request, or null when this request must not be cached.
 * The git/ and live/ copies get different keys (link_mode), and so does a partial (X-Partial) response.
 */
function cache_key(): ?string
{
    // Read the generation before anything else of the database (settings included): a page whose data was
    // read before a cache_clear() is then stored under the old generation, which no request reads again.
    $generation = cache_generation();
    if (!cache_enabled()) {
        return null;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return null;
    }
    $path = request_path();
    if ($path === '/admin' || str_starts_with($path, '/admin/') || str_starts_with($path, '/api/')) {
        return null;
    }
    if (count($_GET) > 5) {
        return null;
    }
    $allowed = ['page', 'q', 'cat', 'from', 'to', 'sort', 'cache'];
    $query = [];
    foreach ($_GET as $key => $value) {
        if (!in_array((string) $key, $allowed, true) || !is_string($value)) {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > 120) {
            return null;
        }
        if ($key === 'cache') {
            continue; // ?cache=1 only turns the cache on; it is not part of the page
        }
        $query[(string) $key] = $value;
    }
    ksort($query);
    $partial = ($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1' ? 'p' : 'f';
    $info = build_info();
    // The database is part of the key: two sites on one folder (the git/ test server and the git/ preview
    // both serve public/) must never get each other's pages.
    $db = config()['db'] ?? [];
    $site = is_array($db) ? json_encode([$db['driver'] ?? '', $db['path'] ?? '', $db['host'] ?? '', $db['port'] ?? '', $db['name'] ?? '']) : '';
    // The host is part of the key too: a page asked for with a made-up Host header (its share links use the
    // host when site_url is not set) lands in its own entry, which real visitors never get (cache poisoning).
    return sha1(implode('|', [$generation, (string) $site, request_host(), $info['link_mode'], $info['commit'], $path, http_build_query($query), $partial]));
}

/** The current cache generation; cache_clear() starts a new one. */
function cache_generation(): string
{
    $raw = @file_get_contents(cache_dir() . '/generation');
    return is_string($raw) ? trim($raw) : '';
}

/** @return array{html: string, status: int, post: int}|null */
function cache_read(string $key): ?array
{
    $file = cache_dir() . '/' . $key . '.cache';
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw)) {
        return null;
    }
    $at = strpos($raw, "\n");
    if ($at === false) {
        return null;
    }
    $head = explode('|', substr($raw, 0, $at));
    if (count($head) < 3 || (int) $head[0] < time()) {
        return null;
    }
    return ['status' => (int) $head[1], 'post' => (int) $head[2], 'html' => substr($raw, $at + 1)];
}

/** Store a rendered page. Only 200s are cached (a 404 is cheap and must not stick). */
function cache_store(string $key, string $html, int $status = 200, int $postId = 0): void
{
    if ($status !== 200 || $html === '') {
        return;
    }
    $dir = cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $body = (string) cache_expiry_time() . '|' . $status . '|' . $postId . "\n" . $html;
    $tmp = $dir . '/' . $key . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $body) !== false) {
        @rename($tmp, $dir . '/' . $key . '.cache');
    }
    cache_tidy($dir);
}

/**
 * When the cached page must be thrown away: the next scheduled story's publish time, or a short time to
 * live, whichever comes first.
 */
function cache_expiry_time(): int
{
    $ttl = max(15, min(3600, (int) setting('cache_ttl', '300')));
    $until = time() + $ttl;
    $next = q_one("SELECT MIN(published_at) AS at FROM posts WHERE status = 'published' AND published_at > ?", [now_utc()]);
    $at = (string) ($next['at'] ?? '');
    if ($at !== '') {
        $when = strtotime($at . ' UTC');
        if ($when !== false && $when > time()) {
            $until = min($until, $when);
        }
    }
    return $until;
}

/** Remove every cached page (called from every admin save: the site changed). @return int files removed */
function cache_clear(): int
{
    $dir = cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return 0;
    }
    // New generation first: a request that read the old data is still running and stores its page under the
    // old generation's key, which is never read again (and removed by the next clear or by cache_tidy()).
    $generation = bin2hex(random_bytes(8));
    $tmp = $dir . '/generation.' . getmypid() . '.next'; // not *.tmp: the clear below removes those
    $moved = @file_put_contents($tmp, $generation) !== false && @rename($tmp, $dir . '/generation');
    if (!$moved) {
        // Windows cannot replace a file another request has open; write it in place then (a request that
        // reads it half-written only misses the cache once).
        @file_put_contents($dir . '/generation', $generation);
        if (is_file($tmp)) {
            @unlink($tmp); // our own temporary file
        }
    }
    $n = 0;
    foreach (glob($dir . '/*.cache') ?: [] as $file) {
        if (@unlink($file)) {
            $n++;
        }
    }
    foreach (glob($dir . '/*.tmp') ?: [] as $file) {
        @unlink($file);
    }
    return $n;
}

/** Keep the folder small: when there are very many cached pages, drop the oldest ones (generated files only). */
function cache_tidy(string $dir): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $files = glob($dir . '/*.cache') ?: [];
    if (count($files) < 300) {
        return;
    }
    usort($files, static fn (string $a, string $b): int => (int) @filemtime($a) <=> (int) @filemtime($b));
    foreach (array_slice($files, 0, count($files) - 250) as $file) {
        @unlink($file);
    }
}

/** @return array{files: int, bytes: int} for the admin settings page */
function cache_stats(): array
{
    $files = glob(cache_dir() . '/*.cache') ?: [];
    $bytes = 0;
    foreach ($files as $file) {
        $bytes += (int) @filesize($file);
    }
    return ['files' => count($files), 'bytes' => $bytes];
}
