<?php
namespace GovernDocs\Shortcodes\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface GovernDocs_Shortcode_Type {

    /**
     * Main type slug.
     */
    public function get_slug() : string;

    /**
     * Accepted shortcode aliases.
     *
     * Example: policy, policies
     *
     * @return string[]
     */
    public function get_aliases() : array;

    /**
     * Backing post type.
     */
    public function get_post_type() : string;

    /**
     * Default enabled fields for free.
     *
     * @return string[]
     */
    public function get_default_enabled_fields() : array;

    /**
     * Default field order for free.
     *
     * @return string[]
     */
    public function get_default_field_order() : array;

    /**
     * Resolve a meta pill value for a supported free field.
     */
    public function get_field_value( string $key, \WP_Post $post, array $ctx, array $flags ) : string;
}