<?php
declare(strict_types=1);

/**
 * The admin at /admin (step 3, wireframe section 5). Same address in both copies.
 * Every POST needs the CSRF token. Editors write stories; admins also run the homepage, users and settings.
 *
 *   /admin                    dashboard           /admin/stories             stories list (tabs, search, filters, bulk)
 *   /admin/setup              first admin         /admin/stories/new|{id}    story editor
 *   /admin/login, two-step    sign in             /admin/preview/{id}        story preview (any status)
 *   /admin/logout (POST)                          /admin/authors[/new|{id}]  authors
 *   /admin/homepage           homepage builder    /admin/categories, tags, pictures, users, account
 *   /admin/homepage/preview   live preview (F16)  /admin/categories/{id}, /admin/pictures/{id}   edit (F17)
 *   /admin/menu               menu builder (F5)   /admin/settings            site settings (F17)
 *   /admin/backup             backups (B17)       /admin/backup/download/{file}
 *   /admin/api/...            JSON: story, stories-status, media, tag, category, author, homepage, homepage-draft,
 *                             menu, stories-find, categories-order
 */

require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/content.php';
require APP_DIR . '/lib/manage.php';

/** @param list<string> $parts the path after /admin */
function admin_route(array $parts): void
{
    // The admin makes AVIF in the browser (WebAssembly) and shows picked photos before upload (blob:).
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    admin_session_start();

    $first = $parts[0] ?? '';
    $api = $first === 'api';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_ok()) {
        admin_fail(403, 'This form has expired. Please reload the page and try again.', $api);
    }
    $hasUsers = (int) (q_one('SELECT COUNT(*) AS n FROM users')['n'] ?? 0) > 0;
    if (!$hasUsers) {
        $first === 'setup' ? admin_setup() : admin_redirect('/admin/setup');
        return;
    }
    switch ($first) {
        case 'setup':
            admin_fail(404, 'The first admin account already exists. Sign in instead.', false);
        case 'login':
            admin_login();
            return;
        case 'two-step':
            admin_two_step();
            return;
        case 'logout':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                audit('logout');
                logout();
            }
            admin_redirect('/admin/login');
    }
    $user = current_user();
    if ($user === null) {
        if ($api) {
            admin_json(['error' => 'Please sign in again.'], 401);
        }
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '/admin');
        admin_redirect('/admin/login' . ($next !== '/admin' ? '?next=' . rawurlencode($next) : ''));
    }
    $id = (int) ($parts[1] ?? 0);
    match ($first) {
        '' => admin_dashboard($user),
        'stories' => match (true) {
            ($parts[1] ?? '') === 'new' => admin_editor($user, 0),
            $id > 0 => admin_editor($user, $id),
            ($parts[1] ?? '') === 'bulk' => admin_bulk($user),
            default => admin_stories($user),
        },
        'preview' => admin_preview($id),
        'authors' => ($parts[1] ?? '') === 'new' || $id > 0 ? admin_author_edit($user, $id) : admin_authors($user),
        'homepage' => ($parts[1] ?? '') === 'preview' ? admin_homepage_preview(admin_need($user, 'admin')) : admin_homepage(admin_need($user, 'admin')),
        'categories' => $id > 0 ? admin_category_edit($user, $id) : admin_categories($user),
        'tags' => admin_tags($user),
        'pictures' => $id > 0 ? admin_picture($user, $id) : admin_pictures($user),
        'users' => admin_users(admin_need($user, 'admin')),
        'account' => admin_account($user, $parts[1] ?? ''),
        'menu' => admin_menu(admin_need($user, 'admin')),
        'settings' => admin_settings(admin_need($user, 'admin')),
        'backup' => admin_backup(admin_need($user, 'admin'), $parts),
        'api' => admin_api($user, $parts[1] ?? ''),
        default => admin_fail(404, 'This admin page does not exist.', false),
    };
}

/* ---------- helpers ---------- */

function admin_redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function admin_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function admin_fail(int $status, string $message, bool $json): never
{
    if ($json) {
        admin_json(['error' => $message], $status);
    }
    http_response_code($status);
    echo view('admin/auth', ['mode' => 'message', 'title' => $status === 403 ? 'Not allowed' : 'Not found', 'message' => $message]);
    exit;
}

/** @param array<string, mixed> $user @return array<string, mixed> */
function admin_need(array $user, string $role): array
{
    if ($role === 'admin' && $user['role'] !== 'admin') {
        admin_fail(403, 'Only admins can open this page.', ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
    }
    return $user;
}

/** @param array<string, mixed> $vars */
function admin_page(array $user, string $title, string $nav, string $view, array $vars = []): never
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    echo view('admin/layout', ['user' => $user, 'title' => $title, 'nav' => $nav, 'flash' => $flash,
        'body' => view('admin/' . $view, $vars + ['user' => $user])]);
    exit;
}

function flash(string $text, string $kind = 'ok'): void
{
    $_SESSION['flash'] = ['text' => $text, 'kind' => $kind];
}

