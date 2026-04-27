<?php
/**
 * Read-side data layer for the trust center.
 *
 * Single source of truth for "what published trust-center items exist." Owns
 * all DB fetching for the five OpenTrust CPTs and the per-CPT projection
 * shape consumed by Render (templates) and Chat_Corpus (AI corpus index).
 *
 * Returns ALL published items unconditionally — no visibility filtering, no
 * "section is empty" gating. Consumers apply the `sections_visible` settings
 * filter at their own layer:
 *
 *   - Render::gather_data() honors sections_visible for template output
 *     (hidden section = empty array → template renders nothing).
 *   - Chat_Corpus::build() honors the same setting so a section hidden on
 *     the public page is also hidden from the AI's corpus index.
 *
 * Caching: each fetcher is memoized in a locale-scoped transient bumped by
 * opentrust_cache_version. Returns are plain projected arrays so the cached
 * shape is identical to what Render's per-CPT getters previously emitted —
 * no behavior change at consumer sites.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Repository {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    // ──────────────────────────────────────────────
    // Per-CPT fetchers (projected arrays)
    // ──────────────────────────────────────────────

    /**
     * @return array<int, array{id:int,title:string,type:string,issuing_body:string,status:string,issue_date:string,expiry_date:string,badge_url:string,description:string,artifact_url:string}>
     */
    public function fetch_certifications(): array {
        return $this->cached_query(
            'certifications',
            [
                'post_type'      => 'ot_certification',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            ],
            static function (WP_Post $post): array {
                $badge_id    = (int) get_post_meta($post->ID, '_ot_cert_badge_id', true);
                $artifact_id = (int) get_post_meta($post->ID, '_ot_cert_artifact_id', true);
                return [
                    'id'           => $post->ID,
                    'title'        => $post->post_title,
                    'type'         => get_post_meta($post->ID, '_ot_cert_type', true) ?: 'compliant',
                    'issuing_body' => get_post_meta($post->ID, '_ot_cert_issuing_body', true) ?: '',
                    'status'       => get_post_meta($post->ID, '_ot_cert_status', true) ?: 'active',
                    'issue_date'   => get_post_meta($post->ID, '_ot_cert_issue_date', true) ?: '',
                    'expiry_date'  => get_post_meta($post->ID, '_ot_cert_expiry_date', true) ?: '',
                    'badge_url'    => $badge_id ? (wp_get_attachment_image_url($badge_id, 'thumbnail') ?: '') : '',
                    'description'  => get_post_meta($post->ID, '_ot_cert_description', true) ?: '',
                    'artifact_url' => $artifact_id ? (wp_get_attachment_url($artifact_id) ?: '') : '',
                ];
            }
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch_policies(): array {
        return $this->cached_query(
            'policies',
            [
                'post_type'      => 'ot_policy',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'meta_value_num title',
                'meta_key'       => '_ot_policy_sort_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Transient-cached; <100 posts
                'order'          => 'ASC',
            ],
            function (WP_Post $post): array {
                $eff        = get_post_meta($post->ID, '_ot_policy_effective_date', true) ?: '';
                $attachment = $this->resolve_policy_attachment($post->ID);
                return [
                    'id'             => $post->ID,
                    'title'          => $post->post_title,
                    'slug'           => $post->post_name,
                    'excerpt'        => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
                    'version'        => (int) get_post_meta($post->ID, '_ot_version', true) ?: 1,
                    'ref_id'         => (string) (get_post_meta($post->ID, '_ot_policy_ref_id', true) ?: ''),
                    'category'       => get_post_meta($post->ID, '_ot_policy_category', true) ?: 'other',
                    'citations'      => $this->normalize_citations(get_post_meta($post->ID, '_ot_policy_citations', true)),
                    'effective_date' => $eff,
                    'review_date'    => get_post_meta($post->ID, '_ot_policy_review_date', true) ?: '',
                    'attachment'     => $attachment,
                    'last_modified'  => $post->post_modified,
                    'is_pending'     => $eff && strtotime($eff) > time(),
                ];
            }
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch_subprocessors(): array {
        return $this->cached_query(
            'subprocessors',
            [
                'post_type'      => 'ot_subprocessor',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
            ],
            static fn(WP_Post $post): array => [
                'id'             => $post->ID,
                'name'           => $post->post_title,
                'purpose'        => get_post_meta($post->ID, '_ot_sub_purpose', true) ?: '',
                'data_processed' => get_post_meta($post->ID, '_ot_sub_data_processed', true) ?: '',
                'country'        => get_post_meta($post->ID, '_ot_sub_country', true) ?: '',
                'website'        => get_post_meta($post->ID, '_ot_sub_website', true) ?: '',
                'dpa_signed'     => (bool) get_post_meta($post->ID, '_ot_sub_dpa_signed', true),
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch_data_practices(): array {
        return $this->cached_query(
            'data_practices',
            [
                'post_type'      => 'ot_data_practice',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'meta_value_num title',
                'meta_key'       => '_ot_dp_sort_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Transient-cached; <100 posts
                'order'          => 'ASC',
            ],
            static function (WP_Post $post): array {
                $data_items = get_post_meta($post->ID, '_ot_dp_data_items', true);
                $shared     = get_post_meta($post->ID, '_ot_dp_shared_with', true);
                return [
                    'id'               => $post->ID,
                    'title'            => $post->post_title,
                    'data_items'       => is_array($data_items) ? $data_items : [],
                    'purpose'          => get_post_meta($post->ID, '_ot_dp_purpose', true) ?: '',
                    'legal_basis'      => get_post_meta($post->ID, '_ot_dp_legal_basis', true) ?: '',
                    'retention_period' => get_post_meta($post->ID, '_ot_dp_retention_period', true) ?: '',
                    'shared_with'      => is_array($shared) ? $shared : [],
                    'prop_collected'   => (bool) get_post_meta($post->ID, '_ot_dp_collected', true),
                    'prop_stored'      => (bool) get_post_meta($post->ID, '_ot_dp_stored', true),
                    'prop_shared'      => (bool) get_post_meta($post->ID, '_ot_dp_shared', true),
                    'prop_sold'        => (bool) get_post_meta($post->ID, '_ot_dp_sold', true),
                    'prop_encrypted'   => (bool) get_post_meta($post->ID, '_ot_dp_encrypted', true),
                ];
            }
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch_faqs(): array {
        $endpoint = OpenTrust::get_settings()['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;

        return $this->cached_query(
            'faqs',
            [
                'post_type'      => 'ot_faq',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
            ],
            static function (WP_Post $post) use ($endpoint): array {
                $related_id    = (int) get_post_meta($post->ID, '_ot_faq_related_policy', true);
                $related_url   = '';
                $related_title = '';
                if ($related_id && get_post_status($related_id) === 'publish') {
                    $related_post  = get_post($related_id);
                    $related_url   = home_url('/' . $endpoint . '/policy/' . $related_post->post_name . '/');
                    $related_title = $related_post->post_title;
                }
                return [
                    'id'            => $post->ID,
                    'title'         => $post->post_title,
                    'slug'          => $post->post_name,
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter.
                    'answer_html'   => apply_filters('the_content', $post->post_content),
                    'answer_text'   => wp_strip_all_tags($post->post_content),
                    'menu_order'    => (int) $post->menu_order,
                    'related_url'   => $related_url,
                    'related_title' => $related_title,
                ];
            }
        );
    }

    /**
     * Raw WP_Post objects for callers that need full post_content (Chat_Corpus
     * indexes the body, projections only carry excerpts).
     *
     * Not cached — Corpus already wraps its consumer in a 12h transient, and
     * caching WP_Post graphs at this layer would double-cache the same data.
     *
     * @return array<int, \WP_Post>
     */
    public function fetch_policy_posts(): array {
        return get_posts([
            'post_type'      => 'ot_policy',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
    }

    /**
     * Most-recently-modified timestamp for a CPT, as a Unix-timestamp string
     * (or '' when the CPT has no published posts). Used by the "Updated X ago"
     * pills in the section headers.
     */
    public function section_last_updated(string $post_type): string {
        $posts = get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);

        if (empty($posts)) {
            return '';
        }

        $time = get_post_modified_time('U', false, $posts[0]);
        return $time ? (string) $time : '';
    }

    // ──────────────────────────────────────────────
    // Cache plumbing
    // ──────────────────────────────────────────────

    /**
     * Build a locale-and-version-scoped transient key. The locale suffix keeps
     * WPML/Polylang variants in separate buckets; the version counter
     * (opentrust_cache_version, bumped by OpenTrust::invalidate_cache) lets a
     * single option flip bust every cached locale at once.
     */
    private function cache_key(string $bucket): string {
        $version = (int) get_option('opentrust_cache_version', 1);
        return 'opentrust_' . $bucket . '_' . sanitize_key(determine_locale()) . '_v' . $version;
    }

    /**
     * Shared transient + WP_Query plumbing for the per-CPT fetchers. Memoized
     * for HOUR_IN_SECONDS and invalidated globally via opentrust_cache_version.
     *
     * @param array<string,mixed> $query_args
     * @param callable(WP_Post):array<string,mixed> $mapper
     * @return array<int, array<string,mixed>>
     */
    private function cached_query(string $bucket, array $query_args, callable $mapper): array {
        $cached = get_transient($this->cache_key($bucket));
        if (is_array($cached)) {
            return $cached;
        }

        $items = array_map($mapper, get_posts($query_args));

        set_transient($this->cache_key($bucket), $items, HOUR_IN_SECONDS);
        return $items;
    }

    // ──────────────────────────────────────────────
    // Per-record helpers
    // ──────────────────────────────────────────────

    /**
     * Normalize the citations meta into a list of ["SOC 2 CC6.1", …] strings.
     * Stored shape is [['name' => '…'], …]; defensive against legacy variants.
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    public function normalize_citations($raw): array {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            $name = trim($name);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Resolve a policy's uploaded PDF into a display-ready struct, or return
     * null when no attachment is set or the attachment has been deleted.
     *
     * @return array{url:string,filename:string,size_bytes:int,size_human:string}|null
     */
    public function resolve_policy_attachment(int $post_id): ?array {
        $attachment_id = (int) get_post_meta($post_id, '_ot_policy_attachment_id', true);
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return null;
        }
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return null;
        }
        $path  = get_attached_file($attachment_id) ?: '';
        $bytes = $path && file_exists($path) ? (int) wp_filesize($path) : 0;
        return [
            'url'        => $url,
            'filename'   => get_the_title($attachment_id) ?: basename($url),
            'size_bytes' => $bytes,
            'size_human' => $bytes > 0 ? size_format($bytes, 1) : '',
        ];
    }
}
