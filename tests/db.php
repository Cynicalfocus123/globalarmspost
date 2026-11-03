<?php
declare(strict_types=1);

// Test helper: run one SQL statement against a test copy's database and print the rows as JSON.
// Usage: GAP_CONFIG=... php tests/db.php <docroot> "<sql>" [param ...]
[$_, $docroot, $sql] = $argv + [null, '', ''];
$params = array_slice($argv, 3);
require $docroot . '/app/bootstrap.php';
$stmt = q($sql, $params);
echo json_encode(stripos(ltrim($sql), 'select') === 0 ? $stmt->fetchAll() : ['changed' => $stmt->rowCount()]);