/** @return array<string, mixed> JSON body of an API call */
function json_input(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/* ---------- sign in ---------- */

function admin_setup(): void
{
    $error = '';
    $old = ['name' => '', 'email' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $old = ['name' => clean_text($_POST['name'] ?? '', 120), 'email' => strtolower(clean_text($_POST['email'] ?? '', 190))];
        $password = (string) ($_POST['password'] ?? '');
        $error = match (true) {
            $old['name'] === '' => 'Please type your name.',
            filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false => 'Please type a valid email address.',
            password_problem($password) !== '' => password_problem($password),
            $password !== (string) ($_POST['password2'] ?? '') => 'The two passwords are not the same.',
            default => '',
        };
        if ($error === '') {
            // Only while there is no user at all (checked again inside the same request).
            if ((int) (q_one('SELECT COUNT(*) AS n FROM users')['n'] ?? 0) === 0) {
                q("INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', ?)",
                    [$old['email'], $old['name'], password_hash($password, PASSWORD_DEFAULT), now_utc()]);
                $id = (int) db()->lastInsertId();
                audit('setup.first_admin', $old['email'], $id);
                finish_login($id, false);
                flash('Welcome! Your admin account is ready.');
            }
            admin_redirect('/admin');
        }
    }
    echo view('admin/auth', ['mode' => 'setup', 'title' => 'Create the first admin', 'error' => $error, 'old' => $old]);
}

function admin_login(): void
{
    if (current_user() !== null) {
        admin_redirect('/admin');
    }
    $error = '';
    $email = '';
    $next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
    $next = preg_match('~^/admin(/[^\s]*)?$~', $next) === 1 ? $next : '/admin';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(clean_text($_POST['email'] ?? '', 190));
        // The hidden "website" field (honeypot): people never see it, scripts that fill every field do. Such a
        // try counts as a wrong one (so it is throttled too) and gets the same answer, so the script learns nothing.
        $bot = trim((string) ($_POST['website'] ?? '')) !== '';
        if ($bot) {
            record_attempt($email, false);
            audit('login.bot', $email);
        }
        $result = $bot ? ['ok' => false, 'error' => 'Wrong email or password.'] : attempt_login($email, (string) ($_POST['password'] ?? ''), !empty($_POST['remember']));
        if ($result['ok']) {
            admin_redirect(!empty($result['two_step']) ? '/admin/two-step?next=' . rawurlencode($next) : $next);
        }
        $error = (string) $result['error'];
        http_response_code(str_starts_with($error, 'Too many') ? 429 : 401);
    }
    echo view('admin/auth', ['mode' => 'login', 'title' => 'Sign in to the newsroom', 'error' => $error, 'email' => $email, 'next' => $next]);
}

function admin_two_step(): void
{
    if (empty($_SESSION['pending_uid'])) {
        admin_redirect('/admin/login');
    }
    $error = '';
    $next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
    $next = preg_match('~^/admin(/[^\s]*)?$~', $next) === 1 ? $next : '/admin';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = attempt_two_step((string) ($_POST['code'] ?? ''));
        if ($result['ok']) {
            admin_redirect($next);
        }
        if (!empty($result['restart'])) {
            admin_redirect('/admin/login');
        }
        $error = (string) $result['error'];
        http_response_code(401);
    }
    echo view('admin/auth', ['mode' => 'two_step', 'title' => 'Two-step login', 'error' => $error, 'next' => $next]);
}

/* ---------- pages ---------- */

