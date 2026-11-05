<?php
declare(strict_types=1);

/** The one database connection: MySQL (Hostinger) or SQLite. Prepared statements only. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config()['db'] ?? null;
    if (!is_array($c)) {
        throw new RuntimeException('Database is not configured.');
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (($c['driver'] ?? '') === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', (int) ($c['port'] ?? 3306), $c['name'] ?? '');
        $pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), $options);
        $pdo->exec("SET time_zone = '+00:00'");
    } else {
        $path = (string) ($c['path'] ?? '');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // Wait at most 5 s for another request's write. PDO's default is 60 s, so a busy database turned
        // into a request that answered minutes later (a 45 s test sees it as "never answered").
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // WAL already keeps every commit safe: the extra full fsync only made writes slower, and slower
        // writes hold the write lock longer for everybody else.
        $pdo->exec('PRAGMA synchronous = NORMAL');
    }
    return $pdo;
}

function db_driver(): string
{
    return (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/**
 * Run a prepared query.
 * @param array<int|string, mixed> $params
 */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** @param array<int|string, mixed> $params @return array<string, mixed>|null */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}
