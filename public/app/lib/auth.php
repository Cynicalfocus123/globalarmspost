<?php
declare(strict_types=1);

/**
 * Admin sign-in (tasks B4, B16): hardened session, CSRF tokens, login throttling, "keep me signed in",
 * roles (admin, editor) and optional two-step login with an authenticator app (TOTP, RFC 6238).
 * Only loaded for /admin.
 */

const LOGIN_TRIES = 5;          // wrong tries per email + address ...
const LOGIN_WINDOW = 15 * 60;   // ... in this many seconds
const REMEMBER_DAYS = 30;

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $path = (string) (config()['session_path'] ?? PUBLIC_DIR . '/storage/sessions');
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    if (is_dir($path) && is_writable($path)) {
        session_save_path($path);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) (12 * 3600));
    session_name('gap_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Every POST must carry the session's token (form field or X-CSRF-Token header) and come from this site. */
function csrf_ok(): bool
{
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        return false;
    }
    return $sent !== '' && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $sent);
}

/** @return array<string, mixed>|null the signed-in user */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $id = (int) ($_SESSION['uid'] ?? 0);
    if ($id > 0) {
        $user = q_one('SELECT id, email, name, role, totp_enabled FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            unset($_SESSION['uid']);
        }
    } elseif (isset($_COOKIE['gap_remember'])) {
        $user = remember_login((string) $_COOKIE['gap_remember']);
    }
    return $user;
}

/** Is a login for this email from this address blocked? Returns the minutes left, or 0. */
function login_blocked(string $email): int
{
    $since = gmdate('Y-m-d H:i:s', time() - LOGIN_WINDOW);
    $ip = client_ip();
    // Wrong tries since the last success, for this email from this address.
    $lastOk = (string) (q_one('SELECT MAX(at) AS at FROM login_attempts WHERE email = ? AND ip = ? AND ok = 1', [$email, $ip])['at'] ?? '');
    $from = $lastOk > $since ? $lastOk : $since;
    $row = q_one('SELECT COUNT(*) AS n, MIN(at) AS first FROM login_attempts WHERE email = ? AND ip = ? AND ok = 0 AND at > ?', [$email, $ip, $from]);
    $limitIp = (int) (config()['login_ip_limit'] ?? 50); // all emails from one address
    $ipRow = q_one('SELECT COUNT(*) AS n, MIN(at) AS first FROM login_attempts WHERE ip = ? AND ok = 0 AND at > ?', [$ip, $since]);
    foreach ([[$row, LOGIN_TRIES], [$ipRow, $limitIp]] as [$r, $limit]) {
        if ((int) ($r['n'] ?? 0) >= $limit) {
            $left = strtotime((string) $r['first'] . ' UTC') + LOGIN_WINDOW - time();
            return max(1, (int) ceil($left / 60));
        }
    }
    return 0;
}

function record_attempt(string $email, bool $ok): void
{
    q('INSERT INTO login_attempts (email, ip, ok, at) VALUES (?, ?, ?, ?)', [$email, client_ip(), $ok ? 1 : 0, now_utc()]);
}

/**
 * Check email + password. On success the user is signed in, or (with two-step login on) waits for the code.
 * @return array{ok: bool, two_step?: bool, error?: string}
 */