/** @return array{published: int, scheduled: int, drafts: int, trash: int, all: int} */
function story_counts(): array
{
    $now = now_utc();
    $row = q_one("SELECT
        SUM(CASE WHEN status = 'published' AND published_at <= ? THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status = 'published' AND published_at > ? THEN 1 ELSE 0 END) AS scheduled,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
        SUM(CASE WHEN status = 'trash' THEN 1 ELSE 0 END) AS trash
        FROM posts", [$now, $now]) ?? [];
    $c = array_map('intval', $row + ['published' => 0, 'scheduled' => 0, 'drafts' => 0, 'trash' => 0]);
    $c['all'] = $c['published'] + $c['scheduled'] + $c['drafts'];
    return $c;
}

function admin_dashboard(array $user): never
{
    $now = now_utc();
    $today = (new DateTimeImmutable('now', site_tz()))->format('Y-m-d');
    admin_page($user, 'Dashboard', 'dashboard', 'dashboard', [
        'counts' => story_counts(),
        'views_today' => (int) (q_one('SELECT views FROM views_daily WHERE day = ?', [$today])['views'] ?? 0),
        'scheduled' => q_all("SELECT id, title, published_at FROM posts WHERE status = 'published' AND published_at > ? ORDER BY published_at LIMIT 5", [$now]),
        'drafts' => q_all("SELECT id, title, updated_at FROM posts WHERE status = 'draft' ORDER BY updated_at DESC, id DESC LIMIT 5"),
        'checks' => server_checks(),
    ]);
}

/** @return list<array{label: string, ok: bool, note: string}> */
function server_checks(): array
{
    $uploads = PUBLIC_DIR . '/uploads';
    return [
        ['label' => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'ok' => PHP_VERSION_ID >= 80100, 'note' => 'needs 8.1 or newer'],
        ['label' => 'Database (' . (db_driver() === 'mysql' ? 'MySQL' : 'SQLite') . ')', 'ok' => true, 'note' => ''],
        ['label' => 'Uploads folder', 'ok' => is_dir($uploads) && is_writable($uploads), 'note' => 'uploads/ must be writable'],
        ['label' => 'GD WebP (server fallback)', 'ok' => function_exists('imagewebp'), 'note' => 'used when a browser cannot make WebP'],
        ['label' => 'GD AVIF (server fallback)', 'ok' => function_exists('imageavif'), 'note' => 'without it, the server fallback makes WebP only'],
        ['label' => 'HTTPS', 'ok' => is_https(), 'note' => 'the live site must use HTTPS'],
    ];
}

function admin_stories(array $user): never
{
    $tab = (string) ($_GET['tab'] ?? 'all');
    $tab = in_array($tab, ['all', 'published', 'scheduled', 'drafts', 'trash'], true) ? $tab : 'all';
    $search = clean_text($_GET['q'] ?? '', 120);
    $cat = (int) ($_GET['cat'] ?? 0);
    $author = (int) ($_GET['author'] ?? 0);
    $sort = (string) ($_GET['sort'] ?? 'date');
    $dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $now = now_utc();
    [$where, $params] = match ($tab) {
        'published' => ["p.status = 'published' AND p.published_at <= ?", [$now]],
        'scheduled' => ["p.status = 'published' AND p.published_at > ?", [$now]],
        'drafts' => ["p.status = 'draft'", []],
        'trash' => ["p.status = 'trash'", []],
        default => ["p.status <> 'trash'", []],
    };
    if ($search !== '') {
        $where .= ' AND LOWER(p.title) LIKE ?';
        $params[] = '%' . mb_strtolower(str_replace(['%', '_'], ' ', $search)) . '%';
    }
    if ($cat > 0) {
        // Main or extra category (owner, 2026-11-13).
        [$catSql, $catParams] = category_match_sql([$cat]);
        $where .= ' AND ' . $catSql;
        array_push($params, ...$catParams);
    }
    if ($author > 0) {
        $where .= ' AND p.author_id = ?';
        $params[] = $author;
    }
    $order = match ($sort) {
        'title' => "p.title $dir",
        'category' => "c.name $dir",
        'status' => "p.status $dir, p.published_at $dir",
        default => "COALESCE(p.published_at, p.updated_at) $dir",
    };
    $per = 25;
    $total = (int) (q_one("SELECT COUNT(*) AS n FROM posts p WHERE $where", $params)['n'] ?? 0);
    $rows = q_all("SELECT p.id, p.title, p.status, p.published_at, p.updated_at, p.slug, c.name AS cat_name, COALESCE(a.name, p.author_name) AS author_name,
                          m.path AS m_path, m.widths AS m_widths
                     FROM posts p JOIN categories c ON c.id = p.category_id LEFT JOIN authors a ON a.id = p.author_id LEFT JOIN media m ON m.id = p.media_id
                    WHERE $where ORDER BY $order, p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    admin_page($user, 'Stories', 'stories', 'stories', [
        'tab' => $tab, 'search' => $search, 'cat' => $cat, 'author' => $author, 'sort' => $sort, 'dir' => strtolower($dir),
        'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total, 'rows' => $rows, 'counts' => story_counts(),
        'categories' => category_tree(), 'authors' => q_all('SELECT id, name FROM authors ORDER BY name'),
    ]);
}

function admin_bulk(array $user): never
{
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $action = (string) ($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $ids === [] || !in_array($action, ['publish', 'trash', 'restore', 'delete'], true)) {
        flash('Tick at least one story and choose an action.', 'warn');
    } elseif ($action === 'delete') {
        // Delete for good (task B7): admins only, and only stories that are in the trash.
        admin_need($user, 'admin');
        $n = stories_delete_forever($ids, (int) $user['id']);
        flash($n . ' ' . ($n === 1 ? 'story' : 'stories') . ' deleted for good.');
    } else {
        $n = stories_set_status($ids, $action, (int) $user['id']);
        $verb = ['publish' => 'published', 'trash' => 'moved to the trash', 'restore' => 'restored as drafts'][$action];
        flash($n . ' ' . ($n === 1 ? 'story' : 'stories') . ' ' . $verb . '.');
    }
    $back = (string) ($_POST['back'] ?? '/admin/stories');
    admin_redirect(str_starts_with($back, '/admin/stories') ? $back : '/admin/stories');
}

function admin_editor(array $user, int $id): never
{
    $story = $id > 0 ? story_for_editor($id) : null;
    if ($id > 0 && $story === null) {
        admin_fail(404, 'This story does not exist.', false);
    }
    $data = [
        'story' => $story === null ? null : [
            'id' => (int) $story['id'], 'title' => $story['title'], 'slug' => $story['slug'], 'body' => $story['body_html'],
            'excerpt' => (string) $story['excerpt'], 'summary' => (string) $story['summary'], 'credits' => (string) $story['credits'],
            'status' => $story['status'], 'state' => story_state((string) $story['status'], $story['published_at']),
            'published_local' => utc_to_local_input($story['published_at']), 'sticky' => (int) $story['sticky'] === 1,
            'live_label' => (int) $story['live_label'] === 1, 'category_id' => (int) $story['category_id'],
            'extra_category_ids' => array_map(fn ($c) => $c['id'], $story['extra_categories']),
            'author_id' => (int) ($story['author_id'] ?? 0), 'tag_ids' => array_map(fn ($t) => (int) $t['id'], $story['tags']),
            'media_id' => (int) ($story['media_id'] ?? 0), 'media_src' => media_thumb($story['m_path'], $story['m_widths']),
            'featured_caption' => (string) ($story['featured_caption'] ?? '') !== '' ? (string) $story['featured_caption'] : (string) ($story['m_caption'] ?? ''),
            'featured_credit' => (string) ($story['featured_credit'] ?? '') !== '' ? (string) $story['featured_credit'] : (string) ($story['m_credit'] ?? ''),
            'gallery' => array_map(fn ($g) => ['media_id' => (int) $g['media_id'], 'caption' => (string) $g['caption'], 'credit' => (string) $g['credit'],
                'src' => media_thumb($g['path'], $g['widths'])], $story['gallery']),
            'url' => story_public_url((int) $story['id']),
            'updated_at' => $story['updated_at'],
        ],
        'categories' => category_tree(),
        'tags' => q_all('SELECT id, name FROM tags ORDER BY name'),
        'authors' => array_map(fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email'], 'avatar' => media_thumb($a['path'], $a['widths'])],
            q_all('SELECT a.id, a.name, a.email, m.path, m.widths FROM authors a LEFT JOIN media m ON m.id = a.media_id ORDER BY a.name')),
        'body_media' => body_media_for_editor($story === null ? '' : (string) $story['body_html']),
        'tz' => gmt_label(new DateTimeImmutable('now', site_tz())),
        'site' => preg_replace('~^https?://~', '', (string) setting('site_url', 'globalarmspost.com')),
        'link_mode' => build_info()['link_mode'],
    ];
    admin_page($user, $story === null ? 'New story' : 'Edit story', 'stories', 'editor', ['data' => $data]);
}

/** Thumbnails for the pictures already in a story body: media id => address. @return array<int, string> */
function body_media_for_editor(string $html): array
{
    preg_match_all('~data-media="(\d+)"~', $html, $m);
    $ids = array_values(array_unique(array_map('intval', $m[1])));
    $out = [];
    if ($ids !== []) {
        foreach (q_all('SELECT id, path, widths FROM media WHERE id IN (' . implode(',', $ids) . ')') as $r) {
            $w = array_filter(array_map('intval', explode(',', (string) $r['widths'])));
            sort($w);
            $out[(int) $r['id']] = '/' . $r['path'] . '-' . $w[min(1, count($w) - 1)] . '.webp';
        }
    }
    return $out;
}

function admin_preview(int $id): never
{
    $p = q_one(posts_select() . ' WHERE p.id = ?', [$id]);
    if ($p === null) {
        admin_fail(404, 'This story does not exist.', false);
    }
    echo view('layout', ['title' => 'Preview: ' . $p['title'], 'body' => '<p class="preview-note">Preview · ' . e(story_state((string) q_one('SELECT status FROM posts WHERE id = ?', [$id])['status'], $p['published_at'])) . '</p>' . view('article', ['p' => $p])]);
    exit;
}

function admin_authors(array $user): never
{
    $rows = q_all('SELECT a.id, a.name, a.email, m.path, m.widths, (SELECT COUNT(*) FROM posts p WHERE p.author_id = a.id AND p.status <> \'trash\') AS stories
                     FROM authors a LEFT JOIN media m ON m.id = a.media_id ORDER BY a.name');
    admin_page($user, 'Authors', 'authors', 'authors', ['rows' => $rows]);
}

function admin_author_edit(array $user, int $id): never
{
    $author = $id > 0 ? author_row($id) : null;
    if ($id > 0 && $author === null) {
        admin_fail(404, 'This author does not exist.', false);
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $saved = author_save($_POST, $id);
            flash('Author saved.');
            admin_redirect('/admin/authors/' . (int) $saved['id']);
        } catch (InputError $e) {
            $error = $e->getMessage();
            $author = ['id' => $id] + array_map(fn ($v) => is_string($v) ? $v : '', $_POST) + ($author ?? []);
        }
    }
    admin_page($user, $id > 0 ? 'Edit author' : 'New author', 'authors', 'author_edit', ['author' => $author, 'error' => $error]);
}

function admin_homepage(array $user): never
{
    $sections = array_map(function ($r) {
        $s = json_decode((string) $r['settings'], true);
        return ['id' => (int) $r['id'], 'layout' => (string) $r['layout'], 'title' => (string) $r['title'], 'span' => (int) $r['span'],
                'active' => (int) $r['active'] === 1, 'settings' => is_array($s) ? $s : []];
    }, q_all('SELECT id, layout, title, span, settings, active FROM home_sections ORDER BY position, id'));
    admin_page($user, 'Homepage', 'homepage', 'homepage', ['data' => [
        'sections' => $sections, 'layouts' => section_layouts(), 'categories' => category_tree(),
        'tags' => q_all('SELECT slug, name FROM tags ORDER BY name'),
    ]]);
}

function admin_categories(array $user): never
{
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $c = category_create((string) ($_POST['name'] ?? ''), (int) ($_POST['parent_id'] ?? 0));
            flash($c['created'] ? 'Category ' . $c['name'] . ' added.' : 'Category ' . $c['name'] . ' already exists.', $c['created'] ? 'ok' : 'warn');
            admin_redirect('/admin/categories');
        } catch (InputError $e) {
            $error = $e->getMessage();
        }
    }
    $counts = category_story_counts("p.status <> 'trash'"); // main and extra categories
    admin_page($user, 'Categories', 'categories', 'categories', ['rows' => category_tree(), 'counts' => $counts, 'menu' => categories_in_menu(), 'error' => $error]);
}

