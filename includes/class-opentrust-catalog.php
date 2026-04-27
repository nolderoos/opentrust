<?php
/**
 * Catalog service.
 *
 * Loads and normalizes the bundled subprocessor, data-practice, and
 * certification catalogs used by the admin typeahead autofill, plus the
 * default FAQ catalog seeded once on first activation. Catalogs are static
 * PHP arrays shipped with the plugin; all four are filterable so integrators
 * can extend them without forking.
 *
 * Entry schema (both catalogs share the same shape):
 *
 *   'slug' => [
 *       'name'          => 'Canonical Name',
 *       'aliases'       => ['alt', 'nickname'],
 *       'fields'        => [ '<meta_key>' => 'value', ... ],  // facts (green)
 *       'fields_review' => [ '<meta_key>' => 'value', ... ],  // templates (amber)
 *   ]
 *
 * Any string field with a scalar value can be handed straight to a DOM
 * input; array-valued fields (e.g. tag repeaters for data practices) are
 * passed through untouched and handled by JS.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Catalog {

    /**
     * Return the normalized subprocessor catalog.
     *
     * @return array<string, array{name:string, aliases:array<int,string>, fields:array<string,mixed>, fields_review:array<string,mixed>}>
     */
    public static function subprocessors(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $raw = require OPENTRUST_PLUGIN_DIR . 'includes/data/subprocessor-catalog.php';
        $raw = is_array( $raw ) ? $raw : [];

        /**
         * Filter the subprocessor catalog.
         *
         * @param array $raw Catalog entries keyed by slug.
         */
        $raw = apply_filters( 'opentrust_subprocessor_catalog', $raw );

        $cache = self::normalize( is_array( $raw ) ? $raw : [] );
        return $cache;
    }

    /**
     * Return the normalized data-practice catalog.
     *
     * @return array<string, array{name:string, aliases:array<int,string>, fields:array<string,mixed>, fields_review:array<string,mixed>}>
     */
    public static function data_practices(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $raw = require OPENTRUST_PLUGIN_DIR . 'includes/data/data-practice-catalog.php';
        $raw = is_array( $raw ) ? $raw : [];

        /**
         * Filter the data-practice catalog.
         *
         * @param array $raw Catalog entries keyed by slug.
         */
        $raw = apply_filters( 'opentrust_data_practice_catalog', $raw );

        $cache = self::normalize( is_array( $raw ) ? $raw : [] );
        return $cache;
    }

    /**
     * Return the normalized certification catalog.
     *
     * @return array<string, array{name:string, aliases:array<int,string>, fields:array<string,mixed>, fields_review:array<string,mixed>}>
     */
    public static function certifications(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $raw = require OPENTRUST_PLUGIN_DIR . 'includes/data/certification-catalog.php';
        $raw = is_array( $raw ) ? $raw : [];

        /**
         * Filter the certification catalog.
         *
         * @param array $raw Catalog entries keyed by slug.
         */
        $raw = apply_filters( 'opentrust_certification_catalog', $raw );

        $cache = self::normalize( is_array( $raw ) ? $raw : [] );
        return $cache;
    }

    /**
     * Return the default FAQ catalog as a list of question/answer pairs.
     *
     * Unlike the other catalogs, FAQ entries are seeded once into real
     * `ot_faq` posts on first activation and then owned by the user. This
     * loader is only consulted by the seeder.
     *
     * @return array<string, array{question:string, answer:string}>
     */
    public static function faqs(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $raw = require OPENTRUST_PLUGIN_DIR . 'includes/data/faq-catalog.php';
        $raw = is_array( $raw ) ? $raw : [];

        /**
         * Filter the default FAQ catalog.
         *
         * Because FAQs are seeded once on first activation, this filter only
         * affects fresh installs (or sites that have never been seeded).
         *
         * @param array $raw FAQ entries keyed by slug.
         */
        $raw = apply_filters( 'opentrust_faq_catalog', $raw );

        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $slug => $entry ) {
            if ( ! is_string( $slug ) || $slug === '' || ! is_array( $entry ) ) {
                continue;
            }
            $question = isset( $entry['question'] ) && is_string( $entry['question'] ) ? trim( $entry['question'] ) : '';
            $answer   = isset( $entry['answer'] ) && is_string( $entry['answer'] ) ? trim( $entry['answer'] ) : '';
            if ( $question === '' || $answer === '' ) {
                continue;
            }
            $out[ $slug ] = [
                'question' => $question,
                'answer'   => $answer,
            ];
        }

        $cache = $out;
        return $cache;
    }

    /**
     * Seed the default FAQs as published `ot_faq` posts.
     *
     * Gated by the `opentrust_faqs_seeded` option so deletions stick: once a
     * site has been seeded, re-activating the plugin will not recreate the
     * FAQs. Each seeded post is tagged with `_ot_seeded=1` and a stable
     * `_ot_seed_slug` meta for later bulk operations.
     */
    public static function seed_default_faqs(): void {
        if ( get_option( 'opentrust_faqs_seeded' ) ) {
            return;
        }

        $faqs  = self::faqs();
        $order = 0;

        foreach ( $faqs as $slug => $entry ) {
            $order += 10;

            $content = "<!-- wp:paragraph -->\n<p>" . esc_html( $entry['answer'] ) . "</p>\n<!-- /wp:paragraph -->";

            $post_id = wp_insert_post(
                [
                    'post_type'    => 'ot_faq',
                    'post_status'  => 'publish',
                    'post_title'   => $entry['question'],
                    'post_content' => $content,
                    'menu_order'   => $order,
                ],
                false
            );

            if ( is_int( $post_id ) && $post_id > 0 ) {
                update_post_meta( $post_id, '_ot_seeded', 1 );
                update_post_meta( $post_id, '_ot_seed_slug', $slug );
            }
        }

        update_option( 'opentrust_faqs_seeded', 1, false );
    }

    /**
     * Return the minified payload that ships to the admin JS for a given CPT.
     * Entries become a positional array with a stable `id` (slug) so JS can
     * key option elements without reshaping.
     *
     * @return array{entries: array<int, array{id:string, name:string, haystack:string, fields:array<string,mixed>, fields_review:array<string,mixed>}>}
     */
    public static function for_js( string $post_type ): array {
        $catalog = match ( $post_type ) {
            'ot_subprocessor'  => self::subprocessors(),
            'ot_data_practice' => self::data_practices(),
            'ot_certification' => self::certifications(),
            default            => [],
        };

        $entries = [];
        foreach ( $catalog as $slug => $entry ) {
            // Build a single lowercase haystack the JS can scan cheaply:
            // the canonical name plus every alias, separated by a delimiter
            // that can't appear in a vendor name. Normalization happens here
            // once on the server rather than on every keystroke in the browser.
            $needles = array_merge( [ $entry['name'] ], $entry['aliases'] );
            $needles = array_map( [ self::class, 'normalize_string' ], $needles );
            $haystack = implode( '|', array_filter( $needles ) );

            $entries[] = [
                'id'            => (string) $slug,
                'name'          => (string) $entry['name'],
                'haystack'      => $haystack,
                'fields'        => $entry['fields'],
                'fields_review' => $entry['fields_review'],
            ];
        }

        return [ 'entries' => $entries ];
    }

    /**
     * Normalize a catalog array: ensure every entry has name, aliases,
     * fields, and fields_review keys. Drops entries that are malformed
     * or missing a name. Does not mutate the catalog source.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{name:string, aliases:array<int,string>, fields:array<string,mixed>, fields_review:array<string,mixed>}>
     */
    private static function normalize( array $raw ): array {
        $out = [];
        foreach ( $raw as $slug => $entry ) {
            if ( ! is_string( $slug ) || $slug === '' || ! is_array( $entry ) ) {
                continue;
            }
            $name = isset( $entry['name'] ) && is_string( $entry['name'] ) ? trim( $entry['name'] ) : '';
            if ( $name === '' ) {
                continue;
            }

            $aliases = [];
            if ( isset( $entry['aliases'] ) && is_array( $entry['aliases'] ) ) {
                foreach ( $entry['aliases'] as $alias ) {
                    if ( is_string( $alias ) && $alias !== '' ) {
                        $aliases[] = $alias;
                    }
                }
            }

            $out[ $slug ] = [
                'name'          => $name,
                'aliases'       => array_values( array_unique( $aliases ) ),
                'fields'        => isset( $entry['fields'] ) && is_array( $entry['fields'] ) ? $entry['fields'] : [],
                'fields_review' => isset( $entry['fields_review'] ) && is_array( $entry['fields_review'] ) ? $entry['fields_review'] : [],
            ];
        }
        return $out;
    }

    /**
     * Lowercase + strip non-alphanumeric. Same rules used in JS so server
     * and client match byte-for-byte.
     */
    public static function normalize_string( string $value ): string {
        $value = strtolower( $value );
        return (string) preg_replace( '/[^a-z0-9]/', '', $value );
    }
}
