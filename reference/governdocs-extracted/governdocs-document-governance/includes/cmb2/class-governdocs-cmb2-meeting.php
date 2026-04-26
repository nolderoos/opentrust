<?php
namespace GovernDocs\CMB2\Meeting;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_CMB2_Meeting {

    public function hooks() : void {
        add_action( 'cmb2_admin_init', array( $this, 'register_meeting_metaboxes' ) );
    }

    public function register_meeting_metaboxes() : void {
        if ( ! function_exists( 'new_cmb2_box' ) ) {
            return;
        }

        $this->metabox_meeting_core();
        $this->metabox_meeting_description();
        $this->metabox_meeting_agenda();
        $this->metabox_meeting_minutes();

        do_action( 'governdocs_cmb2_meeting_metaboxes', $this );
    }

    /* -------------------------------------------------------------------------
     * Status options
     * ---------------------------------------------------------------------- */

    private function default_status_options() : array {
        return array(
            'draft'    => __( 'Draft', 'governdocs-document-governance' ),
            'review'   => __( 'Under review', 'governdocs-document-governance' ),
            'approved' => __( 'Approved', 'governdocs-document-governance' ),
            'archived' => __( 'Archived', 'governdocs-document-governance' ),
        );
    }

    private function get_status_options() : array {
        return $this->default_status_options();
    }

    public function render_status_column_badge( $field_args, $field ) : void {
        $value = strtolower( (string) $field->escaped_value() );
        $opts  = $this->get_status_options();

        $label = isset( $opts[ $value ] ) ? (string) $opts[ $value ] : ucfirst( $value );

        echo '<span class="governdocs-status-badge governdocs-status-' . esc_attr( $value ) . '">';
        echo esc_html( $label );
        echo '</span>';
    }

    /* -------------------------------------------------------------------------
     * Metaboxes
     * ---------------------------------------------------------------------- */

    private function metabox_meeting_core() : void {
        $box = new_cmb2_box(
            array(
                'id'           => 'governdocs_meeting_core',
                'title'        => __( 'Meeting Details', 'governdocs-document-governance' ),
                'object_types' => array( 'governdocs_meeting' ),
                'context'      => 'normal',
                'priority'     => 'high',
                'show_names'   => true,
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Meeting Date', 'governdocs-document-governance' ),
                'id'          => 'governdocs_meeting_date',
                'type'        => 'text_date',
                'description' => __( 'Date of the meeting.', 'governdocs-document-governance' ),
                'date_format' => 'Y-m-d',
                'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
                'after_row'   => '</div>',
                'column'      => array(
                    'position' => 2,
                    'name'     => __( 'Date', 'governdocs-document-governance' ),
                ),
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Meeting ID', 'governdocs-document-governance' ),
                'id'          => 'governdocs_meeting_id',
                'type'        => 'text',
                'description' => __( 'Internal identifier.', 'governdocs-document-governance' ),
                'attributes'  => array(
                    'placeholder' => 'MTG-001',
                ),
                'before_row'  => '<div class="governdocs-col">',
                'after_row'   => '</div>',
                'column'      => array(
                    'position' => 3,
                    'name'     => __( 'ID', 'governdocs-document-governance' ),
                ),
            )
        );

        $box->add_field(
            array(
                'name'       => __( 'Status', 'governdocs-document-governance' ),
                'id'         => 'governdocs_status',
                'type'       => 'select',
                'options'    => $this->get_status_options(),
                'default'    => 'draft',
                'before_row' => '<div class="governdocs-col">',
                'after_row'  => '</div></div>',
                'column'     => array(
                    'position' => 7,
                ),
                'display_cb' => array( $this, 'render_status_column_badge' ),
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Chair', 'governdocs-document-governance' ),
                'id'          => 'governdocs_meeting_chair',
                'type'        => 'text',
                'description' => __( 'Chair or presiding officer.', 'governdocs-document-governance' ),
                'attributes'  => array(
                    'placeholder' => __( 'Mayor / Chairperson', 'governdocs-document-governance' ),
                ),
                'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
                'after_row'   => '</div>',
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Minute Taker', 'governdocs-document-governance' ),
                'id'          => 'governdocs_meeting_taker',
                'type'        => 'text',
                'description' => __( 'Meeting minute taker.', 'governdocs-document-governance' ),
                'attributes'  => array(
                    'placeholder' => __( 'Governance Officer', 'governdocs-document-governance' ),
                ),
                'before_row'  => '<div class="governdocs-col">',
                'after_row'   => '</div>',
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Location', 'governdocs-document-governance' ),
                'id'          => 'governdocs_meeting_location',
                'type'        => 'text',
                'description' => __( 'Optional meeting location.', 'governdocs-document-governance' ),
                'attributes'  => array(
                    'placeholder' => __( 'Council Chambers', 'governdocs-document-governance' ),
                ),
                'before_row'  => '<div class="governdocs-col">',
                'after_row'   => '</div></div>',
            )
        );

    }

