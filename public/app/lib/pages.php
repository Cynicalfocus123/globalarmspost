<?php
declare(strict_types=1);

/**
 * Pages (task F24): About Us, Terms of Service, Privacy Policy, Advertise, Contact Us and any page the admin adds.
 * A page lives at /{slug} (live/) or /#{slug} (git/), like a category, so a page and a category never share an
 * address. Its text is cleaned with the same allow-list as a story's (clean_html()).
 */

/** @return array<string, mixed>|null */
function page_by_slug(string $slug): ?array
{
    return q_one('SELECT id, title, slug, body, created_at, updated_at FROM pages WHERE slug = ?', [$slug]);
}

/** @return list<array<string, mixed>> */
function pages_all(): array
{
    return q_all('SELECT id, title, slug, updated_at FROM pages ORDER BY title, id');
}

/** @return array<string, true> every page address, for menus (a link to a page that is gone is left out) */
function page_slugs(): array
{
    static $slugs = null;
    if ($slugs === null) {
        $slugs = [];
        foreach (q_all('SELECT slug FROM pages') as $r) {
            $slugs[(string) $r['slug']] = true;
        }
    }
    return $slugs;
}

/**
 * The first words of a page, for its description in search results and share cards.
 * @param array<string, mixed> $page
 */
function page_description(array $page): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string) $page['body'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 157)) . '…' : $text;
}

/**
 * Social links shown in the footer (Settings → Social links). Only the ones with an address.
 * @return list<array{key: string, label: string, url: string}>
 */
function social_links(): array
{
    $out = [];
    foreach (social_networks() as $key => $label) {
        $url = trim(setting('social_' . $key));
        if ($url !== '' && preg_match('~^https://~i', $url) === 1) {
            $out[] = ['key' => $key, 'label' => $label, 'url' => $url];
        }
    }
    return $out;
}

/** @return array<string, string> */
function social_networks(): array
{
    return ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn'];
}

/** The icon of a social network (simple outline, drawn in currentColor). */
function social_icon(string $key): string
{
    $paths = [
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>',
        'facebook' => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8z"/>',
        'x' => '<path d="M4 4l16 16M20 4L4 20"/>',
        'youtube' => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="M10 9v6l5-3z" fill="currentColor"/>',
        'linkedin' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10v7M8 7v.01M12 17v-4a2 2 0 0 1 4 0v4M12 10v7"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$key] ?? '') . '</svg>';
}
