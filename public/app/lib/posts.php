<?php
declare(strict_types=1);

/**
 * Story query engine (task B9). Only public stories: status 'published' AND published_at <= now (UTC),
 * so scheduled stories appear at their exact time.
 *
 * Options:
 *   categories          category slugs to include (their child categories too)
 *   exclude_categories  category slugs to leave out (children too)
 *   tags                tag slugs (any of them)
 *   order_by            date | updated | title | id | views | random
 *   dir                 desc | asc
 *   count, skip         how many, and how many to skip first (works with page numbers)
 *   page                1, 2, ... (page × count is added to skip)
 *   sticky_first        sticky stories before the rest
 *   exclude_ids         story ids already shown (avoid duplicates)
 *
 * @param array<string, mixed> $o
 * @return list<array<string, mixed>>
 */
function posts_query(array $o): array
{
    [$where, $params] = posts_where($o);
    $dir = strtolower((string) ($o['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $order = match ((string) ($o['order_by'] ?? 'date')) {
        'updated' => "p.updated_at $dir",
        'title' => "p.title $dir",
        'id' => "p.id $dir",
        'views' => "p.views $dir, p.published_at DESC",
        'random' => db_driver() === 'mysql' ? 'RAND()' : 'RANDOM()',
        default => "p.published_at $dir",
    };
    if (!empty($o['sticky_first'])) {
        $order = 'p.sticky DESC, ' . $order;
    }
    $count = max(1, min(100, (int) ($o['count'] ?? 10)));
    $page = max(1, (int) ($o['page'] ?? 1));
    $skip = max(0, (int) ($o['skip'] ?? 0)) + ($page - 1) * $count;
    $sql = posts_select() . ' WHERE ' . $where . ' ORDER BY ' . $order . ', p.id DESC LIMIT ' . $count . ' OFFSET ' . $skip;
    return q_all($sql, $params);
}

/** @param array<string, mixed> $o */
function posts_count(array $o): int
{
    [$where, $params] = posts_where($o);
    return (int) (q_one('SELECT COUNT(*) AS n FROM posts p WHERE ' . $where, $params)['n'] ?? 0);
}

function posts_select(): string
{
    // The author comes from the authors table (B19); author_name is the fallback for stories without one.
    // The main photo's caption and credit are the story's own, else the picture's.
    return "SELECT p.id, p.title, p.slug, p.excerpt, p.summary, p.body, p.credits, COALESCE(a.name, p.author_name) AS author_name,
                   p.author_id, p.sticky, p.live_label, p.views, p.published_at, p.updated_at,
                   c.slug AS cat_slug, c.name AS cat_name, pc.slug AS cat_parent, pc.name AS cat_parent_name,
                   m.path AS m_path, m.widths AS m_widths, m.has_avif AS m_has_avif, m.width AS m_width, m.height AS m_height, m.alt AS m_alt,
                   CASE WHEN COALESCE(p.featured_caption, '') <> '' THEN p.featured_caption ELSE m.caption END AS m_caption,
                   CASE WHEN p.featured_credit <> '' THEN p.featured_credit ELSE m.credit END AS m_credit
              FROM posts p
              JOIN categories c ON c.id = p.category_id
              LEFT JOIN categories pc ON pc.id = c.parent_id
              LEFT JOIN authors a ON a.id = p.author_id
              LEFT JOIN media m ON m.id = p.media_id";
}

/**
 * @param array<string, mixed> $o
 * @return array{0: string, 1: list<mixed>}
 */
function posts_where(array $o): array
{
    $where = ["p.status = 'published'", 'p.published_at <= ?'];
    $params = [now_utc()];
    foreach (['categories' => 'IN', 'exclude_categories' => 'NOT IN'] as $key => $op) {
        $slugs = array_values(array_filter((array) ($o[$key] ?? []), 'is_string'));
        if ($slugs === []) {
            continue;
        }
        $ids = category_ids_with_children($slugs);
        if ($ids === []) {
            $where[] = $op === 'IN' ? '1 = 0' : '1 = 1';
            continue;
        }
        $where[] = 'p.category_id ' . $op . ' (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }
    $tags = array_values(array_filter((array) ($o['tags'] ?? []), 'is_string'));
    if ($tags !== []) {
        $where[] = 'p.id IN (SELECT pt.post_id FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.slug IN ('
            . implode(',', array_fill(0, count($tags), '?')) . '))';
        array_push($params, ...$tags);
    }
    $exclude = array_values(array_filter(array_map('intval', (array) ($o['exclude_ids'] ?? []))));
    if ($exclude !== []) {
        $where[] = 'p.id NOT IN (' . implode(',', $exclude) . ')';
    }
    return [implode(' AND ', $where), $params];
}

/**
 * Category ids for these slugs, with all their child categories (any depth).
 * @param list<string> $slugs
 * @return list<int>
 */
function category_ids_with_children(array $slugs): array
{
    static $all = null;
    if ($all === null) {
        $all = q_all('SELECT id, parent_id, slug FROM categories');
    }
    $ids = [];
    foreach ($all as $c) {
        if (in_array($c['slug'], $slugs, true)) {
            $ids[] = (int) $c['id'];
        }
    }
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

/** @param array<string, mixed> $p */
function post_url(array $p): string
{
    return site_link('article', ['category' => (string) $p['cat_slug'], 'slug' => (string) $p['slug']]);
}

/** @param array<string, mixed> $p */
function category_url_of(array $p): string
{
    return site_link('category', ['slug' => (string) $p['cat_slug'], 'parent' => (string) ($p['cat_parent'] ?? '')]);
}

/** @return array<string, mixed>|null a public story by slug */
function post_by_slug(string $slug): ?array
{
    return q_one(posts_select() . " WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= ?", [$slug, now_utc()]);
}

/**
 * Extra gallery photos of a story, in order, each with its caption and credit.
 * @return list<array<string, mixed>>
 */
function post_gallery(int $postId): array
{
    return q_all('SELECT m.path, m.widths, m.has_avif, m.width, m.height, m.alt, g.caption, g.credit
                    FROM post_gallery g JOIN media m ON m.id = g.media_id WHERE g.post_id = ? ORDER BY g.position, g.id', [$postId]);
}

/** One more story view today (site time zone), for the dashboard. */
function count_view(int $postId): void
{
    q('UPDATE posts SET views = views + 1 WHERE id = ?', [$postId]);
    $day = (new DateTimeImmutable('now', site_tz()))->format('Y-m-d');
    if (q('UPDATE views_daily SET views = views + 1 WHERE day = ?', [$day])->rowCount() === 0) {
        try {
            q('INSERT INTO views_daily (day, views) VALUES (?, 1)', [$day]);
        } catch (PDOException) {
            q('UPDATE views_daily SET views = views + 1 WHERE day = ?', [$day]); // another request added the day first
        }
    }
}
