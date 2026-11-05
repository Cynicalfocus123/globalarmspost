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

/** Write one setting (insert or update, the same SQL on MySQL and SQLite). */
function setting_save(string $name, string $value): void
{
    if (q_one('SELECT name FROM settings WHERE name = ?', [$name]) === null) {
        q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    } else {
        q('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    }
}
