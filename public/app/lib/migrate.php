<?php
declare(strict_types=1);

/**
 * Numbered migrations in app/migrations/NNN_name.php. Each file returns function (PDO $db, string $driver): void.
 * Ran migrations are recorded in the migrations table, so each runs once and new features
 * add columns without losing data.
 *
 * @return list<string> the migrations that ran now
 */
function migrate(): array
{
    $db = db();
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (id VARCHAR(100) PRIMARY KEY, ran_at VARCHAR(19) NOT NULL)');
    $done = array_column(q_all('SELECT id FROM migrations'), 'id');
    $files = glob(APP_DIR . '/migrations/[0-9][0-9][0-9]_*.php') ?: [];
    sort($files);
    $ran = [];
    foreach ($files as $file) {
        $id = basename($file, '.php');
        if (in_array($id, $done, true)) {
            continue;
        }
        $step = require $file;
        $db->beginTransaction();
        try {
            $step($db, db_driver());
            q('INSERT INTO migrations (id, ran_at) VALUES (?, ?)', [$id, now_utc()]);
            // MySQL ends a transaction on CREATE TABLE by itself, so commit only if still open.
            if ($db->inTransaction()) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $ran[] = $id;
    }
    return $ran;
}

/** Column type for an auto-increment primary key on either database. */
function pk(string $driver): string
{
    return $driver === 'mysql' ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
}

/** Table options for MySQL (utf8mb4, InnoDB). */
function table_opts(string $driver): string
{
    return $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
}

function is_installed(): bool
{
    try {
        return q_one("SELECT id FROM migrations WHERE id = '002_starter_content'") !== null;
    } catch (Throwable) {
        return false;
    }
}
