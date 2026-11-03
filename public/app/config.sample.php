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
];
