<?php
declare(strict_types=1);

/**
 * Step 5 admin work (only loaded for /admin): the menu builder (F5), categories, pictures and site settings (F17),
 * "Delete for good" (B7), renaming and merging tags (B8), the homepage preview (F16) and backups (B17).
 *
 * Every write that changes several rows runs in one db_atomic() transaction, every change readers can see clears
 * the page cache, and every action goes into the audit log. The SQL is the same on MySQL 8 and SQLite: prepared
 * statements, no upserts (look first, then insert or update), no || for joining text.
 */

/* ---------- Menu builder (F5) ---------- */

/** The header menu's id (made when it is missing). */
function header_menu_id(): int
{
    $row = q_one('SELECT id FROM menus WHERE location = ?', ['header']);
    if ($row !== null) {
        return (int) $row['id'];
    }
    q('INSERT INTO menus (location) VALUES (?)', ['header']);
    return (int) db()->lastInsertId();
}

/**
 * Every header menu item in order, flat, with its depth (for the builder).
 * @return list<array<string, mixed>>
 */
function menu_items_for_builder(): array
{
    $rows = q_all('SELECT id, parent_id, type, label, target, new_tab, hidden FROM menu_items WHERE menu_id = ? ORDER BY position, id', [header_menu_id()]);
    $byParent = [];
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    foreach ($rows as $r) {
        $parent = $r['parent_id'] !== null && in_array((int) $r['parent_id'], $ids, true) ? (int) $r['parent_id'] : 0;
        $byParent[$parent][] = $r;
    }
    $out = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
        foreach ($depth > 8 ? [] : ($byParent[$parent] ?? []) as $r) {
            $out[] = ['id' => (int) $r['id'], 'type' => (string) $r['type'], 'label' => (string) $r['label'], 'target' => (string) $r['target'],
                      'new_tab' => (int) $r['new_tab'] === 1, 'hidden' => (int) $r['hidden'] === 1, 'depth' => $depth];
            $walk((int) $r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

/**
 * Save the whole header menu from the builder: the list comes in display order, each item naming its parent by the
 * builder's key (a parent always comes before its children, so there can be no loops). One transaction: the header
 * never shows half a menu. Items left out of the list were removed in the builder.
 * @param array<string, mixed> $in
 * @return array{ids: array<string, int>, saved: bool}
 */
function menu_save(array $in): array
{
    $list = array_values(array_filter((array) ($in['items'] ?? []), 'is_array'));
    if (count($list) > 200) {
        throw new InputError('A menu can have at most 200 items.');
    }
    $catSlugs = array_column(q_all('SELECT slug FROM categories'), 'slug');
    $tagSlugs = array_column(q_all('SELECT slug FROM tags'), 'slug');
    $clean = [];
    $depthOf = [];
    foreach ($list as $n => $it) {
        $key = clean_text($it['key'] ?? '', 40);
        $type = (string) ($it['type'] ?? '');
        $label = clean_text($it['label'] ?? '', 120);
        $raw = clean_text($it['target'] ?? '', 500);
        $where = 'Item ' . ($n + 1) . ($label !== '' ? ' (' . $label . ')' : '');
        if ($key === '' || isset($depthOf[$key])) {
            throw new InputError($where . ': the item has no key. Please reload the page.');
        }
        if ($label === '') {
            throw new InputError($where . ': please type the text the menu shows.');
        }
        $target = match ($type) {
            'home' => '',
            'category' => in_array($raw, $catSlugs, true) ? $raw : throw new InputError($where . ': please choose a category.'),
            'tag' => in_array($raw, $tagSlugs, true) ? $raw : throw new InputError($where . ': please choose a tag.'),
            'story' => (int) $raw > 0 && q_one('SELECT id FROM posts WHERE id = ?', [(int) $raw]) !== null ? (string) (int) $raw
                : throw new InputError($where . ': please choose a story.'),
            'page' => slugify($raw) !== '' ? slugify($raw) : throw new InputError($where . ': please type the page name (like contact).'),
            'url' => safe_url($raw) !== '#' ? safe_url($raw)
                : throw new InputError($where . ': a web address must start with https://, http://, / or mailto:'),
            default => throw new InputError($where . ': unknown kind of link.'),
        };
        $parentKey = clean_text($it['parent'] ?? '', 40);
        if ($parentKey !== '' && !isset($depthOf[$parentKey])) {
            throw new InputError($where . ': its parent must come before it. Please reload the page.');
        }
        $depth = $parentKey === '' ? 0 : $depthOf[$parentKey] + 1;
        if ($depth > 8) {
            throw new InputError($where . ': menus can be at most 9 levels deep.');
        }
        $depthOf[$key] = $depth;
        $clean[] = ['key' => $key, 'id' => (int) ($it['id'] ?? 0), 'parent' => $parentKey, 'type' => $type, 'label' => $label,
                    'target' => $target, 'new_tab' => !empty($it['new_tab']) ? 1 : 0, 'hidden' => !empty($it['hidden']) ? 1 : 0];
    }
    $ids = db_atomic(static function () use ($clean): array {
        $menu = header_menu_id();
        $existing = array_map('intval', array_column(q_all('SELECT id FROM menu_items WHERE menu_id = ?', [$menu]), 'id'));
        $ids = [];
        foreach ($clean as $pos => $it) {
            $parentId = $it['parent'] === '' ? null : $ids[$it['parent']];
            $row = [$parentId, $pos, $it['type'], $it['label'], $it['target'], $it['new_tab'], $it['hidden']];
            if ($it['id'] > 0 && in_array($it['id'], $existing, true)) {
                q('UPDATE menu_items SET parent_id = ?, position = ?, type = ?, label = ?, target = ?, new_tab = ?, hidden = ? WHERE id = ?', [...$row, $it['id']]);
                $ids[$it['key']] = $it['id'];
            } else {
                q('INSERT INTO menu_items (menu_id, parent_id, position, type, label, target, new_tab, hidden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$menu, ...$row]);
                $ids[$it['key']] = (int) db()->lastInsertId();
            }
        }
        // Items the admin removed in the builder (with "Remove" and its confirm).
        foreach (array_diff($existing, array_values($ids)) as $gone) {
            q('DELETE FROM menu_items WHERE id = ?', [$gone]);
        }
        return $ids;
    });
    audit('menu.save', count($clean) . ' items');
    cache_clear(); // the header is on every page
    return ['ids' => $ids, 'saved' => true];
}

/**
 * Stories for the menu builder's story picker (any status: a link to a story that is not public yet stays hidden
 * in the header until it is).
 * @return list<array{id: int, title: string, state: string}>
 */
function menu_find_stories(string $q): array
{
    $q = clean_text($q, 120);
    $rows = $q === ''
        ? q_all("SELECT id, title, status, published_at FROM posts WHERE status <> 'trash' ORDER BY id DESC LIMIT 10")
        : q_all("SELECT id, title, status, published_at FROM posts WHERE status <> 'trash' AND LOWER(title) LIKE ? ORDER BY id DESC LIMIT 10",
            ['%' . mb_strtolower(str_replace(['%', '_'], ' ', $q)) . '%']);
    return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'],
        'state' => story_state((string) $r['status'], $r['published_at'] === null ? null : (string) $r['published_at'])], $rows);
}

/* ---------- Categories (F17) ---------- */

/** @return array<string, bool> category slug => shown in the header menu */
function categories_in_menu(): array
{
    $out = [];
    foreach (q_all("SELECT i.target, i.hidden FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.location = 'header' AND i.type = 'category'") as $r) {
        $out[(string) $r['target']] = ($out[(string) $r['target']] ?? false) || (int) $r['hidden'] === 0;
    }
    return $out;
}

/** The ids of a category and every category under it (any depth). @return list<int> */
function category_branch(int $id): array
{
    $all = q_all('SELECT id, parent_id FROM categories');
    $ids = [$id];
    for ($added = true; $added;) {
        $added = false;
        foreach ($all as $c) {
            if ($c['parent_id'] !== null && in_array((int) $c['parent_id'], $ids, true) && !in_array((int) $c['id'], $ids, true)) {
                $ids[] = (int) $c['id'];
                $added = true;
            }
        }
    }
    return $ids;
}

/**
 * Rename a category, change its address, parent and whether the header menu shows it.
 * Its address is used by menu items and homepage sections: those follow a new address in the same transaction.
 * $menuAllowed: only admins change the header menu (the menu builder is admin-only too); for editors the
 * category's place in the menu stays as it is (security review, step 5).
 * @param array<string, mixed> $in
 * @return array<string, mixed> the saved category
 */
function category_update(int $id, array $in, bool $menuAllowed = false): array
{
    $old = q_one('SELECT id, name, slug, parent_id FROM categories WHERE id = ?', [$id]);
    if ($old === null) {
        throw new InputError('This category does not exist.');
    }
    $name = clean_text($in['name'] ?? '', 120);
    if ($name === '') {
        throw new InputError('Please type the category name.');
    }
    $slug = slugify(clean_text($in['slug'] ?? '', 120));
    if ($slug === '') {
        $slug = (string) $old['slug'];
    }
    if (in_array($slug, reserved_slugs(), true)) {
        throw new InputError('The address /' . $slug . ' is kept for the site\'s own pages. Please choose another one.');
    }
    if (q_one('SELECT id FROM categories WHERE slug = ? AND id <> ?', [$slug, $id]) !== null) {
        throw new InputError('Another category already uses the address /' . $slug . '.');
    }
    $parentId = (int) ($in['parent_id'] ?? 0);
    if ($parentId > 0) {
        if (q_one('SELECT id FROM categories WHERE id = ?', [$parentId]) === null) {
            throw new InputError('That parent category does not exist.');
        }
        if (in_array($parentId, category_branch($id), true)) {
            throw new InputError('A category cannot go inside itself or inside one of its own sub-categories.');
        }
    }
    $inMenu = !empty($in['in_menu']);
    db_atomic(static function () use ($id, $name, $slug, $parentId, $old, $inMenu, $menuAllowed): void {
        q('UPDATE categories SET name = ?, slug = ?, parent_id = ? WHERE id = ?', [$name, $slug, $parentId > 0 ? $parentId : null, $id]);
        if ($slug !== (string) $old['slug']) {
            q("UPDATE menu_items SET target = ? WHERE type = 'category' AND target = ?", [$slug, (string) $old['slug']]);
            home_sections_replace_slug(['categories', 'exclude_categories'], (string) $old['slug'], $slug);
        }
        if ($menuAllowed) {
            category_set_in_menu($slug, $name, $inMenu);
        }
    });
    if ($name !== (string) $old['name']) {
        search_reindex_where('p.category_id = ?', [$id]); // the category name is searchable (task B11)
    }
    audit('category.update', '#' . $id . ' ' . $old['name'] . ' -> ' . $name . ' /' . $slug);
    cache_clear();
    return q_one('SELECT id, name, slug, parent_id FROM categories WHERE id = ?', [$id]) ?? [];
}

/**
 * Show or hide a category in the header menu. Showing it unhides its menu item or adds one at the end of the top
 * level; hiding it hides the item (it keeps its place in the menu builder).
 */
function category_set_in_menu(string $slug, string $name, bool $show): void
{
    $menu = header_menu_id();
    $items = q_all("SELECT id FROM menu_items WHERE menu_id = ? AND type = 'category' AND target = ?", [$menu, $slug]);
    if ($items !== []) {
        q("UPDATE menu_items SET hidden = ? WHERE menu_id = ? AND type = 'category' AND target = ?", [$show ? 0 : 1, $menu, $slug]);
        return;
    }
    if ($show) {
        $pos = (int) (q_one('SELECT MAX(position) AS n FROM menu_items WHERE menu_id = ? AND parent_id IS NULL', [$menu])['n'] ?? 0) + 1;
        q("INSERT INTO menu_items (menu_id, parent_id, position, type, label, target, new_tab, hidden) VALUES (?, NULL, ?, 'category', ?, ?, 0, 0)", [$menu, $pos, $name, $slug]);
    }
}

/**
 * New category order from the Categories page (drag or ↑ ↓): every category's position is its place in the list.
 * @param list<int> $ids
 */
function categories_order(array $ids): int
{
    $all = array_map('intval', array_column(q_all('SELECT id FROM categories'), 'id'));
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($all);
    $check = $ids;
    sort($check);
    if ($check !== $all) {
        throw new InputError('The list of categories changed meanwhile. Please reload the page.');
    }
    db_atomic(static function () use ($ids): void {
        foreach ($ids as $pos => $id) {
            q('UPDATE categories SET position = ? WHERE id = ?', [$pos, $id]);
        }
    });
    audit('category.order', implode(',', $ids));
    cache_clear(); // the footer and the search filter list categories in this order
    return count($ids);
}

/**
 * Homepage sections keep category and tag addresses in their settings: rename (or merge) one there.
 * @param list<string> $keys
 */
function home_sections_replace_slug(array $keys, string $old, string $new): void
{
    foreach (q_all('SELECT id, settings FROM home_sections') as $row) {
        $s = json_decode((string) $row['settings'], true);
        if (!is_array($s)) {
            continue;
        }
        $changed = false;
        foreach ($keys as $key) {
            $list = array_map('strval', (array) ($s[$key] ?? []));
            if (in_array($old, $list, true)) {
                $s[$key] = array_values(array_unique(array_map(static fn (string $v): string => $v === $old ? $new : $v, $list)));
                $changed = true;
            }
        }
        if ($changed) {
            q('UPDATE home_sections SET settings = ? WHERE id = ?', [json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int) $row['id']]);
        }
    }
}

/* ---------- Tags (B8) ---------- */

/**
 * Rename a tag (and optionally its address). The stories with it are indexed again: tag names are searchable.
 * @return array<string, mixed>
 */
function tag_rename(int $id, string $name, string $slug): array
{
    $old = q_one('SELECT id, name, slug FROM tags WHERE id = ?', [$id]);
    if ($old === null) {
        throw new InputError('This tag does not exist.');
    }
    $name = clean_text($name, 120);
    $slug = slugify(clean_text($slug, 120));
    if ($name === '' || slugify($name) === '') {
        throw new InputError('Please type a tag name with letters or numbers.');
    }
    if ($slug === '') {
        $slug = (string) $old['slug'];
    }
    if (q_one('SELECT id FROM tags WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $id]) !== null) {
        throw new InputError('Another tag is already called ' . $name . '. Merge the two tags instead.');
    }
    if (q_one('SELECT id FROM tags WHERE slug = ? AND id <> ?', [$slug, $id]) !== null) {
        throw new InputError('Another tag already uses the address /tag/' . $slug . '. Merge the two tags instead.');
    }
    db_atomic(static function () use ($id, $name, $slug, $old): void {
        q('UPDATE tags SET name = ?, slug = ? WHERE id = ?', [$name, $slug, $id]);
        if ($slug !== (string) $old['slug']) {
            q("UPDATE menu_items SET target = ? WHERE type = 'tag' AND target = ?", [$slug, (string) $old['slug']]);
            home_sections_replace_slug(['tags'], (string) $old['slug'], $slug);
        }
    });
    $n = search_reindex_where('p.id IN (SELECT pt.post_id FROM post_tags pt WHERE pt.tag_id = ?)', [$id]);
    audit('tag.rename', $old['name'] . ' -> ' . $name . ' /tag/' . $slug . ' (' . $n . ' stories reindexed)');
    cache_clear();
    return ['id' => $id, 'name' => $name, 'slug' => $slug, 'reindexed' => $n];
}

/**
 * Merge tag $fromId into tag $intoId: every story with the first tag gets the second (no story gets it twice),
 * menu items and homepage sections follow, and the first tag is removed. One transaction.
 * @return array{moved: int, into: string}
 */
function tag_merge(int $fromId, int $intoId): array
{
    $from = q_one('SELECT id, name, slug FROM tags WHERE id = ?', [$fromId]);
    $into = q_one('SELECT id, name, slug FROM tags WHERE id = ?', [$intoId]);
    if ($from === null || $into === null) {
        throw new InputError('Please choose two existing tags.');
    }
    if ($fromId === $intoId) {
        throw new InputError('A tag cannot be merged into itself.');
    }
    $moved = db_atomic(static function () use ($fromId, $intoId, $from, $into): int {
        $posts = array_map('intval', array_column(q_all('SELECT post_id FROM post_tags WHERE tag_id = ?', [$fromId]), 'post_id'));
        $has = array_map('intval', array_column(q_all('SELECT post_id FROM post_tags WHERE tag_id = ?', [$intoId]), 'post_id'));
        $moved = 0;
        foreach ($posts as $postId) {
            if (!in_array($postId, $has, true)) {
                q('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, $intoId]);
                $moved++;
            }
        }
        q('DELETE FROM post_tags WHERE tag_id = ?', [$fromId]);
        q("UPDATE menu_items SET target = ? WHERE type = 'tag' AND target = ?", [(string) $into['slug'], (string) $from['slug']]);
        home_sections_replace_slug(['tags'], (string) $from['slug'], (string) $into['slug']);
        q('DELETE FROM tags WHERE id = ?', [$fromId]);
        return $moved;
    });
    search_reindex_where('p.id IN (SELECT pt.post_id FROM post_tags pt WHERE pt.tag_id = ?)', [$intoId]);
    audit('tag.merge', $from['name'] . ' -> ' . $into['name'] . ' (' . $moved . ' stories moved)');
    cache_clear();
    return ['moved' => $moved, 'into' => (string) $into['name']];
}

/* ---------- Pictures (F17) ---------- */

/**
 * Where a picture is used: main photo, gallery, inside a story body, author profile picture.
 * @return list<array{what: string, label: string, href: string}>
 */
function media_usage(int $id): array
{
    $out = [];
    $state = static fn (array $r): string => story_state((string) $r['status'], $r['published_at'] === null ? null : (string) $r['published_at']);
    foreach (q_all('SELECT id, title, status, published_at FROM posts WHERE media_id = ? ORDER BY id', [$id]) as $r) {
        $out[] = ['what' => 'Main photo', 'label' => $r['title'] . ' (' . $state($r) . ')', 'href' => '/admin/stories/' . (int) $r['id']];
    }
    foreach (q_all('SELECT DISTINCT p.id, p.title, p.status, p.published_at FROM post_gallery g JOIN posts p ON p.id = g.post_id WHERE g.media_id = ? ORDER BY p.id', [$id]) as $r) {
        $out[] = ['what' => 'Gallery', 'label' => $r['title'] . ' (' . $state($r) . ')', 'href' => '/admin/stories/' . (int) $r['id']];
    }
    foreach (q_all('SELECT id, title, status, published_at FROM posts WHERE body LIKE ? ORDER BY id', ['%data-media="' . $id . '"%']) as $r) {
        $out[] = ['what' => 'In the story text', 'label' => $r['title'] . ' (' . $state($r) . ')', 'href' => '/admin/stories/' . (int) $r['id']];
    }
    foreach (q_all('SELECT id, name FROM authors WHERE media_id = ? ORDER BY id', [$id]) as $r) {
        $out[] = ['what' => 'Profile picture', 'label' => (string) $r['name'], 'href' => '/admin/authors/' . (int) $r['id']];
    }
    return $out;
}

/** Edit a picture's alt text, caption and credit (used wherever a story has no caption of its own). */
function media_update(int $id, string $alt, string $caption, string $credit): void
{
    if (q_one('SELECT id FROM media WHERE id = ?', [$id]) === null) {
        throw new InputError('This picture does not exist.');
    }
    q('UPDATE media SET alt = ?, caption = ?, credit = ? WHERE id = ?', [clean_text($alt, 300), clean_text($caption, 600), clean_text($credit, 200), $id]);
    audit('media.update', '#' . $id);
    cache_clear();
}

/**
 * Delete a picture that nothing uses: its row and its own files (every width, AVIF and WebP). Files are only
 * removed inside uploads/, and only when no other picture row points at the same files.
 * @return int files removed
 */
function media_delete(int $id): int
{
    $m = q_one('SELECT id, path, widths FROM media WHERE id = ?', [$id]);
    if ($m === null) {
        throw new InputError('This picture does not exist any more.');
    }
    $files = db_atomic(static function () use ($id, $m): array {
        if (media_usage($id) !== []) {
            throw new InputError('This picture is still used, so it cannot be deleted. Remove it from the places listed first.');
        }
        q('DELETE FROM media WHERE id = ?', [$id]);
        if (q_one('SELECT id FROM media WHERE path = ?', [(string) $m['path']]) !== null) {
            return []; // another picture row shares these files
        }
        $files = [];
        foreach (array_filter(array_map('intval', explode(',', (string) $m['widths']))) as $w) {
            foreach (['webp', 'avif'] as $ext) {
                $files[] = (string) $m['path'] . '-' . $w . '.' . $ext;
            }
        }
        return $files;
    });
    $uploads = realpath(PUBLIC_DIR . '/uploads');
    $removed = 0;
    foreach ($files as $rel) {
        if (!str_starts_with($rel, 'uploads/') || str_contains($rel, '..')) {
            continue;
        }
        $abs = realpath(PUBLIC_DIR . '/' . $rel);
        if ($uploads !== false && $abs !== false && str_starts_with($abs, $uploads . DIRECTORY_SEPARATOR) && is_file($abs) && @unlink($abs)) {
            $removed++;
        }
    }
    audit('media.delete', '#' . $id . ' ' . $m['path'] . ' (' . $removed . ' files)');
    cache_clear();
    return $removed;
}

/* ---------- Delete for good (B7) ---------- */

/**
 * Delete stories in the trash for good: the story, its tag links, gallery rows, search index rows and menu links
 * to it, in one transaction per story. Stories not in the trash are left alone. Pictures stay (Pictures page).
 * @param list<int> $ids
 */
function stories_delete_forever(array $ids, int $userId): int
{
    $titles = [];
    foreach ($ids as $id) {
        $done = db_atomic(static function () use ($id): ?string {
            $row = q_one("SELECT id, title FROM posts WHERE id = ? AND status = 'trash'", [$id]);
            if ($row === null) {
                return null;
            }
            q('DELETE FROM post_tags WHERE post_id = ?', [$id]);
            q('DELETE FROM post_gallery WHERE post_id = ?', [$id]);
            search_forget_post_now($id);
            q("DELETE FROM menu_items WHERE type = 'story' AND target = ?", [(string) $id]);
            q('DELETE FROM posts WHERE id = ?', [$id]);
            return '#' . $id . ' ' . $row['title'];
        });
        if ($done !== null) {
            $titles[] = $done;
        }
    }
    if ($titles !== []) {
        audit('stories.delete', mb_substr(implode('; ', $titles), 0, 500), $userId);
        cache_clear();
    }
    return count($titles);
}

/* ---------- Site settings (F17) ---------- */

/**
 * Save the site settings: name, tagline, about text, site address, time zone, stories per category page.
 * @param array<string, mixed> $in
 */
function site_settings_save(array $in): void
{
    $name = clean_text($in['site_name'] ?? '', 120);
    $tagline = clean_text($in['tagline'] ?? '', 200);
    $about = clean_text($in['about'] ?? '', 600);
    $url = rtrim(clean_text($in['site_url'] ?? '', 200), '/');
    $tz = clean_text($in['timezone'] ?? '', 64);
    $per = (int) ($in['category_per_page'] ?? 20);
    if ($name === '') {
        throw new InputError('Please type the site name.');
    }
    if ($url !== '') {
        $parts = parse_url($url);
        $ok = filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && preg_match('/^[a-z0-9.-]+$/i', (string) ($parts['host'] ?? '')) === 1 && !isset($parts['query']) && !isset($parts['fragment'])
            && !isset($parts['user']) && trim((string) ($parts['path'] ?? ''), '/') === '';
        if (!$ok) {
            throw new InputError('The site address must look like https://globalarmspost.com (no page after it).');
        }
        $url = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        throw new InputError('Please choose a time zone from the list.');
    }
    if ($per < 5 || $per > 50) {
        throw new InputError('Stories per category page must be between 5 and 50.');
    }
    db_atomic(static function () use ($name, $tagline, $about, $url, $tz, $per): void {
        foreach (['site_name' => $name, 'tagline' => $tagline, 'about' => $about, 'site_url' => $url, 'timezone' => $tz, 'category_per_page' => (string) $per] as $k => $v) {
            setting_save($k, $v);
        }
    });
    audit('settings.site', $name . ' · ' . ($url !== '' ? $url : 'no site address') . ' · ' . $tz . ' · ' . $per . ' per page');
    cache_clear();
}

/* ---------- Homepage builder: preview (F16) ---------- */

/**
 * Check the builder's sections and give them back as rows (the same checks as saving), without writing anything.
 * @param array<string, mixed> $in
 * @return list<array{id: int, layout: string, title: string, span: int, active: int, settings: array<string, mixed>}>
 */
function homepage_normalize(array $in): array
{
    $layouts = section_layouts();
    $slugs = array_column(category_tree(), 'slug');
    $tagSlugs = array_column(q_all('SELECT slug FROM tags'), 'slug');
    $list = array_values(array_filter((array) ($in['sections'] ?? []), 'is_array'));
    if (count($list) > 60) {
        throw new InputError('Too many sections.');
    }
    $rows = [];
    foreach ($list as $sec) {
        $layout = (string) ($sec['layout'] ?? '');
        if (!isset($layouts[$layout])) {
            throw new InputError('Unknown layout: ' . $layout);
        }
        $raw = is_array($sec['settings'] ?? null) ? $sec['settings'] : [];
        $s = [];
        foreach ($layouts[$layout]['settings'] as $key => $default) {
            $v = $raw[$key] ?? $default;
            $s[$key] = match ($key) {
                'categories', 'exclude_categories' => array_values(array_intersect(array_map('strval', (array) $v), $slugs)),
                'tags' => array_values(array_intersect(array_map('strval', (array) $v), $tagSlugs)),
                'order_by' => in_array($v, ['date', 'updated', 'title', 'id', 'views', 'random'], true) ? $v : 'date',
                'dir' => $v === 'asc' ? 'asc' : 'desc',
                'count' => max(1, min(30, (int) $v)),
                'columns' => max(1, min(6, (int) $v)),
                'skip' => max(0, min(100, (int) $v)),
                'photo_height', 'thumb_size' => max(1, min(5, (int) $v)),
                default => is_bool($default) ? (bool) $v : $v,
            };
        }
        $rows[] = ['id' => (int) ($sec['id'] ?? 0), 'layout' => $layout, 'title' => clean_text($sec['title'] ?? '', 120),
                   'span' => max(3, min(12, (int) ($sec['span'] ?? 12))), 'active' => !empty($sec['active']) ? 1 : 0, 'settings' => $s];
    }
    return $rows;
}

/* ---------- Backup (B17) ---------- */

function backup_dir(): string
{
    return PUBLIC_DIR . '/storage/backups';
}

/** Full path of a backup by its file name, or null (only names this code makes are accepted). */
function backup_path(string $name): ?string
{
    if (preg_match('/^gap-backup-\d{8}-\d{6}-[a-f0-9]{6}\.zip$/', $name) !== 1) {
        return null;
    }
    $file = backup_dir() . '/' . $name;
    return is_file($file) ? $file : null;
}

/** @return list<array{name: string, bytes: int, at: int}> earlier backups, newest first */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/gap-backup-*.zip') ?: [] as $file) {
        if (backup_path(basename($file)) !== null) {
            $out[] = ['name' => basename($file), 'bytes' => (int) filesize($file), 'at' => (int) filemtime($file)];
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
    return $out;
}

/** @return list<string> every table of the site's database (MySQL or SQLite) */
function backup_tables(): array
{
    $names = db_driver() === 'mysql'
        ? array_map(static fn (array $r): string => (string) array_values($r)[0], q_all('SHOW TABLES'))
        : array_map('strval', array_column(q_all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name'));
    return array_values(array_filter($names, static fn (string $n): bool => preg_match('/^[A-Za-z0-9_]+$/', $n) === 1));
}

/** @return list<string> tables a backup never holds (sign-in session data, not content) */
function backup_left_out(): array
{
    return ['remember_tokens', 'login_attempts'];
}

/**
 * Make a backup ZIP in storage/backups (never served: storage/ is blocked): backup.json (what is inside), one JSON
 * file per table in db/, and every file of uploads/. Written under a temporary name first, so the list never shows
 * a half-written backup.
 * @return array{name: string, bytes: int, tables: int, rows: int, files: int}
 */
function backup_create(): array
{
    if (!class_exists('ZipArchive')) {
        throw new InputError('This server\'s PHP has no ZIP support (the zip extension), so backups cannot be made here.');
    }
    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new InputError('The folder storage/backups cannot be made. Please check the folder permissions.');
    }
    $name = 'gap-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
    $tmp = $dir . '/' . $name . '.part';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new InputError('The backup file could not be written in storage/backups.');
    }
    $tables = [];
    $rows = 0;
    foreach (backup_tables() as $table) {
        // Left out (security review, step 5): "keep me signed in" tokens and the sign-in attempt log. A restore
        // does not need them, and token hashes have no place in a file that is downloaded and kept elsewhere.
        if (in_array($table, backup_left_out(), true)) {
            continue;
        }
        $data = q_all('SELECT * FROM ' . $table);
        $tables[$table] = count($data);
        $rows += count($data);
        $zip->addFromString('db/' . $table . '.json', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    $files = 0;
    $uploads = PUBLIC_DIR . '/uploads';
    if (is_dir($uploads)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink()) {
                $rel = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                $zip->addFile($file->getPathname(), $rel);
                $files++;
            }
        }
    }
    $zip->addFromString('backup.json', (string) json_encode([
        'site' => setting('site_name', 'Global Arms Post'),
        'made_at' => now_utc(),
        'database' => db_driver(),
        'code' => build_info()['commit'],
        'migrations' => array_column(q_all('SELECT id FROM migrations ORDER BY id'), 'id'),
        'tables' => $tables,
        'left_out' => backup_left_out(),
        'upload_files' => $files,
        'restore' => 'Not built yet. Each db/<table>.json is that table\'s rows; uploads/ is the uploads folder.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (!$zip->close() || !@rename($tmp, $dir . '/' . $name)) {
        throw new InputError('The backup could not be finished. Is there enough free disk space?');
    }
    $bytes = (int) filesize($dir . '/' . $name);
    audit('backup.create', $name . ' (' . count($tables) . ' tables, ' . $rows . ' rows, ' . $files . ' files)');
    return ['name' => $name, 'bytes' => $bytes, 'tables' => count($tables), 'rows' => $rows, 'files' => $files];
}
