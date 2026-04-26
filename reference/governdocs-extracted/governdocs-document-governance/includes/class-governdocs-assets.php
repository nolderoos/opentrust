<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Assets {

    public function hooks() : void {
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
    }

    public function admin_assets() : void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return;
        }

        // Only load on GovernDocs post type screens and plugin admin screens.
        $governdocs_post_types = array(
            'governdocs_policy',
            'governdocs_meeting',
            'governdocs_report',
        );

        $is_governdocs_post_type = in_array( (string) $screen->post_type, $governdocs_post_types, true );
        $is_governdocs_screen    = ( false !== strpos( (string) $screen->id, 'governdocs-document-governance' ) );

        if ( ! $is_governdocs_post_type && ! $is_governdocs_screen ) {
            return;
        }

        wp_enqueue_style(
            'governdocs-admin',
            GOVERNDOCS_URL . 'assets/admin.css',
            array(),
            GOVERNDOCS_VERSION
        );

        wp_enqueue_script(
            'governdocs-admin',
            GOVERNDOCS_URL . 'assets/admin.js',
            array( 'jquery' ),
            GOVERNDOCS_VERSION,
            true
        );

    }

    public function frontend_assets() : void {
        // Placeholder for frontend assets.
    }
}
