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
        // Windows can refuse the -wal/-shm files for a moment while another program (a virus scanner) has them
        // open ("disk I/O error"): try the open a few times before giving up.
        for ($try = 1; ; $try++) {
            try {
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA journal_mode = WAL');
                break;
            } catch (PDOException $e) {
                $pdo = null;
                if ($try >= 5 || !str_contains($e->getMessage(), 'disk I/O error')) {
                    throw $e;
                }
                usleep(200_000 * $try);
            }
        }
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

/**
 * Run $fn as one transaction that writes, so two requests changing the same rows never interleave
 * (e.g. the search index of one story being rebuilt by a save and by "Rebuild search index" at once).
 *   - SQLite: BEGIN IMMEDIATE takes the write lock first (waiting up to busy_timeout), so a transaction that
 *     reads and then writes can never fail half-way because another request wrote in between;
 *   - MySQL: InnoDB row locks; a deadlock (another request held the rows) is retried twice.
 * Nested calls join the outer transaction.
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function db_atomic(callable $fn): mixed
{
    static $depth = 0;
    if ($depth > 0) {
        return $fn();
    }
    $db = db();
    $sqlite = db_driver() === 'sqlite';
    for ($try = 1; ; $try++) {
        $sqlite ? $db->exec('BEGIN IMMEDIATE') : $db->beginTransaction();
        $depth++;
        try {
            $out = $fn();
            $sqlite ? $db->exec('COMMIT') : $db->commit();
            return $out;
        } catch (Throwable $e) {
            try {
                $sqlite ? $db->exec('ROLLBACK') : $db->rollBack();
            } catch (Throwable) {
                // the transaction is already gone (the database rolled it back itself)
            }
            $deadlock = $e instanceof PDOException && in_array((string) ($e->errorInfo[1] ?? ''), ['1213', '1205'], true);
            if (!$deadlock || $try >= 3) {
                throw $e;
            }
        } finally {
            $depth--;
        }
    }
}