/** Edit one category (F17): name, address, parent, shown in the header menu. */
function admin_category_edit(array $user, int $id): never
{
    $cat = q_one('SELECT id, name, slug, parent_id FROM categories WHERE id = ?', [$id]);
    if ($cat === null) {
        admin_fail(404, 'This category does not exist.', false);
    }
    $error = '';
    $inMenu = categories_in_menu()[(string) $cat['slug']] ?? false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $saved = category_update($id, $_POST, $user['role'] === 'admin');
            flash('Category ' . $saved['name'] . ' saved.');
            admin_redirect('/admin/categories/' . $id);
        } catch (InputError $e) {
            $error = $e->getMessage();
            $cat = ['id' => $id, 'name' => clean_text($_POST['name'] ?? '', 120), 'slug' => clean_text($_POST['slug'] ?? '', 120),
                    'parent_id' => (int) ($_POST['parent_id'] ?? 0) ?: null];
            $inMenu = !empty($_POST['in_menu']);
        }
    }
    $branch = category_branch($id);
    $parents = array_values(array_filter(category_tree(), static fn (array $c): bool => !in_array($c['id'], $branch, true)));
    $stories = category_story_counts("p.status <> 'trash'")[$id] ?? 0; // main and extra categories
    admin_page($user, 'Edit category', 'categories', 'category_edit', ['cat' => $cat, 'parents' => $parents, 'in_menu' => $inMenu,
        'stories' => $stories, 'error' => $error]);
}