function attempt_login(string $email, string $password, bool $remember): array
{
    $email = strtolower(trim($email));
    if (($wait = login_blocked($email)) > 0) {
        return ['ok' => false, 'error' => "Too many wrong tries. Wait $wait minute" . ($wait === 1 ? '' : 's') . ' and try again.'];
    }
    $user = q_one('SELECT id, password_hash, totp_enabled FROM users WHERE email = ?', [$email]);
    // Same work whether or not the email exists.
    $hash = $user['password_hash'] ?? '$2y$12$GSiT2R4piGpz24Q0PDwjt.pJ3MJPwtMXiWSlJOvH7cDwTpHi80Vq.';
    if (!password_verify($password, (string) $hash) || $user === null) {
        record_attempt($email, false);
        return ['ok' => false, 'error' => 'Wrong email or password.'];
    }
    if (password_needs_rehash((string) $hash, PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
    }
    session_regenerate_id(true);
    if ((int) $user['totp_enabled'] === 1) {
        $_SESSION['pending_uid'] = (int) $user['id'];
        $_SESSION['pending_email'] = $email;
        $_SESSION['pending_remember'] = $remember;
        $_SESSION['pending_at'] = time();
        return ['ok' => true, 'two_step' => true];
    }
    record_attempt($email, true);
    finish_login((int) $user['id'], $remember);
    return ['ok' => true];
}

/** Second step: the 6-digit code from the authenticator app. */
function attempt_two_step(string $code): array
{
    $id = (int) ($_SESSION['pending_uid'] ?? 0);
    $email = (string) ($_SESSION['pending_email'] ?? '');
    if ($id === 0 || time() - (int) ($_SESSION['pending_at'] ?? 0) > 600) {
        unset($_SESSION['pending_uid']);
        return ['ok' => false, 'error' => 'Please sign in again.', 'restart' => true];
    }
    if (($wait = login_blocked($email)) > 0) {
        return ['ok' => false, 'error' => "Too many wrong tries. Wait $wait minutes and try again."];
    }
    $user = q_one('SELECT id, totp_secret, totp_last FROM users WHERE id = ?', [$id]);
    $step = $user === null ? null : totp_verify((string) $user['totp_secret'], $code, (int) $user['totp_last']);
    if ($step === null) {
        record_attempt($email, false);
        return ['ok' => false, 'error' => 'That code is not right. Use the newest code from your app.'];
    }
    q('UPDATE users SET totp_last = ? WHERE id = ?', [$step, $id]); // a code works only once
    record_attempt($email, true);
    $remember = !empty($_SESSION['pending_remember']);
    unset($_SESSION['pending_uid'], $_SESSION['pending_email'], $_SESSION['pending_remember'], $_SESSION['pending_at']);
    finish_login($id, $remember);
    return ['ok' => true];
}

function finish_login(int $id, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now_utc(), $id]);
    if ($remember) {
        issue_remember_token($id);
    }
    audit('login', '', $id);
}

function issue_remember_token(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $secret = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    q('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)',
        [$userId, $selector, hash('sha256', $secret), gmdate('Y-m-d H:i:s', $expires)]);
    setcookie('gap_remember', $selector . ':' . $secret, ['expires' => $expires, 'path' => '/admin', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Sign in from a "keep me signed in" cookie. Each use swaps the token for a new one. */
function remember_login(string $cookie): ?array
{
    [$selector, $secret] = array_pad(explode(':', $cookie, 2), 2, '');
    $row = $selector === '' ? null : q_one('SELECT id, user_id, token_hash, expires_at FROM remember_tokens WHERE selector = ?', [$selector]);
    if ($row === null || (string) $row['expires_at'] < now_utc() || !hash_equals((string) $row['token_hash'], hash('sha256', $secret))) {
        setcookie('gap_remember', '', ['expires' => 1, 'path' => '/admin']);
        return null;
    }
    q('UPDATE remember_tokens SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 1), (int) $row['id']]); // used up
    $user = q_one('SELECT id, email, name, role, totp_enabled FROM users WHERE id = ?', [(int) $row['user_id']]);
    if ($user === null) {
        return null;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    issue_remember_token((int) $user['id']);
    return $user;
}

function logout(): void
{
    if (isset($_COOKIE['gap_remember'])) {
        [$selector] = explode(':', (string) $_COOKIE['gap_remember'], 2);
        q('UPDATE remember_tokens SET expires_at = ? WHERE selector = ?', [gmdate('Y-m-d H:i:s', time() - 1), $selector]);
        setcookie('gap_remember', '', ['expires' => 1, 'path' => '/admin']);
    }
    $_SESSION = [];
    session_regenerate_id(true);
}

function audit(string $action, string $detail = '', ?int $userId = null): void
{
    $userId ??= (int) ($_SESSION['uid'] ?? 0) ?: null;
    q('INSERT INTO audit_log (user_id, action, detail, ip, at) VALUES (?, ?, ?, ?, ?)', [$userId, $action, mb_substr($detail, 0, 500), client_ip(), now_utc()]);
}

/** Password rule for new accounts: at least 10 characters. */
function password_problem(string $password): string
{
    return mb_strlen($password) < 10 ? 'The password needs at least 10 characters.' : '';
}

/* ---- Two-step login (TOTP: 6 digits, 30 seconds, SHA-1, as every authenticator app uses) ---- */

function base32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '')) as $c) {
        $bits .= str_pad(decbin((int) strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int) bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0xf;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The time step the code belongs to (now ±30 s), or null. Steps up to $last were used already. */
function totp_verify(string $secret, string $code, int $last = 0): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || $secret === '') {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([$now, $now - 1, $now + 1] as $step) {
        if ($step > $last && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}
