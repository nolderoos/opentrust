<?php
namespace GovernDocs\CPT;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class GovernDocs_CPT_Base {

    abstract public function hooks() : void;
    abstract public function register() : void;

    protected function common_supports() : array {
        return array( 'title', 'editor', 'excerpt', 'revisions', 'thumbnail' );
    }

    protected function common_args( string $post_type, string $singular, string $plural, string $menu_icon ) : array {
        $labels = array(
            'name'               => $plural,
            'singular_name'      => $singular,
            'add_new'            => __( 'Add New', 'governdocs-document-governance' ),

            /* translators: %s: singular post type label */
            'add_new_item'       => sprintf( __( 'Add New %s', 'governdocs-document-governance' ), $singular ),

            /* translators: %s: singular post type label */
            'edit_item'          => sprintf( __( 'Edit %s', 'governdocs-document-governance' ), $singular ),

            /* translators: %s: singular post type label */
            'new_item'           => sprintf( __( 'New %s', 'governdocs-document-governance' ), $singular ),

            /* translators: %s: singular post type label */
            'view_item'          => sprintf( __( 'View %s', 'governdocs-document-governance' ), $singular ),

            /* translators: %s: plural post type label */
            'search_items'       => sprintf( __( 'Search %s', 'governdocs-document-governance' ), $plural ),

            'not_found'          => __( 'Not found', 'governdocs-document-governance' ),
            'not_found_in_trash' => __( 'Not found in Trash', 'governdocs-document-governance' ),

            /* translators: %s: plural post type label */
            'all_items'          => sprintf( __( 'All %s', 'governdocs-document-governance' ), $plural ),
        );

        return array(
            'labels'       => $labels,
            'public'       => true,
            'show_in_rest' => true,
            'has_archive'  => true,
            'menu_icon'    => $menu_icon,
            'supports'     => $this->common_supports(),
            'rewrite'      => array( 'slug' => $post_type ),
            'show_in_menu' => 'governdocs-document-governance',
        );
    }
}
