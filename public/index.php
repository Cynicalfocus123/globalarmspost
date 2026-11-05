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
$page = max(1, (int) ($_GET['page'] ?? 1));

/**
 * Send a page. The git/ copy's '#' links load pages into the current one: those requests send
 * X-Partial: 1 and get only the main content (title in X-Page-Title).
 */
$send = function (string $title, string $body, int $status = 200): never {
    http_response_code($status);
    if (($_SERVER['HTTP_X_PARTIAL'] ?? '') === '1') {
        header('X-Page-Title: ' . rawurlencode($title === '' ? setting('site_name', 'Global Arms Post') : $title . ' | ' . setting('site_name', 'Global Arms Post')));
        echo $body;
        exit;
    }
    echo view('layout', ['title' => $title, 'body' => $body]);
    exit;
};

// Admin (same address in both copies): sign-in, stories, homepage builder ...
if (($parts[0] ?? '') === 'admin') {
    require APP_DIR . '/admin/router.php';
    admin_route(array_slice($parts, 1));
    exit;
}

if ($parts === [] || $parts === ['home']) {
    $send('', view('home'));
}

if (count($parts) === 1 && !in_array($parts[0], reserved_slugs(), true)) {
    $category = q_one('SELECT c.id, c.name, c.slug, p.name AS parent_name, p.slug AS parent_slug
                         FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.slug = ?', [$parts[0]]);
    if ($category !== null) {
        $send((string) $category['name'], view('category', ['category' => $category, 'page' => $page]));
    }
}

// Story: /{category}/{slug} in live/, /news/{slug} for the git/ copy's '#news/{slug}' links.
if (count($parts) === 2) {
    $post = post_by_slug($parts[1]);
    if ($post !== null && ($parts[0] === 'news' || $parts[0] === $post['cat_slug'])) {
        if ($parts[0] !== 'news' || build_info()['link_mode'] === 'hash') {
            count_view((int) $post['id']);
            $send((string) $post['title'], view('article', ['p' => $post]));
        }
    }
}

$send('Page not found', view('not_found'), 404);
