<?php
declare(strict_types=1);

/**
 * Smart search (tasks B11 + F7). Our own word index (migration 006), so the same code runs on MySQL 8 and
 * SQLite and the site needs no search server:
 *
 *   - one stemmed vocabulary (`search_terms`), so "missile" and "missiles" are the same word, and a query
 *     word also matches every longer word that starts with it ("strike" -> "strikes", "striking");
 *   - an inverted index (`search_post_terms`): term -> story with a weight, so the title counts for more
 *     than the body and a query never scans the stories table;
 *   - joined words are split with the vocabulary ("ukrainewar" -> "ukraine war");
 *   - unknown words are matched against the vocabulary by edit distance ("ukrane" -> "ukraine"), and the
 *     search page says "Did you mean …";
 *   - results are ranked (title and tag matches first, whole phrase higher, newer stories a little higher)
 *     and shown with a snippet where the matched words are highlighted.
 *
 * Only public stories are ever returned: status 'published' AND published_at <= now (UTC).
 */

/* ---------- words ---------- */

/** Lower case, accents folded to ASCII, everything that is not a letter or a digit becomes a space. */
function search_normalize(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') {
            $text = strtolower($ascii);
        }
    }
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
}

/** @return list<string> */
function search_stopwords(): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = ['a', 'an', 'the', 'and', 'or', 'but', 'if', 'of', 'in', 'on', 'at', 'to', 'for', 'from', 'by', 'with',
            'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'it', 'its', 'this', 'that', 'these', 'those',
            'he', 'she', 'they', 'we', 'you', 'i', 'his', 'her', 'their', 'our', 'your', 'its', 'not', 'no', 'has',
            'have', 'had', 'do', 'does', 'did', 'will', 'would', 'can', 'could', 'should', 'may', 'might', 'must',
            'than', 'then', 'there', 'here', 'when', 'where', 'who', 'whom', 'which', 'what', 'how', 'why', 'all',
            'any', 'both', 'each', 'more', 'most', 'other', 'some', 'such', 'only', 'own', 'same', 'so', 'too',
            'very', 'just', 'also', 'about', 'after', 'before', 'over', 'under', 'again', 'further', 'once', 'up',
            'down', 'out', 'off', 'into', 'onto', 'per', 'via', 'amid', 's', 't', 'us'];
    }
    return $stop;
}

function search_is_stop(string $term): bool
{
    return in_array($term, search_stopwords(), true);
}

/** One word -> its stem, so plural and simple verb forms collapse ("missiles" -> "missile"). */
function search_stem(string $word): string
{
    $w = search_normalize($word);
    $len = strlen($w);
    if ($len <= 2) {
        return $w;
    }
    if ($len > 4 && str_ends_with($w, 'ies')) {
        return substr($w, 0, -3) . 'y';
    }
    if ($len > 4 && preg_match('/(s|x|z|ch|sh)es$/', $w) === 1) {
        return substr($w, 0, -2);
    }
    if ($len > 3 && str_ends_with($w, 's') && preg_match('/(ss|us|is)$/', $w) !== 1) {
        return substr($w, 0, -1);
    }
    if ($len > 5 && str_ends_with($w, 'ing')) {
        return search_undouble(substr($w, 0, -3));
    }
    if ($len > 4 && str_ends_with($w, 'ed')) {
        return search_undouble(substr($w, 0, -2));
    }
    if ($len > 5 && str_ends_with($w, 'ly')) {
        return substr($w, 0, -2);
    }
    return $w;
}

/** "stopp" -> "stop" (a doubled consonant before -ing / -ed). */
function search_undouble(string $w): string
{
    $len = strlen($w);
    if ($len > 3 && $w[$len - 1] === $w[$len - 2] && preg_match('/[bcdfghjklmnpqrstvwxz]/', $w[$len - 1]) === 1) {
        return substr($w, 0, -1);
    }
    return $w;
}

/**
 * Every stemmed word of a text, without stopwords.
 * @return list<string>
 */
