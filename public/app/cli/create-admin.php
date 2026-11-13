<?php
declare(strict_types=1);

/**
 * Make an admin (or editor) account, or give an account a new password (task B22, owner 2026-11-14: there is no
 * setup page on the web, so nobody on the internet can ever make the first admin).
 *
 *   php app/cli/create-admin.php <email> "<name>" [--role=editor] [--reset]
 *
 * The password: typed or piped in on the first line (at least 10 characters); with nothing given, a strong one is
 * made and shown once. --reset gives an existing account a new password and signs it out everywhere.
 * Runs only on the command line (SSH, or the local site: npm run admin); the web server answers 404.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
require_once APP_DIR . '/lib/auth.php';
require_once APP_DIR . '/lib/content.php';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn (string $a): bool => str_starts_with($a, '--')));
$plain = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));
$email = strtolower(trim($plain[0] ?? ''));
$name = clean_text($plain[1] ?? '', 120);
$reset = in_array('--reset', $flags, true);
$role = in_array('--role=editor', $flags, true) ? 'editor' : 'admin';
$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $fail("Usage: php app/cli/create-admin.php <email> \"<name>\" [--role=editor] [--reset]\nPlease give a valid email address.");
}
$user = q_one('SELECT id, role FROM users WHERE email = ?', [$email]);
if ($user !== null && !$reset) {
    $fail("An account with $email already exists. Add --reset to give it a new password.");
}
if ($user === null && $name === '') {
    $fail('Please give the name too: php app/cli/create-admin.php ' . $email . ' "Your Name"');
}

// The password: the first line of the input when one is typed or piped in, else a new strong one.
$typed = '';
if (!stream_isatty(STDIN)) {
    $typed = rtrim((string) fgets(STDIN), "\r\n");
} else {
    fwrite(STDOUT, 'Password (at least 10 characters; press Enter to have one made for you): ');
    $typed = rtrim((string) fgets(STDIN), "\r\n");
}
$made = $typed === '';
$password = $made ? strtr(base64_encode(random_bytes(15)), '+/', '-_') : $typed;
if (password_problem($password) !== '') {
    $fail(password_problem($password));
}
$hash = password_hash($password, PASSWORD_DEFAULT);

if ($user === null) {
    q('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)', [$email, $name, $hash, $role, now_utc()]);
    $id = (int) db()->lastInsertId();
    audit('cli.create_user', $email . ' (' . $role . ')', $id);
    echo "Account made: $email ($role).\n";
} else {
    $id = (int) $user['id'];
    q('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    // Every "keep me signed in" cookie stops working; open sessions end at their next request (password mark).
    q('UPDATE remember_tokens SET expires_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $id]);
    audit('cli.reset_password', $email, $id);
    echo "New password set for $email; it is signed out everywhere.\n";
}
if ($made) {
    echo "Password (shown only now, keep it safe and change it in the admin under your name): $password\n";
}
echo "Sign in at /admin/login.\n";
