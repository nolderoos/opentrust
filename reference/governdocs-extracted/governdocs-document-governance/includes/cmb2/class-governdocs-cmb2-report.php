<?php
namespace GovernDocs\CMB2\Report;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_CMB2_Report {

    public function hooks() : void {
        add_action( 'cmb2_admin_init', array( $this, 'register_report_metaboxes' ) );
    }

    public function register_report_metaboxes() : void {
        if ( ! function_exists( 'new_cmb2_box' ) ) {
            return;
        }

        $this->metabox_report_core();
        $this->metabox_report_description();
        $this->metabox_report_pro_upsell();
    }

    /* -------------------------------------------------------------------------
     * Options
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

    private function default_report_type_options() : array {
        return array(
            'annual'      => __( 'Annual Report', 'governdocs-document-governance' ),
            'financial'   => __( 'Financial Report', 'governdocs-document-governance' ),
            'strategic'   => __( 'Strategic Report', 'governdocs-document-governance' ),
            'compliance'  => __( 'Compliance Report', 'governdocs-document-governance' ),
            'performance' => __( 'Performance Report', 'governdocs-document-governance' ),
            'general'     => __( 'General Report', 'governdocs-document-governance' ),
        );
    }

    private function get_report_type_options() : array {
        return $this->default_report_type_options();
    }

    public function render_status_column_badge( $field_args, $field ) {
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

    private function metabox_report_core() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_report_core',
            'title'        => __( 'Report Details', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_report' ),
            'context'      => 'normal',
            'priority'     => 'high',
            'show_names'   => true,
        ) );

        $box->add_field( array(
            'name'         => '',
            'id'           => 'governdocs_primary_file',
            'type'         => 'file',
            'options'      => array( 'url' => false ),
            'preview_size' => array( 60, 60 ),
            'text'         => array(
                'add_upload_file_text' => __( 'Add File', 'governdocs-document-governance' ),
            ),
            'description'  => '',
            'before_row'   => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'    => '</div></div>',
            'classes'      => 'governdocs-section governdocs-section-doc',
            'column'       => array(
                'position' => 6,
                'name'     => __( 'File', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'       => __( 'Report Type', 'governdocs-document-governance' ),
            'id'         => 'governdocs_report_type',
            'type'       => 'select',
            'options'    => $this->get_report_type_options(),
            'default'    => 'general',
            'before_row' => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'  => '</div>',
            'column'     => array(
                'position' => 3,
                'name'     => __( 'Type', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Report ID', 'governdocs-document-governance' ),
            'id'          => 'governdocs_report_id',
            'type'        => 'text',
            'description' => __( 'Internal identifier.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => 'REP-001',
            ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
            'column'      => array(
                'position' => 2,
                'name'     => __( 'ID', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
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
        ) );

        $box->add_field( array(
            'name'        => __( 'Author', 'governdocs-document-governance' ),
            'id'          => 'governdocs_owner_text',
            'type'        => 'text',
            'description' => __( 'Author or owner of report.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => __( 'Governance Team', 'governdocs-document-governance' ),
            ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div>',
            'column'      => array(
                'position' => 5,
                'name'     => __( 'Author', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Published Date', 'governdocs-document-governance' ),
            'id'          => 'governdocs_published_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
            'description' => __( 'Published date.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Report Date', 'governdocs-document-governance' ),
            'id'          => 'governdocs_report_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
            'description' => __( 'Main date shown for the report.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div></div>',
            'column'      => array(
                'position' => 4,
                'name'     => __( 'Date', 'governdocs-document-governance' ),
            ),
        ) );

    }

    private function metabox_report_description() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_report_description',
            'title'        => __( 'Description', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_report' ),
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
            'description' => __( 'Description of the report that is displayed on the frontend.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div></div>',
        ) );

    }

    private function metabox_report_pro_upsell() : void {
        if ( apply_filters( 'governdocs_is_pro_active', false ) ) {
            return;
        }

        $box = new_cmb2_box( array(
            'id'           => 'governdocs_report_pro_upsell',
            'title'        => __( 'Supporting Docs, Previous Versions, Audit Log', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_report' ),
            'context'      => 'normal',
            'priority'     => 'low',
            'show_names'   => false,
        ) );

        $upgrade_url = (string) apply_filters( 'governdocs_pro_upgrade_url', 'https://governdocs.com/pricing' );

        $box->add_field( array(
            'id'            => 'governdocs_report_pro_upsell_field',
            'type'          => 'title',
            'render_row_cb' => function() use ( $upgrade_url ) : void {

                echo '<div class="governdocs-pro-upsell" style="padding:12px 10px;">';

                echo '<p style="margin:0 0 8px 0;">' . esc_html__( 'Upgrade to GovernDocs PRO to unlock:', 'governdocs-document-governance' ) . '</p>';

                echo '<ul style="margin:0 0 10px 18px;">';
                echo '<li>' . esc_html__( 'Supporting Docs: attach appendices, schedules, and reference files.', 'governdocs-document-governance' ) . '</li>';
                echo '<li>' . esc_html__( 'Audit Log: track who changed what and when.', 'governdocs-document-governance' ) . '</li>';
                echo '</ul>';

                echo '<p style="margin:0;">';
                echo '<a class="button button-primary" href="' . esc_url( $upgrade_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade to PRO', 'governdocs-document-governance' ) . '</a>';
                echo '</p>';

                echo '</div>';
            },
        ) );
    }
}