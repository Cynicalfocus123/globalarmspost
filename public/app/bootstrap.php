<?php
declare(strict_types=1);

/**
 * Loads config, the library and the database. Every entry point (index.php, cli/*) starts here.
 *
 * Config: app/config.php on the server (never in git, never overwritten by the live sync).
 * Tests and local runs can point GAP_CONFIG at another config file.
 * Build info: app/build.php, written per copy (git/ = '#' links, live/ = proper addresses).
 */

define('APP_DIR', __DIR__);
define('PUBLIC_DIR', dirname(__DIR__));

date_default_timezone_set('UTC'); // all dates are stored in UTC

foreach (['html', 'db', 'migrate', 'settings', 'link', 'menu', 'view', 'http'] as $lib) {
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
