<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Admin_Menu {

    const OPT_DISABLED_POST_TYPES = 'governdocs_disabled_post_types';

    public function hooks() : void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_menu() : void {
        add_menu_page(
            __( 'GovernDocs', 'governdocs-document-governance' ),
            __( 'GovernDocs', 'governdocs-document-governance' ),
            'manage_options',
            'governdocs-document-governance',
            array( $this, 'render_settings_page' ),
            'dashicons-media-document',
            58
        );

        add_submenu_page(
            'governdocs-document-governance',
            __( 'Settings', 'governdocs-document-governance' ),
            __( 'Settings', 'governdocs-document-governance' ),
            'manage_options',
            'governdocs-document-governance',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() : void {
        register_setting(
            'governdocs_settings',
            self::OPT_DISABLED_POST_TYPES,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_disabled_post_types' ),
                'default'           => $this->default_disabled_post_types(),
            )
        );

        /**
         * Allow extensions such as GovernDocs PRO to register additional settings
         * on the main GovernDocs settings page.
         */
        do_action( 'governdocs_register_settings' );
    }

    private function default_disabled_post_types() : array {
        return array(
            'policies' => false,
            'meetings' => false,
            'reports'  => false,
        );
    }

    public function sanitize_disabled_post_types( $value ) : array {
        $defaults = $this->default_disabled_post_types();
        $value    = is_array( $value ) ? $value : array();

        $out = array();

        foreach ( $defaults as $key => $default ) {
            $out[ $key ] = ! empty( $value[ $key ] );
        }

        return $out;
    }

    public static function get_disabled_post_types() : array {
        $defaults = array(
            'policies' => false,
            'meetings' => false,
            'reports'  => false,
        );

        $stored = get_option( self::OPT_DISABLED_POST_TYPES, array() );
        $stored = is_array( $stored ) ? $stored : array();

        return array(
            'policies' => array_key_exists( 'policies', $stored ) ? (bool) $stored['policies'] : $defaults['policies'],
            'meetings' => array_key_exists( 'meetings', $stored ) ? (bool) $stored['meetings'] : $defaults['meetings'],
            'reports'  => array_key_exists( 'reports', $stored ) ? (bool) $stored['reports'] : $defaults['reports'],
        );
    }

    public static function is_post_type_disabled( string $type ) : bool {
        $disabled = self::get_disabled_post_types();

        return ! empty( $disabled[ $type ] );
    }

    public function render_settings_page() : void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $disabled = self::get_disabled_post_types();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'GovernDocs Settings', 'governdocs-document-governance' ) . '</h1>';

        echo '<form method="post" action="options.php">';
        settings_fields( 'governdocs_settings' );

        echo '<h2>' . esc_html__( 'Content Types', 'governdocs-document-governance' ) . '</h2>';
        echo '<p>' . esc_html__( 'Disable any GovernDocs content types you do not want to use.', 'governdocs-document-governance' ) . '</p>';

        echo '<table class="form-table" role="presentation"><tbody>';

        $this->render_switch_row(
            'policies',
            __( 'Disable Policies', 'governdocs-document-governance' ),
            $disabled['policies'],
            __( 'Turn off the Policies content type throughout GovernDocs.', 'governdocs-document-governance' )
        );

        $this->render_switch_row(
            'meetings',
            __( 'Disable Meetings', 'governdocs-document-governance' ),
            $disabled['meetings'],
            __( 'Turn off the Meetings content type throughout GovernDocs.', 'governdocs-document-governance' )
        );

        $this->render_switch_row(
            'reports',
            __( 'Disable Reports', 'governdocs-document-governance' ),
            $disabled['reports'],
            __( 'Turn off the Reports content type throughout GovernDocs.', 'governdocs-document-governance' )
        );

        echo '</tbody></table>';

        /**
         * Allow extensions such as GovernDocs PRO to render additional settings
         * sections inside the main settings form.
         */
        do_action( 'governdocs_render_settings_sections' );

        submit_button();
        echo '</form>';
        echo '</div>';
    }

    private function render_switch_row( string $key, string $label, bool $checked, string $description ) : void {
        echo '<tr>';
        echo '<th scope="row">' . esc_html( $label ) . '</th>';
        echo '<td>';

        $id = 'governdocs_switch_' . esc_attr( $key );

        printf(
            '<label class="governdocs-switch">
                <input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s>
                <span class="governdocs-slider"></span>
            </label>
            <p class="description">%5$s</p>',
            esc_attr( $id ),
            esc_attr( self::OPT_DISABLED_POST_TYPES ),
            esc_attr( $key ),
            checked( $checked, true, false ),
            esc_html( $description )
        );

        echo '</td>';
        echo '</tr>';
    }
}