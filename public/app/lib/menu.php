<?php
declare(strict_types=1);

/**
 * The menu for a location as a tree: each item has label, href, new_tab, children.
 * Hidden items (menu builder, task F5) and everything under them are left out, and so is a link to a story that is
 * not public (yet) or to a page that is gone. Locations: 'header' and 'footer' (task F24).
 * @return list<array{label: string, href: string, new_tab: bool, children: list<array<string, mixed>>}>
 */
function menu_tree(string $location): array
{
    $rows = q_all(
        'SELECT i.id, i.parent_id, i.type, i.label, i.target, i.new_tab, i.hidden
           FROM menu_items i JOIN menus m ON m.id = i.menu_id
          WHERE m.location = ? ORDER BY i.position, i.id',
        [$location]
    );
    $parents = [];
    foreach (q_all('SELECT c.slug, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id') as $c) {
        $parents[(string) $c['slug']] = (string) ($c['parent'] ?? '');
    }
    $stories = [];
    $storyIds = array_values(array_filter(array_map(static fn (array $r): int => $r['type'] === 'story' ? (int) $r['target'] : 0, $rows)));
    if ($storyIds !== []) {
        foreach (q_all(posts_select() . " WHERE p.status = 'published' AND p.published_at <= ? AND p.id IN (" . implode(',', $storyIds) . ')', [now_utc()]) as $p) {
            $stories[(int) $p['id']] = post_url($p);
        }
    }
    $items = [];
    $hidden = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $target = (string) $r['target'];
        $href = match ((string) $r['type']) {
            'home' => site_link('home'),
            'category' => site_link('category', ['slug' => $target, 'parent' => $parents[$target] ?? '']),
            'tag' => site_link('tag', ['slug' => $target]),
            'page' => isset(page_slugs()[$target]) ? site_link('page', ['slug' => $target]) : '',
            'story' => $stories[(int) $target] ?? '',
            'text' => '',
            default => safe_url($target),
        };
        // A 'text' item is a title with no link (a footer column's title, or a header item that only opens its submenu).
        if ((int) $r['hidden'] === 1 || ($href === '' && $r['type'] !== 'text')) {
            $hidden[$id] = true;
        }
        $items[$id] = [
            'parent' => $r['parent_id'] === null ? 0 : (int) $r['parent_id'],
            'label' => (string) $r['label'],
            'href' => $href,
            'new_tab' => (bool) $r['new_tab'],
            'children' => [],
        ];
    }
    // Build the tree (any depth), keeping the order. Items whose parent is gone go to the top level; items under a
    // hidden item are hidden with it.
    $byParent = [];
    foreach ($items as $id => $item) {
        $parent = isset($items[$item['parent']]) ? $item['parent'] : 0;
        $byParent[$parent][] = $id;
    }
    $build = function (int $parent, int $depth) use (&$build, $byParent, $items, $hidden): array {
        $out = [];
        foreach ($depth > 8 ? [] : ($byParent[$parent] ?? []) as $id) {
            if (isset($hidden[$id])) {
                continue;
            }
            $item = $items[$id];
            unset($item['parent']);
            $item['children'] = $build($id, $depth + 1);
            $out[] = $item;
        }
        return $out;
    };
    return $build(0, 0);
}

/** Only http(s), site-relative and mailto addresses are allowed in menus. */
function safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('~^(https?://|/(?!/)|mailto:)~i', $url) !== 1) {
        return '#';
    }
    return $url;
}