function search_tokens(string $text): array
{
    $out = [];
    foreach (explode(' ', search_normalize($text)) as $word) {
        if ($word === '' || strlen($word) < 2) {
            continue;
        }
        $stem = search_stem($word);
        if ($stem === '' || search_is_stop($stem) || search_is_stop($word)) {
            continue;
        }
        // The index columns hold 64 characters (MySQL's strict mode refuses longer); a longer "word" is a code
        // or a pasted address, and its first 64 letters find it just as well (queries are cut the same way).
        $out[] = substr($stem, 0, 64);
    }
    return $out;
}

/** Body HTML -> plain text (pictures and tags dropped). */
function search_plain(string $html): string
{
    $text = (string) preg_replace('~<(br|/p|/h[1-6]|/li|/blockquote|/figcaption)\b[^>]*>~i', ' ', $html);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/* ---------- indexing ---------- */

/** @return array<string, int> base weight per searchable part of a story */
function search_weights(): array
{
    return ['title' => 10, 'tags' => 7, 'summary' => 5, 'category' => 4, 'excerpt' => 4, 'author' => 3, 'body' => 1];
}

/**
 * The searchable text of a story and the fingerprint of it.
 * @return array{fields: array<string, string>, fingerprint: string, words: int}
 */
function search_document(int $postId): ?array
{
    $row = q_one('SELECT p.id, p.title, p.summary, p.excerpt, p.credits, p.body, p.updated_at,
                         c.name AS category, COALESCE(a.name, p.author_name) AS author
                    FROM posts p JOIN categories c ON c.id = p.category_id LEFT JOIN authors a ON a.id = p.author_id
                   WHERE p.id = ?', [$postId]);
    if ($row === null) {
        return null;
    }
    $tags = implode(' ', array_column(q_all('SELECT t.name FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = ?', [$postId]), 'name'));
    $fields = [
        'title' => (string) $row['title'],
        'tags' => $tags,
        'summary' => (string) $row['summary'],
        'category' => (string) $row['category'],
        'excerpt' => (string) $row['excerpt'],
        'author' => (string) $row['author'],
        'body' => search_plain((string) $row['body']),
    ];
    $fingerprint = md5(implode("\x1f", $fields) . '|' . (string) $row['updated_at'] . '|' . (string) $row['credits']);
    return ['fields' => $fields, 'fingerprint' => $fingerprint, 'words' => str_word_count($fields['body']) + str_word_count($fields['title'])];
}

/** Drop everything the index holds for one story (and fix the vocabulary counts), as one transaction. */
function search_forget_post(int $postId): void
{
    db_atomic(static function () use ($postId): void {
        search_forget_post_now($postId);
    });
}

function search_forget_post_now(int $postId): void
{
    $terms = array_column(q_all('SELECT term FROM search_post_terms WHERE post_id = ?', [$postId]), 'term');
    q('DELETE FROM search_post_terms WHERE post_id = ?', [$postId]);
    q('DELETE FROM search_docs WHERE post_id = ?', [$postId]);
    foreach ($terms as $term) {
        search_refresh_term((string) $term);
    }
}

/** Recount one vocabulary entry after the index changed (0 rows -> it is gone). */
function search_refresh_term(string $term): void
{
    $n = (int) (q_one('SELECT COUNT(*) AS n FROM search_post_terms WHERE term = ?', [$term])['n'] ?? 0);
    // Look first, then write: MySQL reports 0 changed rows when the count happens to be the same, and an
    // "insert if no rows changed" would then hit the primary key.
    if (q_one('SELECT term FROM search_terms WHERE term = ?', [$term]) === null) {
        if ($n > 0) {
            q('INSERT INTO search_terms (term, docs) VALUES (?, ?)', [$term, $n]);
        }
        return;
    }
    if ($n === 0) {
        q('DELETE FROM search_terms WHERE term = ?', [$term]);
        return;
    }
    q('UPDATE search_terms SET docs = ? WHERE term = ?', [$n, $term]);
}

/**
 * Index one story (called on every save, publish, trash and restore).
 * One transaction: a save and "Rebuild search index" (or two saves) indexing the same story at once would
 * otherwise both delete its rows and then both insert them, and the second insert breaks the primary key.
 * @return int the number of words indexed
 */
function search_index_post(int $postId): int
{
    return db_atomic(static fn (): int => search_index_post_now($postId));
}

function search_index_post_now(int $postId): int
{
    $doc = search_document($postId);
    if ($doc === null) {
        search_forget_post_now($postId);
        return 0;
    }
    $weights = search_weights();
    $perTerm = [];
    foreach ($doc['fields'] as $field => $text) {
        $base = $weights[$field] ?? 1;
        foreach (array_unique(search_tokens($text)) as $term) {
            $perTerm[$term] = min(40, ($perTerm[$term] ?? 0) + $base);
        }
    }
    $old = array_column(q_all('SELECT term FROM search_post_terms WHERE post_id = ?', [$postId]), 'term');
    q('DELETE FROM search_post_terms WHERE post_id = ?', [$postId]);
    foreach ($perTerm as $term => $weight) {
        q('INSERT INTO search_post_terms (term, post_id, weight) VALUES (?, ?, ?)', [$term, $postId, $weight]);
    }
    foreach (array_unique(array_merge($old, array_keys($perTerm))) as $term) {
        search_refresh_term((string) $term);
    }
    $docs = q_one('SELECT post_id FROM search_docs WHERE post_id = ?', [$postId]);
    if ($docs === null) {
        q('INSERT INTO search_docs (post_id, fingerprint, words, indexed_at) VALUES (?, ?, ?, ?)', [$postId, $doc['fingerprint'], $doc['words'], now_utc()]);
    } else {
        q('UPDATE search_docs SET fingerprint = ?, words = ?, indexed_at = ? WHERE post_id = ?', [$doc['fingerprint'], $doc['words'], now_utc(), $postId]);
    }
    return count($perTerm);
}

/**
 * Rebuild the whole index (the admin's "Rebuild search index" button). Only stories whose text changed are
 * indexed again, and index rows of deleted stories are removed.
 * @return array{posts: int, terms: int, skipped: int}
 */
function search_reindex_all(): array
{
    $done = [];
    foreach (q_all('SELECT post_id, fingerprint FROM search_docs') as $row) {
        $done[(int) $row['post_id']] = (string) $row['fingerprint'];
    }
    $ids = array_map('intval', array_column(q_all('SELECT id FROM posts'), 'id'));
    $indexed = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        $doc = search_document($id);
        if ($doc !== null && ($done[$id] ?? '') === $doc['fingerprint']) {
            $skipped++;
            continue;
        }
        search_index_post($id);
        $indexed++;
    }
    // Stories that no longer exist.
    foreach (array_keys($done) as $gone) {
        if (!in_array($gone, $ids, true)) {
            search_forget_post($gone);
        }
    }
    // Vocabulary counts from scratch (cheap and exact): a full re-count keeps "docs" trustworthy. One
    // transaction, so a second rebuild or a save at the same time never sees (or refills) a half-empty table.
    db_atomic(static function (): void {
        q('DELETE FROM search_terms');
        q('INSERT INTO search_terms (term, docs) SELECT term, COUNT(*) FROM search_post_terms GROUP BY term');
    });
    $terms = (int) (q_one('SELECT COUNT(*) AS n FROM search_terms')['n'] ?? 0);
    return ['posts' => $indexed, 'terms' => $terms, 'skipped' => $skipped];
}

/**
 * Reindex the stories that match a WHERE clause on posts (e.g. every story of a renamed category).
 * @param array<int|string, mixed> $params
 */
function search_reindex_where(string $where, array $params = []): int
{
    $ids = array_map('intval', array_column(q_all('SELECT p.id FROM posts p WHERE ' . $where, $params), 'id'));
    foreach ($ids as $id) {
        search_index_post($id);
    }
    return count($ids);
}

/** @return array<string, mixed>|null one indexed story's stored text (for snippets) */
function search_story_text(int $postId): ?array
{
    return q_one('SELECT p.id, p.title, p.excerpt, p.summary, p.body FROM posts p WHERE p.id = ?', [$postId]);
}

/* ---------- vocabulary lookups: joined words and typos ---------- */

function search_vocabulary_has(string $term): bool
{
    return q_one('SELECT term FROM search_terms WHERE term = ?', [$term]) !== null;
}

/** Edit distance with adjacent swaps counted as one ("ukriane" -> "ukraine"). */
function search_distance(string $a, string $b): int
{
    $la = strlen($a);
    $lb = strlen($b);
    if (abs($la - $lb) > 2) {
        return 3;
    }
    $prev2 = array_fill(0, $lb + 1, 0);
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $cur[$j] = min($cur[$j], $prev2[$j - 2] + 1);
            }
        }
        $prev2 = $prev;
        $prev = $cur;
    }
    return $prev[$lb];
}

