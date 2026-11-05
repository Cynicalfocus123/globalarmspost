<?php
declare(strict_types=1);

// Demo admin for the LOCAL PREVIEW only (never the real site, never live/). Run by tests/serve.mjs with --demo-admin.
// The email and password are in tests/fixtures/test-users.json ("demo_admin").
// Usage: GAP_CONFIG=... php tests/seed-admin.php <docroot>
$docroot = $argv[1] ?? '';
require $docroot . '/app/bootstrap.php';

$users = json_decode((string) file_get_contents(__DIR__ . '/fixtures/test-users.json'), true);
$a = $users['demo_admin'];
if (q_one('SELECT id FROM users WHERE email = ?', [$a['email']]) !== null) {
    echo "Demo admin already there.
";
    return;
}
q("INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', ?)",
    [$a['email'], $a['name'], password_hash($a['password'], PASSWORD_DEFAULT), now_utc()]);
echo "Demo admin added: {$a['email']} (password in tests/fixtures/test-users.json).
";
