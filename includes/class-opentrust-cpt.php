<?php
/**
 * Custom Post Type registration and meta box management.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenTrust_CPT {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('init', [self::class, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);
        add_action('admin_notices', [$this, 'render_broadcast_admin_notice']);

        // Admin columns.
        add_filter('manage_ot_certification_posts_columns', [$this, 'cert_columns']);
        add_action('manage_ot_certification_posts_custom_column', [$this, 'cert_column_content'], 10, 2);
        add_filter('manage_ot_policy_posts_columns', [$this, 'policy_columns']);
        add_action('manage_ot_policy_posts_custom_column', [$this, 'policy_column_content'], 10, 2);
        add_filter('manage_ot_subprocessor_posts_columns', [$this, 'sub_columns']);
        add_action('manage_ot_subprocessor_posts_custom_column', [$this, 'sub_column_content'], 10, 2);
        add_filter('manage_ot_data_practice_posts_columns', [$this, 'dp_columns']);
        add_action('manage_ot_data_practice_posts_custom_column', [$this, 'dp_column_content'], 10, 2);
        add_filter('manage_ot_faq_posts_columns', [$this, 'faq_columns']);
        add_action('manage_ot_faq_posts_custom_column', [$this, 'faq_column_content'], 10, 2);

        // Catalog-autofill title-field prompt for subprocessor / data-practice CPTs.
        add_filter('enter_title_here', [$this, 'filter_enter_title_here'], 10, 2);
    }

    /**
     * Replace the "Add title" prompt on subprocessor and data-practice new-post
     * screens so users know the title field is also a catalog lookup.
     */
    public function filter_enter_title_here(string $text, \WP_Post $post): string {
        if ($post->post_type === 'ot_subprocessor') {
            return __('Pick from the catalog or type your own, e.g. Datadog, Stripe, or AWS', 'opentrust');
        }
        if ($post->post_type === 'ot_data_practice') {
            return __('Pick from the catalog or type your own, e.g. Analytics or Transactional Email', 'opentrust');
        }
        if ($post->post_type === 'ot_certification') {
            return __('Pick from the catalog or type your own, e.g. SOC 2 Type II or ISO 27001', 'opentrust');
        }
        return $text;
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
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'opentrust',
            'show_in_rest'        => true,
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
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'opentrust',
            'show_in_rest'        => true,
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
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'opentrust',
            'show_in_rest'        => true,
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
            // public=>true is required so WPML and Polylang detect the post type
            // as translatable content and offer per-language variants in their UIs.
            // publicly_queryable + exclude_from_search + has_archive + rewrite are
            // all disabled so the CPTs never leak to the frontend — the trust
            // center stays the single rendering surface via template_redirect.
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'opentrust',
            'show_in_rest'        => true,
            'supports'      => ['title'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-database',
            'menu_position' => 34,
        ]);

        // ── FAQs ──
        register_post_type('ot_faq', [
            'labels' => [
                'name'               => __('FAQs', 'opentrust'),
                'singular_name'      => __('FAQ', 'opentrust'),
                'add_new'            => __('Add FAQ', 'opentrust'),
                'add_new_item'       => __('Add New FAQ', 'opentrust'),
                'edit_item'          => __('Edit FAQ', 'opentrust'),
                'new_item'           => __('New FAQ', 'opentrust'),
                'view_item'          => __('View FAQ', 'opentrust'),
                'search_items'       => __('Search FAQs', 'opentrust'),
                'not_found'          => __('No FAQs found.', 'opentrust'),
                'not_found_in_trash' => __('No FAQs in trash.', 'opentrust'),
                'all_items'          => __('FAQs', 'opentrust'),
            ],
            'public'              => true,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_ui'             => true,
            'show_in_menu'        => 'opentrust',
            'show_in_rest'        => true,
            'supports'      => ['title', 'editor', 'page-attributes'],
            'has_archive'   => false,
            'rewrite'       => false,
            'menu_icon'     => 'dashicons-format-chat',
            'menu_position' => 35,
        ]);
    }

    // ──────────────────────────────────────────────
    // Meta Boxes
    // ──────────────────────────────────────────────

    public function add_meta_boxes(): void {
        add_meta_box('ot_cert_details', __('Certification Details', 'opentrust'), [$this, 'render_cert_meta_box'], 'ot_certification', 'normal', 'high');

        // Broadcast checkbox first so it renders above Policy Details in the sidebar.
        $settings = OpenTrust::get_settings();
        if (!empty($settings['notifications_enabled'])) {
            add_meta_box('ot_policy_broadcast', __('Email subscribers', 'opentrust'), [$this, 'render_policy_broadcast_meta_box'], 'ot_policy', 'side', 'high');
        }

        add_meta_box('ot_policy_details', __('Policy Details', 'opentrust'), [$this, 'render_policy_meta_box'], 'ot_policy', 'side', 'high');
        add_meta_box('ot_sub_details', __('Subprocessor Details', 'opentrust'), [$this, 'render_sub_meta_box'], 'ot_subprocessor', 'normal', 'high');
        add_meta_box('ot_dp_details', __('Data Practice Details', 'opentrust'), [$this, 'render_dp_meta_box'], 'ot_data_practice', 'normal', 'high');
        add_meta_box('ot_faq_details', __('FAQ Details', 'opentrust'), [$this, 'render_faq_meta_box'], 'ot_faq', 'side', 'high');
    }

    public function render_policy_broadcast_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_policy_broadcast', 'opentrust_policy_broadcast_nonce');
        $last_sent     = get_post_meta($post->ID, '_ot_policy_last_broadcast_at', true);
        $last_sent_n   = (int) get_post_meta($post->ID, '_ot_policy_last_broadcast_sent', true);
        $last_failed_n = (int) get_post_meta($post->ID, '_ot_policy_last_broadcast_failed', true);

        // Fresh-result callout: only shown briefly after a save that fired a
        // broadcast. Read once and clear the transient so it disappears next render.
        $just_sent = get_transient('opentrust_broadcast_result_' . $post->ID);
        if (is_array($just_sent)) {
            delete_transient('opentrust_broadcast_result_' . $post->ID);
            $sent_count   = (int) ($just_sent['sent'] ?? 0);
            $failed_count = (int) ($just_sent['failed'] ?? 0);

            if ($sent_count === 0 && $failed_count === 0) {
                $bg = '#fef3c7'; $bd = '#b45309'; $fg = '#92400e';
                $msg = __('Broadcast triggered, but no active subscribers are opted in to policy updates.', 'opentrust');
            } elseif ($failed_count === 0) {
                $bg = '#dcfce7'; $bd = '#166534'; $fg = '#166534';
                /* translators: %d: subscriber count */
                $msg = sprintf(_n('Broadcast just sent to %d subscriber.', 'Broadcast just sent to %d subscribers.', $sent_count, 'opentrust'), $sent_count);
            } else {
                $bg = '#fef3c7'; $bd = '#b45309'; $fg = '#92400e';
                /* translators: 1: delivered count, 2: failed count */
                $msg = sprintf(__('Broadcast finished: %1$d delivered, %2$d failed.', 'opentrust'), $sent_count, $failed_count);
            }
            ?>
            <div style="padding:10px 12px;background:<?php echo esc_attr($bg); ?>;border-left:3px solid <?php echo esc_attr($bd); ?>;border-radius:4px;margin-bottom:10px;font-size:12px;color:<?php echo esc_attr($fg); ?>">
                <strong><?php echo esc_html($msg); ?></strong>
            </div>
            <?php
        }
        ?>
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
            <input type="checkbox" name="ot_policy_broadcast" value="1" autocomplete="off" style="margin-top:3px">
            <span>
                <strong><?php esc_html_e('Broadcast this change to subscribers', 'opentrust'); ?></strong><br>
                <span style="color:#6b7280;font-size:11px">
                    <?php esc_html_e('Emails all active subscribers who opted in to policy updates. The checkbox resets after each save.', 'opentrust'); ?>
                </span>
            </span>
        </label>
        <?php if ($last_sent): ?>
            <p class="description" style="margin-top:10px;font-size:11px;color:#6b7280;line-height:1.5">
                <?php
                /* translators: %s: date and time */
                printf(esc_html__('Last broadcast: %s', 'opentrust'), esc_html(wp_date(get_option('date_format', 'F j, Y') . ' \a\t ' . get_option('time_format', 'g:i a'), strtotime($last_sent))));
                ?>
                <br>
                <?php
                /* translators: 1: delivered count, 2: failed count */
                printf(esc_html__('%1$d delivered, %2$d failed', 'opentrust'), intval($last_sent_n), intval($last_failed_n));
                ?>
            </p>
        <?php endif; ?>
        <?php
    }

    // ── Certification meta box ──

    public function render_cert_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_cert', 'opentrust_cert_nonce');

        $type         = get_post_meta($post->ID, '_ot_cert_type', true) ?: 'certified';
        $issuing_body = get_post_meta($post->ID, '_ot_cert_issuing_body', true) ?: '';
        $status       = get_post_meta($post->ID, '_ot_cert_status', true) ?: 'active';
        $issue_date   = get_post_meta($post->ID, '_ot_cert_issue_date', true) ?: '';
        $expiry_date  = get_post_meta($post->ID, '_ot_cert_expiry_date', true) ?: '';
        $badge_id     = (int) get_post_meta($post->ID, '_ot_cert_badge_id', true);
        $badge_url    = $badge_id ? wp_get_attachment_image_url($badge_id, 'thumbnail') : '';
        $description  = get_post_meta($post->ID, '_ot_cert_description', true) ?: '';
        $artifact_id  = (int) get_post_meta($post->ID, '_ot_cert_artifact_id', true);
        $artifact_url = $artifact_id ? wp_get_attachment_url($artifact_id) : '';
        $artifact_name = $artifact_id ? get_the_title($artifact_id) : '';

        $types = [
            'certified' => __('Audited certification (issued by a third party)', 'opentrust'),
            'compliant' => __('Self-attested alignment (no external audit)', 'opentrust'),
        ];

        $statuses = [
            'active'      => __('Active / currently met', 'opentrust'),
            'in_progress' => __('In progress', 'opentrust'),
            'expired'     => __('Expired / lapsed', 'opentrust'),
        ];
        ?>
        <div class="ot-meta-field">
            <label for="ot_cert_type"><?php esc_html_e('Certification Type', 'opentrust'); ?></label>
            <select id="ot_cert_type" name="ot_cert_type" data-ot-cert-type>
                <?php foreach ($types as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Audited means a third-party issued a formal certificate with dates (SOC 2, ISO 27001, PCI DSS). Self-attested means you adhere to the framework without an external audit — the honest framing for GDPR, CCPA, and most HIPAA posture claims.', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ot_cert_status"><?php esc_html_e('Status', 'opentrust'); ?></label>
            <select id="ot_cert_status" name="ot_cert_status">
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('"Active" for audited means you hold a current certificate. "Active" for self-attested means you currently meet the framework. Use "In progress" while working toward either.', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field" data-ot-cert-certified-only>
            <label for="ot_cert_issuing_body"><?php esc_html_e('Issuing Body', 'opentrust'); ?></label>
            <input type="text" id="ot_cert_issuing_body" name="ot_cert_issuing_body" value="<?php echo esc_attr($issuing_body); ?>" placeholder="<?php esc_attr_e('e.g., AICPA, BSI Group, Schellman', 'opentrust'); ?>">
        </div>

        <div class="ot-meta-field" data-ot-cert-certified-only>
            <label for="ot_cert_issue_date"><?php esc_html_e('Issue Date', 'opentrust'); ?></label>
            <input type="date" id="ot_cert_issue_date" name="ot_cert_issue_date" value="<?php echo esc_attr($issue_date); ?>">
        </div>

        <div class="ot-meta-field" data-ot-cert-certified-only>
            <label for="ot_cert_expiry_date"><?php esc_html_e('Expiry Date', 'opentrust'); ?></label>
            <input type="date" id="ot_cert_expiry_date" name="ot_cert_expiry_date" value="<?php echo esc_attr($expiry_date); ?>">
        </div>

        <div class="ot-meta-field">
            <label><?php esc_html_e('Framework Logo', 'opentrust'); ?></label>
            <img class="ot-badge-preview" src="<?php echo esc_url($badge_url); ?>" alt="" <?php echo $badge_url ? '' : 'style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>>
            <input type="hidden" class="ot-badge-input" name="ot_cert_badge_id" value="<?php echo esc_attr((string) $badge_id); ?>">
            <button type="button" class="button ot-upload-badge"><?php esc_html_e('Select Logo', 'opentrust'); ?></button>
            <button type="button" class="button ot-remove-badge" <?php echo $badge_id ? '' : 'style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>><?php esc_html_e('Remove', 'opentrust'); ?></button>
            <p class="description"><?php esc_html_e('Use the official framework mark where licensing allows (SOC 2, ISO, GDPR shield). Square images work best at 44×44.', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field" data-ot-cert-artifact>
            <label><?php esc_html_e('Proof Artifact', 'opentrust'); ?></label>
            <div class="ot-artifact-preview" <?php echo $artifact_id ? '' : 'style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>>
                <span class="ot-artifact-preview__icon" aria-hidden="true">📄</span>
                <a class="ot-artifact-preview__link" href="<?php echo esc_url($artifact_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($artifact_name ?: __('View file', 'opentrust')); ?></a>
            </div>
            <input type="hidden" class="ot-artifact-input" name="ot_cert_artifact_id" value="<?php echo esc_attr((string) $artifact_id); ?>">
            <button type="button" class="button ot-upload-artifact"><?php echo $artifact_id ? esc_html__('Replace File', 'opentrust') : esc_html__('Upload File', 'opentrust'); ?></button>
            <button type="button" class="button ot-remove-artifact" <?php echo $artifact_id ? '' : 'style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>><?php esc_html_e('Remove', 'opentrust'); ?></button>
            <p class="description"><?php esc_html_e('Optional PDF the trust center can link to — e.g. the audit report, certificate, or policy mapping document. Shown as a download button on the card.', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field">
            <label for="ot_cert_description"><?php esc_html_e('Scope & Notes', 'opentrust'); ?></label>
            <textarea id="ot_cert_description" name="ot_cert_description" rows="3" placeholder="<?php esc_attr_e('e.g., We process EU personal data under GDPR. Our DPA covers customer data, and we support DSARs within 30 days.', 'opentrust'); ?>"><?php echo esc_textarea($description); ?></textarea>
            <p class="description"><?php esc_html_e('Required for self-attested frameworks so the card has meaningful content. One or two sentences on scope, how you meet the framework, or what prospects should know.', 'opentrust'); ?></p>
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
        <div class="ot-meta-field" style="background:#f0f4ff;padding:12px;border-radius:6px;margin-bottom:16px;">
            <p style="font-size:20px;font-weight:700;margin:0 0 4px;color:#2563eb;">
                <?php
                /* translators: %s: policy version number */
                printf(esc_html__('Version %s', 'opentrust'), esc_html((string) $version)); ?>
            </p>
            <p class="description" style="margin:0;color:#6b7280;">
                <?php esc_html_e('Regular saves update the current version. Use the checkbox below to formally publish a new version.', 'opentrust'); ?>
            </p>
        </div>

        <?php if ('publish' === $post->post_status): ?>
        <div class="ot-meta-field ot-version-bump" style="border:2px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:16px;">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                <input type="checkbox" name="ot_publish_new_version" value="1" id="ot_publish_new_version" style="margin-top:2px;">
                <span>
                    <strong><?php esc_html_e('Publish as new version', 'opentrust'); ?></strong><br>
                    <span class="description" style="font-size:12px;">
                        <?php
                        printf(
                            /* translators: %1$d: current version number, %2$d: next version number */
                            esc_html__('This will save the current content as v%1$d and create v%2$d. Only check this for formal, published changes — not minor edits.', 'opentrust'),
                            intval( $version ),
                            intval( $version + 1 )
                        ); ?>
                    </span>
                </span>
            </label>
            <div id="ot-version-summary-wrap" style="margin-top:10px;display:none;">
                <label for="ot_version_summary" style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">
                    <?php esc_html_e('What changed?', 'opentrust'); ?>
                </label>
                <input type="text" id="ot_version_summary" name="ot_version_summary" value="" style="width:100%;"
                    placeholder="<?php esc_attr_e('e.g., Updated data retention from 90 to 60 days', 'opentrust'); ?>">
                <p class="description" style="margin-top:2px;font-size:11px;"><?php esc_html_e('Shown in the public version history.', 'opentrust'); ?></p>
            </div>
        </div>
        <script>document.getElementById('ot_publish_new_version').addEventListener('change',function(){document.getElementById('ot-version-summary-wrap').style.display=this.checked?'block':'none';if(this.checked)document.getElementById('ot_version_summary').focus();});</script>
        <?php endif; ?>

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
            <p class="description"><?php esc_html_e('A Data Processing Agreement (DPA) is a contract between you and the subprocessor covering how they handle personal data on your behalf. Check this box once your organization has signed one with this vendor.', 'opentrust'); ?></p>
        </div>
        <?php
    }

    // ── Data Practice meta box ──

    public function render_dp_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_dp', 'opentrust_dp_nonce');

        $data_items       = get_post_meta($post->ID, '_ot_dp_data_items', true);
        $data_items       = is_array($data_items) ? $data_items : [];
        $purpose          = get_post_meta($post->ID, '_ot_dp_purpose', true) ?: '';
        $legal_basis      = get_post_meta($post->ID, '_ot_dp_legal_basis', true) ?: '';
        $retention_period = get_post_meta($post->ID, '_ot_dp_retention_period', true) ?: '';
        $shared_with      = get_post_meta($post->ID, '_ot_dp_shared_with', true);
        $shared_with      = is_array($shared_with) ? $shared_with : [];
        $sort_order       = (int) get_post_meta($post->ID, '_ot_dp_sort_order', true);

        $prop_collected   = (bool) get_post_meta($post->ID, '_ot_dp_collected', true);
        $prop_stored      = (bool) get_post_meta($post->ID, '_ot_dp_stored', true);
        $prop_shared      = (bool) get_post_meta($post->ID, '_ot_dp_shared', true);
        $prop_sold        = (bool) get_post_meta($post->ID, '_ot_dp_sold', true);
        $prop_encrypted   = (bool) get_post_meta($post->ID, '_ot_dp_encrypted', true);

        $basis_options    = OpenTrust_Render::legal_basis_labels();
        ?>

        <!-- Data Items — tag input -->
        <div class="ot-meta-field">
            <label><?php esc_html_e('Data Items Collected', 'opentrust'); ?></label>
            <div class="ot-tags" data-ot-tags="ot_dp_data_items">
                <?php foreach ($data_items as $i => $item): ?>
                <span class="ot-tag">
                    <span class="ot-tag__text"><?php echo esc_html($item['name'] ?? ''); ?></span>
                    <input type="hidden" name="ot_dp_data_items[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($item['name'] ?? ''); ?>">
                    <button type="button" class="ot-tag__remove" aria-label="<?php esc_attr_e('Remove', 'opentrust'); ?>">&times;</button>
                </span>
                <?php endforeach; ?>
                <input type="text" class="ot-tags__input" placeholder="<?php esc_attr_e('Type and press Enter...', 'opentrust'); ?>" />
            </div>
        </div>

        <!-- Purpose -->
        <div class="ot-meta-field">
            <label for="ot_dp_purpose"><?php esc_html_e('Purpose', 'opentrust'); ?></label>
            <textarea id="ot_dp_purpose" name="ot_dp_purpose" rows="2"><?php echo esc_textarea($purpose); ?></textarea>
        </div>

        <!-- Legal Basis & Retention row -->
        <div class="ot-meta-row">
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
                <input type="text" id="ot_dp_retention_period" name="ot_dp_retention_period" value="<?php echo esc_attr($retention_period); ?>" placeholder="<?php esc_attr_e('e.g., 30 days', 'opentrust'); ?>">
            </div>
        </div>

        <!-- Shared With — tag input -->
        <div class="ot-meta-field">
            <label><?php esc_html_e('Shared With', 'opentrust'); ?></label>
            <div class="ot-tags" data-ot-tags="ot_dp_shared_with">
                <?php foreach ($shared_with as $i => $entry): ?>
                <span class="ot-tag">
                    <span class="ot-tag__text"><?php echo esc_html($entry['name'] ?? ''); ?></span>
                    <input type="hidden" name="ot_dp_shared_with[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($entry['name'] ?? ''); ?>">
                    <button type="button" class="ot-tag__remove" aria-label="<?php esc_attr_e('Remove', 'opentrust'); ?>">&times;</button>
                </span>
                <?php endforeach; ?>
                <input type="text" class="ot-tags__input" placeholder="<?php esc_attr_e('Type and press Enter...', 'opentrust'); ?>" />
            </div>
        </div>

        <!-- Properties — binary flags the AI assistant reports verbatim -->
        <div class="ot-meta-field">
            <label><?php esc_html_e('Properties', 'opentrust'); ?></label>
            <div class="ot-dp-props">
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ot_dp_collected" value="1" <?php checked($prop_collected); ?>>
                    <span><?php esc_html_e('Collected', 'opentrust'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ot_dp_stored" value="1" <?php checked($prop_stored); ?>>
                    <span><?php esc_html_e('Stored', 'opentrust'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ot_dp_shared" value="1" <?php checked($prop_shared); ?>>
                    <span><?php esc_html_e('Shared with third parties', 'opentrust'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ot_dp_sold" value="1" <?php checked($prop_sold); ?>>
                    <span><?php esc_html_e('Sold to third parties', 'opentrust'); ?></span>
                </label>
                <label class="ot-dp-props__item">
                    <input type="checkbox" name="ot_dp_encrypted" value="1" <?php checked($prop_encrypted); ?>>
                    <span><?php esc_html_e('Encrypted', 'opentrust'); ?></span>
                </label>
            </div>
            <p class="description"><?php esc_html_e('Unchecked means an explicit "No". The AI assistant reports these values verbatim to visitors asking questions like "Do you sell customer data?".', 'opentrust'); ?></p>
        </div>

        <!-- Sort order -->
        <div class="ot-meta-field">
            <label for="ot_dp_sort_order"><?php esc_html_e('Sort Order', 'opentrust'); ?></label>
            <input type="number" id="ot_dp_sort_order" name="ot_dp_sort_order" value="<?php echo esc_attr((string) $sort_order); ?>" min="0" step="1">
            <p class="description"><?php esc_html_e('Lower numbers appear first.', 'opentrust'); ?></p>
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
            'ot_faq'           => $this->save_faq_meta($post_id),
            default            => null,
        };

        // Policy broadcast checkbox (separate save flow so existing policy
        // save is untouched and we can bail safely if notifications are off).
        if ($post->post_type === 'ot_policy') {
            $this->maybe_broadcast_policy($post_id);
        }
    }

    /**
     * If the admin ticked "Broadcast this change" on the policy edit screen,
     * fire a one-shot email to all active policy subscribers. Result stats
     * land in post meta and surface in the broadcast meta box on next render.
     */
    private function maybe_broadcast_policy(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['opentrust_policy_broadcast_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentrust_policy_broadcast_nonce'] ) ), 'opentrust_save_policy_broadcast')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (empty($_POST['ot_policy_broadcast'])) {
            return;
        }

        $settings = OpenTrust::get_settings();
        if (empty($settings['notifications_enabled'])) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        $result = OpenTrust_Notify::instance()->broadcast_policy_change($post);

        set_transient(
            'opentrust_broadcast_result_' . $post_id,
            ['sent' => (int) $result['sent'], 'failed' => (int) $result['failed']],
            60
        );
    }

    /**
     * Render a one-shot admin notice on the policy edit screen after a save
     * that triggered a broadcast. Reads (and immediately clears) a transient
     * set by maybe_broadcast_policy().
     */
    public function render_broadcast_admin_notice(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'ot_policy') {
            return;
        }

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identifier
        if ($post_id <= 0) {
            return;
        }

        $key    = 'opentrust_broadcast_result_' . $post_id;
        $result = get_transient($key);
        if (!is_array($result)) {
            return;
        }
        delete_transient($key);

        $sent   = (int) ($result['sent'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);

        if ($sent === 0 && $failed === 0) {
            $class   = 'notice-warning';
            $message = __('Broadcast triggered, but no active subscribers are opted in to policy updates. Nothing was sent.', 'opentrust');
        } elseif ($failed === 0) {
            $class = 'notice-success';
            /* translators: %d: subscriber count */
            $message = sprintf(_n('Broadcast sent to %d subscriber.', 'Broadcast sent to %d subscribers.', $sent, 'opentrust'), $sent);
        } else {
            $class = 'notice-warning';
            /* translators: 1: delivered count, 2: failed count */
            $message = sprintf(__('Broadcast finished: %1$d delivered, %2$d failed. Check your SMTP configuration.', 'opentrust'), $sent, $failed);
        }
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    private function save_cert_meta(int $post_id): void {
        if (!isset($_POST['opentrust_cert_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentrust_cert_nonce'] ) ), 'opentrust_save_cert')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $valid_types = ['certified', 'compliant'];
        $type = sanitize_text_field( wp_unslash( $_POST['ot_cert_type'] ?? 'certified' ) );
        update_post_meta($post_id, '_ot_cert_type', in_array($type, $valid_types, true) ? $type : 'certified');

        update_post_meta($post_id, '_ot_cert_issuing_body', sanitize_text_field( wp_unslash( $_POST['ot_cert_issuing_body'] ?? '' ) ));

        $valid_statuses = ['active', 'in_progress', 'expired'];
        $status = sanitize_text_field( wp_unslash( $_POST['ot_cert_status'] ?? 'active' ) );
        update_post_meta($post_id, '_ot_cert_status', in_array($status, $valid_statuses, true) ? $status : 'active');

        update_post_meta($post_id, '_ot_cert_issue_date', sanitize_text_field( wp_unslash( $_POST['ot_cert_issue_date'] ?? '' ) ));
        update_post_meta($post_id, '_ot_cert_expiry_date', sanitize_text_field( wp_unslash( $_POST['ot_cert_expiry_date'] ?? '' ) ));
        update_post_meta($post_id, '_ot_cert_badge_id', absint( wp_unslash( $_POST['ot_cert_badge_id'] ?? 0 ) ));
        update_post_meta($post_id, '_ot_cert_artifact_id', absint( wp_unslash( $_POST['ot_cert_artifact_id'] ?? 0 ) ));
        update_post_meta($post_id, '_ot_cert_description', sanitize_textarea_field( wp_unslash( $_POST['ot_cert_description'] ?? '' ) ));
    }

    private function save_policy_meta(int $post_id): void {
        if (!isset($_POST['opentrust_policy_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentrust_policy_nonce'] ) ), 'opentrust_save_policy')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Version bump — only when explicitly requested by the user.
        if (!empty($_POST['ot_publish_new_version'])) {
            $post = get_post($post_id);
            if ($post && 'publish' === $post->post_status) {
                $summary = sanitize_text_field( wp_unslash( $_POST['ot_version_summary'] ?? '' ) );
                OpenTrust_Version::bump_version($post_id, $summary);
            }
        }

        // Ensure first-publish posts get v1.
        OpenTrust_Version::ensure_initial_version($post_id);

        $valid_categories = ['security', 'privacy', 'compliance', 'operational', 'other'];
        $category = sanitize_text_field( wp_unslash( $_POST['ot_policy_category'] ?? 'other' ) );
        update_post_meta($post_id, '_ot_policy_category', in_array($category, $valid_categories, true) ? $category : 'other');

        update_post_meta($post_id, '_ot_policy_effective_date', sanitize_text_field( wp_unslash( $_POST['ot_policy_effective_date'] ?? '' ) ));
        update_post_meta($post_id, '_ot_policy_review_date', sanitize_text_field( wp_unslash( $_POST['ot_policy_review_date'] ?? '' ) ));
        update_post_meta($post_id, '_ot_policy_downloadable', !empty($_POST['ot_policy_downloadable']));
        update_post_meta($post_id, '_ot_policy_sort_order', absint( wp_unslash( $_POST['ot_policy_sort_order'] ?? 0 ) ));
    }

    private function save_sub_meta(int $post_id): void {
        if (!isset($_POST['opentrust_sub_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentrust_sub_nonce'] ) ), 'opentrust_save_sub')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_ot_sub_purpose', sanitize_textarea_field( wp_unslash( $_POST['ot_sub_purpose'] ?? '' ) ));
        update_post_meta($post_id, '_ot_sub_data_processed', sanitize_textarea_field( wp_unslash( $_POST['ot_sub_data_processed'] ?? '' ) ));
        update_post_meta($post_id, '_ot_sub_country', sanitize_text_field( wp_unslash( $_POST['ot_sub_country'] ?? '' ) ));
        update_post_meta($post_id, '_ot_sub_website', esc_url_raw( wp_unslash( $_POST['ot_sub_website'] ?? '' ) ));
        update_post_meta($post_id, '_ot_sub_dpa_signed', !empty($_POST['ot_sub_dpa_signed']));
    }

    private function save_dp_meta(int $post_id): void {
        if (!isset($_POST['opentrust_dp_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentrust_dp_nonce'] ) ), 'opentrust_save_dp')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Data Items (repeater array).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is individually sanitized below.
        $raw_items = wp_unslash( $_POST['ot_dp_data_items'] ?? [] );
        $data_items = [];
        if (is_array($raw_items)) {
            foreach ($raw_items as $item) {
                $name = sanitize_text_field($item['name'] ?? '');
                if ($name !== '') {
                    $data_items[] = ['name' => $name];
                }
            }
        }
        update_post_meta($post_id, '_ot_dp_data_items', $data_items);

        // Purpose.
        update_post_meta($post_id, '_ot_dp_purpose', sanitize_textarea_field( wp_unslash( $_POST['ot_dp_purpose'] ?? '' ) ));

        // Legal Basis.
        $valid_bases = ['consent', 'contract', 'legitimate_interest', 'legal_obligation', 'vital_interest', 'public_interest'];
        $basis = sanitize_text_field( wp_unslash( $_POST['ot_dp_legal_basis'] ?? '' ) );
        update_post_meta($post_id, '_ot_dp_legal_basis', in_array($basis, $valid_bases, true) ? $basis : '');

        // Retention Period.
        update_post_meta($post_id, '_ot_dp_retention_period', sanitize_text_field( wp_unslash( $_POST['ot_dp_retention_period'] ?? '' ) ));

        // Shared With (repeater array).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is individually sanitized below.
        $raw_shared = wp_unslash( $_POST['ot_dp_shared_with'] ?? [] );
        $shared_items = [];
        if (is_array($raw_shared)) {
            foreach ($raw_shared as $entry) {
                $name = sanitize_text_field($entry['name'] ?? '');
                if ($name !== '') {
                    $shared_items[] = ['name' => $name];
                }
            }
        }
        update_post_meta($post_id, '_ot_dp_shared_with', $shared_items);

        // Sort order.
        update_post_meta($post_id, '_ot_dp_sort_order', absint( wp_unslash( $_POST['ot_dp_sort_order'] ?? 0 ) ));

        // Property flags — the AI assistant reports these verbatim. Unchecked
        // means explicit "No", not "unknown", so we always write the value.
        update_post_meta($post_id, '_ot_dp_collected', !empty($_POST['ot_dp_collected']));
        update_post_meta($post_id, '_ot_dp_stored',    !empty($_POST['ot_dp_stored']));
        update_post_meta($post_id, '_ot_dp_shared',    !empty($_POST['ot_dp_shared']));
        update_post_meta($post_id, '_ot_dp_sold',      !empty($_POST['ot_dp_sold']));
        update_post_meta($post_id, '_ot_dp_encrypted', !empty($_POST['ot_dp_encrypted']));

        // Clean up legacy meta keys.
        delete_post_meta($post_id, '_ot_dp_data_type');
        delete_post_meta($post_id, '_ot_dp_collection_method');
        delete_post_meta($post_id, '_ot_dp_is_sensitive');
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
            'ot_status'       => (function () use ($post_id): void {
                $status = get_post_meta($post_id, '_ot_cert_status', true) ?: 'active';
                $type   = get_post_meta($post_id, '_ot_cert_type', true) ?: 'certified';
                $labels = $type === 'compliant'
                    ? OpenTrust_Render::cert_aligned_status_labels()
                    : OpenTrust_Render::cert_status_labels();
                $swatch = match ($status) {
                    'active'      => 'background:#dcfce7;color:#166534',
                    'in_progress' => 'background:#fef9c3;color:#854d0e',
                    'expired'     => 'background:#f3f4f6;color:#6b7280',
                    default       => '',
                };
                printf(
                    '<span class="ot-pill ot-pill--%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;%2$s">%3$s</span>',
                    esc_attr($status),
                    esc_attr($swatch),
                    esc_html($labels[$status] ?? '')
                );
            })(),
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
        $new['cb']          = $columns['cb'];
        $new['title']       = $columns['title'];
        $new['ot_dp_items'] = __('Data Items', 'opentrust');
        $new['ot_dp_sort']  = __('Order', 'opentrust');
        $new['date']        = $columns['date'];
        return $new;
    }

    public function dp_column_content(string $column, int $post_id): void {
        match ($column) {
            'ot_dp_items' => print(esc_html((string) count((array) (get_post_meta($post_id, '_ot_dp_data_items', true) ?: [])))),
            'ot_dp_sort'  => print(esc_html((string) ((int) get_post_meta($post_id, '_ot_dp_sort_order', true)))),
            default       => null,
        };
    }

    // ── FAQ meta box ──

    public function render_faq_meta_box(\WP_Post $post): void {
        wp_nonce_field('opentrust_save_faq', 'opentrust_faq_nonce');

        $policy_id = (int) get_post_meta($post->ID, '_ot_faq_related_policy', true);

        $policies = get_posts([
            'post_type'      => 'ot_policy',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        ?>
        <div class="ot-meta-field">
            <label for="ot_faq_related_policy"><?php esc_html_e('Related Policy', 'opentrust'); ?></label>
            <select id="ot_faq_related_policy" name="ot_faq_related_policy" style="width:100%">
                <option value="0"><?php esc_html_e('— None —', 'opentrust'); ?></option>
                <?php foreach ($policies as $policy): ?>
                    <option value="<?php echo esc_attr((string) $policy->ID); ?>" <?php selected($policy_id, $policy->ID); ?>><?php echo esc_html($policy->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Optional — link this answer to a published policy for deeper context.', 'opentrust'); ?></p>
        </div>

        <div class="ot-meta-field">
            <p class="description">
                <strong><?php esc_html_e('Sort order:', 'opentrust'); ?></strong>
                <?php esc_html_e('Use the Page Attributes box below (Order field) to control FAQ order. Lower numbers appear first.', 'opentrust'); ?>
            </p>
        </div>
        <?php
    }

    private function save_faq_meta(int $post_id): void {
        if (!isset($_POST['opentrust_faq_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opentrust_faq_nonce'] ) ), 'opentrust_save_faq')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $related = absint( wp_unslash( $_POST['ot_faq_related_policy'] ?? 0 ) );
        if ($related > 0 && get_post_type($related) === 'ot_policy') {
            update_post_meta($post_id, '_ot_faq_related_policy', $related);
        } else {
            delete_post_meta($post_id, '_ot_faq_related_policy');
        }
    }

    // FAQs
    public function faq_columns(array $columns): array {
        $new = [];
        $new['cb']           = $columns['cb'];
        $new['title']        = $columns['title'];
        $new['ot_faq_order'] = __('Order', 'opentrust');
        $new['date']         = $columns['date'];
        return $new;
    }

    public function faq_column_content(string $column, int $post_id): void {
        match ($column) {
            'ot_faq_order' => print(esc_html((string) ((int) (get_post($post_id)->menu_order ?? 0)))),
            default        => null,
        };
    }

    // ──────────────────────────────────────────────
    // Migration
    // ──────────────────────────────────────────────

    /**
     * Migrate data practices from v1 flat fields to v2 structured arrays.
     *
     * Idempotent — safe to call multiple times.
     */
    public static function migrate_data_practices_v2(): void {
        $posts = get_posts([
            'post_type'      => 'ot_data_practice',
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ]);

        foreach ($posts as $post) {
            $id = $post->ID;

            // Skip if already migrated.
            $existing_items = get_post_meta($id, '_ot_dp_data_items', true);
            if (is_array($existing_items)) {
                continue;
            }

            // Migrate _ot_dp_data_type (comma-separated text) -> _ot_dp_data_items (array).
            $data_type_raw = get_post_meta($id, '_ot_dp_data_type', true);
            $data_items = [];
            if ($data_type_raw) {
                $parts = array_map('trim', explode(',', $data_type_raw));
                foreach ($parts as $part) {
                    if ($part !== '') {
                        $data_items[] = ['name' => $part];
                    }
                }
            }
            update_post_meta($id, '_ot_dp_data_items', $data_items);

            // Migrate _ot_dp_shared_with (textarea text) -> serialized array.
            $shared_raw = get_post_meta($id, '_ot_dp_shared_with', true);
            if ($shared_raw && is_string($shared_raw)) {
                $parts = preg_split('/[,\n]+/', $shared_raw);
                $shared_items = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $shared_items[] = ['name' => $part];
                    }
                }
                update_post_meta($id, '_ot_dp_shared_with', $shared_items);
            }

            // Set default sort order for migrated entries.
            if (!metadata_exists('post', $id, '_ot_dp_sort_order')) {
                update_post_meta($id, '_ot_dp_sort_order', 0);
            }
        }
    }
}
