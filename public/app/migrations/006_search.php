<?php
declare(strict_types=1);

/**
 * Smart search (tasks B11 + F7): our own word index, so no full text search engine is needed and the same
 * SQL runs on MySQL 8 and SQLite.
 *
 *   search_terms       the vocabulary: one row per stemmed word, with how many stories use it (for typo
 *                      candidates and for splitting joined words like "ukrainewar")
 *   search_post_terms  the inverted index: term -> story, with a weight per story (title beats body)
 *   search_docs        what was indexed for a story (fingerprint), so a rebuild only redoes what changed
 *
 * Stories are indexed whether they are published, scheduled or drafts; the query itself only ever returns
 * public stories (status 'published' AND published_at <= now), which is why a scheduled story becomes
 * findable at its exact time without touching the index.
 */
return function (PDO $db, string $driver): void {
    $o = table_opts($driver);

    $db->exec("CREATE TABLE search_terms (
        term VARCHAR(64) NOT NULL PRIMARY KEY,
        docs INT NOT NULL DEFAULT 0
    )$o");

    $db->exec("CREATE TABLE search_post_terms (
        term VARCHAR(64) NOT NULL,
        post_id INT NOT NULL,
        weight INT NOT NULL DEFAULT 1,
        PRIMARY KEY (term, post_id)
    )$o");
    $db->exec('CREATE INDEX search_post_terms_post ON search_post_terms (post_id)');
    $db->exec('CREATE INDEX search_post_terms_weight ON search_post_terms (post_id, weight)');

    // fingerprint = md5(title|summary|excerpt|credits|body|category|tags|author|updated_at) so a rebuild can
    // skip stories whose text did not change.
    $db->exec("CREATE TABLE search_docs (
        post_id INT NOT NULL PRIMARY KEY,
        fingerprint VARCHAR(40) NOT NULL DEFAULT '',
        words INT NOT NULL DEFAULT 0,
        indexed_at VARCHAR(19) NOT NULL
    )$o");
};