/**
 * Split a joined word into known words ("ukrainewar" -> ['ukraine','war']); [] if it cannot be split.
 * @return list<string>
 */
function search_split_joined(string $word): array
{
    $len = strlen($word);
    if ($len < 6) {
        return [];
    }
    $best = null;
    $walk = function (int $at, array $parts) use (&$walk, &$best, $word, $len): void {
        if ($best !== null) {
            return;
        }
        for ($to = $at + 2; $to <= min($len, $at + 16); $to++) {
            $piece = substr($word, $at, $to - $at);
            if (!search_vocabulary_has($piece)) {
                continue;
            }
            if ($to === $len) {
                if ($parts !== []) {
                    $best = [...$parts, $piece]; // the last piece may end the word
                }
                return;
            }
            $walk($to, [...$parts, $piece]);
        }
    };
    $walk(0, []);
    return $best ?? [];
}

/**
 * The vocabulary word closest to a misspelled one, or ''.
 * @return array{term: string, distance: int}
 */
function search_typo_fix(string $word): array
{
    $len = strlen($word);
    if ($len < 4) {
        return ['term' => '', 'distance' => 99];
    }
    $allowed = $len >= 8 ? 2 : 1;
    $first = substr($word, 0, 1);
    $next = chr(ord($first) + 1);
    // The lengths are interpolated on purpose: a bound parameter arrives as text and SQLite compares
    // LENGTH(term) (an integer) with text by type order, which would return no rows at all.
    $min = max(3, $len - 1);
    $max = $len + 1;
    $rows = q_all('SELECT term, docs FROM search_terms WHERE term >= ? AND term < ? AND LENGTH(term) BETWEEN ' . $min . ' AND ' . $max . ' ORDER BY docs DESC LIMIT 600',
        [$first, $next]);
    $best = ['term' => '', 'distance' => 99];
    foreach ($rows as $row) {
        $d = search_distance($word, (string) $row['term']);
        if ($d < $best['distance']) {
            $best = ['term' => (string) $row['term'], 'distance' => $d];
            if ($d === 1) {
                break;
            }
        }
    }
    return $best['distance'] <= $allowed ? $best : ['term' => '', 'distance' => 99];
}

