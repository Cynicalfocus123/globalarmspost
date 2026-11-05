<?php
declare(strict_types=1);

// Copy to app/config.php on the server and fill in. app/config.php is never in git
// and the live sync never overwrites it.
return [
    // SQLite (simple) ...
    'db' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/../storage/site.sqlite',
    ],
    // ... or MySQL (Hostinger):
    // 'db' => [
    //     'driver' => 'mysql',
    //     'host' => 'localhost',
    //     'name' => 'database_name',
    //     'user' => 'database_user',
    //     'pass' => 'database_password',
    // ],
    'debug' => false,
    // Optional. Admin sign-in sessions are kept in storage/sessions unless set here.
    // 'session_path' => __DIR__ . '/../storage/sessions',
    // Optional. Wrong sign-in tries allowed per address in 15 minutes for all emails together (default 50).
    // Each email is always limited to 5 wrong tries in 15 minutes.
    // 'login_ip_limit' => 50,
    // Suggestions while typing: requests per address per minute (default 40).
    // 'suggest_limit' => 40,
];
