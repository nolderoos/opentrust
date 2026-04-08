<?php
/**
 * Custom Post Type registration and meta box management.
 */

declare(strict_types=1);

final class OpenTrust_CPT {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('init', [self::class, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);

        // Admin columns.
        add_filter('manage_ot_certification_posts_columns', [$this, 'cert_columns']);
        add_action('manage_ot_certification_posts_custom_column', [$this, 'cert_column_content'], 10, 2);
        add_filter('manage_ot_policy_posts_columns', [$this, 'policy_columns']);
        add_action('manage_ot_policy_posts_custom_column', [$this, 'policy_column_content'], 10, 2);
        add_filter('manage_ot_subprocessor_posts_columns', [$this, 'sub_columns']);
        add_action('manage_ot_subprocessor_posts_custom_column', [$this, 'sub_column_content'], 10, 2);
        add_filter('manage_ot_data_practice_posts_columns', [$this, 'dp_columns']);
        add_action('manage_ot_data_practice_posts_custom_column', [$this, 'dp_column_content'], 10, 2);
    }

    // ──────────────────────────────────────────────
    // CPT Registration
    // ──────────────────────────────────────────────

    public static function register_post_types(): void {
        // ── Policies ──
        register_post_type('ot_policy', [
            'labels' => [
                'name'               => __('Policies', 'opentrust'),
                'singular_name'      => __('Policy', 'opentrust'),
                'add_new'            => __('Add Policy', 'opentrust'),
                'add_new_item'       => __('Add New Policy', 'opentrust'),
                'edit_item'          => __('Edit Policy', 'opentrust'),
                'new_item'           => __('New Policy', 'opentrust'),
                'view_item'          => __('View Policy', 'opentrust'),
                'search_items'       => __('Search Policies', 'opentrust'),
                'not_found'          => __('No policies found.', 'opentrust'),
                'not_found_in_trash' => __('No policies in trash.', 'opentrust'),
                'all_items'          => __('Policies', 'opentrust'),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'opentrust',
            'show_in_rest'  => true,
            'supports'      => ['title', 'editor', 'revisions', 'excerpt'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-media-document',
            'menu_position' => 31,
        ]);

        // ── Certifications ──
        register_post_type('ot_certification', [
            'labels' => [
                'name'               => __('Certifications', 'opentrust'),
                'singular_name'      => __('Certification', 'opentrust'),
                'add_new'            => __('Add Certification', 'opentrust'),
                'add_new_item'       => __('Add New Certification', 'opentrust'),
                'edit_item'          => __('Edit Certification', 'opentrust'),
                'new_item'           => __('New Certification', 'opentrust'),
                'search_items'       => __('Search Certifications', 'opentrust'),
                'not_found'          => __('No certifications found.', 'opentrust'),
                'not_found_in_trash' => __('No certifications in trash.', 'opentrust'),
                'all_items'          => __('Certifications', 'opentrust'),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'opentrust',
            'show_in_rest'  => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-awards',
            'menu_position' => 32,
        ]);

        // ── Subprocessors ──
        register_post_type('ot_subprocessor', [
            'labels' => [
                'name'               => __('Subprocessors', 'opentrust'),
                'singular_name'      => __('Subprocessor', 'opentrust'),
                'add_new'            => __('Add Subprocessor', 'opentrust'),
                'add_new_item'       => __('Add New Subprocessor', 'opentrust'),
                'edit_item'          => __('Edit Subprocessor', 'opentrust'),
                'new_item'           => __('New Subprocessor', 'opentrust'),
                'search_items'       => __('Search Subprocessors', 'opentrust'),
                'not_found'          => __('No subprocessors found.', 'opentrust'),
                'not_found_in_trash' => __('No subprocessors in trash.', 'opentrust'),
                'all_items'          => __('Subprocessors', 'opentrust'),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'opentrust',
            'show_in_rest'  => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-networking',
            'menu_position' => 33,
        ]);

        // ── Data Practices ──
        register_post_type('ot_data_practice', [
            'labels' => [
                'name'               => __('Data Practices', 'opentrust'),
                'singular_name'      => __('Data Practice', 'opentrust'),
                'add_new'            => __('Add Data Practice', 'opentrust'),
                'add_new_item'       => __('Add New Data Practice', 'opentrust'),
                'edit_item'          => __('Edit Data Practice', 'opentrust'),
                'new_item'           => __('New Data Practice', 'opentrust'),
                'search_items'       => __('Search Data Practices', 'opentrust'),
                'not_found'          => __('No data practices found.', 'opentrust'),
                'not_found_in_trash' => __('No data practices in trash.', 'opentrust'),
                'all_items'          => __('Data Practices', 'opentrust'),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'opentrust',
            'show_in_rest'  => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-database',
            'menu_position' => 34,
        ]);
    }

    // ──────────────────────────────────────────────
    // Meta Boxes
    // ──────────────────────────────────────────────

    public function add_meta_boxes(): void {
        add_meta_box('ot_cert_details', __('Certification Details', 'opentrust'), [$this, 'render_cert_meta_box'], 'ot_certification', 'normal', 'high');
        add_meta_box('ot_policy_details', __('Policy Details', 'opentrust'), [$this, 'render_policy_meta_box'], 'ot_policy', 'side', 'high');
        add_meta_box('ot_sub_details', __('Subprocessor Details', 'opentrust'), [$this, 'render_sub_meta_box'], 'ot_subprocessor', 'normal', 'high');
        add_meta_box('ot_dp_details', __('Data Practice Details', 'opentrust'), [$this, 'render_dp_meta_box'], 'ot_data_practice', 'normal', 'high');
    }

    // ── Certification meta box ──

    public function render_cert_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_cert', 'opentrust_cert_nonce');

        $issuing_body = get_post_meta($post->ID, '_ot_cert_issuing_body', true) ?: '';
        $status       = get_post_meta($post->ID, '_ot_cert_status', true) ?: 'active';
        $issue_date   = get_post_meta($post->ID, '_ot_cert_issue_date', true) ?: '';
        $expiry_date  = get_post_meta($post->ID, '_ot_cert_expiry_date', true) ?: '';
        $badge_id     = (int) get_post_meta($post->ID, '_ot_cert_badge_id', true);
        $badge_url    = $badge_id ? wp_get_attachment_image_url($badge_id, 'thumbnail') : '';
        $description  = get_post_meta($post->ID, '_ot_cert_description', true) ?: '';

        $statuses = [
            'active'      => __('Active', 'opentrust'),
            'in_progress' => __('In Progress', 'opentrust'),
            'expired'     => __('Expired', 'opentrust'),
        ];
        ?>
        <div class="ot-meta-field">
            <label for="ot_cert_issuing_body"><?php esc_html_e('Issuing Body', 'opentrust'); ?></label>
            <input type="text" id="ot_cert_issuing_body" name="ot_cert_issuing_body" value="<?php echo esc_attr($issuing_body); ?>" placeholder="<?php esc_attr_e('e.g., AICPA, ISO, GDPR', 'opentrust'); ?>">
        </div>

        <div class="ot-meta-field">
            <label for="ot_cert_status"><?php esc_html_e('Status', 'opentrust'); ?></label>
            <select id="ot_cert_status" name="ot_cert_status">
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ot-meta-field">
            <label for="ot_cert_issue_date"><?php esc_html_e('Issue Date', 'opentrust'); ?></label>
            <input type="date" id="ot_cert_issue_date" name="ot_cert_issue_date" value="<?php echo esc_attr($issue_date); ?>">
        </div>

        <div class="ot-meta-field">
            <label for="ot_cert_expiry_date"><?php esc_html_e('Expiry Date', 'opentrust'); ?></label>
            <input type="date" id="ot_cert_expiry_date" name="ot_cert_expiry_date" value="<?php echo esc_attr($expiry_date); ?>">
        </div>

        <div class="ot-meta-field">
            <label><?php esc_html_e('Badge Image', 'opentrust'); ?></label>
            <img class="ot-badge-preview" src="<?php echo esc_url($badge_url); ?>" alt="" <?php echo $badge_url ? '' : 'style="display:none"'; ?>>
            <input type="hidden" class="ot-badge-input" name="ot_cert_badge_id" value="<?php echo esc_attr((string) $badge_id); ?>">
            <button type="button" class="button ot-upload-badge"><?php esc_html_e('Select Badge', 'opentrust'); ?></button>
            <button type="button" class="button ot-remove-badge" <?php echo $badge_id ? '' : 'style="display:none"'; ?>><?php esc_html_e('Remove', 'opentrust'); ?></button>
        </div>

        <div class="ot-meta-field">
            <label for="ot_cert_description"><?php esc_html_e('Description', 'opentrust'); ?></label>
            <textarea id="ot_cert_description" name="ot_cert_description" rows="3"><?php echo esc_textarea($description); ?></textarea>
            <p class="description"><?php esc_html_e('Brief description of this certification and its scope.', 'opentrust'); ?></p>
        </div>
        <?php
    }

    // ── Policy meta box (sidebar) ──

    public function render_policy_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_policy', 'opentrust_policy_nonce');

        $category       = get_post_meta($post->ID, '_ot_policy_category', true) ?: 'other';
        $effective_date  = get_post_meta($post->ID, '_ot_policy_effective_date', true) ?: '';
        $review_date     = get_post_meta($post->ID, '_ot_policy_review_date', true) ?: '';
        $downloadable    = get_post_meta($post->ID, '_ot_policy_downloadable', true);
        $sort_order      = (int) get_post_meta($post->ID, '_ot_policy_sort_order', true);
        $version         = (int) get_post_meta($post->ID, '_ot_version', true) ?: 1;

        // Default downloadable to true for new posts.
        if ($downloadable === '') {
            $downloadable = true;
        }

        $categories = OpenTrust_Render::policy_category_labels();
        ?>
        <div class="ot-meta-field">
            <p style="font-size:24px;font-weight:700;margin:0 0 12px;color:#2563eb;">
                <?php printf(esc_html__('v%s', 'opentrust'), esc_html((string) $version)); ?>
            </p>
        </div>

        <div class="ot-meta-field">
            <label for="ot_policy_category"><?php esc_html_e('Category', 'opentrust'); ?></label>
            <select id="ot_policy_category" name="ot_policy_category" style="width:100%">
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($category, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ot-meta-field">
            <label for="ot_policy_effective_date"><?php esc_html_e('Effective Date', 'opentrust'); ?></label>
            <input type="date" id="ot_policy_effective_date" name="ot_policy_effective_date" value="<?php echo esc_attr($effective_date); ?>" style="width:100%">
        </div>

        <div class="ot-meta-field">
            <label for="ot_policy_review_date"><?php esc_html_e('Next Review Date', 'opentrust'); ?></label>
            <input type="date" id="ot_policy_review_date" name="ot_policy_review_date" value="<?php echo esc_attr($review_date); ?>" style="width:100%">
        </div>

        <div class="ot-meta-field">
            <label for="ot_policy_sort_order"><?php esc_html_e('Sort Order', 'opentrust'); ?></label>
            <input type="number" id="ot_policy_sort_order" name="ot_policy_sort_order" value="<?php echo esc_attr((string) $sort_order); ?>" min="0" step="1" style="width:100%">
            <p class="description"><?php esc_html_e('Lower numbers appear first.', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label>
                <input type="checkbox" name="ot_policy_downloadable" value="1" <?php checked($downloadable); ?>>
                <?php esc_html_e('Allow PDF download', 'opentrust'); ?>
            </label>
        </div>
        <?php
    }

    // ── Subprocessor meta box ──

    public function render_sub_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_sub', 'opentrust_sub_nonce');

        $purpose        = get_post_meta($post->ID, '_ot_sub_purpose', true) ?: '';
        $data_processed = get_post_meta($post->ID, '_ot_sub_data_processed', true) ?: '';
        $country        = get_post_meta($post->ID, '_ot_sub_country', true) ?: '';
        $website        = get_post_meta($post->ID, '_ot_sub_website', true) ?: '';
        $dpa_signed     = (bool) get_post_meta($post->ID, '_ot_sub_dpa_signed', true);
        ?>
        <div class="ot-meta-field">
            <label for="ot_sub_purpose"><?php esc_html_e('Purpose', 'opentrust'); ?></label>
            <textarea id="ot_sub_purpose" name="ot_sub_purpose" rows="2"><?php echo esc_textarea($purpose); ?></textarea>
            <p class="description"><?php esc_html_e('What does this subprocessor do for your company?', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ot_sub_data_processed"><?php esc_html_e('Data Processed', 'opentrust'); ?></label>
            <textarea id="ot_sub_data_processed" name="ot_sub_data_processed" rows="2"><?php echo esc_textarea($data_processed); ?></textarea>
            <p class="description"><?php esc_html_e('What types of data does this subprocessor handle?', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ot_sub_country"><?php esc_html_e('Country / Location', 'opentrust'); ?></label>
            <input type="text" id="ot_sub_country" name="ot_sub_country" value="<?php echo esc_attr($country); ?>" placeholder="<?php esc_attr_e('e.g., United States', 'opentrust'); ?>">
        </div>

        <div class="ot-meta-field">
            <label for="ot_sub_website"><?php esc_html_e('Website', 'opentrust'); ?></label>
            <input type="url" id="ot_sub_website" name="ot_sub_website" value="<?php echo esc_attr($website); ?>" placeholder="https://">
        </div>

        <div class="ot-meta-field">
            <label>
                <input type="checkbox" name="ot_sub_dpa_signed" value="1" <?php checked($dpa_signed); ?>>
                <?php esc_html_e('DPA Signed', 'opentrust'); ?>
            </label>
        </div>
        <?php
    }

    // ── Data Practice meta box ──

    public function render_dp_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_dp', 'opentrust_dp_nonce');

        $data_type        = get_post_meta($post->ID, '_ot_dp_data_type', true) ?: '';
        $purpose          = get_post_meta($post->ID, '_ot_dp_purpose', true) ?: '';
        $legal_basis      = get_post_meta($post->ID, '_ot_dp_legal_basis', true) ?: '';
        $retention_period = get_post_meta($post->ID, '_ot_dp_retention_period', true) ?: '';
        $shared_with      = get_post_meta($post->ID, '_ot_dp_shared_with', true) ?: '';
        $category         = get_post_meta($post->ID, '_ot_dp_category', true) ?: 'personal';

        $basis_options    = OpenTrust_Render::legal_basis_labels();
        $category_options = OpenTrust_Render::dp_category_labels();
        ?>
        <div class="ot-meta-field">
            <label for="ot_dp_category"><?php esc_html_e('Category', 'opentrust'); ?></label>
            <select id="ot_dp_category" name="ot_dp_category">
                <?php foreach ($category_options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($category, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ot-meta-field">
            <label for="ot_dp_data_type"><?php esc_html_e('Data Type', 'opentrust'); ?></label>
            <input type="text" id="ot_dp_data_type" name="ot_dp_data_type" value="<?php echo esc_attr($data_type); ?>" placeholder="<?php esc_attr_e('e.g., Email address, IP address', 'opentrust'); ?>">
        </div>

        <div class="ot-meta-field">
            <label for="ot_dp_purpose"><?php esc_html_e('Purpose', 'opentrust'); ?></label>
            <textarea id="ot_dp_purpose" name="ot_dp_purpose" rows="2"><?php echo esc_textarea($purpose); ?></textarea>
        </div>

        <div class="ot-meta-field">
            <label for="ot_dp_legal_basis"><?php esc_html_e('Legal Basis', 'opentrust'); ?></label>
            <select id="ot_dp_legal_basis" name="ot_dp_legal_basis">
                <option value=""><?php esc_html_e('— Select —', 'opentrust'); ?></option>
                <?php foreach ($basis_options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($legal_basis, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ot-meta-field">
            <label for="ot_dp_retention_period"><?php esc_html_e('Retention Period', 'opentrust'); ?></label>
            <input type="text" id="ot_dp_retention_period" name="ot_dp_retention_period" value="<?php echo esc_attr($retention_period); ?>" placeholder="<?php esc_attr_e('e.g., 30 days, Until account deletion', 'opentrust'); ?>">
        </div>

        <div class="ot-meta-field">
            <label for="ot_dp_shared_with"><?php esc_html_e('Shared With', 'opentrust'); ?></label>
            <textarea id="ot_dp_shared_with" name="ot_dp_shared_with" rows="2"><?php echo esc_textarea($shared_with); ?></textarea>
            <p class="description"><?php esc_html_e('Third parties this data is shared with.', 'opentrust'); ?></p>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // Save Meta
    // ──────────────────────────────────────────────

    public function save_meta(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        match ($post->post_type) {
            'ot_certification' => $this->save_cert_meta($post_id),
            'ot_policy'        => $this->save_policy_meta($post_id),
            'ot_subprocessor'  => $this->save_sub_meta($post_id),
            'ot_data_practice' => $this->save_dp_meta($post_id),
            default            => null,
        };
    }

    private function save_cert_meta(int $post_id): void {
        if (!isset($_POST['opentrust_cert_nonce']) || !wp_verify_nonce($_POST['opentrust_cert_nonce'], 'opentrust_save_cert')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_ot_cert_issuing_body', sanitize_text_field($_POST['ot_cert_issuing_body'] ?? ''));

        $valid_statuses = ['active', 'in_progress', 'expired'];
        $status = $_POST['ot_cert_status'] ?? 'active';
        update_post_meta($post_id, '_ot_cert_status', in_array($status, $valid_statuses, true) ? $status : 'active');

        update_post_meta($post_id, '_ot_cert_issue_date', sanitize_text_field($_POST['ot_cert_issue_date'] ?? ''));
        update_post_meta($post_id, '_ot_cert_expiry_date', sanitize_text_field($_POST['ot_cert_expiry_date'] ?? ''));
        update_post_meta($post_id, '_ot_cert_badge_id', absint($_POST['ot_cert_badge_id'] ?? 0));
        update_post_meta($post_id, '_ot_cert_description', sanitize_textarea_field($_POST['ot_cert_description'] ?? ''));
    }

    private function save_policy_meta(int $post_id): void {
        if (!isset($_POST['opentrust_policy_nonce']) || !wp_verify_nonce($_POST['opentrust_policy_nonce'], 'opentrust_save_policy')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $valid_categories = ['security', 'privacy', 'compliance', 'operational', 'other'];
        $category = $_POST['ot_policy_category'] ?? 'other';
        update_post_meta($post_id, '_ot_policy_category', in_array($category, $valid_categories, true) ? $category : 'other');

        update_post_meta($post_id, '_ot_policy_effective_date', sanitize_text_field($_POST['ot_policy_effective_date'] ?? ''));
        update_post_meta($post_id, '_ot_policy_review_date', sanitize_text_field($_POST['ot_policy_review_date'] ?? ''));
        update_post_meta($post_id, '_ot_policy_downloadable', !empty($_POST['ot_policy_downloadable']));
        update_post_meta($post_id, '_ot_policy_sort_order', absint($_POST['ot_policy_sort_order'] ?? 0));
    }

    private function save_sub_meta(int $post_id): void {
        if (!isset($_POST['opentrust_sub_nonce']) || !wp_verify_nonce($_POST['opentrust_sub_nonce'], 'opentrust_save_sub')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_ot_sub_purpose', sanitize_textarea_field($_POST['ot_sub_purpose'] ?? ''));
        update_post_meta($post_id, '_ot_sub_data_processed', sanitize_textarea_field($_POST['ot_sub_data_processed'] ?? ''));
        update_post_meta($post_id, '_ot_sub_country', sanitize_text_field($_POST['ot_sub_country'] ?? ''));
        update_post_meta($post_id, '_ot_sub_website', esc_url_raw($_POST['ot_sub_website'] ?? ''));
        update_post_meta($post_id, '_ot_sub_dpa_signed', !empty($_POST['ot_sub_dpa_signed']));
    }

    private function save_dp_meta(int $post_id): void {
        if (!isset($_POST['opentrust_dp_nonce']) || !wp_verify_nonce($_POST['opentrust_dp_nonce'], 'opentrust_save_dp')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $valid_categories = ['personal', 'sensitive', 'usage', 'technical', 'financial'];
        $category = $_POST['ot_dp_category'] ?? 'personal';
        update_post_meta($post_id, '_ot_dp_category', in_array($category, $valid_categories, true) ? $category : 'personal');

        update_post_meta($post_id, '_ot_dp_data_type', sanitize_text_field($_POST['ot_dp_data_type'] ?? ''));
        update_post_meta($post_id, '_ot_dp_purpose', sanitize_textarea_field($_POST['ot_dp_purpose'] ?? ''));

        $valid_bases = ['consent', 'contract', 'legitimate_interest', 'legal_obligation', 'vital_interest', 'public_interest'];
        $basis = $_POST['ot_dp_legal_basis'] ?? '';
        update_post_meta($post_id, '_ot_dp_legal_basis', in_array($basis, $valid_bases, true) ? $basis : '');

        update_post_meta($post_id, '_ot_dp_retention_period', sanitize_text_field($_POST['ot_dp_retention_period'] ?? ''));
        update_post_meta($post_id, '_ot_dp_shared_with', sanitize_textarea_field($_POST['ot_dp_shared_with'] ?? ''));
    }

    // ──────────────────────────────────────────────
    // Admin Columns
    // ──────────────────────────────────────────────

    // Certifications
    public function cert_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ot_issuing_body'] = __('Issuing Body', 'opentrust');
        $new['ot_status']       = __('Status', 'opentrust');
        $new['ot_expiry']       = __('Expiry Date', 'opentrust');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function cert_column_content(string $column, int $post_id): void {
        match ($column) {
            'ot_issuing_body' => print(esc_html(get_post_meta($post_id, '_ot_cert_issuing_body', true) ?: '—')),
            'ot_status'       => printf(
                '<span class="ot-pill ot-pill--%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;%2$s">%3$s</span>',
                esc_attr(get_post_meta($post_id, '_ot_cert_status', true) ?: 'active'),
                match (get_post_meta($post_id, '_ot_cert_status', true) ?: 'active') {
                    'active'      => 'background:#dcfce7;color:#166534',
                    'in_progress' => 'background:#fef9c3;color:#854d0e',
                    'expired'     => 'background:#f3f4f6;color:#6b7280',
                    default       => '',
                },
                esc_html(OpenTrust_Render::cert_status_labels()[get_post_meta($post_id, '_ot_cert_status', true) ?: 'active'] ?? '')
            ),
            'ot_expiry'       => print(esc_html(get_post_meta($post_id, '_ot_cert_expiry_date', true) ?: '—')),
            default           => null,
        };
    }

    // Policies
    public function policy_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ot_category'] = __('Category', 'opentrust');
        $new['ot_version']  = __('Version', 'opentrust');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function policy_column_content(string $column, int $post_id): void {
        match ($column) {
            'ot_category' => print(esc_html(OpenTrust_Render::policy_category_labels()[get_post_meta($post_id, '_ot_policy_category', true) ?: 'other'] ?? '')),
            'ot_version'  => printf('<span class="ot-version-badge">v%s</span>', esc_html((string) ((int) get_post_meta($post_id, '_ot_version', true) ?: 1))),
            default       => null,
        };
    }

    // Subprocessors
    public function sub_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ot_purpose'] = __('Purpose', 'opentrust');
        $new['ot_country'] = __('Location', 'opentrust');
        $new['ot_dpa']     = __('DPA', 'opentrust');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function sub_column_content(string $column, int $post_id): void {
        match ($column) {
            'ot_purpose' => print(esc_html(wp_trim_words(get_post_meta($post_id, '_ot_sub_purpose', true) ?: '', 10))),
            'ot_country' => print(esc_html(get_post_meta($post_id, '_ot_sub_country', true) ?: '—')),
            'ot_dpa'     => print((bool) get_post_meta($post_id, '_ot_sub_dpa_signed', true) ? '<span style="color:#16a34a">&#10003;</span>' : '—'),
            default      => null,
        };
    }

    // Data Practices
    public function dp_columns(array $columns): array {
        $new = [];
        $new['cb']    = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['ot_dp_category'] = __('Category', 'opentrust');
        $new['ot_dp_basis']    = __('Legal Basis', 'opentrust');
        $new['ot_dp_retention'] = __('Retention', 'opentrust');
        $new['date']  = $columns['date'];
        return $new;
    }

    public function dp_column_content(string $column, int $post_id): void {
        match ($column) {
            'ot_dp_category'  => print(esc_html(OpenTrust_Render::dp_category_labels()[get_post_meta($post_id, '_ot_dp_category', true) ?: 'personal'] ?? '')),
            'ot_dp_basis'     => print(esc_html(OpenTrust_Render::legal_basis_labels()[get_post_meta($post_id, '_ot_dp_legal_basis', true) ?: ''] ?? '—')),
            'ot_dp_retention' => print(esc_html(get_post_meta($post_id, '_ot_dp_retention_period', true) ?: '—')),
            default           => null,
        };
    }
}