/**
 * Concrete vocabulary terms for the query's words: exact, longer forms ("strike" -> "strikes"), joined
 * words and typo fixes. This is where all the "smart" decisions are made, before the index is read.
 * @param list<string> $stems
 * @return array{terms: list<string>, did: string, words: list<string>}
 */
function search_resolve(array $stems): array
{
    $terms = [];
    $did = [];
    $words = [];
    foreach ($stems as $stem) {
        if ($stem === '' || search_is_stop($stem)) {
            continue;
        }
        $words[] = $stem;
        // The word itself and every longer indexed word that starts with it: "missile" finds "missiles",
        // "strike" finds "strikes" and "striking", and a half-typed "ukr" finds "ukraine".
        $found = array_column(q_all('SELECT term FROM search_terms WHERE (term = ? OR term LIKE ?) ORDER BY docs DESC, LENGTH(term) LIMIT 20', [$stem, $stem . '%']), 'term');
        if ($found !== []) {
            array_push($terms, ...$found);
            continue;
        }
        $split = search_split_joined($stem);
        if ($split !== []) {
            $found = [];
            foreach ($split as $piece) {
                if (search_vocabulary_has($piece)) {
                    array_push($found, ...array_column(q_all('SELECT term FROM search_terms WHERE term LIKE ? AND LENGTH(term) <= ? LIMIT 20', [$piece . '%', strlen($piece) + 3]), 'term'));
                }
            }
            if ($found !== []) {
                array_push($terms, ...$found);
                $did[] = implode(' ', $split);
                continue;
            }
        }
        $fix = search_typo_fix($stem);
        if ($fix['term'] !== '') {
            array_push($terms, ...array_column(q_all('SELECT term FROM search_terms WHERE term LIKE ? AND LENGTH(term) <= ? LIMIT 20', [$fix['term'] . '%', strlen($fix['term']) + 3]), 'term'));
            $did[] = $fix['term'];
            continue;
        }
        // Unknown word: keep it (it may still be a prefix of an indexed word), no suggestion.
        $terms[] = $stem;
    }
    $terms = array_values(array_unique(array_filter($terms)));
    return ['terms' => $terms, 'did' => implode(' ', $did), 'words' => $words];
}

