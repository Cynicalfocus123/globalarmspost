<?php
declare(strict_types=1);

// Admin (step 3): users and sign-in (B4, B16), authors (B19), gallery photos, daily views, audit log.
// Existing stories keep working: each author_name becomes an author and the story is linked to it.
return function (PDO $db, string $driver): void {
    $pk = pk($driver);
    $o = table_opts($driver);

    // role: admin | editor. Passwords are password_hash() (bcrypt/argon2). totp_* = optional two-step login.
    $db->exec("CREATE TABLE users (
        id $pk,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(12) NOT NULL DEFAULT 'editor',
        totp_secret VARCHAR(64) NULL,
        totp_enabled INT NOT NULL DEFAULT 0,
        totp_last INT NOT NULL DEFAULT 0,
        created_at VARCHAR(19) NOT NULL,
        last_login_at VARCHAR(19) NULL
    )$o");

    // Every sign-in try (ok = 1 for a success). Throttling: 5 wrong tries per email + address in 15 minutes.
    $db->exec("CREATE TABLE login_attempts (
        id $pk,
        email VARCHAR(190) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        ok INT NOT NULL DEFAULT 0,
        at VARCHAR(19) NOT NULL
    )$o");
    $db->exec('CREATE INDEX login_attempts_email ON login_attempts (email, ip, at)');
    $db->exec('CREATE INDEX login_attempts_ip ON login_attempts (ip, at)');

    // "Keep me signed in": selector + hash of the secret part (the secret itself is only in the cookie).
    $db->exec("CREATE TABLE remember_tokens (
        id $pk,
        user_id INT NOT NULL,
        selector VARCHAR(24) NOT NULL UNIQUE,
        token_hash VARCHAR(64) NOT NULL,
        expires_at VARCHAR(19) NOT NULL
    )$o");

    $db->exec("CREATE TABLE authors (
        id $pk,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL DEFAULT '',
        bio TEXT NULL,
        media_id INT NULL,
        created_at VARCHAR(19) NOT NULL
    )$o");

    $db->exec('ALTER TABLE posts ADD COLUMN author_id INT NULL');
    $db->exec('ALTER TABLE posts ADD COLUMN featured_caption TEXT NULL');
    $db->exec("ALTER TABLE posts ADD COLUMN featured_credit VARCHAR(200) NOT NULL DEFAULT ''");
    $db->exec('CREATE INDEX posts_author ON posts (author_id)');

    // Extra photos for the article gallery, each with its own caption and credit.
    $db->exec("CREATE TABLE post_gallery (
        id $pk,
        post_id INT NOT NULL,
        media_id INT NOT NULL,
        position INT NOT NULL DEFAULT 0,
        caption TEXT NULL,
        credit VARCHAR(200) NOT NULL DEFAULT ''
    )$o");
    $db->exec('CREATE INDEX post_gallery_post ON post_gallery (post_id, position)');

    // Story views per local day (site time zone), for "Views today" on the dashboard.
    $db->exec("CREATE TABLE views_daily (
        day VARCHAR(10) PRIMARY KEY,
        views INT NOT NULL DEFAULT 0
    )$o");

    $db->exec("CREATE TABLE audit_log (
        id $pk,
        user_id INT NULL,
        action VARCHAR(60) NOT NULL,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        at VARCHAR(19) NOT NULL
    )$o");

    // Existing stories: one author per author_name.
    $now = now_utc();
    $add = $db->prepare('INSERT INTO authors (name, slug, created_at) VALUES (?, ?, ?)');
    $link = $db->prepare('UPDATE posts SET author_id = ? WHERE author_name = ?');
    $names = $db->query("SELECT DISTINCT author_name FROM posts WHERE author_name <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $used = [];
    foreach ($names as $name) {
        $base = slugify((string) $name) ?: 'author';
        $slug = $base;
        for ($n = 2; isset($used[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $used[$slug] = true;
        $add->execute([$name, $slug, $now]);
        $link->execute([(int) $db->lastInsertId(), $name]);
    }
};
