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

/**
 * Before a JSON answer: throw away anything printed so far (a PHP warning, stray whitespace) so the answer is always
 * clean JSON, and write what was thrown away to the error log, so the cause can be found and fixed. The admin and
 * the public API start an output buffer for this (ob_start()). Research, step 5: PHP's own bug reports and PHP
 * guides on "headers already sent": buffer, discard stray output, never display errors in production.
 *
 * Some buffers can never be removed (zlib.output_compression, a host's own output handler): ob_get_clean() then
 * returns false and the level stays the same, so a plain "while there is a buffer" loop would never end and the
 * request would hang until PHP's time limit. The loop stops at the first buffer that cannot be removed (that
 * buffer stays, and the JSON answer goes out through it as normal).
 */
function discard_stray_output(string $where): void
{
    $stray = '';
    while (($level = ob_get_level()) > 0) {
        $status = ob_get_status();
        if ((((int) ($status['flags'] ?? 0)) & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
            break; // not removable: leave it and every buffer under it
        }
        $chunk = ob_get_clean();
        if ($chunk === false || ob_get_level() >= $level) {
            break; // could not be removed after all: never loop on the same buffer
        }
        $stray .= $chunk;
    }
    $text = trim(strip_tags($stray));
    if ($text !== '') {
        error_log('[' . $where . ' ' . request_path() . '] stray output removed before a JSON answer: ' . mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, 1000));
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
    $dir = STORAGE_DIR . '/cache/ratelimit';
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
