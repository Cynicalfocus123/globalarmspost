<?php
declare(strict_types=1);

/**
 * Extra categories (owner, 2026-11-13). A story keeps one MAIN category in posts.category_id: it gives the story's
 * address (/land/{slug}), the breadcrumb and "More in Land". post_categories holds the EXTRA categories the story
 * also shows in (category pages, homepage sections, search filter). New table only: nothing existing changes.
 */
return function (PDO $db, string $driver): void {
    $o = table_opts($driver);
    $db->exec("CREATE TABLE post_categories (
        post_id INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (post_id, category_id)
    )$o");
    $db->exec('CREATE INDEX post_categories_category ON post_categories (category_id, post_id)');
};
