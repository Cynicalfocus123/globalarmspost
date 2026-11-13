<?php
declare(strict_types=1);

/**
 * Saving content from the admin: stories (B7), categories and tags on the spot (B8), authors (B19)
 * and pictures (B6). Only loaded for /admin. Every value is checked here, not only in the browser.
 */

final class InputError extends RuntimeException
{
}

/** A slug not used yet in this table ("name", "name-2", ...). */
function unique_slug(string $table, string $base, int $exceptId = 0): string
{
    $base = $base === '' ? 'item' : $base;
    $slug = $base;
    for ($n = 2; q_one("SELECT id FROM $table WHERE slug = ? AND id <> ?", [$slug, $exceptId]) !== null; $n++) {
        $slug = substr($base, 0, 90) . '-' . $n;
    }
    return $slug;
}

function clean_text(mixed $value, int $max): string
{
    $text = trim(str_replace("\0", '', is_scalar($value) ? (string) $value : ''));
    return mb_substr($text, 0, $max);
}

/* ---------- Stories ---------- */

/** "draft" | "scheduled" | "published" | "trash" for a stored story. */
function story_state(string $status, ?string $publishedAt): string
{
    if ($status !== 'published') {
        return $status;
    }
    return $publishedAt !== null && $publishedAt > now_utc() ? 'scheduled' : 'published';
}

