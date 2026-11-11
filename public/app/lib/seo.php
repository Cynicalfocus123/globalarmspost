<?php
declare(strict_types=1);

/**
 * SEO (task F12): the <head> tags of every public page, the story page's NewsArticle JSON-LD, /sitemap.xml,
 * /robots.txt and the 301 redirects from the old WordPress addresses.
 *
 * Full addresses come from public_url() / absolute_url(): the site_url setting, else the request's own host
 * (request_host() only accepts a plain host name). They are always proper paths (/asia, /{category}/{slug}),
 * also in the git/ copy, whose '#' links are only for moving inside the page.
 * Only public stories ever appear: status 'published' AND published_at <= now.
 */

/**
 * Meta for a page that is not a story: the site's tagline as the description.
 * @return array<string, mixed>
 */
function seo_page_meta(string $canonical, string $description = '', bool $index = true): array
{
    return [
        'description' => seo_text($description !== '' ? $description : setting('tagline')),
        'canonical' => $canonical,
        'type' => 'website',
        'image' => null,
        'robots' => $index ? '' : 'noindex, follow',
    ];
}

/**
 * Meta for a public story: description from the excerpt, else the summary, else the tagline; the main photo;
 * the publish and update times; the NewsArticle JSON-LD.
 * @param array<string, mixed> $p a row of posts_select()
 * @return array<string, mixed>
 */
function seo_post_meta(array $p): array
{
    $summary = trim(str_replace("\n", ' ', (string) $p['summary']));
    $description = seo_text((string) $p['excerpt'] !== '' ? (string) $p['excerpt'] : ($summary !== '' ? $summary : setting('tagline')));
    $canonical = public_url('article', ['category' => (string) $p['cat_slug'], 'slug' => (string) $p['slug']]);
    $image = seo_image(post_media($p));
    $published = seo_time((string) $p['published_at']);
    $modified = (string) $p['updated_at'] > (string) $p['published_at'] ? seo_time((string) $p['updated_at']) : $published;
    $tags = array_column(q_all('SELECT t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ? ORDER BY t.name', [(int) $p['id']]), 'name');
    $site = setting('site_name', 'Global Arms Post');
    $author = (string) $p['author_name'];
    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => mb_substr((string) $p['title'], 0, 110),
        'description' => $description,
        'datePublished' => $published,
        'dateModified' => $modified,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        'url' => $canonical,
        'articleSection' => (string) $p['cat_name'],
        'author' => $author !== '' ? [['@type' => 'Person', 'name' => $author]] : [['@type' => 'Organization', 'name' => $site]],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $site,
            'logo' => ['@type' => 'ImageObject', 'url' => absolute_url('/assets/img/logo/logo.png')],
        ],
    ];
    if ($image !== null) {
        $ld['image'] = [$image['url']];
    }
    if ($tags !== []) {
        $ld['keywords'] = implode(', ', array_map('strval', $tags));
    }
    return [
        'description' => $description,
        'canonical' => $canonical,
        'type' => 'article',
        'image' => $image,
        'published' => $published,
        'modified' => $modified,
        'section' => (string) $p['cat_name'],
        'tags' => array_map('strval', $tags),
        'jsonld' => $ld,
        'robots' => '',
    ];
}

/** Plain text for a description: no tags, one line, at most 160 characters cut at a word. */
function seo_text(string $text): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    if (mb_strlen($text) <= 160) {
        return $text;
    }
    $cut = mb_substr($text, 0, 157);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space !== false && $space > 100 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
}

/** UTC "2026-11-05 10:00:00" -> "2026-11-05T10:00:00Z" (ISO 8601, what Open Graph and schema.org read). */
function seo_time(string $utc): string
{
    return str_replace(' ', 'T', $utc) . 'Z';
}

/**
 * The largest WebP of a stored picture as a full address, with its size (for og:image and the JSON-LD).
 * @param array<string, mixed>|null $m
 * @return array{url: string, width: int, height: int, alt: string}|null
 */
function seo_image(?array $m): ?array
{
    if ($m === null) {
        return null;
    }
    $widths = array_values(array_filter(array_map('intval', explode(',', (string) $m['widths']))));
    if ($widths === []) {
        return null;
    }
    $w = max($widths);
    $h = (int) $m['width'] > 0 ? (int) round((int) $m['height'] * $w / (int) $m['width']) : 0;
    return ['url' => absolute_url('/' . ltrim((string) $m['path'], '/') . '-' . $w . '.webp'), 'width' => $w, 'height' => $h, 'alt' => (string) ($m['alt'] ?? '')];
}

/**
 * The SEO part of <head>: description, canonical, robots, Open Graph, X card and (stories) the JSON-LD.
 * Every value is escaped; the JSON-LD has <, >, & and quotes as \u escapes, so its text can never end the <script>.
 * @param array<string, mixed> $meta
 */
