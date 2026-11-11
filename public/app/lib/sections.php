<?php
declare(strict_types=1);

/**
 * Homepage layout registry (code standard 3). A new layout = one entry here + one view in
 * app/views/sections/. The admin's section form is built from 'settings' later (task F16).
 *
 * @return array<string, array{label: string, settings: array<string, mixed>}>
 */
function section_layouts(): array
{
    $common = ['categories' => [], 'exclude_categories' => [], 'tags' => [], 'order_by' => 'date', 'dir' => 'desc',
               'skip' => 0, 'sticky_first' => false, 'avoid_duplicates' => true,
               // Picture sizes (task F16), steps 1-5 (3 = the standard look): photo_height = the shape of the big
               // photos (1 wide and low ... 5 square), thumb_size = the small photo in title rows (1 small ... 5 big).
               // Steps, not free numbers: they are CSS classes, because the strict CSP allows no inline styles.
               'photo_height' => 3, 'thumb_size' => 3];
    return [
        'hero' => ['label' => 'Hero: dark text panel, photo right', 'settings' => $common + ['count' => 1]],
        'hero_grid' => ['label' => 'Hero, then cards under it', 'settings' => $common + ['count' => 4, 'columns' => 3]],
        'list' => ['label' => 'Photo + title rows, optional tabs', 'settings' => $common + ['count' => 5, 'tabs' => false]],
        'grid' => ['label' => 'Cards, columns x rows', 'settings' => $common + ['count' => 4, 'columns' => 4]],
        'overlay_list' => ['label' => 'Photo with title on it, then rows', 'settings' => $common + ['count' => 4]],
        'lead_grid' => ['label' => 'Lead story left, 2 x 2 cards right', 'settings' => $common + ['count' => 5]],
        'lead_list' => ['label' => 'Lead story + title list', 'settings' => $common + ['count' => 5]],
        'headlines' => ['label' => 'Numbered titles, no photos', 'settings' => $common + ['count' => 5]],
    ];
}

/**
 * All active homepage sections, rendered in order. Stories shown higher up are skipped below ("avoid duplicates").
 * $rows: the sections to draw instead of the saved ones (the homepage builder's live preview, task F16), each with
 * layout, title, span, active and settings (an array); null = the saved homepage.
 * @param list<array<string, mixed>>|null $rows
 */
function render_home_sections(?array $rows = null): string
{
    $layouts = section_layouts();
    $shown = [];
    $html = '';
    $rows ??= q_all('SELECT id, layout, title, span, settings, active FROM home_sections WHERE active = 1 ORDER BY position, id');
    foreach ($rows as $row) {
        $layout = (string) $row['layout'];
        if (!isset($layouts[$layout]) || (int) ($row['active'] ?? 1) !== 1) {
            continue;
        }
        $saved = is_array($row['settings']) ? $row['settings'] : json_decode((string) $row['settings'], true);
        $s = (is_array($saved) ? $saved : []) + $layouts[$layout]['settings'];
        $query = $s;
        if (!empty($s['avoid_duplicates'])) {
            $query['exclude_ids'] = $shown;
        }
        $posts = posts_query($query);
        $tabs = [];
        if ($layout === 'list' && !empty($s['tabs'])) {
            // "Top stories" = sticky first, then newest; "Latest" = newest. Both are real queries.
            $tabs = [
                'Top stories' => posts_query(['sticky_first' => true] + $query),
                'Latest' => posts_query(['sticky_first' => false, 'exclude_ids' => []] + $query),
            ];
            $posts = $tabs['Top stories'];
        }
        if ($posts === []) {
            continue;
        }
        foreach ($posts as $p) {
            $shown[] = (int) $p['id'];
        }
        $span = max(3, min(12, (int) $row['span']));
        $more = '';
        $cats = array_values((array) ($s['categories'] ?? []));
        if (count($cats) === 1) {
            $cat = q_one('SELECT c.slug, p.slug AS parent FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.slug = ?', [$cats[0]]);
            if ($cat !== null) {
                $more = site_link('category', ['slug' => (string) $cat['slug'], 'parent' => (string) ($cat['parent'] ?? '')]);
            }
        }
        // Picture size classes (only when not the standard step 3, so the standard look is exactly as before).
        $img = '';
        foreach (['photo_height' => 'ph', 'thumb_size' => 'th'] as $key => $prefix) {
            $step = max(1, min(5, (int) ($s[$key] ?? 3)));
            $img .= $step !== 3 ? ' ' . $prefix . '-' . $step : '';
        }
        $html .= view('sections/' . $layout, [
            'id' => (int) $row['id'], 'title' => (string) $row['title'], 'span' => $span, 'more' => $more,
            'posts' => $posts, 'tabs' => $tabs, 's' => $s, 'img' => $img,
        ]);
    }
    return $html;
}

/**
 * One story card. Variants: hero, overlay, lead, card, row, title, headline.
 * The first card of a section gets is-first: on phones it becomes the big story, the rest become rows.
 * @param array<string, mixed> $p
 * @param array<string, mixed> $opt  sizes, eager, excerpt (bool), first (bool), n (headline number)
 */
function card(array $p, string $variant, array $opt = []): string
{
    return view('partials/card', ['p' => $p, 'variant' => $variant, 'opt' => $opt]);
}