/** Site-time "2026-11-05T18:00" -> UTC "2026-11-05 11:00:00". */
function local_input_to_utc(string $local): ?string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $local) !== 1) {
        return null;
    }
    try {
        return (new DateTimeImmutable($local, site_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function utc_to_local_input(?string $utc): string
{
    return $utc === null || $utc === '' ? '' : local_dt($utc)->format('Y-m-d\TH:i');
}

/** @return array<string, mixed>|null a story with everything the editor needs */
function story_for_editor(int $id): ?array
{
    $p = q_one('SELECT p.*, m.path AS m_path, m.widths AS m_widths, m.has_avif AS m_has_avif, m.width AS m_width, m.height AS m_height, m.caption AS m_caption, m.credit AS m_credit
                  FROM posts p LEFT JOIN media m ON m.id = p.media_id WHERE p.id = ?', [$id]);
    if ($p === null) {
        return null;
    }
    $p['tags'] = q_all('SELECT t.id, t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.name', [$id]);
    $p['extra_categories'] = post_extra_categories($id);
    $p['gallery'] = q_all('SELECT g.media_id, g.caption, g.credit, m.path, m.widths FROM post_gallery g JOIN media m ON m.id = g.media_id
                            WHERE g.post_id = ? ORDER BY g.position, g.id', [$id]);
    // Old plain-text bodies open as paragraphs.
    $body = (string) $p['body'];
    $p['body_html'] = body_is_html($body) ? clean_html($body) : body_html($body);
    return $p;
}

/**
 * Save a story from the editor.
 * @param array<string, mixed> $in
 * @return array<string, mixed> id, slug, state, published_at (local), updated text
 */
function story_save(array $in, int $userId): array
{
    $id = (int) ($in['id'] ?? 0);
    $old = $id > 0 ? q_one('SELECT id, status, published_at, created_at FROM posts WHERE id = ?', [$id]) : null;
    if ($id > 0 && $old === null) {
        throw new InputError('This story does not exist any more.');
    }
    $title = clean_text($in['title'] ?? '', 300);
    if ($title === '') {
        throw new InputError('Please write a headline.');
    }
    $categoryId = (int) ($in['category_id'] ?? 0);
    if (q_one('SELECT id FROM categories WHERE id = ?', [$categoryId]) === null) {
        throw new InputError('Please choose a category.');
    }
    $authorId = (int) ($in['author_id'] ?? 0);
    $author = $authorId > 0 ? q_one('SELECT id, name FROM authors WHERE id = ?', [$authorId]) : null;
    if ($authorId > 0 && $author === null) {
        throw new InputError('That author does not exist.');
    }
    $mediaId = (int) ($in['media_id'] ?? 0);
    if ($mediaId > 0 && q_one('SELECT id FROM media WHERE id = ?', [$mediaId]) === null) {
        throw new InputError('The main photo is missing. Please add it again.');
    }
    $status = ($in['status'] ?? '') === 'published' ? 'published' : 'draft';
    $local = clean_text($in['published_local'] ?? '', 20);
    $publishedAt = $local === '' ? null : local_input_to_utc($local);
    if ($local !== '' && $publishedAt === null) {
        throw new InputError('The date and time are not valid.');
    }
    if ($status === 'published' && $publishedAt === null) {
        $publishedAt = now_utc();
    }
    $slugIn = slugify(clean_text($in['slug'] ?? '', 200));
    $slug = unique_slug('posts', $slugIn !== '' ? $slugIn : (slugify($title) ?: 'story'), $id);
    $now = now_utc();
    $row = [
        'title' => $title,
        'slug' => $slug,
        'excerpt' => clean_text($in['excerpt'] ?? '', 1000),
        'summary' => clean_text($in['summary'] ?? '', 3000),
        'body' => clean_html(is_string($in['body'] ?? null) ? mb_substr((string) $in['body'], 0, 500000) : ''),
        'credits' => clean_text($in['credits'] ?? '', 300),
        'author_id' => $author === null ? null : (int) $author['id'],
        'author_name' => $author === null ? '' : (string) $author['name'],
        'category_id' => $categoryId,
        'media_id' => $mediaId > 0 ? $mediaId : null,
        'featured_caption' => clean_text($in['featured_caption'] ?? '', 600),
        'featured_credit' => clean_text($in['featured_credit'] ?? '', 200),
        'status' => $status,
        'sticky' => !empty($in['sticky']) ? 1 : 0,
        'live_label' => !empty($in['live_label']) ? 1 : 0,
        'published_at' => $publishedAt,
        'updated_at' => $now,
    ];
    $tagIds = array_values(array_unique(array_filter(array_map('intval', (array) ($in['tag_ids'] ?? [])))));
    // Extra categories (owner, 2026-11-13): the story also shows there. Only categories that exist, never the main one.
    $extraIds = array_values(array_diff(array_unique(array_filter(array_map('intval', array_slice((array) ($in['extra_category_ids'] ?? []), 0, 50)))), [$categoryId]));
    if ($extraIds !== []) {
        $known = array_map('intval', array_column(q_all('SELECT id FROM categories WHERE id IN (' . implode(',', $extraIds) . ')'), 'id'));
        $extraIds = array_values(array_intersect($extraIds, $known));
    }
    $gallery = [];
    foreach (array_slice((array) ($in['gallery'] ?? []), 0, 40) as $g) {
        $mid = (int) (is_array($g) ? ($g['media_id'] ?? 0) : 0);
        if ($mid > 0 && q_one('SELECT id FROM media WHERE id = ?', [$mid]) !== null) {
            $gallery[] = [$mid, clean_text($g['caption'] ?? '', 600), clean_text($g['credit'] ?? '', 200)];
        }
    }

    // One db_atomic() transaction: on MySQL two saves at the same moment can deadlock on the tag and gallery rows
    // (MySQL then cancels one of them); db_atomic() runs it again instead of failing the save. Found by the step 5
    // MySQL run: the plain transaction here answered with a PHP error page.
    $id = db_atomic(static function () use ($old, $row, $now, $id, $tagIds, $extraIds, $gallery): int {
        if ($old === null) {
            $row['created_at'] = $now;
            q('INSERT INTO posts (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
            $id = (int) db()->lastInsertId();
        } else {
            q('UPDATE posts SET ' . implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
        }
        q('DELETE FROM post_tags WHERE post_id = ?', [$id]); // the story's tag links are written fresh
        foreach ($tagIds as $tid) {
            if (q_one('SELECT id FROM tags WHERE id = ?', [$tid]) !== null) {
                q('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$id, $tid]);
            }
        }
        q('DELETE FROM post_categories WHERE post_id = ?', [$id]); // the extra categories are written fresh
        foreach ($extraIds as $cid) {
            q('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)', [$id, $cid]);
        }
        q('DELETE FROM post_gallery WHERE post_id = ?', [$id]); // gallery order is written fresh
        foreach ($gallery as $pos => [$mid, $cap, $cred]) {
            q('INSERT INTO post_gallery (post_id, media_id, position, caption, credit) VALUES (?, ?, ?, ?, ?)', [$id, $mid, $pos, $cap, $cred]);
        }
        return $id;
    });
    audit($old === null ? 'story.create' : 'story.update', '#' . $id . ' ' . $title, $userId);
    // Reader side (tasks B11, B12): the story goes into the search index, and every cached page is stale now.
    search_index_post($id);
    cache_clear();
    return [
        'id' => $id,
        'slug' => $slug,
        'state' => story_state($status, $publishedAt),
        'published_local' => utc_to_local_input($publishedAt),
        'url' => story_public_url($id),
    ];
}

/** Move stories to the trash or publish them (bulk actions). @param list<int> $ids */
function stories_set_status(array $ids, string $action, int $userId): int
{
    $n = 0;
    foreach ($ids as $id) {
        $row = q_one('SELECT id, status, published_at FROM posts WHERE id = ?', [$id]);
        if ($row === null) {
            continue;
        }
        if ($action === 'trash') {
            q("UPDATE posts SET status = 'trash', updated_at = ? WHERE id = ?", [now_utc(), $id]);
        } elseif ($action === 'publish' || $action === 'restore') {
            $status = $action === 'restore' ? 'draft' : 'published';
            $at = $row['published_at'] ?? null;
            if ($status === 'published' && ($at === null || $at === '')) {
                $at = now_utc();
            }
            q('UPDATE posts SET status = ?, published_at = ?, updated_at = ? WHERE id = ?', [$status, $at, now_utc(), $id]);
        } else {
            continue;
        }
        $n++;
    }
    audit('stories.' . $action, implode(',', $ids), $userId);
    // Publish / trash / restore change what the reader side shows, so the index and the cache follow.
    foreach ($ids as $id) {
        search_index_post((int) $id);
    }
    cache_clear();
    return $n;
}

/** The public address of a story (for "View"), or '' if it is not public yet. */
function story_public_url(int $id): string
{
    $p = q_one(posts_select() . " WHERE p.id = ? AND p.status = 'published' AND p.published_at <= ?", [$id, now_utc()]);
    return $p === null ? '' : post_url($p);
}

/* ---------- Categories, tags, authors ---------- */

/** @return array{id: int, name: string, slug: string, parent_id: int|null, created: bool} */
function tag_create(string $name): array
{
    $name = clean_text($name, 120);
    $slug = slugify($name);
    if ($name === '' || $slug === '') {
        throw new InputError('Please type a tag name with letters or numbers.');
    }
    $found = q_one('SELECT id, name, slug FROM tags WHERE slug = ? OR LOWER(name) = LOWER(?)', [$slug, $name]);
    if ($found !== null) {
        return ['id' => (int) $found['id'], 'name' => (string) $found['name'], 'slug' => (string) $found['slug'], 'parent_id' => null, 'created' => false];
    }
    q('INSERT INTO tags (name, slug) VALUES (?, ?)', [$name, $slug]);
    $id = (int) db()->lastInsertId();
    audit('tag.create', $name);
    cache_clear(); // the search page's filters and any page listing tags may change (task B12)
    return ['id' => $id, 'name' => $name, 'slug' => $slug, 'parent_id' => null, 'created' => true];
}

/** @return array{id: int, name: string, slug: string, parent_id: int|null, created: bool} */
function category_create(string $name, int $parentId): array
{
    $name = clean_text($name, 120);
    $base = slugify($name);
    if ($name === '' || $base === '') {
        throw new InputError('Please type a category name with letters or numbers.');
    }
    if ($parentId > 0 && q_one('SELECT id FROM categories WHERE id = ?', [$parentId]) === null) {
        throw new InputError('That parent category does not exist.');
    }
    $found = q_one('SELECT id, name, slug, parent_id FROM categories WHERE LOWER(name) = LOWER(?) AND COALESCE(parent_id, 0) = ?', [$name, $parentId]);
    if ($found !== null) {
        return ['id' => (int) $found['id'], 'name' => (string) $found['name'], 'slug' => (string) $found['slug'], 'parent_id' => $found['parent_id'] === null ? null : (int) $found['parent_id'], 'created' => false];
    }
    // live/ puts categories at the top level (/asia), so reserved names get a suffix.
    $slug = in_array($base, reserved_slugs(), true) || page_by_slug($base) !== null ? $base . '-news' : $base;
    $slug = unique_slug('categories', $slug);
    $pos = (int) (q_one('SELECT MAX(position) AS n FROM categories')['n'] ?? 0) + 1;
    q('INSERT INTO categories (parent_id, name, slug, position, created_at) VALUES (?, ?, ?, ?, ?)', [$parentId > 0 ? $parentId : null, $name, $slug, $pos, now_utc()]);
    $id = (int) db()->lastInsertId();
    audit('category.create', $name);
    cache_clear(); // a new category can show in lists and filters (task B12)
    return ['id' => $id, 'name' => $name, 'slug' => $slug, 'parent_id' => $parentId > 0 ? $parentId : null, 'created' => true];
}

/**
 * Categories as a tree list in menu order, each with its depth (for indented checkbox lists).
 * @return list<array{id: int, name: string, slug: string, parent_id: int|null, depth: int}>
 */
function category_tree(): array
{
    $rows = q_all('SELECT id, name, slug, parent_id FROM categories ORDER BY position, id');
    $by = [];
    foreach ($rows as $r) {
        $by[(int) ($r['parent_id'] ?? 0)][] = $r;
    }
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    $out = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $by): void {
        foreach ($depth > 8 ? [] : ($by[$parent] ?? []) as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'],
                      'parent_id' => $r['parent_id'] === null ? null : (int) $r['parent_id'], 'depth' => $depth];
            $walk((int) $r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    foreach ($rows as $r) { // a category whose parent is gone still shows, at the top level
        if ($r['parent_id'] !== null && !in_array((int) $r['parent_id'], $ids, true)) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'parent_id' => null, 'depth' => 0];
        }
    }
    return $out;
}

/** @return array<string, mixed> */
function author_save(array $in, int $id = 0): array
{
    $name = clean_text($in['name'] ?? '', 120);
    $email = strtolower(clean_text($in['email'] ?? '', 190));
    $bio = clean_text($in['bio'] ?? '', 2000);
    $mediaId = (int) ($in['media_id'] ?? 0);
    if ($name === '') {
        throw new InputError('Please type the author\'s name.');
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InputError('That email address is not valid.');
    }
    if ($mediaId > 0 && q_one('SELECT id FROM media WHERE id = ?', [$mediaId]) === null) {
        throw new InputError('The profile picture is missing. Please add it again.');
    }
    if ($id > 0) {
        if (q_one('SELECT id FROM authors WHERE id = ?', [$id]) === null) {
            throw new InputError('This author does not exist.');
        }
        q('UPDATE authors SET name = ?, email = ?, bio = ?, media_id = ? WHERE id = ?', [$name, $email, $bio, $mediaId > 0 ? $mediaId : null, $id]);
        q('UPDATE posts SET author_name = ? WHERE author_id = ?', [$name, $id]);
        audit('author.update', $name);
        // The author's name is searchable, so those stories are indexed again (task B11).
        search_reindex_where('p.author_id = ?', [$id]);
        cache_clear();
    } else {
        q('INSERT INTO authors (name, slug, email, bio, media_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, unique_slug('authors', slugify($name) ?: 'author'), $email, $bio, $mediaId > 0 ? $mediaId : null, now_utc()]);
        $id = (int) db()->lastInsertId();
        audit('author.create', $name);
    }
    return author_row($id) ?? [];
}

/** @return array<string, mixed>|null author with picture address (smallest WebP) */
function author_row(int $id): ?array
{
    $a = q_one('SELECT a.id, a.name, a.slug, a.email, a.bio, a.media_id, m.path, m.widths FROM authors a LEFT JOIN media m ON m.id = a.media_id WHERE a.id = ?', [$id]);
    if ($a === null) {
        return null;
    }
    $a['avatar'] = media_thumb($a['path'] ?? null, $a['widths'] ?? null);
    unset($a['path'], $a['widths']);
    return $a;
}

/** Smallest WebP of a stored picture, for admin thumbnails. */
function media_thumb(mixed $path, mixed $widths): string
{
    if (!is_string($path) || $path === '') {
        return '';
    }
    $w = array_filter(array_map('intval', explode(',', (string) $widths)));
    sort($w);
    return $w === [] ? '' : '/' . ltrim($path, '/') . '-' . $w[0] . '.webp';
}

/* ---------- Pictures (B6) ---------- */

/** Widths per kind of picture: story photos 480/960/1600, profile pictures 160/320. @return list<int> */
function media_widths(string $kind): array
{
    return $kind === 'avatar' ? [160, 320] : [480, 960, 1600];
}

/** Sniff an image file by its first bytes (never by name or the browser's word). */
function image_kind(string $bytes): string
{
    return match (true) {
        str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
        str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'png',
        str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
        substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'webp',
        substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['avif', 'avis'], true) => 'avif',
        default => '',
    };
}

/** New file base inside uploads/: uploads/2026/11/3f9a1c-name */
function media_new_base(string $name): array
{
    $dir = 'uploads/' . gmdate('Y') . '/' . gmdate('m');
    $abs = PUBLIC_DIR . '/' . $dir;
    if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
        throw new RuntimeException('The uploads folder cannot be written.');
    }
    $slug = substr(slugify(pathinfo($name, PATHINFO_FILENAME)), 0, 40) ?: 'picture';
    $base = $dir . '/' . bin2hex(random_bytes(4)) . '-' . $slug;
    return [$base, PUBLIC_DIR . '/' . $base];
}

/**
 * Store a picture the admin's browser already made into AVIF + WebP.
 * $files: width => ['webp' => tmp path, 'avif' => tmp path|null]
 * @param array<int, array{webp: string, avif: ?string}> $files
 * @return array<string, mixed> the media row
 */
function media_store_variants(array $files, string $kind, string $name, string $alt, int $height): array
{
    $allowed = media_widths($kind);
    if ($files === [] || count($files) > count($allowed)) {
        throw new InputError('No picture sizes were sent.');
    }
    $hasAvif = true;
    foreach ($files as $w => $f) {
        if ($w <= 0 || $w > max($allowed)) {
            throw new InputError('A picture size is not allowed.');
        }
        $webp = (string) file_get_contents($f['webp'], false, null, 0, 16);
        $size = @getimagesize($f['webp']);
        if (image_kind($webp) !== 'webp' || $size === false || (int) $size[0] !== $w || filesize($f['webp']) > 8 * 1048576) {
            throw new InputError('A WebP file is not a real picture of the right size.');
        }
        if ($f['avif'] === null) {
            $hasAvif = false;
        } elseif (image_kind((string) file_get_contents($f['avif'], false, null, 0, 16)) !== 'avif' || filesize($f['avif']) > 8 * 1048576) {
            throw new InputError('An AVIF file is not a real AVIF picture.');
        }
    }
    ksort($files);
    [$base, $abs] = media_new_base($name);
    foreach ($files as $w => $f) {
        copy($f['webp'], "$abs-$w.webp");
        if ($hasAvif && $f['avif'] !== null) {
            copy($f['avif'], "$abs-$w.avif");
        }
    }
    $largest = max(array_keys($files));
    return media_insert($base, array_keys($files), $hasAvif, $largest, $height, $alt);
}

/**
 * Fallback: make the sizes on the server with PHP GD (when the browser cannot make WebP, e.g. Safari).
 * @return array<string, mixed>
 */
function media_store_source(string $tmp, string $kind, string $name, string $alt): array
{
    $bytes = (string) file_get_contents($tmp);
    $type = image_kind($bytes);
    if (!in_array($type, ['jpeg', 'png', 'gif', 'webp'], true) || strlen($bytes) > 20 * 1048576) {
        throw new InputError('Please choose a JPEG, PNG, GIF or WebP picture (up to 20 MB).');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        throw new InputError('This server cannot convert pictures (PHP GD is missing). Please use Chrome, Edge or Firefox.');
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40_000_000) {
        throw new InputError('The picture could not be read.');
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        throw new InputError('The picture could not be read.');
    }
    [$w0, $h0] = [imagesx($src), imagesy($src)];
    $widths = array_values(array_filter(media_widths($kind), fn ($w) => $w <= $w0));
    if ($widths === []) {
        $widths = [$w0];
    }
    [$base, $abs] = media_new_base($name);
    $avif = function_exists('imageavif');
    foreach ($widths as $w) {
        $h = max(1, (int) round($h0 * $w / $w0));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $w0, $h0);
        imagewebp($dst, "$abs-$w.webp", 80);
        // Speed 8: about 40x faster than GD's default 6 (4 s → 0.1 s for 960 px) for ~6% more bytes.
        if ($avif && !@imageavif($dst, "$abs-$w.avif", 50, 8)) {
            $avif = false;
        }
        imagedestroy($dst);
    }
    imagedestroy($src);
    $largest = max($widths);
    return media_insert($base, $widths, $avif, $largest, max(1, (int) round($h0 * $largest / $w0)), $alt);
}

/** @param list<int> $widths @return array<string, mixed> */
function media_insert(string $base, array $widths, bool $hasAvif, int $width, int $height, string $alt): array
{
    q('INSERT INTO media (path, widths, has_avif, width, height, alt, caption, credit, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$base, implode(',', $widths), $hasAvif ? 1 : 0, $width, $height, mb_substr($alt, 0, 300), '', '', now_utc()]);
    $id = (int) db()->lastInsertId();
    audit('media.upload', $base);
    return ['id' => $id, 'path' => $base, 'widths' => implode(',', $widths), 'has_avif' => $hasAvif ? 1 : 0, 'width' => $width, 'height' => $height,
            'thumb' => media_thumb($base, implode(',', $widths)), 'src' => '/' . $base . '-' . $widths[min(1, count($widths) - 1)] . '.webp'];
}