/* ---------- searching ---------- */

/**
 * Run a search.
 *
 * Options: category (slug, children included), from, to ('Y-m-d'), sort ('match' | 'new'), page, per, limit.
 * @param array<string, mixed> $o
 * @return array<string, mixed>
 */
function search_results(string $q, array $o = []): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    $per = max(5, min(30, (int) ($o['per'] ?? 10)));
    $page = max(1, (int) ($o['page'] ?? 1));
    $sort = ($o['sort'] ?? 'match') === 'new' ? 'new' : 'match';
    $stems = search_tokens($q);
    $resolved = search_resolve($stems);
    $terms = $resolved['terms'];
    $out = [
        'q' => $q, 'terms' => $terms, 'did' => '', 'rows' => [], 'total' => 0, 'page' => $page, 'per' => $per,
        'pages' => 1, 'sort' => $sort, 'category' => (string) ($o['category'] ?? ''), 'from' => (string) ($o['from'] ?? ''),
        'to' => (string) ($o['to'] ?? ''), 'took' => 0,
    ];
    if ($q === '' || $terms === []) {
        return $out;
    }
    // "Did you mean" only when a word was actually corrected.
    if ($resolved['did'] !== '' && $resolved['did'] !== implode(' ', $resolved['words'])) {
        $out['did'] = $resolved['did'];
    }
    $t0 = microtime(true);

    $ph = implode(',', array_fill(0, count($terms), '?'));
    $where = "p.status = 'published' AND p.published_at <= ?";
    $params = [now_utc()];
    $cat = trim((string) ($o['category'] ?? ''));
    if ($cat !== '') {
        $ids = category_ids_with_children([$cat]);
        if ($ids === []) {
            $out['took'] = (int) round((microtime(true) - $t0) * 1000);
            return $out;
        }
        [$catSql, $catParams] = category_match_sql($ids); // main or extra category
        $where .= ' AND ' . $catSql;
        array_push($params, ...$catParams);
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
        $raw = trim((string) ($o[$key] ?? ''));
        if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            continue;
        }
        $where .= ' AND p.published_at ' . $op . ' ?';
        $params[] = $raw . ($op === '>=' ? ' 00:00:00' : ' 23:59:59');
    }

    $total = (int) (q_one('SELECT COUNT(DISTINCT st.post_id) AS n FROM search_post_terms st JOIN posts p ON p.id = st.post_id
                            WHERE st.term IN (' . $ph . ') AND ' . $where, array_merge($terms, $params))['n'] ?? 0);
    $out['total'] = $total;
    $out['pages'] = max(1, (int) ceil($total / $per));
    if ($total === 0) {
        $out['took'] = (int) round((microtime(true) - $t0) * 1000);
        return $out;
    }

    // Take the best scoring stories (bounded: ranking is finished in PHP with phrase and date bonuses).
    $candidates = q_all('SELECT st.post_id, SUM(st.weight) AS score FROM search_post_terms st JOIN posts p ON p.id = st.post_id
                          WHERE st.term IN (' . $ph . ') AND ' . $where . '
                       GROUP BY st.post_id ORDER BY score DESC LIMIT 300', array_merge($terms, $params));
    $score = [];
    foreach ($candidates as $row) {
        $score[(int) $row['post_id']] = (int) $row['score'];
    }
    $rows = q_all(posts_select() . ' WHERE p.id IN (' . implode(',', array_map('intval', array_keys($score))) . ')', []);
    $phrase = mb_strtolower(implode(' ', $resolved['words']), 'UTF-8');
    $now = time();
    foreach ($rows as &$row) {
        $id = (int) $row['id'];
        $s = $score[$id] ?? 0;
        // Whole phrase in the title / body is a much better match than the words scattered around.
        $title = mb_strtolower((string) $row['title'], 'UTF-8');
        $body = mb_strtolower((string) $row['excerpt'] . ' ' . (string) $row['summary'] . ' ' . search_plain((string) $row['body']), 'UTF-8');
        if ($phrase !== '' && str_contains($title, $phrase)) {
            $s += 30;
        } elseif ($phrase !== '' && str_contains($body, $phrase)) {
            $s += 10;
        }
        // Newer stories a little higher (the design rules ask for it), but never over a title match.
        $days = max(0, (int) floor(($now - strtotime((string) $row['published_at'] . ' UTC')) / 86400));
        $s += max(0, 6 - intdiv($days, 15));
        $row['score'] = $s;
        $row['snippet'] = search_snippet((string) ($row['excerpt'] ?? ''), $row['body'] ?? '', $resolved['words']);
        // The headline gets the same highlights (escaped, only our <mark> tags), so a story found by a word in its
        // title shows why it was found even when the snippet does not contain the word.
        $row['title_html'] = search_highlight((string) $row['title'], $resolved['words']);
    }
    unset($row);
    usort($rows, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'new') {
            return strcmp((string) $b['published_at'], (string) $a['published_at']) ?: ($b['score'] <=> $a['score']);
        }
        return ($b['score'] <=> $a['score']) ?: strcmp((string) $b['published_at'], (string) $a['published_at']);
    });
    $out['rows'] = array_slice($rows, ($page - 1) * $per, $per);
    $out['took'] = (int) round((microtime(true) - $t0) * 1000);
    return $out;
}

