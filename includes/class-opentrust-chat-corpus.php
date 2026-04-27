<?php
/**
 * Corpus builder for the agentic chat engine.
 *
 * Turns published OpenTrust CPT content into two parallel projections:
 *
 *   - `documents` — full per-document records the AI fetches via the
 *     `get_document(id)` tool. Long-form policies are truncated to a 30K-token
 *     cap so a single tool call cannot blow past Tier 1's input-tokens-per-
 *     minute ceiling.
 *
 *   - `index` — slim TOC of every document (id, title, type, version, URL,
 *     summary). Embedded in the cached system prompt so the AI sees the
 *     entire corpus catalog without ever receiving its content. The model
 *     uses the index to decide which documents to fetch.
 *
 * Both projections share a single locale-aware transient cache invalidated on
 * any CPT save/delete/trash/status transition or settings update. The
 * inverted BM25 index used by `search_documents(query)` is built once at
 * cache-build time and persisted alongside the corpus.
 *
 * Reads only published posts via OpenTrust_Repository — the same read-side
 * surface Render uses, so any cached fetch hit there is shared here.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Corpus {

    public const TRANSIENT_KEY     = 'opentrust_chat_corpus';
    public const TTL               = 12 * HOUR_IN_SECONDS;

    /**
     * Sanity ceiling on the index size only. Per-document content is bounded
     * by DOC_TOKEN_HARD_CAP independently. At ~80 chars per index line a
     * 200K-token cap accommodates ~10,000 documents — far beyond any realistic
     * trust-center scale. We never expect to hit this in practice; it exists
     * so a runaway query can't OOM the prompt.
     */
    public const MAX_INDEX_TOKENS  = 200_000;

    /**
     * Hard cap on a single document's content when returned by `get_document`.
     * Sized to leave headroom under Tier 1's 30K input-tokens-per-minute cap
     * even when combined with the cached system prompt + tools. Larger
     * documents are truncated with a tail pointing to the full URL.
     */
    private const DOC_TOKEN_HARD_CAP = 30_000;

    /**
     * Threshold for surfacing a "this policy is approaching the truncation
     * limit" admin warning. Smaller than the hard cap so operators get a
     * heads-up before truncation actually starts happening.
     */
    public const DOC_TOKEN_WARN      = 25_000;

    /**
     * Postmeta key that stores the AI-generated 2–3 sentence policy summary.
     * Read here; written by OpenTrust_Chat_Summarizer. Defined in the corpus
     * class so this file works whether the summarizer class is loaded or not.
     */
    public const POLICY_SUMMARY_META = '_ot_policy_chat_summary';

    // ──────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────

    /**
     * Return the cached corpus for the given locale, or build it fresh.
     *
     * @return array{
     *     documents:    array<int, array{id:string,type:string,url:string,title:string,content:string,summary:string,metadata:array}>,
     *     index:        array<int, array{id:string,type:string,title:string,summary:string,url:string,category?:string,version?:int,effective?:string}>,
     *     urls:         array<int, string>,
     *     url_to_id:    array<string, string>,
     *     bm25:         array{tf:array,df:array,len:array,avgdl:float,N:int},
     *     index_tokens: int,
     *     doc_count:    int,
     *     built_at:     int
     * }
     */
    public static function get_or_build(?string $locale = null): array {
        $locale = self::normalize_locale($locale);
        $key    = self::transient_key($locale);

        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['documents'], $cached['index'], $cached['urls'], $cached['bm25'])) {
            return $cached;
        }
        $corpus = self::build($locale);
        set_transient($key, $corpus, self::TTL);
        return $corpus;
    }

    /**
     * Drop every cached locale variant. Wired to CPT save/delete/trash/status
     * transitions and to update_option_opentrust_settings.
     */
    public static function invalidate(): void {
        global $wpdb;

        // Locale-suffixed transients can't be enumerated up front because we
        // don't track which locales we've cached. Sweep the option table for
        // any transient that starts with our prefix. This is invalidation
        // glue — the cost is amortized to the rare moment a CPT is saved.
        $prefix = '_transient_' . self::TRANSIENT_KEY;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- prefix is a class constant, not user input.
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        ));

        foreach ((array) $names as $name) {
            // Strip the WP `_transient_` prefix to get the transient key.
            $transient = preg_replace('/^_transient_/', '', (string) $name, 1);
            if ($transient !== '') {
                delete_transient($transient);
            }
        }

        // Belt-and-braces: also delete the unsuffixed key in case any caller
        // ever wrote to it pre-locale-aware.
        delete_transient(self::TRANSIENT_KEY);
    }

    // ──────────────────────────────────────────────
    // Build
    // ──────────────────────────────────────────────

    /**
     * Build the corpus fresh from the database for the given locale.
     */
    public static function build(?string $locale = null): array {
        $locale   = self::normalize_locale($locale);
        $settings = OpenTrust::get_settings();
        $repo     = OpenTrust_Repository::instance();
        $visible  = $settings['sections_visible'] ?? [];

        $documents = [];

        // Certifications, subprocessors, data_practices — indexed only when
        // their section is visible on the public page. Mirrors the gating
        // gather_data() applied before this rewire: a section hidden from
        // visitors is also hidden from the AI.
        if (!empty($visible['certifications'])) {
            foreach ($repo->fetch_certifications() as $cert) {
                $documents[] = self::format_certification($cert, $settings);
            }
        }

        // Policies — always indexed, regardless of $visible['policies'].
        // This mirrors pre-rewire behavior: build() previously fetched
        // policy posts via its own get_posts() call (full post_content
        // is needed for the AI tool, not just gather_data's excerpt
        // projection), which sat outside the visibility gate. Documented
        // here as a latent asymmetry — possibly a bug — but preserved
        // bit-exactly. Repository::fetch_policy_posts() is the single
        // owner of that fetch now.
        foreach ($repo->fetch_policy_posts() as $post) {
            $documents[] = self::format_policy($post, $settings);
        }

        if (!empty($visible['subprocessors'])) {
            foreach ($repo->fetch_subprocessors() as $sub) {
                $documents[] = self::format_subprocessor($sub, $settings);
            }
        }

        if (!empty($visible['data_practices'])) {
            foreach ($repo->fetch_data_practices() as $dp) {
                $documents[] = self::format_data_practice($dp, $settings);
            }
        }

        // Contact (only present when the operator has populated at least one
        // contact field — see format_contact).
        $contact_doc = self::format_contact($settings);
        if ($contact_doc !== null) {
            $documents[] = $contact_doc;
        }

        // URL whitelist: every canonical doc URL plus the main page anchors
        // the model is allowed to cite.
        $urls = [];
        foreach ($documents as $doc) {
            if (!empty($doc['url'])) {
                $urls[] = $doc['url'];
            }
        }
        $base = home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/');
        $urls[] = $base;
        foreach (['ot-certifications', 'ot-policies', 'ot-subprocessors', 'ot-data-practices', 'ot-contact'] as $anchor) {
            $urls[] = $base . '#' . $anchor;
        }
        $urls = array_values(array_unique($urls));

        // Reverse map: URL → doc-id. Used by the Anthropic citation handler
        // so subprocessors that share an anchor URL keep distinct ids.
        $url_to_id = self::build_url_to_id_map($documents);

        // Slim TOC for the system prompt.
        $index = self::project_index($documents);

        // Inverted index for `search_documents`.
        $bm25 = OpenTrust_Chat_Search::build($documents);

        // Token estimate for the rendered index — fed into the budget reserver.
        $company       = (string) ($settings['company_name'] ?? get_bloginfo('name'));
        $index_chars   = strlen(self::format_index_for_prompt($index, $company));
        $index_tokens  = (int) ceil($index_chars / 4);

        return [
            'documents'    => $documents,
            'index'        => $index,
            'urls'         => $urls,
            'url_to_id'    => $url_to_id,
            'bm25'         => $bm25,
            'index_tokens' => $index_tokens,
            'doc_count'    => count($documents),
            'built_at'     => time(),
            'locale'       => $locale,
        ];
    }

    /**
     * URL whitelist for post-stream citation validation. Convenience wrapper
     * around get_or_build()['urls'].
     *
     * @return array<int, string>
     */
    public static function url_whitelist(?string $locale = null): array {
        return self::get_or_build($locale)['urls'] ?? [];
    }

    /**
     * Surface policies whose normalized content is approaching or past the
     * truncation cap. Used by the admin-side warning row.
     *
     * @return array<int, array{title:string, tokens:int, url:string}>
     */
    public static function oversized_policies(?string $locale = null): array {
        $corpus = self::get_or_build($locale);
        $out    = [];
        foreach (($corpus['documents'] ?? []) as $doc) {
            if (($doc['type'] ?? '') !== 'policy') {
                continue;
            }
            // Note: documents have already been truncated, so we measure
            // against the pre-truncation char count we stashed in metadata.
            $est = (int) ($doc['metadata']['raw_token_estimate'] ?? 0);
            if ($est >= self::DOC_TOKEN_WARN) {
                $out[] = [
                    'title'  => (string) ($doc['title'] ?? ''),
                    'tokens' => $est,
                    'url'    => (string) ($doc['url'] ?? ''),
                ];
            }
        }
        return $out;
    }

    // ──────────────────────────────────────────────
    // Index
    // ──────────────────────────────────────────────

    /**
     * Project the document array into its index (TOC) representation.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private static function project_index(array $documents): array {
        $out = [];
        foreach ($documents as $doc) {
            $entry = [
                'id'      => (string) ($doc['id']      ?? ''),
                'type'    => (string) ($doc['type']    ?? ''),
                'title'   => (string) ($doc['title']   ?? ''),
                'summary' => (string) ($doc['summary'] ?? ''),
                'url'     => (string) ($doc['url']     ?? ''),
            ];
            // Carry policy-specific metadata so the prompt can render category
            // / version / effective-date inline.
            if (($doc['type'] ?? '') === 'policy' && isset($doc['metadata']) && is_array($doc['metadata'])) {
                $entry['category']  = (string) ($doc['metadata']['category_label'] ?? $doc['metadata']['category'] ?? '');
                $entry['version']   = (int)    ($doc['metadata']['version']        ?? 0);
                $entry['effective'] = (string) ($doc['metadata']['effective']      ?? '');
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Render the index as the system-prompt block the model reads.
     *
     * Markdown-bullet shape, grouped by document type. ~80 chars per line for
     * a typical policy entry. Cached on the upstream side via the system-
     * prompt cache breakpoint.
     */
    public static function format_index_for_prompt(array $index, string $company): string {
        // Group by type, preserve insertion order within each group.
        $groups = [
            'policy'        => [],
            'subprocessor'  => [],
            'certification' => [],
            'data_practice' => [],
            'contact'       => [],
        ];
        foreach ($index as $row) {
            $type = (string) ($row['type'] ?? '');
            if (!isset($groups[$type])) {
                $groups[$type] = [];
            }
            $groups[$type][] = $row;
        }

        $count   = count($index);
        $company = $company !== '' ? $company : (string) get_bloginfo('name');

        $out   = [];
        $out[] = '=== Available trust center documents ===';
        $out[] = '';
        $out[] = sprintf(
            'You have access to the following %d documents about %s\'s security and compliance program. To read any document\'s full text, call get_document(id) with an id from this list. To search across all documents by keyword, call search_documents(query). Always cite the documents you use; the front end renders a Sources panel from your citations automatically — do NOT write inline "Source:" lines or markdown links pointing at trust-center pages.',
            $count,
            $company
        );

        $titles = [
            'policy'        => 'Policies',
            'subprocessor'  => 'Subprocessors',
            'certification' => 'Certifications',
            'data_practice' => 'Data Practices',
            'contact'       => 'Contact',
        ];

        foreach ($titles as $type => $heading) {
            if (empty($groups[$type])) {
                continue;
            }
            $out[] = '';
            $out[] = $heading . ':';
            foreach ($groups[$type] as $row) {
                $out[] = self::format_index_line($type, $row);
            }
        }

        return implode("\n", $out);
    }

    private static function format_index_line(string $type, array $row): string {
        $id      = (string) ($row['id']      ?? '');
        $title   = (string) ($row['title']   ?? '');
        $summary = trim((string) ($row['summary'] ?? ''));

        if ($type === 'policy') {
            $cat       = (string) ($row['category']  ?? '');
            $version   = (int)    ($row['version']   ?? 0);
            $effective = (string) ($row['effective'] ?? '');
            $bits      = [];
            if ($cat !== '')              { $bits[] = $cat; }
            if ($version > 0)             { $bits[] = 'v' . $version; }
            if ($effective !== '')        { $bits[] = 'eff ' . $effective; }
            $tail = !empty($bits) ? ' (' . implode(', ', $bits) . ')' : '';
            $line = sprintf('- %s — %s%s', $id, $title, $tail);
            if ($summary !== '') {
                // Soft-wrap the summary indented under the policy line.
                $line .= "\n  " . $summary;
            }
            return $line;
        }

        // Non-policy: single-line projection. The summary IS the descriptive
        // suffix; structured records (subprocessors, certs, data practices,
        // contact) are already short enough that a separate paragraph would
        // be wasteful.
        return $summary !== ''
            ? sprintf('- %s — %s — %s', $id, $title, $summary)
            : sprintf('- %s — %s', $id, $title);
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, string>
     */
    private static function build_url_to_id_map(array $documents): array {
        $map = [];
        foreach ($documents as $doc) {
            $url = (string) ($doc['url'] ?? '');
            $id  = (string) ($doc['id']  ?? '');
            if ($url === '' || $id === '') {
                continue;
            }
            // First-write-wins: subprocessors share an anchor URL, so we
            // arbitrarily map the URL to the first id we saw. The Anthropic
            // citation handler still distinguishes subprocessors via the
            // search_result_index that comes alongside each citation, but
            // this map is only used as a fallback when that's missing.
            if (!isset($map[$url])) {
                $map[$url] = $id;
            }
        }
        return $map;
    }

    // ──────────────────────────────────────────────
    // Locale + transient helpers
    // ──────────────────────────────────────────────

    private static function normalize_locale(?string $locale): string {
        if ($locale !== null && $locale !== '') {
            return sanitize_key($locale);
        }
        return sanitize_key((string) determine_locale());
    }

    private static function transient_key(string $locale): string {
        return self::TRANSIENT_KEY . '_' . $locale;
    }

    // ──────────────────────────────────────────────
    // Per-doc truncation + summary
    // ──────────────────────────────────────────────

    /**
     * Truncate $content to DOC_TOKEN_HARD_CAP tokens worth of characters.
     * The 4-chars-per-token heuristic is conservative for English and good
     * enough for sizing. If we truncate, append a tail pointing at the full
     * document URL so the AI can route the visitor there.
     */
    private static function maybe_truncate_content(string $content, string $url): array {
        $est = (int) ceil(strlen($content) / 4);
        if ($est <= self::DOC_TOKEN_HARD_CAP) {
            return ['content' => $content, 'truncated' => false, 'raw_token_estimate' => $est];
        }
        $cutoff = self::DOC_TOKEN_HARD_CAP * 4;
        $head   = substr($content, 0, $cutoff);
        // Trim back to the last sentence break we can find within the last
        // 1KB so we don't end mid-word. Falls through to the hard cut if
        // there's no sentence break in range.
        $tail_window = substr($head, -1024);
        $break_at    = max(strrpos($tail_window, '. '), strrpos($tail_window, ".\n"));
        if ($break_at !== false && $break_at > 0) {
            $head = substr($head, 0, $cutoff - (strlen($tail_window) - $break_at - 1));
        }
        $note = sprintf(
            "\n\n[…truncated for length. The full text is published at %s]",
            $url
        );
        return [
            'content'            => rtrim($head) . $note,
            'truncated'          => true,
            'raw_token_estimate' => $est,
        ];
    }

    /**
     * Resolve the index summary for a policy. Fallback ladder:
     *   1. _ot_policy_chat_summary postmeta if non-empty.
     *   2. post_excerpt (stripped + collapsed) if non-empty.
     *   3. First 240 chars of the stripped post_content with ellipsis.
     */
    private static function policy_summary(\WP_Post $post, string $stripped_content): string {
        $manual = trim((string) get_post_meta($post->ID, self::POLICY_SUMMARY_META, true));
        if ($manual !== '') {
            return self::collapse_whitespace($manual);
        }
        // Prefer the raw column over get_the_excerpt() to avoid running the
        // post through filters that may inject "Read more" tails or strip the
        // text we'd want to summarize.
        $excerpt = trim((string) $post->post_excerpt);
        if ($excerpt !== '') {
            return self::collapse_whitespace($excerpt);
        }
        if ($stripped_content === '') {
            return '';
        }
        $clean = self::collapse_whitespace($stripped_content);
        if (function_exists('mb_strlen') && mb_strlen($clean) > 240) {
            return rtrim(mb_substr($clean, 0, 240)) . '…';
        }
        if (strlen($clean) > 240) {
            return rtrim(substr($clean, 0, 240)) . '…';
        }
        return $clean;
    }

    private static function collapse_whitespace(string $s): string {
        return (string) preg_replace('/\s+/', ' ', trim($s));
    }

    // ──────────────────────────────────────────────
    // Formatters — one per content type
    // ──────────────────────────────────────────────

    private static function format_policy(\WP_Post $post, array $settings): array {
        $endpoint = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $url      = home_url('/' . $endpoint . '/policy/' . $post->post_name . '/');

        $category_labels = OpenTrust_Render::policy_category_labels();
        $category        = (string) (get_post_meta($post->ID, '_ot_policy_category', true) ?: 'other');
        $category_label  = $category_labels[$category] ?? $category;
        $effective       = (string) get_post_meta($post->ID, '_ot_policy_effective_date', true);
        $version         = (int) (get_post_meta($post->ID, '_ot_version', true) ?: 1);

        // Strip block-editor markup down to plain text.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
        $rendered = apply_filters('the_content', $post->post_content);
        $plain    = wp_strip_all_tags($rendered);
        $plain    = (string) preg_replace('/\s+/', ' ', trim($plain));
        $plain    = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $header = sprintf(
            "Category: %s\nVersion: %d%s\n\n",
            $category_label,
            $version,
            $effective !== '' ? "\nEffective: " . $effective : ''
        );

        $full_content = $header . $plain;
        $truncation   = self::maybe_truncate_content($full_content, $url);

        return [
            'id'       => 'policy-' . $post->post_name,
            'type'     => 'policy',
            'url'      => $url,
            'title'    => $post->post_title,
            'content'  => $truncation['content'],
            'summary'  => self::policy_summary($post, $plain),
            'metadata' => [
                'slug'               => $post->post_name,
                'category'           => $category,
                'category_label'     => $category_label,
                'version'            => $version,
                'effective'          => $effective,
                'updated'            => $post->post_modified,
                'truncated'          => $truncation['truncated'],
                'raw_token_estimate' => $truncation['raw_token_estimate'],
            ],
        ];
    }

    private static function format_certification(array $cert, array $settings): array {
        $endpoint = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $url      = home_url('/' . $endpoint . '/#ot-certifications');

        $status_labels = ($cert['type'] ?? 'certified') === 'compliant'
            ? OpenTrust_Render::cert_aligned_status_labels()
            : OpenTrust_Render::cert_status_labels();
        $status_label  = $status_labels[$cert['status']] ?? $cert['status'];

        $lines = [
            'Title: ' . ($cert['title'] ?? ''),
            'Status: ' . $status_label,
        ];
        if (!empty($cert['issuing_body'])) {
            $lines[] = 'Issuing body: ' . $cert['issuing_body'];
        }
        if (!empty($cert['issue_date'])) {
            $lines[] = 'Valid from: ' . $cert['issue_date'];
        }
        if (!empty($cert['expiry_date'])) {
            $lines[] = 'Valid until: ' . $cert['expiry_date'];
        }
        if (!empty($cert['description'])) {
            $lines[] = '';
            $lines[] = 'Description: ' . wp_strip_all_tags((string) $cert['description']);
        }

        // Index summary: status + validity window. Keep it short — the model
        // can fetch the full record via get_document if it needs the body.
        $summary_bits = [$status_label];
        if (!empty($cert['expiry_date'])) {
            $summary_bits[] = 'valid until ' . $cert['expiry_date'];
        } elseif (!empty($cert['issuing_body'])) {
            $summary_bits[] = $cert['issuing_body'];
        }

        return [
            'id'       => 'cert-' . sanitize_title((string) ($cert['title'] ?? '')),
            'type'     => 'certification',
            'url'      => $url,
            'title'    => (string) ($cert['title'] ?? ''),
            'content'  => implode("\n", $lines),
            'summary'  => implode(', ', array_filter($summary_bits)),
            'metadata' => [
                'status'       => $cert['status']       ?? '',
                'issuing_body' => $cert['issuing_body'] ?? '',
                'expiry_date'  => $cert['expiry_date']  ?? '',
            ],
        ];
    }

    private static function format_subprocessor(array $sub, array $settings): array {
        $endpoint = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $url      = home_url('/' . $endpoint . '/#ot-subprocessors');

        $lines = ['Name: ' . ($sub['name'] ?? '')];
        if (!empty($sub['purpose']))        { $lines[] = 'Purpose: '         . $sub['purpose']; }
        if (!empty($sub['data_processed'])) { $lines[] = 'Data processed: '  . $sub['data_processed']; }
        if (!empty($sub['country']))        { $lines[] = 'Country: '         . $sub['country']; }
        if (!empty($sub['website']))        { $lines[] = 'Website: '         . $sub['website']; }
        $lines[] = 'DPA signed: ' . (!empty($sub['dpa_signed']) ? 'Yes' : 'No');

        // Index summary: country + DPA status + purpose snippet.
        $summary_bits = [];
        if (!empty($sub['country']))    { $summary_bits[] = (string) $sub['country']; }
        $summary_bits[] = !empty($sub['dpa_signed']) ? 'DPA signed' : 'no DPA';
        $purpose = trim((string) ($sub['purpose'] ?? ''));
        if ($purpose !== '') {
            // First clause of the purpose only.
            $first = preg_split('/[.;]/', $purpose, 2)[0] ?? $purpose;
            $summary_bits[] = self::collapse_whitespace((string) $first);
        }

        return [
            'id'       => 'sub-' . sanitize_title((string) ($sub['name'] ?? '')),
            'type'     => 'subprocessor',
            'url'      => $url,
            'title'    => (string) ($sub['name'] ?? ''),
            'content'  => implode("\n", $lines),
            'summary'  => implode(', ', array_filter($summary_bits)),
            'metadata' => [
                'country'    => $sub['country'] ?? '',
                'dpa_signed' => !empty($sub['dpa_signed']),
            ],
        ];
    }

    private static function format_data_practice(array $dp, array $settings): array {
        $endpoint = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $url      = home_url('/' . $endpoint . '/#ot-data-practices');

        $legal_labels = OpenTrust_Render::legal_basis_labels();

        $lines = ['Category: ' . (string) ($dp['title'] ?? '')];
        if (!empty($dp['data_items']) && is_array($dp['data_items'])) {
            $rendered_items = [];
            foreach ($dp['data_items'] as $item) {
                if (is_array($item)) {
                    $name = (string) ($item['name'] ?? '');
                    $desc = (string) ($item['description'] ?? '');
                    if ($name === '' && $desc === '') {
                        continue;
                    }
                    $rendered_items[] = $desc !== '' ? "{$name} ({$desc})" : $name;
                } else {
                    $rendered_items[] = (string) $item;
                }
            }
            if (!empty($rendered_items)) {
                $lines[] = 'Data items: ' . implode(', ', $rendered_items);
            }
        }
        if (!empty($dp['purpose'])) {
            $lines[] = 'Purpose: ' . (string) $dp['purpose'];
        }
        if (!empty($dp['legal_basis'])) {
            $label = $legal_labels[$dp['legal_basis']] ?? $dp['legal_basis'];
            $lines[] = 'Legal basis: ' . (string) $label;
        }
        if (!empty($dp['retention_period'])) {
            $lines[] = 'Retention: ' . (string) $dp['retention_period'];
        }
        if (!empty($dp['shared_with']) && is_array($dp['shared_with'])) {
            $rendered_shared = [];
            foreach ($dp['shared_with'] as $item) {
                if (is_array($item)) {
                    $name    = (string) ($item['name']    ?? '');
                    $purpose = (string) ($item['purpose'] ?? '');
                    if ($name === '' && $purpose === '') {
                        continue;
                    }
                    $rendered_shared[] = $purpose !== '' ? "{$name} ({$purpose})" : $name;
                } else {
                    $rendered_shared[] = (string) $item;
                }
            }
            if (!empty($rendered_shared)) {
                $lines[] = 'Shared with: ' . implode(', ', $rendered_shared);
            }
        }

        // Yes/No flags. Always emitted so the model can answer direct procurement
        // questions like "Do you sell customer data?" with a cited factual reply.
        $yn = static fn(mixed $v): string => !empty($v) ? 'Yes' : 'No';
        $lines[] = 'Data collected: '            . $yn($dp['prop_collected'] ?? false);
        $lines[] = 'Data stored: '               . $yn($dp['prop_stored']    ?? false);
        $lines[] = 'Shared with third parties: ' . $yn($dp['prop_shared']    ?? false);
        $lines[] = 'Sold to third parties: '     . $yn($dp['prop_sold']      ?? false);
        $lines[] = 'Encrypted: '                 . $yn($dp['prop_encrypted'] ?? false);

        // Index summary: pluck a few of the most commonly-asked flags.
        $summary_bits = [];
        $items_count = !empty($dp['data_items']) && is_array($dp['data_items']) ? count($dp['data_items']) : 0;
        if ($items_count > 0) {
            $summary_bits[] = $items_count === 1 ? '1 item' : $items_count . ' items';
        }
        if (!empty($dp['legal_basis'])) {
            $summary_bits[] = (string) ($legal_labels[$dp['legal_basis']] ?? $dp['legal_basis']);
        }
        $summary_bits[] = !empty($dp['prop_sold'])      ? 'sold'             : 'not sold';
        $summary_bits[] = !empty($dp['prop_encrypted']) ? 'encrypted'        : 'not encrypted';

        return [
            'id'       => 'dp-' . sanitize_title((string) ($dp['title'] ?? '')),
            'type'     => 'data_practice',
            'url'      => $url,
            'title'    => (string) ($dp['title'] ?? ''),
            'content'  => implode("\n", $lines),
            'summary'  => implode(', ', array_filter($summary_bits)),
            'metadata' => [
                'legal_basis' => $dp['legal_basis'] ?? '',
                'sold'        => !empty($dp['prop_sold']),
                'shared'      => !empty($dp['prop_shared']),
                'encrypted'   => !empty($dp['prop_encrypted']),
            ],
        ];
    }

    /**
     * Synthetic doc for the Get-in-touch settings block. Only emitted when at
     * least one contact field is populated — otherwise the model would cite a
     * hollow "Contact Information" record.
     */
    private static function format_contact(array $settings): ?array {
        $fields = [
            'company_description'  => 'About',
            'dpo_name'             => 'Data Protection Officer',
            'dpo_email'            => 'DPO email',
            'security_email'       => 'Security contact email',
            'contact_form_url'     => 'Contact form',
            'contact_address'      => 'Mailing address',
            'pgp_key_url'          => 'PGP public key',
            'company_registration' => 'Company registration number',
            'vat_number'           => 'VAT / Tax ID',
        ];

        $lines = [];
        $summary_bits = [];
        foreach ($fields as $key => $label) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = (string) preg_replace('/\s+/', ' ', $value);
            $lines[] = $label . ': ' . $value;
            // Summary mentions which kinds of contact are available, not the values.
            if (in_array($key, ['dpo_email', 'security_email', 'contact_form_url'], true)) {
                $summary_bits[] = strtolower($label);
            }
        }
        if (empty($lines)) {
            return null;
        }

        $endpoint = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $url      = home_url('/' . $endpoint . '/#ot-contact');

        return [
            'id'       => 'contact',
            'type'     => 'contact',
            'url'      => $url,
            'title'    => 'Contact Information',
            'content'  => implode("\n", $lines),
            'summary'  => !empty($summary_bits) ? 'available: ' . implode(', ', $summary_bits) : 'contact details available',
            'metadata' => [
                'has_dpo'      => !empty($settings['dpo_email']),
                'has_security' => !empty($settings['security_email']),
                'has_form'     => !empty($settings['contact_form_url']),
            ],
        ];
    }
}
