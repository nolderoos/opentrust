<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GovernDocs_Plugin {

    private static ?GovernDocs_Plugin $instance = null;

    public static function instance() : GovernDocs_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }

        return self::$instance;
    }

    private function __construct() {}

    private function init() : void {
        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-installer.php';

        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-assets.php';
        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-admin-menu.php';
        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-cmb2.php';
        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-helpers.php';
        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-policy-versioning.php';

        require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-policy.php';
        require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-meeting.php';
        require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-report.php';

        require_once GOVERNDOCS_DIR . 'includes/shortcodes/contracts/interface-governdocs-shortcode-type.php';
        require_once GOVERNDOCS_DIR . 'includes/shortcodes/class-governdocs-shortcode-renderer.php';
        require_once GOVERNDOCS_DIR . 'includes/shortcodes/types/class-governdocs-shortcode-type-policy.php';
        require_once GOVERNDOCS_DIR . 'includes/shortcodes/types/class-governdocs-shortcode-type-meeting.php';
        require_once GOVERNDOCS_DIR . 'includes/shortcodes/types/class-governdocs-shortcode-type-report.php';
        require_once GOVERNDOCS_DIR . 'includes/shortcodes/class-governdocs-shortcodes.php';

        // Run installer/upgrade logic needed by the free plugin only.
        add_action( 'plugins_loaded', array( '\GovernDocs\GovernDocs_Installer', 'maybe_upgrade' ), 5 );

        // Multisite: ensure new sites get free-plugin setup too.
        add_action(
            'wp_initialize_site',
            function ( $new_site ) {
                if ( is_object( $new_site ) && isset( $new_site->blog_id ) ) {
                    \GovernDocs\GovernDocs_Installer::on_new_blog( (int) $new_site->blog_id );
                }
            },
            10,
            1
        );

        ( new GovernDocs_Policy_Versioning() )->hooks();

        ( new GovernDocs_Helpers() )->hooks();
        ( new GovernDocs_Assets() )->hooks();
        ( new GovernDocs_Admin_Menu() )->hooks();
        ( new GovernDocs_CMB2() )->hooks();

        ( new CPT\GovernDocs_CPT_Policy() )->hooks();
        ( new CPT\GovernDocs_CPT_Meeting() )->hooks();
        ( new CPT\GovernDocs_CPT_Report() )->hooks();

        $shortcodes = new \GovernDocs\GovernDocs_Shortcodes();
        $shortcodes->hooks();
    }

    public static function activate( bool $network_wide = false ) : void {
        // CPTs must be registered before rewrite flush.
        require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-policy.php';
        require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-meeting.php';
        require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-report.php';

        ( new CPT\GovernDocs_CPT_Policy() )->register();
        ( new CPT\GovernDocs_CPT_Meeting() )->register();
        ( new CPT\GovernDocs_CPT_Report() )->register();

        require_once GOVERNDOCS_DIR . 'includes/class-governdocs-installer.php';

        if ( class_exists( '\GovernDocs\GovernDocs_Installer' ) ) {
            \GovernDocs\GovernDocs_Installer::activate( (bool) $network_wide );
        }

        flush_rewrite_rules();
    }

    public static function deactivate() : void {
        flush_rewrite_rules();
    }
}