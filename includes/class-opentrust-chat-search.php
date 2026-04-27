<?php
/**
 * BM25 keyword search over the cached chat corpus.
 *
 * The agentic chat engine exposes a `search_documents(query)` tool that the
 * model uses when no document id obviously matches a question. This class
 * powers that tool: a small inverted index built once when the corpus is
 * (re)built, persisted alongside `documents` in the corpus transient.
 *
 * Algorithm: textbook Okapi BM25 (k1 = 1.5, b = 0.75) over a tiny tokenizer
 * — lowercase, strip punctuation, drop English stop-words and 1-character
 * tokens, truncate >6-char tokens to a 6-char prefix as a poor man's stem.
 * That stem absorbs trivial suffix variation ("retention" / "retained" /
 * "retains" all collapse to "retent") without pulling in a language-specific
 * stemmer.
 *
 * For corpora up to ~2,000 documents the index serializes to under ~5MB and
 * a single search runs in single-digit milliseconds. See
 * research/keyword-search-bm25.md for the rejection list of heavier options
 * (MySQL FULLTEXT, embeddings, vendored libs).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Search {

    /**
     * English stop-words. Trust-center content is overwhelmingly English even
     * on multilingual installs (compliance lingo doesn't translate cleanly),
     * so a single static list is enough. Tokens of <2 chars are also dropped
     * by tokenize() — that catches articles like "a" without listing them.
     */
    private const STOP = [
        'a','an','and','are','as','at','be','by','for','from','has','have',
        'in','is','it','of','on','or','our','that','the','this','to','we','with','your',
    ];

    private const K1 = 1.5;
    private const B  = 0.75;

    /**
     * Build a BM25 inverted index over the supplied documents.
     *
     * Returned shape is an opaque value object — the caller persists it in
     * the corpus transient and passes it back to search() unchanged.
     *
     * @param array<int, array{id:string,title:string,content:string}> $documents
     * @return array{
     *     tf: array<string, array<int,int>>,
     *     df: array<string, int>,
     *     len: array<int, int>,
     *     avgdl: float,
     *     N: int
     * }
     */
    public static function build(array $documents): array {
        $tf    = [];   // term -> [doc_idx => freq]
        $df    = [];   // term -> document frequency
        $len   = [];   // doc_idx -> tokenized length
        $N     = count($documents);
        $total = 0;

        foreach ($documents as $i => $doc) {
            $text = (string) ($doc['title'] ?? '') . ' ' . (string) ($doc['content'] ?? '');
            $tokens = self::tokenize($text);
            $len[$i] = count($tokens);
            $total  += $len[$i];

            $counts = array_count_values($tokens);
            foreach ($counts as $term => $c) {
                $tf[$term][$i] = $c;
                $df[$term]     = ($df[$term] ?? 0) + 1;
            }
        }

        return [
            'tf'    => $tf,
            'df'    => $df,
            'len'   => $len,
            'avgdl' => $N > 0 ? $total / $N : 0.0,
            'N'     => $N,
        ];
    }

    /**
     * Score a query against an index and return the top $limit document
     * indices, highest-scoring first. Returns indices into the original
     * $documents array passed to build().
     *
     * @param array $idx   Index produced by build().
     * @param int   $limit Max results (1–10 in practice).
     * @return array<int, int>
     */
    public static function search(array $idx, string $query, int $limit = 5): array {
        if (empty($idx['tf']) || $idx['N'] === 0) {
            return [];
        }
        $limit  = max(1, $limit);
        $scores = [];
        $avgdl  = max(1.0, (float) $idx['avgdl']);
        $N      = (int) $idx['N'];

        foreach (self::tokenize($query) as $term) {
            if (!isset($idx['tf'][$term])) {
                continue;
            }
            $df  = (int) $idx['df'][$term];
            // BM25 IDF with the +1 smoothing variant — keeps IDF non-negative
            // when a term appears in more than half the corpus.
            $idf = log(1 + ($N - $df + 0.5) / ($df + 0.5));
            foreach ($idx['tf'][$term] as $i => $f) {
                $doc_len = (int) ($idx['len'][$i] ?? 0);
                $norm    = 1 - self::B + self::B * ($doc_len / $avgdl);
                $score   = $idf * (($f * (self::K1 + 1)) / ($f + self::K1 * $norm));
                $scores[$i] = ($scores[$i] ?? 0) + $score;
            }
        }

        if (empty($scores)) {
            return [];
        }

        arsort($scores);
        return array_slice(array_keys($scores), 0, $limit, false);
    }

    /**
     * Lowercase, strip non-letter/digit characters (Unicode-aware), drop
     * stop-words and 1-character tokens, truncate long tokens to a 6-char
     * prefix as a cheap stem.
     *
     * @return array<int, string>
     */
    private static function tokenize(string $text): array {
        $text = strtolower($text);
        // Replace any run of non-letter / non-digit chars with a single space.
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        $tokens = [];
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $t) {
            if ($t === '' || strlen($t) < 2) {
                continue;
            }
            if (in_array($t, self::STOP, true)) {
                continue;
            }
            $tokens[] = strlen($t) > 6 ? substr($t, 0, 6) : $t;
        }
        return $tokens;
    }
}
