<?php
namespace GovernDocs\Shortcodes\Types;

use GovernDocs\GovernDocs_Shortcode_Renderer;
use GovernDocs\Shortcodes\Contracts\GovernDocs_Shortcode_Type;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Shortcode_Type_Report implements GovernDocs_Shortcode_Type {

    public function get_slug() : string {
        return 'report';
    }

    public function get_aliases() : array {
        return array( 'report', 'reports' );
    }

    public function get_post_type() : string {
        return 'governdocs_report';
    }

    public function get_default_enabled_fields() : array {
        return array( 'type', 'report_date', 'ext', 'size' );
    }

    public function get_default_field_order() : array {
        return array( 'type', 'report_date', 'ext', 'size' );
    }

    public function get_field_value( string $key, \WP_Post $post, array $ctx, array $flags ) : string {
        $key       = strtolower( trim( $key ) );
        $file_ext  = isset( $ctx['file_ext'] ) ? (string) $ctx['file_ext'] : '';
        $file_size = isset( $ctx['file_size'] ) ? (string) $ctx['file_size'] : '';
        $post_type = isset( $ctx['post_type'] ) ? (string) $ctx['post_type'] : '';

        switch ( $key ) {
            case 'ext':
                return $file_ext ? strtoupper( $file_ext ) : '';

            case 'size':
                return $file_size ? $file_size : '';

            case 'type':
                $type = (string) get_post_meta( $post->ID, 'governdocs_report_type', true );

                if ( '' === $type ) {
                    return GovernDocs_Shortcode_Renderer::label_for_post_type( $post_type );
                }

                $options = array(
                    'annual'      => __( 'Annual Report', 'governdocs-document-governance' ),
                    'financial'   => __( 'Financial Report', 'governdocs-document-governance' ),
                    'strategic'   => __( 'Strategic Report', 'governdocs-document-governance' ),
                    'compliance'  => __( 'Compliance Report', 'governdocs-document-governance' ),
                    'performance' => __( 'Performance Report', 'governdocs-document-governance' ),
                    'general'     => __( 'General Report', 'governdocs-document-governance' ),
                );

                return isset( $options[ $type ] ) ? (string) $options[ $type ] : $type;

            case 'report_id':
                return (string) get_post_meta( $post->ID, 'governdocs_report_id', true );

            case 'status':
                $status = strtolower( (string) get_post_meta( $post->ID, 'governdocs_status', true ) );

                $options = array(
                    'draft'    => __( 'Draft', 'governdocs-document-governance' ),
                    'review'   => __( 'Under review', 'governdocs-document-governance' ),
                    'approved' => __( 'Approved', 'governdocs-document-governance' ),
                    'archived' => __( 'Archived', 'governdocs-document-governance' ),
                );

                return isset( $options[ $status ] ) ? (string) $options[ $status ] : $status;

            case 'owner':
                return (string) get_post_meta( $post->ID, 'governdocs_owner_text', true );

            case 'report_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill(
                    $post->ID,
                    'governdocs_report_date',
                    ''
                );
            case 'published_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill(
                    $post->ID,
                    'governdocs_published_date',
                    ''
                );
        }

        return '';
    }
}