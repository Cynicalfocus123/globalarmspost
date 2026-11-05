<?php
declare(strict_types=1);

// Test helper: run one SQL statement against a test copy's database and print the rows as JSON.
// Usage: GAP_CONFIG=... php tests/db.php <docroot> "<sql>" [param ...]
//
// The page cache is on in the test runs, the same as on the real site (task B12). A statement that
// changes the database clears the cache afterwards, just as every admin save does, so the next page
// shows the change. SELECTs leave the cache alone.
[$_, $docroot, $sql] = $argv + [null, '', ''];
$params = array_slice($argv, 3);
require $docroot . '/app/bootstrap.php';
$stmt = q($sql, $params);
$isSelect = stripos(ltrim($sql), 'select') === 0;
$out = $isSelect ? $stmt->fetchAll() : ['changed' => $stmt->rowCount()];
if (!$isSelect) {
    cache_clear();
}
echo json_encode($out);
