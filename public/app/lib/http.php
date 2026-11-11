<?php
declare(strict_types=1);

/** Security headers on every response (code standard 4). No third-party scripts, so the CSP is strict. */
function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** The request path without query string, e.g. "/asia". */
function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = '/' . trim(rawurldecode(is_string($path) ? $path : '/'), '/');
    return $path;
}

/**
 * The Host the request was sent to, lower case, or 'localhost' when it is not a plain host name (with an
 * optional port). The Host header comes from the visitor: it must never put strange text into a page.
 */
function request_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_match('/^[a-z0-9.-]{1,253}(:\d{1,5})?$/', $host) === 1 ? $host : 'localhost';
}

/** The visitor's address (sign-in throttling, the suggestions rate limit). Public pages use it too. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/**
 * A simple per-address rate limit (a small file per address and bucket in storage/cache/ratelimit): true while
 * this address made fewer than $limit requests in the current window. Used by the JSON API (task B15).
 */
function rate_limit_ok(string $bucket, int $limit, int $window = 60): bool
{
    $dir = PUBLIC_DIR . '/storage/cache/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . preg_replace('/[^a-z0-9]/', '', $bucket) . '-' . sha1(client_ip()) . '.txt';
    $now = time();
    $start = $now;
    $count = 0;
    $raw = @file_get_contents($file);
    if (is_string($raw) && $raw !== '') {
        $bits = explode('|', $raw);
        $start = (int) $bits[0]; // explode() always gives at least one part
        $count = (int) ($bits[1] ?? 0);
        if ($start + $window < $now) {
            $start = $now;
            $count = 0;
        }
    }
    if ($count >= $limit) {
        return false;
    }
    @file_put_contents($file, $start . '|' . ($count + 1));
    // Now and then, remove counters of addresses that have been quiet for a while, so many different addresses
    // cannot fill the disk (security review, step 5). Only these generated counter files are ever removed.
    if (random_int(1, 200) === 1) {
        foreach (glob($dir . '/*.txt') ?: [] as $old) {
            if ((int) @filemtime($old) < $now - 10 * $window) {
                @unlink($old);
            }
        }
    }
    return true;
}
