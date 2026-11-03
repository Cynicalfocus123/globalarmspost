<?php
declare(strict_types=1);

// Router for PHP's built-in server in tests. It does what public/.htaccess does on Hostinger:
// real files are served as they are, app/ storage/ and hidden files are refused, everything else goes to index.php.
$root = $_SERVER['DOCUMENT_ROOT'];
$path = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('~^/(app|storage)(/|$)|/\.~', $path) === 1) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = realpath($root . $path);
if ($file !== false && is_file($file) && str_starts_with($file, realpath($root)) && !str_ends_with($file, '.php')) {
    $types = ['avif' => 'image/avif', 'webmanifest' => 'application/manifest+json', 'woff2' => 'font/woff2'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        readfile($file);
        return true;
    }
    return false; // the built-in server sends it
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
return true;
