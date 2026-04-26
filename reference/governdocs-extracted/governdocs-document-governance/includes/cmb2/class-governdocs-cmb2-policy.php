<?php
namespace GovernDocs\CMB2\Policy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_CMB2_Policy {

    public function hooks() : void {
        add_action( 'cmb2_admin_init', array( $this, 'register_policy_metaboxes' ) );
    }

    public function register_policy_metaboxes() : void {
        if ( ! function_exists( 'new_cmb2_box' ) ) {
            return;
        }

        $this->metabox_policy_core();
        $this->metabox_policy_description();

        $this->metabox_policy_compliance();
        $this->metabox_policy_records();
        $this->metabox_policy_pro_upsell();
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
        $options = $this->default_status_options();

        /**
         * Filters the available GovernDocs status options.
         *
         * Allows to add, remove, or rename statuses.
         *
         * @param array $options Status options in key => label format.
         */
        $options = apply_filters( 'governdocs_status_options', $options );

        if ( ! is_array( $options ) ) {
            return $this->default_status_options();
        }

        $sanitized = array();

        foreach ( $options as $key => $label ) {
            $key   = sanitize_key( $key );
            $label = is_string( $label ) ? trim( $label ) : '';

            if ( '' === $key || '' === $label ) {
                continue;
            }

            $sanitized[ $key ] = $label;
        }

        if ( empty( $sanitized ) ) {
            return $this->default_status_options();
        }

        return $sanitized;
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

    private function metabox_policy_core() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_policy_core',
            'title'        => __( 'Policy Details', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_policy' ),
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
            'text'         => array( 'add_upload_file_text' => __( 'Add File', 'governdocs-document-governance' ) ),
            'description'  => '',
            'before_row'   => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'    => '</div></div>',
            'classes'      => 'governdocs-section governdocs-section-doc',
            'column'       => array(
                'position' => 7,
                'name'     => __( 'File', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Current Version', 'governdocs-document-governance' ),
            'id'          => 'governdocs_version',
            'type'        => 'text',
            'default'     => '1.0',
            'description' => __( 'Current policy version number.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => '1.0',
            ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div>',
            'column'      => array(
                'position' => 2,
                'name'     => __( 'Version', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Policy ID', 'governdocs-document-governance' ),
            'id'          => 'governdocs_policy_id',
            'type'        => 'text',
            'description' => __( 'Internal identifier.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => 'POL-001',
            ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
            'column'      => array(
                'position' => 3,
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
                'position' => 8,
            ),
            'display_cb' => array( $this, 'render_status_column_badge' ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Policy Owner', 'governdocs-document-governance' ),
            'id'          => 'governdocs_owner_text',
            'type'        => 'text',
            'description' => __( 'Who owns the policy.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => __( 'Governance Team', 'governdocs-document-governance' ),
            ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div>',
            'column'      => array(
                'position' => 6,
                'name'     => __( 'Owner', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Responsible Role', 'governdocs-document-governance' ),
            'id'          => 'governdocs_responsible_role',
            'type'        => 'text',
            'description' => __( 'The role responsible for this policy.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => __( 'Compliance Manager', 'governdocs-document-governance' ),
            ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Approving Authority', 'governdocs-document-governance' ),
            'id'          => 'governdocs_approving_authority',
            'type'        => 'text',
            'description' => __( 'Role or person approving.', 'governdocs-document-governance' ),
            'attributes'  => array(
                'placeholder' => __( 'Board', 'governdocs-document-governance' ),
            ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div></div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Effective Date', 'governdocs-document-governance' ),
            'id'          => 'governdocs_effective_date',
            'type'        => 'text_date',
            'description' => __( 'Date policy becomes effective.', 'governdocs-document-governance' ),
            'date_format' => 'Y-m-d',
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Approval Date', 'governdocs-document-governance' ),
            'id'          => 'governdocs_approval_date',
            'type'        => 'text_date',
            'description' => __( 'Date policy was approved.', 'governdocs-document-governance' ),
            'date_format' => 'Y-m-d',
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Review Before', 'governdocs-document-governance' ),
            'id'          => 'governdocs_review_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
            'description' => __( 'Next scheduled review date.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
            'column'      => array(
                'position' => 5,
                'name'     => __( 'Review Before', 'governdocs-document-governance' ),
            ),
        ) );

        $box->add_field( array(
            'name'        => __( 'Last Reviewed', 'governdocs-document-governance' ),
            'id'          => 'governdocs_last_reviewed_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
            'description' => __( 'Date of last policy review.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div></div>',
            'column'      => array(
                'position' => 6,
                'name'     => __( 'Last Review', 'governdocs-document-governance' ),
            ),
        ) );

    }

    private function metabox_policy_description() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_policy_description',
            'title'        => __( 'Description', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_policy' ),
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
            'description' => __( 'Description of the policy that is displayed on the frontend.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div></div>',
        ) );

    }

    private function metabox_policy_compliance() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_policy_compliance',
            'title'        => __( 'Compliance', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_policy' ),
            'context'      => 'normal',
            'priority'     => 'default',
            'show_names'   => false,
        ) );

        $box->add_field( array(
            'name'        => __( 'Legal / Regulatory Citation', 'governdocs-document-governance' ),
            'id'          => 'governdocs_legal_citation',
            'type'        => 'text',
            'description' => __( 'Eg. GDPR Article 5, HIPAA, Local Government Act (Section X).', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'   => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Compliance Notes', 'governdocs-document-governance' ),
            'id'          => 'governdocs_compliance_notes',
            'type'        => 'textarea_small',
            'description' => __( 'Optional notes for auditors or internal governance teams.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div></div>',
        ) );
    }

    private function metabox_policy_records() : void {
        $box = new_cmb2_box( array(
            'id'           => 'governdocs_policy_records',
            'title'        => __( 'Records Management', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_policy' ),
            'context'      => 'normal',
            'priority'     => 'default',
            'show_names'   => true,
        ) );

        $box->add_field( array(
            'name'    => __( 'Classification', 'governdocs-document-governance' ),
            'id'      => 'governdocs_classification',
            'type'    => 'select',
            'options' => array(
                'public'       => __( 'Public', 'governdocs-document-governance' ),
                'internal'     => __( 'Internal', 'governdocs-document-governance' ),
                'confidential' => __( 'Confidential', 'governdocs-document-governance' ),
                'restricted'   => __( 'Restricted', 'governdocs-document-governance' ),
            ),
            'default'    => 'public',
            'before_row' => '<div class="governdocs-row"><div class="governdocs-col">',
            'after_row'  => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Retention Period (Years)', 'governdocs-document-governance' ),
            'id'          => 'governdocs_retention_years',
            'type'        => 'text_small',
            'attributes'  => array( 'type' => 'number', 'min' => '0', 'step' => '1' ),
            'description' => __( 'Eg. 7. Leave blank if not applicable.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div>',
        ) );

        $box->add_field( array(
            'name'        => __( 'Disposal Date', 'governdocs-document-governance' ),
            'id'          => 'governdocs_disposal_date',
            'type'        => 'text_date',
            'date_format' => 'Y-m-d',
            'description' => __( 'If a fixed disposal date applies.', 'governdocs-document-governance' ),
            'before_row'  => '<div class="governdocs-col">',
            'after_row'   => '</div></div>',
        ) );

        do_action( 'governdocs_cmb2_policy_records_fields', $box );
    }

    private function metabox_policy_pro_upsell() : void {
        if ( apply_filters( 'governdocs_is_pro_active', false ) ) {
            return;
        }

        $box = new_cmb2_box( array(
            'id'           => 'governdocs_policy_pro_upsell',
            'title'        => __( 'Supporting Docs, Previous Versions, Audit Log', 'governdocs-document-governance' ),
            'object_types' => array( 'governdocs_policy' ),
            'context'      => 'normal',
            'priority'     => 'low',
            'show_names'   => false,
        ) );

        $upgrade_url = (string) apply_filters( 'governdocs_pro_upgrade_url', 'https://governdocs.com/pricing' );

        $box->add_field( array(
            'id'            => 'governdocs_policy_pro_upsell_field',
            'type'          => 'title',
            'render_row_cb' => function() use ( $upgrade_url ) : void {

                echo '<div class="governdocs-pro-upsell" style="padding:12px 10px;">';

                echo '<p style="margin:0 0 8px 0;">' . esc_html__( 'Upgrade to GovernDocs PRO to unlock:', 'governdocs-document-governance' ) . '</p>';

                echo '<ul style="margin:0 0 10px 18px;">';
                echo '<li>' . esc_html__( 'Supporting Docs: attach appendices, forms, and reference files.', 'governdocs-document-governance' ) . '</li>';
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