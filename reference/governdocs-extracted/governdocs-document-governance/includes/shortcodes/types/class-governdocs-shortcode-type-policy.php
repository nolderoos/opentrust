<?php
namespace GovernDocs\Shortcodes\Types;

use GovernDocs\GovernDocs_Shortcode_Renderer;
use GovernDocs\Shortcodes\Contracts\GovernDocs_Shortcode_Type;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Shortcode_Type_Policy implements GovernDocs_Shortcode_Type {

    public function get_slug() : string {
        return 'policy';
    }

    public function get_aliases() : array {
        return array( 'policy', 'policies' );
    }

    public function get_post_type() : string {
        return 'governdocs_policy';
    }

    public function get_default_enabled_fields() : array {
        return array( 'ext', 'size', 'status' );
    }

    public function get_default_field_order() : array {
        return array( 'ext', 'size', 'status' );
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
                return GovernDocs_Shortcode_Renderer::label_for_post_type( $post_type );

            case 'status':
                $raw = get_post_meta( $post->ID, 'governdocs_status', true );
                $raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';

                if ( '' === $raw ) {
                    return '';
                }

                $defaults = array(
                    'draft'    => __( 'Draft', 'governdocs-document-governance' ),
                    'review'   => __( 'Under review', 'governdocs-document-governance' ),
                    'approved' => __( 'Approved', 'governdocs-document-governance' ),
                    'archived' => __( 'Archived', 'governdocs-document-governance' ),
                );

                $options = (array) apply_filters( 'governdocs_policy_status_options', $defaults );

                if ( isset( $options[ $raw ] ) && is_string( $options[ $raw ] ) ) {
                    return (string) $options[ $raw ];
                }

                return ucfirst( $raw );

            case 'version':
                $v = get_post_meta( $post->ID, 'governdocs_version', true );
                $v = is_string( $v ) ? trim( $v ) : '';

                return '' !== $v ? sprintf( 'v%s', $v ) : '';

            case 'effective_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill( $post->ID, 'governdocs_effective_date', __( 'Effective:', 'governdocs-document-governance' ) );

            case 'approval_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill( $post->ID, 'governdocs_approval_date', __( 'Approved:', 'governdocs-document-governance' ) );

            case 'review_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill( $post->ID, 'governdocs_review_date', __( 'Next Review:', 'governdocs-document-governance' ) );

            case 'last_reviewed_date':
                return GovernDocs_Shortcode_Renderer::format_meta_date_pill( $post->ID, 'governdocs_last_reviewed_date', __( 'Reviewed:', 'governdocs-document-governance' ) );

            case 'owner':
                $owner = get_post_meta( $post->ID, 'governdocs_owner_text', true );
                return is_string( $owner ) ? trim( $owner ) : '';

            case 'approving_authority':
                $value = get_post_meta( $post->ID, 'governdocs_approving_authority', true );
                return is_string( $value ) ? trim( $value ) : '';

            case 'policy_id':
                $value = get_post_meta( $post->ID, 'governdocs_policy_id', true );
                return is_string( $value ) ? trim( $value ) : '';
        }

        return '';
    }
}