/** Tags (B8): counts, rename (name + address), merge two tags. */
function admin_tags(array $user): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            if (($_POST['action'] ?? '') === 'merge') {
                $res = tag_merge($id, (int) ($_POST['into'] ?? 0));
                flash('Tags merged into ' . $res['into'] . ' (' . $res['moved'] . ' ' . ($res['moved'] === 1 ? 'story' : 'stories') . ' moved).');
            } else {
                $res = tag_rename($id, (string) ($_POST['name'] ?? ''), (string) ($_POST['slug'] ?? ''));
                flash('Tag renamed to ' . $res['name'] . '.');
            }
        } catch (InputError $e) {
            flash($e->getMessage(), 'warn');
        }
        admin_redirect('/admin/tags');
    }
    $rows = q_all("SELECT t.id, t.name, t.slug, (SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id WHERE pt.tag_id = t.id AND p.status <> 'trash') AS stories
                     FROM tags t ORDER BY t.name");
    admin_page($user, 'Tags', 'tags', 'tags', ['rows' => $rows]);
}

/** Pictures (F17): every stored picture, newest first, with how often it is used. */
function admin_pictures(array $user): never
{
    $per = 60;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) (q_one('SELECT COUNT(*) AS n FROM media')['n'] ?? 0);
    $rows = q_all('SELECT id, path, widths, has_avif, width, height, alt, created_at FROM media ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $used = [];
    foreach ($rows as $m) {
        $used[(int) $m['id']] = count(media_usage((int) $m['id']));
    }
    admin_page($user, 'Pictures', 'pictures', 'pictures', ['rows' => $rows, 'used' => $used, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total]);
}