    private function metabox_meeting_description() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_meeting_description',
            'title'        => __( 'Description', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_meeting' ),
            'context'      => 'normal',
            'priority'     => 'default',
            'show_names'   => false,
        ) );

        $box->add_field( array(
            'name'        => __( 'Description', 'governdocs-document-governance' ),
            'id'          => 'governdocs_description',
            'type'    => 'wysiwyg',
            'options' => array(
                'wpautop' => true, // use wpautop?
                'media_buttons' => false, // show insert/upload button(s)
                'textarea_rows' => 5, // rows="..."
                'teeny' => false, // output the minimal editor config used in Press This
                'dfw' => false, // replace the default fullscreen with DFW (needs specific css)
                'tinymce' => true, // load TinyMCE, can be used to pass settings directly to TinyMCE using an array()
                'quicktags' => true // load Quicktags, can be used to pass settings directly to Quicktags using an array()
            ),
            'description' => __( 'Description of the meeting that is displayed on the frontend.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div></div>',
        ) );

    }

    private function metabox_meeting_agenda() : void {
        $box = new_cmb2_box(
            array(
                'id'           => 'governdocs_meeting_agenda',
                'title'        => __( 'Agenda', 'governdocs-document-governance' ),
                'object_types' => array( 'governdocs_meeting' ),
                'context'      => 'normal',
                'priority'     => 'default',
                'show_names'   => true,
            )
        );

        $box->add_field(
            array(
                'name'         => '',
                'id'           => 'governdocs_agenda_file',
                'type'         => 'file',
                'options'      => array( 'url' => false ),
                'preview_size' => array( 60, 60 ),
                'text'         => array( 'add_upload_file_text' => __( 'Add Agenda File', 'governdocs-document-governance' ) ),
                'description'  => '',
                'before_row'   => '<div class="governdocs-row"><div class="governdocs-col">',
                'after_row'    => '</div></div>',
                'classes'      => 'governdocs-section governdocs-section-doc',
                'column'       => array(
                    'position' => 4,
                    'name'     => __( 'Agenda', 'governdocs-document-governance' ),
                ),
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Agenda Publish Date', 'governdocs-document-governance' ),
                'id'          => 'governdocs_agenda_publish_date',
                'type'        => 'text_date',
                'date_format' => 'Y-m-d',
                'description' => __( 'Optional date the agenda was published or released.', 'governdocs-document-governance' ),
                'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
                'after_row'   => '</div>',
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Agenda Notes', 'governdocs-document-governance' ),
                'id'          => 'governdocs_agenda_notes',
                'type'        => 'textarea_small',
                'description' => __( 'Optional notes about the agenda.', 'governdocs-document-governance' ),
                'before_row'  => '<div class="governdocs-col">',
                'after_row'   => '</div></div>',
            )
        );

        do_action( 'governdocs_cmb2_meeting_agenda_fields', $box );
    }

    private function metabox_meeting_minutes() : void {
        $box = new_cmb2_box(
            array(
                'id'           => 'governdocs_meeting_minutes',
                'title'        => __( 'Minutes', 'governdocs-document-governance' ),
                'object_types' => array( 'governdocs_meeting' ),
                'context'      => 'normal',
                'priority'     => 'default',
                'show_names'   => true,
            )
        );

        $box->add_field(
            array(
                'name'         => '',
                'id'           => 'governdocs_minutes_file',
                'type'         => 'file',
                'options'      => array( 'url' => false ),
                'preview_size' => array( 60, 60 ),
                'text'         => array( 'add_upload_file_text' => __( 'Add Minutes File', 'governdocs-document-governance' ) ),
                'description'  => '',
                'before_row'   => '<div class="governdocs-row"><div class="governdocs-col">',
                'after_row'    => '</div></div>',
                'column'       => array(
                    'position' => 5,
                    'name'     => __( 'Minutes', 'governdocs-document-governance' ),
                ),
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Minutes Approved', 'governdocs-document-governance' ),
                'id'          => 'governdocs_minutes_approval_date',
                'type'        => 'text_date',
                'date_format' => 'Y-m-d',
                'description' => __( 'Optional date the minutes were approved.', 'governdocs-document-governance' ),
                'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
                'after_row'   => '</div>',
            )
        );

        $box->add_field(
            array(
                'name'        => __( 'Minutes Notes', 'governdocs-document-governance' ),
                'id'          => 'governdocs_minutes_notes',
                'type'        => 'textarea_small',
                'description' => __( 'Optional notes about the minutes.', 'governdocs-document-governance' ),
                'before_row'  => '<div class="governdocs-col">',
                'after_row'   => '</div></div>',
            )
        );

        do_action( 'governdocs_cmb2_meeting_minutes_fields', $box );
    }
}