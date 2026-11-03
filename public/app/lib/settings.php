<?php
declare(strict_types=1);

/** Site settings (set in the admin). Read once per request. */
function setting(string $name, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (q_all('SELECT name, value FROM settings') as $row) {
            $all[(string) $row['name']] = (string) $row['value'];
        }
    }
    return $all[$name] ?? $default;
}
