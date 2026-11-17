<?php
declare(strict_types=1);

/**
 * Pictures from other sites, for the story editor's paste (owner, 2026-11-17: an article copied from another site
 * must come in with its pictures, as in WordPress). The browser cannot read another site's picture (CORS), so the
 * admin asks the server for it; the browser then makes the AVIF + WebP sizes and uploads them like any picture.
 *
 * Safe by design: http/https only on ports 80/443; the address must resolve to public internet addresses only (never
 * this server, the local network or cloud metadata), and the connection is pinned to the checked address (no DNS
 * rebinding); every redirect is checked the same way (at most 3); at most 20 MB; 20 s; the answer must be a JPEG,
 * PNG, GIF or WebP by its first bytes. Signed-in admins and editors only (admin API, CSRF).
 */

const REMOTE_PICTURE_MAX = 20 * 1048576;

/** The public IP addresses of a host, or [] when any of them is not public (or it does not resolve). @return list<string> */
function remote_public_ips(string $host): array
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips = [$host];
    } else {
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = array_values((array) @gethostbynamel($host));
        }
    }
    if ($ips === []) {
        return [];
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return [];
        }
    }
    return $ips;
}

/** JPEG, PNG, GIF or WebP by the first bytes, or ''. */
function remote_picture_type(string $bytes): string
{
    return match (true) {
        str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($bytes, "\x89PNG") => 'image/png',
        str_starts_with($bytes, 'GIF8') => 'image/gif',
        str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
        default => '',
    };
}

/**
 * Download one picture from another site.
 * @return array{type: string, name: string, data: string} data = base64 of the picture
 */
function remote_picture(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new InputError('This server cannot fetch pictures from other sites (PHP curl is off).');
    }
    $fail = 'This picture could not be fetched from the other site. Save it on your computer and add it with Replace.';
    for ($hop = 0; $hop <= 3; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = (string) ($p['host'] ?? '');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true) || isset($p['user'])) {
            throw new InputError('Only pictures on http or https addresses can be fetched.');
        }
        $ips = remote_public_ips($host);
        if ($ips === []) {
            throw new InputError($fail);
        }
        $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RESOLVE => [trim($host, '[]') . ':' . $port . ':' . $ip],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'],
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, &$tooBig): int {
                $body .= $chunk;
                if (strlen($body) > REMOTE_PICTURE_MAX) {
                    $tooBig = true;
                    return 0; // stop the download
                }
                return strlen($chunk);
            },
        ];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA; // the local PC's PHP has no CA file of its own
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($tooBig) {
            throw new InputError('This picture is larger than 20 MB.');
        }
        if ($status >= 300 && $status < 400 && $next !== '') {
            $url = $next;
            continue;
        }
        $type = $status === 200 ? remote_picture_type($body) : '';
        if ($type === '') {
            throw new InputError($fail);
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'picture';
        return ['type' => $type, 'name' => clean_text($name, 120), 'data' => base64_encode($body)];
    }
    throw new InputError($fail);
}
