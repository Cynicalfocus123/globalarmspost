<?php
declare(strict_types=1);

/**
 * A stored picture as <picture>: AVIF + WebP in every saved width, width/height set so nothing jumps.
 * $m keys: path, widths ("480,960,1600"), has_avif, width, height, alt.
 * @param array<string, mixed> $m
 */
function picture(array $m, string $sizes, bool $eager = false, string $class = ''): string
{
    $base = '/' . ltrim((string) $m['path'], '/');
    $widths = array_values(array_filter(array_map('intval', explode(',', (string) $m['widths']))));
    if ($widths === []) {
        return '';
    }
    sort($widths);
    $set = fn (string $ext): string => implode(', ', array_map(fn (int $w): string => e($base . '-' . $w . '.' . $ext) . ' ' . $w . 'w', $widths));
    $fallback = $widths[min(1, count($widths) - 1)];
    $w = (int) $m['width'];
    $h = (int) $m['height'];
    $out = '<picture' . ($class !== '' ? ' class="' . e($class) . '"' : '') . '>';
    if ((int) ($m['has_avif'] ?? 0) === 1) {
        $out .= '<source type="image/avif" srcset="' . $set('avif') . '" sizes="' . e($sizes) . '">';
    }
    $out .= '<img src="' . e($base . '-' . $fallback . '.webp') . '" srcset="' . $set('webp') . '" sizes="' . e($sizes) . '"'
        . ' width="' . $w . '" height="' . $h . '" alt="' . e((string) ($m['alt'] ?? '')) . '"'
        . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"></picture>';
    return $out;
}

/**
 * The picture fields of a post row (joined as m_*), or null.
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function post_media(array $row): ?array
{
    if (empty($row['m_path'])) {
        return null;
    }
    return [
        'path' => $row['m_path'], 'widths' => $row['m_widths'], 'has_avif' => $row['m_has_avif'],
        'width' => $row['m_width'], 'height' => $row['m_height'], 'alt' => $row['m_alt'],
        'caption' => $row['m_caption'] ?? '', 'credit' => $row['m_credit'] ?? '',
    ];
}
