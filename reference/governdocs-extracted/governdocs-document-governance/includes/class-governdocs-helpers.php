<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper: Force Classic Editor for GovernDocs post types.
 */
final class GovernDocs_Helpers {

    /**
     * Boot hooks.
     */
    public static function hooks() : void {
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_post_type' ), 10, 2 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_prevent_block_assets' ) );
    }
    /**
     * Post types that should use the classic editor.
     *
     * @return string[]
     */
    public static function post_types() : array {
        $post_types = array(
            'governdocs_policy',
            'governdocs_meeting',
            'governdocs_report',
        );

        /**
         * Filter the post types that should use the classic editor.
         *
         * @param string[] $post_types
         */
        $post_types = (array) apply_filters( 'governdocs_classic_editor_post_types', $post_types );

        // Sanitize.
        $post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );

        return $post_types;
    }


    /**
     * Disable Gutenberg for supported post types.
     *
     * @param bool   $use_block_editor
     * @param string $post_type
     * @return bool
     */
    public static function disable_block_editor_for_post_type( $use_block_editor, $post_type ) : bool {
        $post_type = sanitize_key( (string) $post_type );

        if ( in_array( $post_type, self::post_types(), true ) ) {
            return false;
        }

        return (bool) $use_block_editor;
    }

    /**
     * Optional: prevent editor assets from enqueueing on those edit screens.
     * This is defensive; the filter above is usually enough.
     */
    public static function maybe_prevent_block_assets() : void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || empty( $screen->post_type ) ) {
            return;
        }

        if ( in_array( $screen->post_type, self::post_types(), true ) ) {
            // Prevent core editor assets enqueue.
            remove_action( 'admin_enqueue_scripts', 'wp_enqueue_editor' );
        }
    }
}