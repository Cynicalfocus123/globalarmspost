<?php
declare(strict_types=1);

/**
 * Every link on the site goes through site_link() (PHP already has a built-in link()), so the two copies never drift apart (hard rule 9).
 *
 *   route      git/ copy (hash)         live/ copy (path)
 *   home       /#home                   /home
 *   category   /#asia, /#regions/asia   /asia          (?page=2 for older stories)
 *   article    /#news/{slug}            /{category}/{slug}
 *   tag        /#tag/usa                /tag/usa
 *   search     /#search?q=              /search?q=
 *   page       /#contact                /contact
 *   admin      /admin                   /admin
 *
 * @param array<string, string> $p
 */
function site_link(string $route, array $p = []): string
{
    $hash = build_info()['link_mode'] === 'hash';
    $slug = rawurlencode($p['slug'] ?? '');
    $query = isset($p['q']) && $p['q'] !== '' ? '?q=' . rawurlencode($p['q']) : '';
    $page = isset($p['page']) && (int) $p['page'] > 1 ? '?page=' . (int) $p['page'] : '';
    return match ($route) {
        'home' => $hash ? '/#home' : '/home',
        'category' => ($hash
            ? '/#' . (($p['parent'] ?? '') !== '' ? rawurlencode($p['parent']) . '/' : '') . $slug
            : '/' . $slug) . $page,
        'article' => $hash ? '/#news/' . $slug : '/' . rawurlencode($p['category'] ?? '') . '/' . $slug,
        'tag' => ($hash ? '/#tag/' : '/tag/') . $slug,
        'search' => ($hash ? '/#search' : '/search') . $query,
        'page' => ($hash ? '/#' : '/') . $slug,
        'admin' => '/admin',
        default => throw new InvalidArgumentException('Unknown link route: ' . $route),
    };
}

/** Asset address with a version so browsers fetch new files after an update. */
function asset(string $path): string
{
    $file = PUBLIC_DIR . '/assets/' . $path;
    $v = is_file($file) ? substr(md5((string) filemtime($file) . filesize($file)), 0, 8) : '0';
    return '/assets/' . $path . '?v=' . $v;
}

/**
 * Names a category cannot use, because live/ puts categories at the top level (/asia).
 * @return list<string>
 */
function reserved_slugs(): array
{
    return ['home', 'admin', 'api', 'search', 'tag', 'install', 'assets', 'uploads', 'feed', 'sitemap.xml', 'robots.txt', 'app', 'storage'];
}
