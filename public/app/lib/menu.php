<?php
declare(strict_types=1);

/**
 * The menu for a location as a tree: each item has label, href, new_tab, children.
 * @return list<array{label: string, href: string, new_tab: bool, children: list<array<string, mixed>>}>
 */
function menu_tree(string $location): array
{
    $rows = q_all(
        'SELECT i.id, i.parent_id, i.type, i.label, i.target, i.new_tab
           FROM menu_items i JOIN menus m ON m.id = i.menu_id
          WHERE m.location = ? ORDER BY i.position, i.id',
        [$location]
    );
    $parents = [];
    foreach (q_all('SELECT c.slug, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id') as $c) {
        $parents[(string) $c['slug']] = (string) ($c['parent'] ?? '');
    }
    $items = [];
    foreach ($rows as $r) {
        $target = (string) $r['target'];
        $href = match ((string) $r['type']) {
            'home' => site_link('home'),
            'category' => site_link('category', ['slug' => $target, 'parent' => $parents[$target] ?? '']),
            'tag' => site_link('tag', ['slug' => $target]),
            'page' => site_link('page', ['slug' => $target]),
            default => safe_url($target),
        };
        $items[(int) $r['id']] = [
            'parent' => $r['parent_id'] === null ? 0 : (int) $r['parent_id'],
            'label' => (string) $r['label'],
            'href' => $href,
            'new_tab' => (bool) $r['new_tab'],
            'children' => [],
        ];
    }
    // Build the tree (any depth), keeping the order. Items whose parent is gone go to the top level.
    $byParent = [];
    foreach ($items as $id => $item) {
        $parent = isset($items[$item['parent']]) ? $item['parent'] : 0;
        $byParent[$parent][] = $id;
    }
    $build = function (int $parent, int $depth) use (&$build, $byParent, $items): array {
        $out = [];
        foreach ($depth > 8 ? [] : ($byParent[$parent] ?? []) as $id) {
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