/**
 * ~200 characters around the first matched word, with every matched word wrapped in <mark>.
 * Everything is escaped; only the <mark> tags are ours.
 */
function search_snippet(string $excerpt, string $bodyHtml, array $words, int $length = 200): string
{
    $text = $excerpt !== '' ? $excerpt : search_plain($bodyHtml);
    if ($text === '') {
        return '';
    }
    $source = $excerpt !== '' ? $excerpt . ' ' . search_plain($bodyHtml) : $text;
    $at = search_first_match($source, $words);
    if ($at >= 0) { // 0 = the very first word matches
        $start = max(0, $at - (int) ($length / 3));
        $text = mb_substr($source, $start, $length, 'UTF-8');
        if ($start > 0) {
            $text = '… ' . ltrim($text);
        }
        if (mb_strlen($source, 'UTF-8') > $start + $length) {
            $text = rtrim($text) . ' …';
        }
    } else {
        $text = mb_substr($text, 0, $length, 'UTF-8') . (mb_strlen($text, 'UTF-8') > $length ? ' …' : '');
    }
    return search_highlight($text, $words);
}

/** Position (in characters) of the first word that matches any of the words/stems, or -1. */
function search_first_match(string $text, array $words): int
{
    $best = -1;
    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }
        if (preg_match('/\b' . preg_quote($word, '/') . '/iu', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = mb_strlen(substr($text, 0, (int) $m[0][1]), 'UTF-8');
            if ($best < 0 || $at < $best) {
                $best = $at;
            }
        }
    }
    return $best;
}

