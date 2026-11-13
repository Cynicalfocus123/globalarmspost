<?php
declare(strict_types=1);

/**
 * Loads config, the library and the database. Every entry point (index.php, cli/*) starts here.
 *
 * Config: app/config.php on the server (never in git, never overwritten by the live sync).
 * Tests and local runs can point GAP_CONFIG at another config file.
 * Build info: app/build.php, written per copy (git/ = '#' links, live/ = proper addresses).
 *
 * Three folders (the same code runs in both layouts):
 *   APP_DIR      the code: app/
 *   STORAGE_DIR  server data never served: storage/ (page cache, sessions, backups, rate limits), next to app/
 *   PUBLIC_DIR   the web folder: index.php, assets/, uploads/ (set by index.php; command line: GAP_PUBLIC_DIR)
 * git/:  public/ holds all three (public/app, public/storage, public/assets ...).
 * live/: backend/ (app + storage, uploaded NEXT TO public_html) and frontend/ (uploaded INTO public_html), so no
 *        code, config, backup or session file is inside the web folder at all.
 */

define('APP_DIR', __DIR__);
define('STORAGE_DIR', dirname(__DIR__) . '/storage');
if (!defined('PUBLIC_DIR')) {
    define('PUBLIC_DIR', (string) (getenv('GAP_PUBLIC_DIR') ?: dirname(__DIR__)));
}

date_default_timezone_set('UTC'); // all dates are stored in UTC

foreach (['html', 'db', 'migrate', 'settings', 'link', 'menu', 'view', 'http', 'dates', 'media', 'posts', 'sections', 'sanitize', 'search', 'cache', 'seo', 'api', 'pages'] as $lib) {
    require APP_DIR . '/lib/' . $lib . '.php';
}

/** @return array<string, mixed> */
function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = getenv('GAP_CONFIG') ?: APP_DIR . '/config.php';
        $config = is_file($file) ? require $file : [];
        if (!is_array($config)) {
            $config = [];
        }
    }
    return $config;
}

// PHP errors and warnings go to storage/logs/php-errors.log (never served), and are shown on the page only when
// 'debug' is on (local tests). A warning printed into a page or a JSON answer breaks it (step 5: an admin JSON
// answer came back as text/html now and then under load), and visitors must never see one on the real site.
(static function (): void {
    $logs = STORAGE_DIR . '/logs';
    if (!is_dir($logs)) {
        @mkdir($logs, 0775, true);
    }
    ini_set('log_errors', '1');
    if (is_dir($logs) && is_writable($logs)) {
        ini_set('error_log', $logs . '/php-errors.log');
    }
    ini_set('display_errors', !empty(config()['debug']) ? '1' : '0');
})();

/** @return array{link_mode: string, commit: string} */
function build_info(): array
{
    static $build = null;
    if ($build === null) {
        $file = APP_DIR . '/build.php';
        $data = is_file($file) ? require $file : [];
        $build = [
            'link_mode' => ($data['link_mode'] ?? 'hash') === 'path' ? 'path' : 'hash',
            'commit' => (string) ($data['commit'] ?? 'dev'),
        ];
    }
    return $build;
}
