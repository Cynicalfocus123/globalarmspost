<?php
declare(strict_types=1);

// Stories, tags, pictures and homepage sections (tasks B7, B8, B9, F8).
return function (PDO $db, string $driver): void {
    $pk = pk($driver);
    $o = table_opts($driver);

    // A picture is stored once in several widths: {path}-{w}.avif and {path}-{w}.webp.
    $db->exec("CREATE TABLE media (
        id $pk,
        path VARCHAR(255) NOT NULL,
        widths VARCHAR(60) NOT NULL,
        has_avif INT NOT NULL DEFAULT 1,
        width INT NOT NULL,
        height INT NOT NULL,
        alt VARCHAR(300) NOT NULL DEFAULT '',
        caption TEXT NULL,
        credit VARCHAR(200) NOT NULL DEFAULT '',
        created_at VARCHAR(19) NOT NULL
    )$o");

    // status: draft | published | trash. Public = published AND published_at <= now (UTC).
    $db->exec("CREATE TABLE posts (
        id $pk,
        title VARCHAR(300) NOT NULL,
        slug VARCHAR(200) NOT NULL UNIQUE,
        excerpt TEXT NULL,
        summary TEXT NULL,
        body MEDIUMTEXT NULL,
        credits VARCHAR(300) NOT NULL DEFAULT '',
        author_name VARCHAR(120) NOT NULL DEFAULT '',
        category_id INT NOT NULL,
        media_id INT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        sticky INT NOT NULL DEFAULT 0,
        live_label INT NOT NULL DEFAULT 0,
        views INT NOT NULL DEFAULT 0,
        published_at VARCHAR(19) NULL,
        updated_at VARCHAR(19) NOT NULL,
        created_at VARCHAR(19) NOT NULL
    )$o");
    $db->exec('CREATE INDEX posts_public ON posts (status, published_at)');
    $db->exec('CREATE INDEX posts_category ON posts (category_id, status, published_at)');

    $db->exec("CREATE TABLE tags (
        id $pk,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE
    )$o");
    $db->exec("CREATE TABLE post_tags (
        post_id INT NOT NULL,
        tag_id INT NOT NULL,
        PRIMARY KEY (post_id, tag_id)
    )$o");

    // One row per homepage section. settings is JSON (see app/lib/sections.php for the keys).
    $db->exec("CREATE TABLE home_sections (
        id $pk,
        position INT NOT NULL DEFAULT 0,
        layout VARCHAR(40) NOT NULL,
        title VARCHAR(120) NOT NULL DEFAULT '',
        span INT NOT NULL DEFAULT 12,
        settings TEXT NOT NULL,
        active INT NOT NULL DEFAULT 1
    )$o");
};
