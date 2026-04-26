<?php
namespace GovernDocs\Shortcodes\Types;

use GovernDocs\GovernDocs_Shortcode_Renderer;
use GovernDocs\Shortcodes\Contracts\GovernDocs_Shortcode_Type;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Shortcode_Type_Meeting implements GovernDocs_Shortcode_Type {

    public function get_slug() : string {
        return 'meetings';
    }

    public function get_aliases() : array {
        return array( 'meetings', 'meeting' );
    }

    public function get_post_type() : string {
        return 'governdocs_meeting';
    }

    public function get_default_enabled_fields() : array {
        return array(
            'type',
            'ext',
            'size',
            'meeting_date',
        );
    }

    public function get_default_field_order() : array {
        return array(
            'type',
            'ext',
            'size',
            'meeting_date',
        );
    }

    public function get_field_value( string $key, \WP_Post $post, array $ctx, array $flags ) : string {
        $key = strtolower( trim( $key ) );

        $section      = isset( $ctx['section'] ) ? (string) $ctx['section'] : '';
        $file_ext     = isset( $ctx['file_ext'] ) ? (string) $ctx['file_ext'] : '';
        $file_size    = isset( $ctx['file_size'] ) ? (string) $ctx['file_size'] : '';
        $section_date = isset( $ctx['section_date'] ) ? (string) $ctx['section_date'] : '';

        switch ( $key ) {
            case 'type':
                if ( 'agenda' === $section ) {
                    return __( 'Agenda', 'governdocs-document-governance' );
                }

                if ( 'minutes' === $section ) {
                    return __( 'Minutes', 'governdocs-document-governance' );
                }

                return GovernDocs_Shortcode_Renderer::label_for_post_type( $this->get_post_type() );

            case 'ext':
                return $file_ext ? strtoupper( $file_ext ) : '';

            case 'size':
                return $file_size ? $file_size : '';

            case 'date':
                if ( '' === $section_date ) {
                    return '';
                }

                $label = ( 'agenda' === $section ) ? __( 'Published:', 'governdocs-document-governance' ) : __( 'Approved:', 'governdocs-document-governance' );

                return GovernDocs_Shortcode_Renderer::format_raw_date_pill( $section_date, $label );

            case 'meeting_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill(
                    $post->ID,
                    'governdocs_meeting_date',
                    __( 'Meeting date:', 'governdocs-document-governance' )
                );
        }

        return '';
    }
}