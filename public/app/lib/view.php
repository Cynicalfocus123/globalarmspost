<?php
declare(strict_types=1);

/**
 * Render a view from app/views with its own variables.
 * @param array<string, mixed> $vars
 */
function view(string $name, array $vars = []): string
{
    $file = APP_DIR . '/views/' . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Missing view: ' . $name);
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    require $file;
    return (string) ob_get_clean();
}

/**
 * Header logo sizes, written by scripts/build-logo.mjs.
 * @return array{w1: int, h1: int, w2: int, h2: int}
 */
function logo_meta(): array
{
    static $meta = null;
    if ($meta === null) {
        $json = @file_get_contents(PUBLIC_DIR . '/assets/img/logo/logo.json');
        $data = is_string($json) ? json_decode($json, true) : null;
        $meta = is_array($data) ? array_map('intval', $data) + ['w1' => 240, 'h1' => 77, 'w2' => 480, 'h2' => 153]
            : ['w1' => 240, 'h1' => 77, 'w2' => 480, 'h2' => 153];
    }
    /** @var array{w1: int, h1: int, w2: int, h2: int} $meta */
    return $meta;
}
