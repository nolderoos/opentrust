<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Installer {

    const DB_VERSION_OPTION = 'governdocs_db_version';
    const DB_VERSION        = '1.0.0';

    /**
     * Plugin activation hook.
     *
     * @param bool $network_wide Whether the plugin is being network activated.
     */
    public static function activate( bool $network_wide ) : void {
        if ( is_multisite() && $network_wide ) {
            $sites = get_sites(
                array(
                    'fields' => 'ids',
                )
            );

            foreach ( $sites as $blog_id ) {
                switch_to_blog( (int) $blog_id );
                self::install();
                restore_current_blog();
            }

            return;
        }

        self::install();
    }

    /**
     * Run free-plugin install tasks.
     */
    public static function install() : void {
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
    }

    /**
     * Run free-plugin upgrades when needed.
     */
    public static function maybe_upgrade() : void {
        $installed = (string) get_option( self::DB_VERSION_OPTION, '' );

        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::install();
        }
    }

    /**
     * Multisite: run setup for newly created sites.
     *
     * @param int $blog_id Site blog ID.
     */
    public static function on_new_blog( int $blog_id ) : void {
        if ( ! is_multisite() ) {
            return;
        }

        switch_to_blog( $blog_id );
        self::install();
        restore_current_blog();
    }
}