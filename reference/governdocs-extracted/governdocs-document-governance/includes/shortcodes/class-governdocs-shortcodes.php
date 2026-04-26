<?php
namespace GovernDocs;

use GovernDocs\Shortcodes\Contracts\GovernDocs_Shortcode_Type;
use GovernDocs\Shortcodes\Types\GovernDocs_Shortcode_Type_Meeting;
use GovernDocs\Shortcodes\Types\GovernDocs_Shortcode_Type_Policy;
use GovernDocs\Shortcodes\Types\GovernDocs_Shortcode_Type_Report;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Shortcodes {

    /**
     * @var GovernDocs_Shortcode_Type[]
     */
    private $types = array();

    public function hooks() : void {
        $this->register_default_types();

        $this->types = apply_filters( 'governdocs_shortcode_types', $this->types );

        add_action( 'wp_enqueue_scripts', array( 'GovernDocs\GovernDocs_Shortcode_Renderer', 'register_styles' ) );

        add_shortcode( 'governdocs', array( $this, 'shortcode_doc' ) );
        add_shortcode( 'governdocs_policy', array( $this, 'shortcode_policy' ) );
        add_shortcode( 'governdocs_meeting', array( $this, 'shortcode_meeting' ) );
        add_shortcode( 'governdocs_report', array( $this, 'shortcode_report' ) );
    }

    private function register_default_types() : void {
        $this->register_type( new GovernDocs_Shortcode_Type_Policy() );
        $this->register_type( new GovernDocs_Shortcode_Type_Meeting() );
        $this->register_type( new GovernDocs_Shortcode_Type_Report() );
    }

    private function register_type( GovernDocs_Shortcode_Type $type ) : void {
        $this->types[ $type->get_slug() ] = $type;
    }

    public function shortcode_policy( $atts ) : string {
        $atts         = (array) $atts;
        $atts['type'] = 'policy';

        return $this->shortcode_doc( $atts );
    }

    public function shortcode_meeting( $atts ) : string {
        $atts         = (array) $atts;
        $atts['type'] = 'meeting';

        return $this->shortcode_doc( $atts );
    }

    public function shortcode_report( $atts ) : string {
        $atts         = (array) $atts;
        $atts['type'] = 'report';

        return $this->shortcode_doc( $atts );
    }

    public function shortcode_doc( $atts ) : string {
        $atts = shortcode_atts(
            array(
                'type'          => 'policy',
                'id'            => '',
                'slug'          => '',
                'display'       => 'card',
                'title'         => '',
                'show_icon'     => '',
                'button'        => '1',
                'class'         => '',
                'fields'        => '',
                'order'         => '',
                'desc_location' => '',
            ),
            (array) $atts,
            'governdocs'
        );

        $atts['display'] = strtolower( trim( (string) $atts['display'] ) );
        if ( ! in_array( $atts['display'], array( 'card', 'full' ), true ) ) {
            $atts['display'] = 'card';
        }

        if ( '' === $atts['show_icon'] ) {
            $atts['show_icon'] = ( 'full' === $atts['display'] ) ? '0' : '1';
        }

        $type_slug = strtolower( trim( (string) $atts['type'] ) );
        $type_obj  = $this->get_type_by_alias( $type_slug );

        if ( ! $type_obj ) {
            return '';
        }

        return GovernDocs_Shortcode_Renderer::render( $type_obj, $atts );
    }

    private function get_type_by_alias( string $type_slug ) : ?GovernDocs_Shortcode_Type {
        foreach ( $this->types as $type_obj ) {
            if ( $type_obj->get_slug() === $type_slug ) {
                return $type_obj;
            }

            if ( in_array( $type_slug, $type_obj->get_aliases(), true ) ) {
                return $type_obj;
            }
        }

        return null;
    }
}