/** One picture: edit alt / caption / credit, see where it is used, delete it when nothing uses it (admins). */
function admin_picture(array $user, int $id): never
{
    $m = q_one('SELECT id, path, widths, has_avif, width, height, alt, caption, credit, created_at FROM media WHERE id = ?', [$id]);
    if ($m === null) {
        admin_fail(404, 'This picture does not exist.', false);
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (($_POST['action'] ?? '') === 'delete') {
                admin_need($user, 'admin');
                $n = media_delete($id);
                flash('Picture deleted (' . $n . ' files removed).');
                admin_redirect('/admin/pictures');
            }
            media_update($id, (string) ($_POST['alt'] ?? ''), (string) ($_POST['caption'] ?? ''), (string) ($_POST['credit'] ?? ''));
            flash('Picture saved.');
            admin_redirect('/admin/pictures/' . $id);
        } catch (InputError $e) {
            $error = $e->getMessage();
        }
    }
    admin_page($user, 'Picture', 'pictures', 'picture', ['m' => $m, 'usage' => media_usage($id), 'error' => $error]);
}

function admin_users(array $user): never
{
    $error = '';
    $old = ['name' => '', 'email' => '', 'role' => 'editor'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $old = ['name' => clean_text($_POST['name'] ?? '', 120), 'email' => strtolower(clean_text($_POST['email'] ?? '', 190)),
                'role' => ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'editor'];
        $password = (string) ($_POST['password'] ?? '');
        $error = match (true) {
            $old['name'] === '' => 'Please type a name.',
            filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false => 'Please type a valid email address.',
            q_one('SELECT id FROM users WHERE email = ?', [$old['email']]) !== null => 'A user with this email already exists.',
            password_problem($password) !== '' => password_problem($password),
            default => '',
        };
        if ($error === '') {
            q('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)',
                [$old['email'], $old['name'], password_hash($password, PASSWORD_DEFAULT), $old['role'], now_utc()]);
            audit('user.create', $old['email'] . ' (' . $old['role'] . ')');
            flash('User ' . $old['email'] . ' added as ' . $old['role'] . '.');
            admin_redirect('/admin/users');
        }
    }
    admin_page($user, 'Users', 'users', 'users', [
        'rows' => q_all('SELECT id, email, name, role, totp_enabled, last_login_at FROM users ORDER BY id'), 'error' => $error, 'old' => $old,
    ]);
}

function admin_account(array $user, string $action): never
{
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $row = q_one('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);
        if ($action === 'two-step-start') {
            $_SESSION['totp_new'] = base32_encode(random_bytes(20));
        } elseif ($action === 'two-step-confirm') {
            $secret = (string) ($_SESSION['totp_new'] ?? '');
            $step = totp_verify($secret, (string) ($_POST['code'] ?? ''));
            if ($step === null) {
                $error = 'That code is not right. Type the 6 digits your app shows now.';
            } else {
                q('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last = ? WHERE id = ?', [$secret, $step, (int) $user['id']]);
                unset($_SESSION['totp_new']);
                audit('two_step.on');
                flash('Two-step login is on. From now on you need a code from your app to sign in.');
                admin_redirect('/admin/account');
            }
        } elseif ($action === 'two-step-off') {
            if (!password_verify((string) ($_POST['password'] ?? ''), (string) ($row['password_hash'] ?? ''))) {
                $error = 'Wrong password, so two-step login stays on.';
            } else {
                q('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [(int) $user['id']]);
                audit('two_step.off');
                flash('Two-step login is off.');
                admin_redirect('/admin/account');
            }
        } elseif ($action === 'password') {
            $new = (string) ($_POST['new_password'] ?? '');
            $error = match (true) {
                !password_verify((string) ($_POST['password'] ?? ''), (string) ($row['password_hash'] ?? '')) => 'Your current password is wrong.',
                password_problem($new) !== '' => password_problem($new),
                default => '',
            };
            if ($error === '') {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
                sign_out_everywhere_else((int) $user['id']); // this session stays signed in
                audit('password.change');
                flash('Password changed.');
                admin_redirect('/admin/account');
            }
        }
    }
    $secret = (string) ($_SESSION['totp_new'] ?? '');
    $label = rawurlencode(setting('site_name', 'Global Arms Post') . ':' . $user['email']);
    admin_page($user, 'Account', 'account', 'account', [
        'error' => $error, 'secret' => $secret,
        'uri' => $secret === '' ? '' : 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode(setting('site_name', 'Global Arms Post')),
    ]);
}

/** Menu builder (F5): the header menu, built in assets/admin/menu.js. */
function admin_menu(array $user): never
{
    $storyIds = array_values(array_filter(array_map(static fn (array $i): int => $i['type'] === 'story' ? (int) $i['target'] : 0, menu_items_for_builder())));
    $stories = $storyIds === [] ? [] : q_all('SELECT id, title FROM posts WHERE id IN (' . implode(',', $storyIds) . ')');
    admin_page($user, 'Menu', 'menu', 'menu', ['data' => [
        'items' => menu_items_for_builder(),
        'categories' => array_map(static fn (array $c): array => ['slug' => $c['slug'], 'name' => $c['name'], 'depth' => $c['depth']], category_tree()),
        'tags' => q_all('SELECT slug, name FROM tags ORDER BY name'),
        'stories' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $stories),
    ]]);
}