/**
 * Escape a text and wrap the matched words in <mark>. Matched words are a word that starts with one of the
 * query stems, so "strike" highlights "strikes" and "striking".
 */
function search_highlight(string $text, array $words): string
{
    $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
    if ($words === []) {
        return e($text);
    }
    // The whole word is captured (stem + the rest of the word): preg_split keeps only what the group captured.
    $pattern = '/\b((?:' . implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . ')[\p{L}\p{N}]*)/iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return e($text);
    }
    $out = '';
    foreach ($parts as $i => $part) {
        $out .= $i % 2 === 1 ? '<mark>' . e($part) . '</mark>' : e($part);
    }
    return $out;
}

/**
 * "Suggestions while typing": vocabulary completions plus the best matching headlines.
 * @return array{terms: list<string>, stories: list<array{title: string, url: string}>}
 */
function search_suggest(string $q, int $limit = 6): array
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    $out = ['terms' => [], 'stories' => []];
    if (mb_strlen($q, 'UTF-8') < 2) {
        return $out;
    }
    $last = '';
    $parts = explode(' ', search_normalize($q));
    $last = (string) end($parts);
    if ($last !== '' && strlen($last) >= 2) {
        $terms = array_column(q_all('SELECT term FROM search_terms WHERE term LIKE ? ORDER BY docs DESC, term LIMIT ' . max(1, min(20, $limit)), [$last . '%']), 'term');
        $out['terms'] = array_map('strval', $terms);
    }
    $res = search_results($q, ['per' => $limit]);
    foreach ($res['rows'] as $row) {
        $out['stories'][] = ['title' => (string) $row['title'], 'url' => post_url($row)];
    }
    return $out;
}

/**
 * The suggestions endpoint, rate limited per address (a small file in storage/cache/ratelimit), so typing
 * in the search box can never be used to hammer the server. No third-party code, no cookies.
 * @return array{terms: list<string>, stories: list<array{title: string, url: string}>, limited?: bool}
 */
function search_suggest_limited(string $q): array
{
    $q = mb_substr(trim($q), 0, 60, 'UTF-8');
    if (mb_strlen($q, 'UTF-8') < 2) {
        return ['terms' => [], 'stories' => []];
    }
    $dir = PUBLIC_DIR . '/storage/cache/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/suggest-' . sha1(client_ip()) . '.txt';
    $window = 60;
    $limit = max(1, (int) (config()['suggest_limit'] ?? 40)); // per address per minute (app/config.php)
    $now = time();
    $count = 0;
    $start = $now;
    $raw = @file_get_contents($file);
    if (is_string($raw) && $raw !== '') {
        $bits = explode('|', $raw);
        $start = (int) $bits[0]; // explode() always gives at least one part
        $count = (int) ($bits[1] ?? 0);
        if ($start + $window < $now) {
            $start = $now;
            $count = 0;
        }
    }
    if ($count >= $limit) {
        return ['terms' => [], 'stories' => [], 'limited' => true];
    }
    @file_put_contents($file, $start . '|' . ($count + 1));
    return search_suggest($q);
}

/**
 * Trending tags (task F11): the tags most used by recent public stories.
 * @return list<array{name: string, slug: string, n: int}>
 */function trending_tags(int $limit = 8, int $days = 14): array
{
    if (setting('trending_tags', '1') !== '1') {
        return [];
    }
    $limit = max(1, min(20, $limit));
    $days = max(1, min(365, $days));
    $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $rows = q_all("SELECT t.name, t.slug, COUNT(*) AS n
                     FROM post_tags pt
                     JOIN tags t ON t.id = pt.tag_id
                     JOIN posts p ON p.id = pt.post_id
                    WHERE p.status = 'published' AND p.published_at <= ? AND p.published_at >= ?
                 GROUP BY t.id, t.name, t.slug
                 ORDER BY n DESC, t.name ASC
                    LIMIT " . $limit, [now_utc(), $since]);
    return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'n' => (int) $r['n']], $rows);
}
