<?php
declare(strict_types=1);

// Front controller: every page request comes here (.htaccess on Hostinger, tests/php-router.php locally).
require __DIR__ . '/app/bootstrap.php';

send_security_headers();
header('Content-Type: text/html; charset=utf-8');

if (!is_installed()) {
    http_response_code(503);
    echo view('not_installed');
    exit;
}

$path = request_path();
$parts = $path === '/' ? [] : explode('/', substr($path, 1));

if ($parts === [] || $parts === ['home']) {
    echo view('layout', ['title' => '', 'body' => view('home')]);
    exit;
}

if (count($parts) === 1 && !in_array($parts[0], reserved_slugs(), true)) {
    $category = q_one('SELECT c.id, c.name, c.slug, p.name AS parent_name, p.slug AS parent_slug
                         FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.slug = ?', [$parts[0]]);
    if ($category !== null) {
        echo view('layout', ['title' => (string) $category['name'], 'body' => view('category', ['category' => $category])]);
        exit;
    }
}

http_response_code(404);
echo view('layout', ['title' => 'Page not found', 'body' => view('not_found')]);