/** Backups (B17): make one, list the earlier ones, download one. No restore yet. @param list<string> $parts */
function admin_backup(array $user, array $parts): never
{
    if (($parts[1] ?? '') === 'download') {
        $file = backup_path((string) ($parts[2] ?? ''));
        if ($file === null) {
            admin_fail(404, 'This backup does not exist.', false);
        }
        audit('backup.download', basename($file));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        @set_time_limit(300);
        try {
            $b = backup_create();
            flash('Backup made: ' . $b['name'] . ' (' . $b['tables'] . ' tables, ' . $b['rows'] . ' rows, ' . $b['files'] . ' picture files).');
        } catch (InputError $e) {
            flash($e->getMessage(), 'warn');
        }
        admin_redirect('/admin/backup');
    }
    admin_page($user, 'Backup', 'backup', 'backup', ['rows' => backup_list(), 'zip' => class_exists('ZipArchive')]);
}

/**
 * The homepage builder's live preview (F16): the unsaved sections (sent to /admin/api/homepage-draft) drawn with
 * the site's own layout and section templates, inside the builder's frame. Never cached, never indexed.
 */
function admin_homepage_preview(array $user): never
{
    $rows = $_SESSION['hb_draft'] ?? null;
    // This page is shown in a frame of the builder (same site only); everything else keeps frame-ancestors 'none'.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    header('X-Frame-Options: SAMEORIGIN');
    $html = render_home_sections(is_array($rows) ? $rows : null);
    $body = '<p class="preview-note">Preview of the homepage · not saved yet</p>'
        . ($html === '' ? '<p class="empty" id="home">No stories in these sections.</p>' : '<div class="home-grid" id="home">' . $html . '</div>');
    echo view('layout', ['title' => 'Homepage preview', 'body' => $body, 'meta' => ['robots' => 'noindex, nofollow']]);
    exit;
}

function admin_later(array $user, string $title, string $nav, string $text): never
{
    admin_page($user, $title, $nav, 'later', ['text' => $text, 'title' => $title]);
}

/* ---------- settings (the reader-side parts of task F17) ---------- */

/**
 * Trending tags, the page cache and the search index tools. Saving here clears the page cache, because a
 * changed setting changes every page.
 */
function admin_settings(array $user): never
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'rebuild') {
            $res = search_reindex_all();
            cache_clear();
            audit('search.rebuild', 'indexed ' . $res['posts'] . ', skipped ' . $res['skipped'] . ', terms ' . $res['terms']);
            flash('Search index rebuilt: ' . $res['posts'] . ' stories indexed, ' . $res['skipped'] . ' already up to date, ' . $res['terms'] . ' words in the vocabulary.');
            admin_redirect('/admin/settings');
        }
        if ($action === 'site') {
            try {
                site_settings_save($_POST);
                flash('Site settings saved. The page cache was cleared.');
            } catch (InputError $e) {
                flash($e->getMessage(), 'warn');
            }
            admin_redirect('/admin/settings');
        }
        if ($action === 'cache') {
            $n = cache_clear();
            audit('cache.clear', $n . ' pages');
            flash('Page cache cleared (' . $n . ' page' . ($n === 1 ? '' : 's') . ').');
            admin_redirect('/admin/settings');
        }
        setting_save('trending_tags', !empty($_POST['trending_tags']) ? '1' : '0');
        setting_save('trending_tags_count', (string) max(1, min(20, (int) ($_POST['trending_tags_count'] ?? 8))));
        setting_save('trending_tags_days', (string) max(1, min(365, (int) ($_POST['trending_tags_days'] ?? 14))));
        setting_save('cache_enabled', !empty($_POST['cache_enabled']) ? '1' : '0');
        setting_save('cache_ttl', (string) max(15, min(3600, (int) ($_POST['cache_ttl'] ?? 300))));
        cache_clear();
        audit('settings.save', 'trending tags, page cache');
        flash('Settings saved. The page cache was cleared.');
        admin_redirect('/admin/settings');
    }
    $stats = cache_stats();
    admin_page($user, 'Settings', 'settings', 'settings', [
        'stats' => $stats,
        'terms' => (int) (q_one('SELECT COUNT(*) AS n FROM search_terms')['n'] ?? 0),
        'docs' => (int) (q_one('SELECT COUNT(*) AS n FROM search_docs')['n'] ?? 0),
    ]);
}

/* ---------- JSON API ---------- */

