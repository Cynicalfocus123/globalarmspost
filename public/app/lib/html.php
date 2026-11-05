<?php
declare(strict_types=1);

/** Escape for HTML text and attributes. Every value printed in a view goes through this. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** "Middle East & Africa!" -> "middle-east-africa" (letters and digits only, for addresses). */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii)) {
            $text = strtolower($ascii);
        }
    }
    $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
    return substr(trim($text, '-'), 0, 100);
}
