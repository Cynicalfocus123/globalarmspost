<?php
declare(strict_types=1);

/** Escape for HTML text and attributes. Every value printed in a view goes through this. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