function admin_api(array $user, string $what): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        admin_json(['error' => 'Use POST.'], 405);
    }
    try {
        switch ($what) {
            case 'story':
                admin_json(story_save(json_input(), (int) $user['id']));
            case 'stories-status':
                $in = json_input();
                $ids = array_values(array_filter(array_map('intval', (array) ($in['ids'] ?? []))));
                $action = (string) ($in['action'] ?? '');
                if (!in_array($action, ['publish', 'trash', 'restore'], true) || $ids === []) {
                    throw new InputError('Nothing to change.');
                }
                admin_json(['changed' => stories_set_status($ids, $action, (int) $user['id'])]);
            case 'tag':
                admin_json(tag_create((string) (json_input()['name'] ?? '')));
            case 'category':
                $in = json_input();
                admin_json(category_create((string) ($in['name'] ?? ''), (int) ($in['parent_id'] ?? 0)));
            case 'author':
                admin_json(author_save(json_input()));
            case 'media':
                admin_json(admin_upload());
            case 'homepage':
                admin_need($user, 'admin');
                admin_json(homepage_save(json_input()));
            case 'homepage-draft':
                // The live preview's sections (F16): checked like a save, kept only in this session.
                admin_need($user, 'admin');
                $_SESSION['hb_draft'] = homepage_normalize(json_input());
                admin_json(['ok' => true, 'sections' => count($_SESSION['hb_draft'])]);
            case 'menu':
                admin_need($user, 'admin');
                admin_json(menu_save(json_input()));
            case 'stories-find':
                admin_json(['stories' => menu_find_stories((string) (json_input()['q'] ?? ''))]);
            case 'categories-order':
                admin_json(['saved' => categories_order(array_map('intval', (array) (json_input()['ids'] ?? [])))]);
        }
    } catch (InputError $e) {
        admin_json(['error' => $e->getMessage()], 422);
    }
    admin_json(['error' => 'Unknown action.'], 404);
}

/** @return array<string, mixed> */
function admin_upload(): array
{
    $kind = ($_POST['kind'] ?? '') === 'avatar' ? 'avatar' : 'photo';
    $name = clean_text($_POST['name'] ?? 'picture', 120);
    $alt = clean_text($_POST['alt'] ?? '', 300);
    $ok = fn (string $field): ?string => isset($_FILES[$field]) && is_array($_FILES[$field]) && ($_FILES[$field]['error'] ?? 1) === UPLOAD_ERR_OK
        && is_uploaded_file((string) $_FILES[$field]['tmp_name']) ? (string) $_FILES[$field]['tmp_name'] : null;
    if (($src = $ok('source')) !== null) {
        return media_store_source($src, $kind, $name, $alt);
    }
    $files = [];
    foreach (media_widths($kind) as $w) {
        if (($webp = $ok('webp_' . $w)) !== null) {
            $files[$w] = ['webp' => $webp, 'avif' => $ok('avif_' . $w)];
        }
    }
    // Small originals: the browser sends its one size under the real width.
    foreach ($_FILES as $field => $f) {
        if (preg_match('/^webp_(\d+)$/', (string) $field, $m) === 1 && !isset($files[(int) $m[1]]) && ($webp = $ok($field)) !== null) {
            $files[(int) $m[1]] = ['webp' => $webp, 'avif' => $ok('avif_' . $m[1])];
        }
    }
    if ($files === []) {
        throw new InputError('No picture was received. It may be larger than the server allows (' . ini_get('upload_max_filesize') . ').');
    }
    return media_store_variants($files, $kind, $name, $alt, max(1, (int) ($_POST['height'] ?? 1)));
}

/**
 * Save the homepage builder: the order of the sections and each section's settings (stored in home_sections).
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function homepage_save(array $in): array
{
    $rows = homepage_normalize($in);
    $ids = db_atomic(static function () use ($rows): array {
        $existing = array_map('intval', array_column(q_all('SELECT id FROM home_sections'), 'id'));
        $ids = [];
        foreach ($rows as $pos => $r) {
            $json = json_encode($r['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $id = $r['id'];
            if ($id > 0 && in_array($id, $existing, true)) {
                q('UPDATE home_sections SET position = ?, layout = ?, title = ?, span = ?, settings = ?, active = ? WHERE id = ?', [$pos, $r['layout'], $r['title'], $r['span'], $json, $r['active'], $id]);
            } else {
                q('INSERT INTO home_sections (position, layout, title, span, settings, active) VALUES (?, ?, ?, ?, ?, ?)', [$pos, $r['layout'], $r['title'], $r['span'], $json, $r['active']]);
                $id = (int) db()->lastInsertId();
            }
            $ids[] = $id;
        }
        // Sections not in the list stay in the database, hidden, after the others (nothing is deleted).
        foreach (array_values(array_diff($existing, $ids)) as $n => $gone) {
            q('UPDATE home_sections SET active = 0, position = ? WHERE id = ?', [count($ids) + $n, $gone]);
        }
        return $ids;
    });
    audit('homepage.save', count($ids) . ' sections');
    cache_clear(); // the homepage just changed (task B12)
    return ['ids' => $ids, 'saved' => true];
}
