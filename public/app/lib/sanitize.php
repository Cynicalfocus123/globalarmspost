<?php
declare(strict_types=1);

/**
 * HTML allow-list cleaner for story bodies (task B5). Runs when a story is saved and again when it is shown.
 *
 * Kept: paragraphs, line breaks, bold, italic, underline, subheads (h2, h3), bullet and numbered lists,
 * quotes, links (http, https, mailto, site addresses; rel="noopener" added) and body pictures.
 * A body picture is stored as <figure data-media="ID" data-size="full|inline" data-caption="…" data-credit="…"></figure>
 * and is only ever a picture from the site's own media table, so remote images can never be shown.
 * Removed with their content: scripts, styles, frames, forms, embeds, images, SVG. Every other tag is unwrapped
 * (its text stays). Every attribute that is not listed here is removed (onclick, style, class …).
 */
function clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><html><body><div id="gap-root">' . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('gap-root');
    if ($root === null) {
        return '';
    }
    $out = '';
    $inline = '';
    $flush = function () use (&$out, &$inline): void {
        if (trim(strip_tags($inline, '<br>')) !== '' && trim(str_replace('<br>', '', $inline)) !== '') {
            $out .= '<p>' . trim($inline) . '</p>';
        }
        $inline = '';
    };
    foreach ($root->childNodes as $child) {
        [$piece, $block] = clean_node($child, 0, true);
        if ($block) {
            $flush();
            $out .= $piece;
        } else {
            $inline .= $piece;
        }
    }
    $flush();
    // No line break left at the end of a block (Safari's editor adds one: <li>Point one<br></li>).
    $out = (string) preg_replace('~(?:<br>|\s)+</(p|h2|h3|blockquote|li)>~', '</$1>', $out);
    // No empty paragraphs or subheads.
    return (string) preg_replace('~<(p|h2|h3|blockquote|li)>(\s|&nbsp;|<br>)*</\1>~', '', $out);
}

/**
 * @return array{0: string, 1: bool} cleaned HTML, and whether it is a block (top level only)
 */
function clean_node(DOMNode $node, int $depth, bool $top = false): array
{
    if ($node instanceof DOMText) {
        return [e(str_replace("\u{a0}", ' ', $node->nodeValue ?? '')), false];
    }
    if (!$node instanceof DOMElement) {
        return ['', false];
    }
    $tag = strtolower($node->nodeName);
    $drop = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template', 'svg', 'math',
             'form', 'input', 'button', 'select', 'textarea', 'img', 'picture', 'video', 'audio', 'source', 'canvas', 'head',
             'title', 'meta', 'link', 'base'];
    if (in_array($tag, $drop, true)) {
        return ['', false];
    }
    $tag = match ($tag) {
        'b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4', 'h5', 'h6' => 'h3',
        'div' => $top ? 'p' : 'span', // editors make <div> lines; inside other tags they are unwrapped
        default => $tag,
    };
    if ($depth > 24) {
        return [e($node->textContent), false];
    }
    $inner = '';
    foreach ($node->childNodes as $child) {
        $inner .= clean_node($child, $depth + 1)[0];
    }
    $blocks = ['p', 'h2', 'h3', 'ul', 'ol', 'blockquote', 'figure'];
    switch ($tag) {
        case 'br':
            return ['<br>', false];
        case 'a':
            $href = trim($node->getAttribute('href'));
            if ($href === '' || safe_url($href) === '#') {
                return [$inner, false];
            }
            return ['<a href="' . e($href) . '" rel="noopener">' . $inner . '</a>', false];
        case 'figure':
            $id = (int) $node->getAttribute('data-media');
            if ($id <= 0) {
                return ['', true];
            }
            $size = $node->getAttribute('data-size') === 'inline' ? 'inline' : 'full';
            $caption = mb_substr(trim($node->getAttribute('data-caption')), 0, 600);
            $credit = mb_substr(trim($node->getAttribute('data-credit')), 0, 200);
            return ['<figure data-media="' . $id . '" data-size="' . $size . '" data-caption="' . e($caption) . '" data-credit="' . e($credit) . '"></figure>', true];
        case 'strong': case 'em': case 'u':
            return [$inner === '' ? '' : "<$tag>$inner</$tag>", false];
        case 'p': case 'h2': case 'h3': case 'ul': case 'ol': case 'blockquote': case 'li':
            if (($tag === 'ul' || $tag === 'ol')) {
                // Lists hold only list items.
                $items = '';
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                        $items .= clean_node($child, $depth + 1)[0];
                    } elseif (trim($child->textContent) !== '') {
                        $items .= '<li>' . clean_node($child, $depth + 1)[0] . '</li>';
                    }
                }
                return [$items === '' ? '' : "<$tag>$items</$tag>", true];
            }
            if (!$top && in_array($tag, ['p', 'h2', 'h3'], true)) {
                return [$inner . '<br>', false]; // nested blocks become lines
            }
            return ["<$tag>" . trim($inner) . "</$tag>", true]; // p, h2, h3, blockquote and li are all blocks here
        default:
            return [$inner, false]; // unknown tag: keep its text
    }
}

/**
 * A story body for the page: cleaned again, body pictures turned into <picture> with caption and credit.
 * Old plain-text bodies (no tags) are shown as paragraphs.
 */
function body_html(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }
    if (!body_is_html($body)) {
        $out = '';
        foreach (preg_split('/\R{2,}/', $body) ?: [] as $para) {
            if (trim($para) !== '') {
                $out .= '<p>' . nl2br(e(trim($para)), false) . '</p>';
            }
        }
        return $out;
    }
    $clean = clean_html($body);
    $re = '~<figure data-media="(\d+)" data-size="(full|inline)" data-caption="([^"]*)" data-credit="([^"]*)"></figure>~';
    preg_match_all($re, $clean, $found);
    $ids = array_values(array_unique(array_map('intval', $found[1])));
    $media = [];
    if ($ids !== []) {
        foreach (q_all('SELECT * FROM media WHERE id IN (' . implode(',', $ids) . ')') as $m) {
            $media[(int) $m['id']] = $m;
        }
    }
    return (string) preg_replace_callback($re, function (array $m) use ($media): string {
        $row = $media[(int) $m[1]] ?? null;
        if ($row === null) {
            return '';
        }
        // Caption and credit are already escaped by clean_html().
        $sizes = $m[2] === 'inline' ? '(max-width: 600px) 100vw, 380px' : '(max-width: 900px) 100vw, 860px';
        $cap = $m[3] !== '' || $m[4] !== ''
            ? '<figcaption>' . $m[3] . ($m[4] !== '' ? ' <span class="credit">Photo: ' . $m[4] . '</span>' : '') . '</figcaption>'
            : '';
        return '<figure class="body-fig fig-' . $m[2] . '">' . picture(['alt' => $m[3] !== '' ? html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5) : (string) $row['alt']] + $row, $sizes) . $cap . '</figure>';
    }, $clean);
}

/** Old stories (and the sample stories) have plain-text bodies; editor bodies are HTML. */
function body_is_html(string $body): bool
{
    return preg_match('~<(p|h[1-6]|ul|ol|li|blockquote|figure|strong|em|b|i|u|a|br|div)[\s>/]~i', $body) === 1;
}