function seo_head(string $title, array $meta): string
{
    $site = setting('site_name', 'Global Arms Post');
    $fullTitle = $title === '' ? $site : $title . ' | ' . $site;
    $description = (string) ($meta['description'] ?? '') !== '' ? (string) $meta['description'] : seo_text(setting('tagline'));
    $tag = static fn (string $attr, string $name, string $content): string => '<meta ' . $attr . '="' . e($name) . '" content="' . e($content) . '">' . "\n";
    $out = $tag('name', 'description', $description);
    $canonical = (string) ($meta['canonical'] ?? '');
    if ($canonical !== '') {
        $out .= '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
    }
    if ((string) ($meta['robots'] ?? '') !== '') {
        $out .= $tag('name', 'robots', (string) $meta['robots']);
    }
    $out .= $tag('property', 'og:site_name', $site);
    $out .= $tag('property', 'og:type', (string) ($meta['type'] ?? 'website'));
    $out .= $tag('property', 'og:title', $title === '' ? $site : $title);
    $out .= $tag('property', 'og:description', $description);
    if ($canonical !== '') {
        $out .= $tag('property', 'og:url', $canonical);
    }
    $image = $meta['image'] ?? null;
    if (is_array($image)) {
        $out .= $tag('property', 'og:image', (string) $image['url']);
        if ((int) $image['width'] > 0 && (int) $image['height'] > 0) {
            $out .= $tag('property', 'og:image:width', (string) $image['width']);
            $out .= $tag('property', 'og:image:height', (string) $image['height']);
        }
        if ((string) $image['alt'] !== '') {
            $out .= $tag('property', 'og:image:alt', (string) $image['alt']);
        }
    }
    if (($meta['type'] ?? '') === 'article') {
        $out .= $tag('property', 'article:published_time', (string) $meta['published']);
        $out .= $tag('property', 'article:modified_time', (string) $meta['modified']);
        $out .= $tag('property', 'article:section', (string) $meta['section']);
        foreach ((array) ($meta['tags'] ?? []) as $t) {
            $out .= $tag('property', 'article:tag', (string) $t);
        }
    }
    $out .= $tag('name', 'twitter:card', is_array($image) ? 'summary_large_image' : 'summary');
    $out .= $tag('name', 'twitter:title', $title === '' ? $site : $title);
    $out .= $tag('name', 'twitter:description', $description);
    if (is_array($image)) {
        $out .= $tag('name', 'twitter:image', (string) $image['url']);
    }
    if (isset($meta['jsonld']) && is_array($meta['jsonld'])) {
        $json = json_encode($meta['jsonld'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            $out .= '<script type="application/ld+json">' . $json . '</script>' . "\n";
        }
    }
    return $out . '<title>' . e($fullTitle) . '</title>' . "\n";
}

/** /sitemap.xml: the homepage, every category, every public story (lastmod) and every tag with a public story. */
function sitemap_xml(): string
{
    $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $now = now_utc();
    $url = static fn (string $loc, string $lastmod = ''): string => '<url><loc>' . $x($loc) . '</loc>'
        . ($lastmod !== '' ? '<lastmod>' . $x(seo_time($lastmod)) . '</lastmod>' : '') . '</url>' . "\n";
    $newest = q_one("SELECT MAX(published_at) AS at FROM posts WHERE status = 'published' AND published_at <= ?", [$now]);
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $out .= $url(absolute_url('/'), (string) ($newest['at'] ?? ''));
    $catLast = [];
    foreach (q_all("SELECT category_id, MAX(published_at) AS at FROM posts WHERE status = 'published' AND published_at <= ? GROUP BY category_id", [$now]) as $r) {
        $catLast[(int) $r['category_id']] = (string) $r['at'];
    }
    foreach (q_all('SELECT id, slug FROM categories ORDER BY position, id') as $c) {
        $out .= $url(public_url('category', ['slug' => (string) $c['slug']]), $catLast[(int) $c['id']] ?? '');
    }
    $rows = q_all("SELECT p.slug, p.published_at, p.updated_at, c.slug AS cat_slug FROM posts p JOIN categories c ON c.id = p.category_id
                    WHERE p.status = 'published' AND p.published_at <= ? ORDER BY p.published_at DESC, p.id DESC LIMIT 45000", [$now]);
    foreach ($rows as $p) {
        $last = max((string) $p['published_at'], (string) $p['updated_at']);
        $out .= $url(public_url('article', ['category' => (string) $p['cat_slug'], 'slug' => (string) $p['slug']]), min($last, $now));
    }
    $tags = q_all("SELECT t.slug, MAX(p.published_at) AS at FROM tags t JOIN post_tags pt ON pt.tag_id = t.id JOIN posts p ON p.id = pt.post_id
                    WHERE p.status = 'published' AND p.published_at <= ? GROUP BY t.id, t.slug ORDER BY t.slug", [$now]);
    foreach ($tags as $t) {
        $out .= $url(public_url('tag', ['slug' => (string) $t['slug']]), (string) $t['at']);
    }
    return $out . '</urlset>' . "\n";
}

/** /robots.txt: everything but the admin, and where the sitemap is. */
function robots_txt(): string
{
    return "User-agent: *\nDisallow: /admin\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
}

/**
 * Old WordPress addresses -> the new ones (301): /category/{a}/{b}/ -> /{b}; /2026/07/{slug}/ and
 * /2026/07/05/{slug}/ -> /{category}/{slug}. Null when the address is not an old one (or nothing matches).
 * @param list<string> $parts the request path split at '/'
 */
function wp_redirect_target(array $parts): ?string
{
    if (count($parts) >= 2 && count($parts) <= 4 && $parts[0] === 'category') {
        $slug = (string) end($parts);
        $c = q_one('SELECT slug FROM categories WHERE slug = ?', [$slug]);
        return $c === null ? null : site_link('category', ['slug' => (string) $c['slug']], 'path');
    }
    if (count($parts) >= 3 && count($parts) <= 4 && preg_match('/^\d{4}$/', $parts[0]) === 1 && preg_match('/^\d{2}$/', $parts[1]) === 1
        && (count($parts) === 3 || preg_match('/^\d{2}$/', $parts[2]) === 1)) {
        $p = post_by_slug((string) end($parts));
        return $p === null ? null : site_link('article', ['category' => (string) $p['cat_slug'], 'slug' => (string) $p['slug']], 'path');
    }
    return null;
}
