<?php
namespace GovernDocs;

use GovernDocs\Shortcodes\Contracts\GovernDocs_Shortcode_Type;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Shortcode_Renderer {

    const STYLE_HANDLE = 'governdocs-doc-shortcode';

    public static function register_styles() : void {
        wp_register_style(
            self::STYLE_HANDLE,
            GOVERNDOCS_URL . 'assets/frontend.css',
            array(),
            GOVERNDOCS_VERSION
        );
    }

    public static function render( GovernDocs_Shortcode_Type $type_obj, array $atts ) : string {
        $post = self::resolve_post(
            $type_obj->get_post_type(),
            (string) $atts['id'],
            (string) $atts['slug']
        );

        if ( ! $post ) {
            return '';
        }

        wp_enqueue_style( self::STYLE_HANDLE );

        if ( self::is_meeting_type( $type_obj, $post ) ) {
            return self::render_meeting( $type_obj, $post, $atts );
        }

        $display = self::get_display_mode( $atts );

        $file      = self::find_primary_file_for_post( $post->ID );
        $file_url  = $file ? (string) $file['url'] : '';
        $file_size = $file ? (string) $file['size_human'] : '';
        $file_ext  = $file ? (string) $file['ext'] : '';

        $primary_url = $file_url;

        $extra_class = self::sanitize_classes(
            'governdocs-doc-box governdocs-doc-box-' . $display,
            (string) $atts['class']
        );

        $uid      = 'gwpdoc_' . (int) $post->ID . '_' . substr( wp_hash( (string) $post->ID . '|' . wp_json_encode( $atts ) ), 0, 8 );
        $title_id = $uid . '_title';
        $meta_id  = $uid . '_meta';

        $icon_html = '';
        if ( self::should_show_icon( $atts, $display ) ) {
            /* translators: %s: file extension (e.g., PDF, DOCX) */
            $icon_label = $file_ext ? sprintf( __( '%s document', 'governdocs-document-governance' ), strtoupper( $file_ext ) )
                : __( 'Document', 'governdocs-document-governance' );

            $icon_html  = '<div class="governdocs-doc-icon" role="img" aria-label="' . esc_attr( $icon_label ) . '">';
            $icon_html .= self::icon_svg_for_ext( $file_ext );
            $icon_html .= '</div>';
        }

        $title_data = self::get_title_data(
            $atts,
            $display,
            get_the_title( $post ),
            __( 'Document', 'governdocs-document-governance' )
        );

        $title_text = $title_data['text'];
        $show_title = $title_data['show'];

        $title_html = '';
        if ( $show_title ) {
            $title_markup = esc_html( $title_text );

            if ( $primary_url ) {
                /* translators: %s: document title */
                $aria = sprintf( __( 'Download %s', 'governdocs-document-governance' ), $title_text );

                $title_markup = '<a href="' . esc_url( $primary_url ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $title_text ) . '</a>';
            }

            $title_html = '<p class="governdocs-doc-title" id="' . esc_attr( $title_id ) . '">' . $title_markup . '</p>';
        }

        $config = self::get_config( $type_obj, $post, $atts );

        $ctx = array(
            'type'      => $type_obj->get_slug(),
            'post_type' => $type_obj->get_post_type(),
            'file_ext'  => $file_ext,
            'file_size' => $file_size,
            'meta_id'   => $meta_id,
            'display'   => $display,
        );

        $meta_html = self::build_meta_html( $type_obj, $config, $post, $ctx );

        $button_html = '';
        if ( '1' === (string) $atts['button'] && $primary_url ) {
            $btn_label = __( 'Download', 'governdocs-document-governance' );

            $aria_title = $title_text ? $title_text : __( 'document', 'governdocs-document-governance' );

            /* translators: %s: document title */
            $btn_aria = sprintf( __( 'Download %s', 'governdocs-document-governance' ), $aria_title );

            /* translators: %s: file extension (e.g., PDF, DOCX) */
            $type_hint = $file_ext ? sprintf( __( ' (%s)', 'governdocs-document-governance' ), strtoupper( $file_ext ) ) : '';

            $button_html  = '<div class="governdocs-doc-actions">';
            $button_html .= '<a class="governdocs-doc-btn" href="' . esc_url( $primary_url ) . '" aria-label="' . esc_attr( $btn_aria . $type_hint ) . '">';
            $button_html .= esc_html( $btn_label );
            $button_html .= '</a>';
            $button_html .= '</div>';
        }

        $desc      = get_post_meta( $post->ID, 'governdocs_description', true );
        $desc      = is_string( $desc ) ? trim( $desc ) : '';
        $desc_html = '' !== $desc ? '<div class="governdocs-doc-desc">' . wp_kses_post( wpautop( $desc ) ) . '</div>' : '';

        $desc_location = (string) apply_filters(
            'governdocs_shortcode_desc_location',
            'below',
            $type_obj,
            $post,
            $atts,
            $config,
            $ctx
        );

        $desc_location = in_array( $desc_location, array( 'above', 'below' ), true ) ? $desc_location : 'below';

        $wrapper_aria = ' role="group"';
        if ( $show_title ) {
            $wrapper_aria .= ' aria-labelledby="' . esc_attr( $title_id ) . '"';
        }
        if ( '' !== trim( $meta_html ) ) {
            $wrapper_aria .= ' aria-describedby="' . esc_attr( $meta_id ) . '"';
        }

        $html  = '<div class="' . esc_attr( $extra_class ) . '"' . $wrapper_aria . '>';
        $html .= $icon_html;
        $html .= '<div class="governdocs-doc-body">';
        $html .= $title_html;

        if ( 'above' === $desc_location ) {
            $html .= $desc_html;
            $html .= $meta_html;
        } else {
            $html .= $meta_html;
            $html .= $desc_html;
        }

        $html .= '</div>';
        $html .= $button_html;
        $html .= '</div>';

        return $html;
    }

    private static function render_meeting( GovernDocs_Shortcode_Type $type_obj, \WP_Post $post, array $atts ) : string {
        $display = self::get_display_mode( $atts );

        $title_data = self::get_title_data(
            $atts,
            $display,
            get_the_title( $post ),
            __( 'Meeting', 'governdocs-document-governance' )
        );

        $title_text = $title_data['text'];
        $show_title = $title_data['show'];

        $desc      = get_post_meta( $post->ID, 'governdocs_description', true );
        $desc      = is_string( $desc ) ? trim( $desc ) : '';
        $desc_html = '' !== $desc ? '<div class="governdocs-doc-desc">' . wp_kses_post( wpautop( $desc ) ) . '</div>' : '';

        $config = self::get_config( $type_obj, $post, $atts );

        $meeting_level_fields = array( 'meeting_date' );

        $requested_enabled = isset( $config['enabled'] ) && is_array( $config['enabled'] ) ? array_map( 'strval', $config['enabled'] ) : array();
        $requested_order   = isset( $config['order'] ) && is_array( $config['order'] ) ? array_map( 'strval', $config['order'] ) : array();

        $meeting_enabled = array_values( array_intersect( $requested_enabled, $meeting_level_fields ) );
        $meeting_order   = array_values( array_intersect( $requested_order, $meeting_level_fields ) );

        $meeting_config = $config;
        $meeting_config['enabled'] = $meeting_enabled;
        $meeting_config['order']   = ! empty( $meeting_order ) ? $meeting_order : $meeting_enabled;

        $uid      = 'gwpmeeting_' . (int) $post->ID . '_' . substr( wp_hash( (string) $post->ID . '|' . wp_json_encode( $atts ) ), 0, 8 );
        $title_id = $uid . '_title';
        $meta_id  = $uid . '_meta';

        $meeting_ctx = array(
            'type'      => $type_obj->get_slug(),
            'post_type' => $type_obj->get_post_type(),
            'meta_id'   => $meta_id,
            'display'   => $display,
        );

        $meeting_meta_html = self::build_meta_html( $type_obj, $meeting_config, $post, $meeting_ctx );

        $agenda  = self::find_file_for_meta_keys( $post->ID, array( 'governdocs_agenda_file_id', 'governdocs_agenda_file' ) );
        $minutes = self::find_file_for_meta_keys( $post->ID, array( 'governdocs_minutes_file_id', 'governdocs_minutes_file' ) );

        $sections = array();

        if ( $agenda ) {
            $sections[] = self::render_meeting_section(
                $type_obj,
                $post,
                $atts,
                'agenda',
                __( 'Agenda', 'governdocs-document-governance' ),
                $agenda,
                'governdocs_agenda_publish_date',
                'governdocs_agenda_notes'
            );
        }

        if ( $minutes ) {
            $sections[] = self::render_meeting_section(
                $type_obj,
                $post,
                $atts,
                'minutes',
                __( 'Minutes', 'governdocs-document-governance' ),
                $minutes,
                'governdocs_minutes_approval_date',
                'governdocs_minutes_notes'
            );
        }

        $extra_class = self::sanitize_classes(
            'governdocs-doc-box governdocs-doc-box-' . $display,
            (string) $atts['class']
        );

        $title_html = '';
        if ( $show_title ) {
            $title_html = '<p class="governdocs-doc-title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title_text ) . '</p>';
        }

        $wrapper_aria = ' role="group"';
        if ( $show_title ) {
            $wrapper_aria .= ' aria-labelledby="' . esc_attr( $title_id ) . '"';
        }
        if ( '' !== trim( $meeting_meta_html ) ) {
            $wrapper_aria .= ' aria-describedby="' . esc_attr( $meta_id ) . '"';
        }

        $html  = '<div class="' . esc_attr( $extra_class ) . '"' . $wrapper_aria . '>';
        $html .= '<div class="governdocs-doc-body">';
        $html .= $title_html;
        $html .= $meeting_meta_html;
        $html .= $desc_html;

        if ( ! empty( $sections ) ) {
            $html .= '<div class="governdocs-meeting-sections">' . implode( '', $sections ) . '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function render_meeting_section( GovernDocs_Shortcode_Type $type_obj, \WP_Post $post, array $atts, string $section_key, string $section_label, array $file, string $date_meta_key, string $notes_meta_key ) : string {
        $display      = self::get_display_mode( $atts );
        $file_url     = isset( $file['url'] ) ? (string) $file['url'] : '';
        $file_ext     = isset( $file['ext'] ) ? (string) $file['ext'] : '';
        $file_size    = isset( $file['size_human'] ) ? (string) $file['size_human'] : '';
        $section_date = (string) get_post_meta( $post->ID, $date_meta_key, true );
        $show_icon    = self::should_show_icon( $atts, $display );
        $show_button  = ( '1' === (string) $atts['button'] );

        $uid      = 'gwpdoc_' . (int) $post->ID . '_' . $section_key . '_' . substr( wp_hash( (string) $post->ID . '|' . $section_key . '|' . wp_json_encode( $atts ) ), 0, 8 );
        $title_id = $uid . '_title';
        $meta_id  = $uid . '_meta';

        $config = self::get_config( $type_obj, $post, $atts );

        $section_only_fields = array( 'type', 'date', 'ext', 'size', 'notes' );

        $section_config = $config;
        $section_config['enabled'] = array_values( array_intersect( (array) $config['enabled'], $section_only_fields ) );
        $section_config['order']   = array_values( array_intersect( (array) $config['order'], $section_only_fields ) );

        $ctx = array(
            'type'          => $type_obj->get_slug(),
            'post_type'     => $type_obj->get_post_type(),
            'meta_id'       => $meta_id,
            'section'       => $section_key,
            'section_label' => $section_label,
            'file_ext'      => $file_ext,
            'file_size'     => $file_size,
            'section_date'  => $section_date,
            'display'       => $display,
        );

        $meta_html = self::build_meta_html( $type_obj, $section_config, $post, $ctx );

        $primary_url = $file_url;

        $icon_html = '';
        if ( $show_icon ) {
            /* translators: 1: section label (e.g. Policy, Minutes), 2: file extension (e.g. PDF, DOCX) */
            $icon_label = $file_ext ? sprintf( __( '%1$s %2$s document', 'governdocs-document-governance' ), $section_label, strtoupper( $file_ext ) )
                /* translators: %s: section label (e.g. Policy, Minutes) */
                : sprintf( __( '%s document', 'governdocs-document-governance' ), $section_label );

            $icon_html  = '<div class="governdocs-doc-icon" role="img" aria-label="' . esc_attr( $icon_label ) . '">';
            $icon_html .= self::icon_svg_for_ext( $file_ext );
            $icon_html .= '</div>';
        }

        $title_text = $section_label;
        $show_title = true;

        $title_html = '';
        if ( $show_title ) {
            $title_markup = esc_html( $title_text );

            if ( $primary_url ) {
                /* translators: %s: document title */
                $aria = sprintf( __( 'Download %s', 'governdocs-document-governance' ), $title_text );
                $title_markup = '<a href="' . esc_url( $primary_url ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $title_text ) . '</a>';
            }

            $title_html = '<p class="governdocs-meeting-section-title" id="' . esc_attr( $title_id ) . '">' . $title_markup . '</p>';
        }

        $button_html = '';
        if ( $show_button && $primary_url ) {
            $aria_title = $title_text ? $title_text : $section_label;

            /* translators: %s: document title */
            $btn_aria = sprintf( __( 'Download %s', 'governdocs-document-governance' ), $aria_title );

            /* translators: %s: file extension (e.g., PDF, DOCX) */
            $type_hint = $file_ext ? sprintf( __( ' (%s)', 'governdocs-document-governance' ), strtoupper( $file_ext ) ) : '';

            /* translators: %s: section label (e.g. Policy, Minutes) */
            $btn_label = sprintf( __( 'Download %s', 'governdocs-document-governance' ), $section_label );

            $button_html  = '<div class="governdocs-doc-actions">';
            $button_html .= '<a class="governdocs-doc-btn" href="' . esc_url( $primary_url ) . '" aria-label="' . esc_attr( $btn_aria . $type_hint ) . '">';
            $button_html .= esc_html( $btn_label );
            $button_html .= '</a>';
            $button_html .= '</div>';
        }

        $desc      = get_post_meta( $post->ID, $notes_meta_key, true );
        $desc      = is_string( $desc ) ? trim( $desc ) : '';
        $desc_html = '' !== $desc ? '<div class="governdocs-doc-desc">' . esc_html( $desc ) . '</div>' : '';

        $wrapper_aria = ' role="group"';
        if ( $show_title ) {
            $wrapper_aria .= ' aria-labelledby="' . esc_attr( $title_id ) . '"';
        }
        if ( '' !== trim( $meta_html ) ) {
            $wrapper_aria .= ' aria-describedby="' . esc_attr( $meta_id ) . '"';
        }

        $html  = '<div class="governdocs-meeting-section governdocs-meeting-section-' . esc_attr( $display ) . '"' . $wrapper_aria . '>';
        $html .= $icon_html;
        $html .= '<div class="governdocs-meeting-section-body">';
        $html .= $title_html;
        $html .= $meta_html;
        $html .= $desc_html;
        $html .= '</div>';
        $html .= $button_html;
        $html .= '</div>';

        return $html;
    }

    private static function is_meeting_type( GovernDocs_Shortcode_Type $type_obj, \WP_Post $post ) : bool {
        if ( 'governdocs_meeting' === $post->post_type ) {
            return true;
        }

        return in_array( $type_obj->get_slug(), array( 'meeting', 'meetings' ), true );
    }

    private static function get_config( GovernDocs_Shortcode_Type $type_obj, \WP_Post $post, array $atts ) : array {
        $config = array(
            'enabled' => $type_obj->get_default_enabled_fields(),
            'order'   => $type_obj->get_default_field_order(),
        );

        $requested_fields = self::parse_csv_list( (string) $atts['fields'] );
        $requested_order  = self::parse_csv_list( (string) $atts['order'] );

        $allowed_fields = array_unique(
            array_map(
                'strval',
                array_merge(
                    (array) $type_obj->get_default_enabled_fields(),
                    (array) $type_obj->get_default_field_order()
                )
            )
        );

        if ( ! empty( $requested_fields ) ) {
            $config['enabled'] = array_values( array_intersect( $requested_fields, $allowed_fields ) );
        }

        $config = (array) apply_filters( 'governdocs_shortcode_config', $config, $type_obj, $post, $atts );
        $config = (array) apply_filters( 'governdocs_shortcode_config_' . $type_obj->get_slug(), $config, $type_obj, $post, $atts );

        $config['enabled'] = isset( $config['enabled'] ) && is_array( $config['enabled'] ) ? array_values( array_unique( array_map( 'strval', $config['enabled'] ) ) ) : array();
        $config['order']   = isset( $config['order'] ) && is_array( $config['order'] ) ? array_values( array_unique( array_map( 'strval', $config['order'] ) ) ) : array();
        $config['flags']   = isset( $config['flags'] ) && is_array( $config['flags'] ) ? $config['flags'] : array();

        return $config;
    }

    private static function build_meta_html( GovernDocs_Shortcode_Type $type_obj, array $config, \WP_Post $post, array $ctx ) : string {
        $enabled = isset( $config['enabled'] ) && is_array( $config['enabled'] ) ? array_values( $config['enabled'] ) : array();
        $order   = isset( $config['order'] ) && is_array( $config['order'] ) ? array_values( $config['order'] ) : array();
        $flags   = isset( $config['flags'] ) && is_array( $config['flags'] ) ? $config['flags'] : array();
        $meta_id = isset( $ctx['meta_id'] ) ? (string) $ctx['meta_id'] : '';

        if ( empty( $order ) ) {
            $order = $enabled;
        }

        $enabled_lookup = array_flip( array_map( 'strval', $enabled ) );
        $items          = array();

        foreach ( $order as $key ) {
            $key = (string) $key;

            if ( ! isset( $enabled_lookup[ $key ] ) ) {
                continue;
            }

            $val = $type_obj->get_field_value( $key, $post, $ctx, $flags );

            if ( '' === $val ) {
                $val = apply_filters( 'governdocs_shortcode_meta_value', '', $key, $type_obj, $post, $ctx, $flags );
                $val = apply_filters( 'governdocs_shortcode_meta_value_' . $type_obj->get_slug(), $val, $key, $type_obj, $post, $ctx, $flags );
            }

            $val = is_string( $val ) ? trim( $val ) : '';

            if ( '' === $val ) {
                continue;
            }

            $items[] = '<li class="governdocs-doc-pill">' . esc_html( $val ) . '</li>';
        }

        if ( empty( $items ) ) {
            return '';
        }

        $id_attr = '' !== $meta_id ? ' id="' . esc_attr( $meta_id ) . '"' : '';

        return '<ul class="governdocs-doc-meta" role="list"' . $id_attr . '>' . implode( '', $items ) . '</ul>';
    }

    private static function get_display_mode( array $atts ) : string {
        $display = isset( $atts['display'] ) ? strtolower( trim( (string) $atts['display'] ) ) : 'card';

        return in_array( $display, array( 'card', 'full' ), true ) ? $display : 'card';
    }

    private static function should_show_icon( array $atts, string $display ) : bool {
        if ( isset( $atts['show_icon'] ) && '' !== (string) $atts['show_icon'] ) {
            return '1' === (string) $atts['show_icon'];
        }

        return 'full' !== $display;
    }

    private static function get_title_data( array $atts, string $display, string $default_title, string $fallback_title ) : array {
        $raw = isset( $atts['title'] ) ? trim( (string) $atts['title'] ) : '';

        if ( '' === $raw ) {
            $show = ( 'full' !== $display );
            $text = trim( wp_strip_all_tags( $default_title ) );
            if ( '' === $text ) {
                $text = $fallback_title;
            }

            return array(
                'show' => $show,
                'text' => $text,
            );
        }

        $normalized = strtolower( $raw );

        if ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
            return array(
                'show' => false,
                'text' => '',
            );
        }

        if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
            $text = trim( wp_strip_all_tags( $default_title ) );
            if ( '' === $text ) {
                $text = $fallback_title;
            }

            return array(
                'show' => true,
                'text' => $text,
            );
        }

        return array(
            'show' => true,
            'text' => trim( wp_strip_all_tags( $raw ) ),
        );
    }

    

    private static function parse_csv_list( string $value ) : array {
        $value = trim( $value );

        if ( '' === $value ) {
            return array();
        }

        $parts = array_map( 'trim', explode( ',', $value ) );
        $parts = array_filter(
            $parts,
            static function ( $item ) {
                return '' !== $item;
            }
        );

        return array_values( array_unique( array_map( 'strval', $parts ) ) );
    }

    private static function sanitize_classes( string $base, string $user_class ) : string {
        $final      = $base;
        $user_class = trim( $user_class );

        if ( '' === $user_class ) {
            return $final;
        }

        $classes = preg_split( '/\s+/', $user_class );

        if ( ! is_array( $classes ) ) {
            return $final;
        }

        foreach ( $classes as $class_name ) {
            $class_name = sanitize_html_class( $class_name );

            if ( '' !== $class_name ) {
                $final .= ' ' . $class_name;
            }
        }

        return $final;
    }

    public static function label_for_post_type( string $post_type ) : string {
        $obj = get_post_type_object( $post_type );

        if ( $obj && ! empty( $obj->labels->singular_name ) ) {
            return (string) $obj->labels->singular_name;
        }

        return '';
    }

    public static function format_meta_date_pill( int $post_id, string $meta_key, string $label ) : string {
        $raw = get_post_meta( $post_id, $meta_key, true );
        $raw = is_string( $raw ) ? trim( $raw ) : '';

        if ( '' === $raw ) {
            return '';
        }

        $ts = strtotime( $raw );

        if ( ! $ts ) {
            return $label . ' ' . $raw;
        }

        $format = get_option( 'date_format' );

        if ( function_exists( 'wp_date' ) ) {
            $date = wp_date( $format, $ts );
        } else {
            $date = date_i18n( $format, $ts );
        }

        return $label . ' ' . $date;
    }

    public static function format_raw_date_pill( string $raw, string $label ) : string {
        $raw = trim( $raw );

        if ( '' === $raw ) {
            return '';
        }

        $ts = strtotime( $raw );

        if ( ! $ts ) {
            return $label . ' ' . $raw;
        }

        $format = get_option( 'date_format' );

        if ( function_exists( 'wp_date' ) ) {
            $date = wp_date( $format, $ts );
        } else {
            $date = date_i18n( $format, $ts );
        }

        return $label . ' ' . $date;
    }

    public static function resolve_post( string $post_type, string $id, string $slug ) : ?\WP_Post {
        $post = null;

        if ( '' !== $id && ctype_digit( $id ) ) {
            $p = get_post( (int) $id );
            if ( $p instanceof \WP_Post && $p->post_type === $post_type && 'trash' !== $p->post_status ) {
                $post = $p;
            }
        }

        if ( ! $post && '' !== $slug ) {
            $p = get_page_by_path( sanitize_title( $slug ), OBJECT, $post_type );
            if ( $p instanceof \WP_Post && 'trash' !== $p->post_status ) {
                $post = $p;
            }
        }

        return $post ?: null;
    }

    public static function find_primary_file_for_post( int $post_id ) : ?array {
        return self::find_file_for_meta_keys(
            $post_id,
            array(
                'governdocs_primary_file_id',
                'governdocs_primary_file',
            )
        );
    }

    public static function find_file_for_meta_keys( int $post_id, array $meta_keys ) : ?array {
        $attachment_id = 0;
        $url           = '';

        foreach ( $meta_keys as $k ) {
            $v = get_post_meta( $post_id, $k, true );

            if ( empty( $v ) ) {
                continue;
            }

            if ( is_numeric( $v ) && (int) $v > 0 ) {
                $attachment_id = (int) $v;
                break;
            }

            if ( is_string( $v ) && preg_match( '#^https?://#i', $v ) ) {
                $url = $v;
            }
        }

        if ( $attachment_id > 0 ) {
            $att_url = wp_get_attachment_url( $attachment_id );

            if ( $att_url ) {
                $path = get_attached_file( $attachment_id );
                $size = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;
                $ext  = pathinfo( $att_url, PATHINFO_EXTENSION );
                $ext  = $ext ? strtolower( $ext ) : '';

                return array(
                    'url'        => $att_url,
                    'size_bytes' => (int) $size,
                    'size_human' => $size ? size_format( (int) $size, 1 ) : '',
                    'ext'        => $ext,
                );
            }
        }

        if ( $url ) {
            $ext = pathinfo( $url, PATHINFO_EXTENSION );
            $ext = $ext ? strtolower( $ext ) : '';

            return array(
                'url'        => $url,
                'size_bytes' => 0,
                'size_human' => '',
                'ext'        => $ext,
            );
        }

        return null;
    }

    public static function icon_svg_for_ext( string $ext ) : string {
        $ext = strtolower( trim( $ext ) );

        $doc = '<svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg"><path d="M200,26H72A14,14,0,0,0,58,40V66H40A14,14,0,0,0,26,80v96a14,14,0,0,0,14,14H58v26a14,14,0,0,0,14,14H200a14,14,0,0,0,14-14V40A14,14,0,0,0,200,26Zm-42,76h44v52H158ZM70,40a2,2,0,0,1,2-2H200a2,2,0,0,1,2,2V90H158V80a14,14,0,0,0-14-14H70ZM38,176V80a2,2,0,0,1,2-2H144a2,2,0,0,1,2,2v96a2,2,0,0,1-2,2H40A2,2,0,0,1,38,176Zm162,42H72a2,2,0,0,1-2-2V190h74a14,14,0,0,0,14-14V166h44v50A2,2,0,0,1,200,218ZM70.18,153.46l-12-48a6,6,0,1,1,11.64-2.92l8.07,32.27,8.74-17.49a6,6,0,0,1,10.74,0l8.74,17.49,8.07-32.27a6,6,0,1,1,11.64,2.92l-12,48a6,6,0,0,1-5.17,4.5,4.63,4.63,0,0,1-.65,0,6,6,0,0,1-5.37-3.32L92,133.42,81.37,154.68a6,6,0,0,1-11.19-1.22Z"></path></svg>';

        $pdf = '<svg stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M13.85 4.44l-3.28-3.3-.35-.14H2.5l-.5.5V7h1V2h6v3.5l.5.5H13v1h1V4.8l-.15-.36zM10 5V2l3 3h-3zM2.5 8l-.5.5v6l.5.5h11l.5-.5v-6l-.5-.5h-11zM13 13v1H3V9h10v4zm-8-1h-.32v1H4v-3h1.06c.75 0 1.13.36 1.13 1a.94.94 0 0 1-.32.72A1.33 1.33 0 0 1 5 12zm-.06-1.45h-.26v.93h.26c.36 0 .54-.16.54-.47 0-.31-.18-.46-.54-.46zM9 12.58a1.48 1.48 0 0 0 .44-1.12c0-1-.53-1.46-1.6-1.46H6.78v3h1.06A1.6 1.6 0 0 0 9 12.58zm-1.55-.13v-1.9h.33a.94.94 0 0 1 .7.25.91.91 0 0 1 .25.67 1 1 0 0 1-.25.72.94.94 0 0 1-.69.26h-.34zm4.45-.61h-.97V13h-.68v-3h1.74v.55h-1.06v.74h.97v.55z"></path></svg>';

        $img = '<svg stroke="currentColor" fill="none" stroke-width="0" viewBox="0 0 15 15" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 1C13.3284 1 14 1.67157 14 2.5V12.5C14 13.3284 13.3284 14 12.5 14H2.5C1.72334 14 1.08461 13.4097 1.00781 12.6533L1 12.5V2.5C1 1.67157 1.67157 1 2.5 1H12.5ZM2 9.63574V12.5L2.00977 12.6006C2.04966 12.7961 2.20392 12.9503 2.39941 12.9902L2.5 13H8.94141L7.52832 11.4395V11.4385L3.98828 7.64746L2 9.63574ZM8.4834 11.1523L10.1553 13H12.5L12.6006 12.9902C12.7961 12.9503 12.9503 12.7961 12.9902 12.6006L13 12.5V10.6367L11 8.63672L8.4834 11.1523ZM2.39941 2.00977C2.17145 2.05629 2 2.25829 2 2.5V8.36328L3.68164 6.68164L3.75195 6.625C3.82721 6.57522 3.91621 6.54823 4.00781 6.5498C4.1298 6.55192 4.24585 6.60417 4.3291 6.69336L7.87305 10.4893L10.6816 7.68164L10.752 7.62402C10.9266 7.50851 11.1645 7.5278 11.3184 7.68164L13 9.36328V2.5C13 2.25829 12.8286 2.05629 12.6006 2.00977L12.5 2H2.5L2.39941 2.00977ZM7.5 3.74902C8.46693 3.74902 9.25098 4.53307 9.25098 5.5C9.25098 6.46693 8.46693 7.25098 7.5 7.25098C6.53307 7.25098 5.74902 6.46693 5.74902 5.5C5.74902 4.53307 6.53307 3.74902 7.5 3.74902ZM7.5 4.64941C7.03013 4.64941 6.64941 5.03013 6.64941 5.5C6.64941 5.96987 7.03013 6.35059 7.5 6.35059C7.96987 6.35059 8.35059 5.96987 8.35059 5.5C8.35059 5.03013 7.96987 4.64941 7.5 4.64941Z" fill="currentColor"></path></svg>';

        $link = '<svg viewBox="0 0 24 24" fill="none" role="img" aria-hidden="true"><path d="M10 13a5 5 0 0 1 0-7l1-1a5 5 0 0 1 7 7l-1 1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path><path d="M14 11a5 5 0 0 1 0 7l-1 1a5 5 0 0 1-7-7l1-1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>';

        if ( 'pdf' === $ext ) {
            return $pdf;
        }

        if ( in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ), true ) ) {
            return $img;
        }

        if ( '' === $ext ) {
            return $link;
        }

        return $doc;
    }
}