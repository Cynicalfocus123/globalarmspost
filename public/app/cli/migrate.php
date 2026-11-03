<?php
declare(strict_types=1);

// Run pending database migrations: php app/cli/migrate.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

$ran = migrate();
echo $ran === [] ? "Database is up to date.\n" : 'Ran: ' . implode(', ', $ran) . "\n";
