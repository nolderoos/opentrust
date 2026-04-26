<?php
namespace GovernDocs\CPT;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once GOVERNDOCS_DIR . 'includes/cpt/class-governdocs-cpt-base.php';

class GovernDocs_CPT_Meeting extends GovernDocs_CPT_Base {

    public function hooks() : void {

        if ( \GovernDocs\GovernDocs_Admin_Menu::is_post_type_disabled( 'meetings' ) ) {
            return;
        }

        add_action( 'init', array( $this, 'register' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );

        add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
    }

    private function meetings_shortcode( int $post_id ) : string {
        return '[governdocs_meeting id="' . absint( $post_id ) . '"]';
    }

    public function updated_messages( array $messages ) : array {
        $post = get_post();

        if ( ! $post || 'governdocs_meeting' !== $post->post_type ) {
            return $messages;
        }

        $shortcode = $this->meetings_shortcode( (int) $post->ID );

        $shortcode_hint = sprintf(
            ' <span class="governdocs-shortcode-hint">%s</span>',
            sprintf(
                /* translators: %s: shortcode */
                esc_html__( 'Display this meeting using the shortcode: %s', 'governdocs-document-governance' ),
                $shortcode
            )
        );

        $revision_id = filter_input( INPUT_GET, 'revision', FILTER_SANITIZE_NUMBER_INT );
        $revision_id = $revision_id ? absint( $revision_id ) : 0;

        $messages['governdocs_meeting'] = array(
            0  => '',
            1  => esc_html__( 'Meeting updated.', 'governdocs-document-governance' ) . $shortcode_hint,
            2  => esc_html__( 'Custom field updated.', 'governdocs-document-governance' ),
            3  => esc_html__( 'Custom field deleted.', 'governdocs-document-governance' ),
            4  => esc_html__( 'Meeting updated.', 'governdocs-document-governance' ),
            5  => $revision_id
                ? sprintf(
                    /* translators: %s: revision title */
                    esc_html__( 'Meeting restored to revision from %s.', 'governdocs-document-governance' ),
                    wp_post_revision_title( $revision_id, false )
                )
                : false,
            6  => esc_html__( 'Meeting published.', 'governdocs-document-governance' ) . $shortcode_hint,
            7  => esc_html__( 'Meeting saved.', 'governdocs-document-governance' ) . $shortcode_hint,
            8  => esc_html__( 'Meeting submitted.', 'governdocs-document-governance' ) . $shortcode_hint,
            9  => sprintf(
                /* translators: %s: scheduled date */
                esc_html__( 'Meeting scheduled for: %s.', 'governdocs-document-governance' ),
                '<strong>' . esc_html( wp_date( 'M j, Y @ G:i', strtotime( $post->post_date ) ) ) . '</strong>'
            ) . $shortcode_hint,
            10 => esc_html__( 'Meeting draft updated.', 'governdocs-document-governance' ) . $shortcode_hint,
        );

        return $messages;
    }

    public function register() : void {
        $args = $this->common_args(
            'governdocs_meeting',
            __( 'Meeting', 'governdocs-document-governance' ),
            __( 'Meetings', 'governdocs-document-governance' ),
            'dashicons-clipboard'
        );

        $args['supports']            = array( 'title' );
        $args['rewrite']             = false;
        $args['has_archive']         = false;
        $args['publicly_queryable']  = false;
        $args['exclude_from_search'] = true;
        $args['query_var']           = false;

        register_post_type( 'governdocs_meeting', $args );
    }

    public function register_taxonomies() : void {
        $post_types = array( 'governdocs_meeting' );

        register_taxonomy(
            'governdocs_meeting_department',
            $post_types,
            array(
                'labels' => array(
                    'name'                       => __( 'Departments', 'governdocs-document-governance' ),
                    'singular_name'              => __( 'Department', 'governdocs-document-governance' ),
                    'search_items'               => __( 'Search Departments', 'governdocs-document-governance' ),
                    'popular_items'              => __( 'Popular Departments', 'governdocs-document-governance' ),
                    'all_items'                  => __( 'All Departments', 'governdocs-document-governance' ),
                    'parent_item'                => __( 'Parent Department', 'governdocs-document-governance' ),
                    'parent_item_colon'          => __( 'Parent Department:', 'governdocs-document-governance' ),
                    'edit_item'                  => __( 'Edit Department', 'governdocs-document-governance' ),
                    'view_item'                  => __( 'View Department', 'governdocs-document-governance' ),
                    'update_item'                => __( 'Update Department', 'governdocs-document-governance' ),
                    'add_new_item'               => __( 'Add New Department', 'governdocs-document-governance' ),
                    'new_item_name'              => __( 'New Department Name', 'governdocs-document-governance' ),
                    'separate_items_with_commas' => __( 'Separate departments with commas', 'governdocs-document-governance' ),
                    'add_or_remove_items'        => __( 'Add or remove departments', 'governdocs-document-governance' ),
                    'choose_from_most_used'      => __( 'Choose from the most used departments', 'governdocs-document-governance' ),
                    'not_found'                  => __( 'No departments found.', 'governdocs-document-governance' ),
                    'menu_name'                  => __( 'Departments', 'governdocs-document-governance' ),
                ),
                'public'            => true,
                'hierarchical'      => true,
                'show_in_rest'      => true,
                'show_admin_column' => true,
                'rewrite'           => array( 'slug' => 'meeting-department' ),
            )
        );
    }
}