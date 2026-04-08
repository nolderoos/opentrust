<?php
/**
 * Frontend rendering engine.
 *
 * Outputs a complete, standalone HTML document for the trust center,
 * completely bypassing the active theme.
 */

declare(strict_types=1);

final class OpenTrust_Render {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    // ──────────────────────────────────────────────
    // Dispatch
    // ──────────────────────────────────────────────

    public function dispatch(string $page): void {
        match ($page) {
            'main'           => $this->render_trust_center(),
            'policy'         => $this->render_policy_single(),
            'policy_version' => $this->render_policy_version(),
            'policy_pdf'     => $this->render_policy_pdf(),
            default          => $this->render_404(),
        };
    }

    // ──────────────────────────────────────────────
    // Main trust center page
    // ──────────────────────────────────────────────

    private function render_trust_center(): void {
        $settings = OpenTrust::get_settings();
        $data     = $this->gather_data($settings);

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // Single policy view
    // ──────────────────────────────────────────────

    private function render_policy_single(): void {
        $slug   = sanitize_title(get_query_var('ot_policy_slug', ''));
        $policy = $this->find_policy_by_slug($slug);

        if (!$policy) {
            $this->render_404();
            return;
        }

        $settings = OpenTrust::get_settings();
        $data     = $this->gather_data($settings);
        $data['current_policy'] = $policy;
        $data['policy_content'] = apply_filters('the_content', $policy->post_content);
        $data['policy_version'] = (int) get_post_meta($policy->ID, '_ot_version', true) ?: 1;
        $data['policy_meta']    = $this->get_policy_meta($policy->ID);
        $data['view']           = 'policy_single';

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // Historical policy version
    // ──────────────────────────────────────────────

    private function render_policy_version(): void {
        $slug    = sanitize_title(get_query_var('ot_policy_slug', ''));
        $version = (int) get_query_var('ot_version', '0');
        $policy  = $this->find_policy_by_slug($slug);

        if (!$policy || $version < 1) {
            $this->render_404();
            return;
        }

        // Current version — redirect to canonical.
        $current_version = (int) get_post_meta($policy->ID, '_ot_version', true) ?: 1;
        if ($version === $current_version) {
            $settings = OpenTrust::get_settings();
            $base     = home_url('/' . $settings['endpoint_slug'] . '/policy/' . $policy->post_name . '/');
            wp_redirect($base, 301);
            exit;
        }

        // Find the revision matching this version.
        $revisions = wp_get_post_revisions($policy->ID, ['order' => 'ASC']);
        $target    = null;
        foreach ($revisions as $rev) {
            if ((int) get_post_meta($rev->ID, '_ot_version', true) === $version) {
                $target = $rev;
                break;
            }
        }

        if (!$target) {
            $this->render_404();
            return;
        }

        $settings = OpenTrust::get_settings();
        $data     = $this->gather_data($settings);
        $data['current_policy'] = $policy;
        $data['policy_content'] = apply_filters('the_content', $target->post_content);
        $data['policy_version'] = $version;
        $data['policy_meta']    = $this->get_policy_meta($policy->ID);
        $data['is_old_version'] = true;
        $data['view']           = 'policy_single';

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // PDF download
    // ──────────────────────────────────────────────

    private function render_policy_pdf(): void {
        $slug   = sanitize_title(get_query_var('ot_policy_slug', ''));
        $policy = $this->find_policy_by_slug($slug);

        if (!$policy) {
            $this->render_404();
            return;
        }

        // PDF generation handled by OpenTrust_PDF (Phase 5).
        // For now, serve the policy as a clean HTML page for browser print.
        $settings = OpenTrust::get_settings();
        $data     = $this->gather_data($settings);
        $data['current_policy'] = $policy;
        $data['policy_content'] = apply_filters('the_content', $policy->post_content);
        $data['policy_version'] = (int) get_post_meta($policy->ID, '_ot_version', true) ?: 1;
        $data['policy_meta']    = $this->get_policy_meta($policy->ID);
        $data['view']           = 'policy_pdf';

        header('Content-Type: text/html; charset=utf-8');
        include OPENTRUST_PLUGIN_DIR . 'templates/trust-center.php';
    }

    // ──────────────────────────────────────────────
    // 404
    // ──────────────────────────────────────────────

    private function render_404(): void {
        status_header(404);
        $settings = OpenTrust::get_settings();
        $hsl      = OpenTrust::hex_to_hsl($settings['accent_color'] ?? '#2563EB');

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head>';
        echo '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Inter,Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;color:#374151">';
        echo '<div style="text-align:center"><h1 style="font-size:4rem;margin:0;color:#d1d5db">404</h1>';
        echo '<p style="font-size:1.125rem;margin:1rem 0">' . esc_html__('Page not found.', 'opentrust') . '</p>';
        echo '<a href="' . esc_url(home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/')) . '" style="color:hsl(' . (int) $hsl['h'] . ',' . (int) $hsl['s'] . '%,' . (int) $hsl['l'] . '%);text-decoration:none">' . esc_html__('Back to Trust Center', 'opentrust') . '</a>';
        echo '</div></body></html>';
    }

    // ──────────────────────────────────────────────
    // Data gathering
    // ──────────────────────────────────────────────

    public function gather_data(array $settings): array {
        $hsl = OpenTrust::hex_to_hsl($settings['accent_color'] ?? '#2563EB');

        $data = [
            'settings'       => $settings,
            'hsl'            => $hsl,
            'logo_url'       => '',
            'base_url'       => home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/'),
            'view'           => 'main',
            'certifications' => [],
            'policies'       => [],
            'subprocessors'  => [],
            'data_practices' => [],
        ];

        // Logo.
        $logo_id = (int) ($settings['logo_id'] ?? 0);
        if ($logo_id) {
            $data['logo_url'] = wp_get_attachment_image_url($logo_id, 'medium') ?: '';
        }

        // Certifications.
        $visible = $settings['sections_visible'] ?? [];
        if (!empty($visible['certifications'])) {
            $data['certifications'] = $this->get_certifications();
        }
        if (!empty($visible['policies'])) {
            $data['policies'] = $this->get_policies();
        }
        if (!empty($visible['subprocessors'])) {
            $data['subprocessors'] = $this->get_subprocessors();
        }
        if (!empty($visible['data_practices'])) {
            $data['data_practices'] = $this->get_data_practices();
        }

        return $data;
    }

    // ──────────────────────────────────────────────
    // Queries
    // ──────────────────────────────────────────────

    private function get_certifications(): array {
        $cached = get_transient('opentrust_certifications');
        if (is_array($cached)) {
            return $cached;
        }

        $posts = get_posts([
            'post_type'      => 'ot_certification',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ]);

        $items = [];
        foreach ($posts as $post) {
            $badge_id = (int) get_post_meta($post->ID, '_ot_cert_badge_id', true);
            $items[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'issuing_body' => get_post_meta($post->ID, '_ot_cert_issuing_body', true) ?: '',
                'status'       => get_post_meta($post->ID, '_ot_cert_status', true) ?: 'active',
                'issue_date'   => get_post_meta($post->ID, '_ot_cert_issue_date', true) ?: '',
                'expiry_date'  => get_post_meta($post->ID, '_ot_cert_expiry_date', true) ?: '',
                'badge_url'    => $badge_id ? (wp_get_attachment_image_url($badge_id, 'thumbnail') ?: '') : '',
                'description'  => get_post_meta($post->ID, '_ot_cert_description', true) ?: '',
            ];
        }

        set_transient('opentrust_certifications', $items, HOUR_IN_SECONDS);
        return $items;
    }

    private function get_policies(): array {
        $cached = get_transient('opentrust_policies');
        if (is_array($cached)) {
            return $cached;
        }

        $posts = get_posts([
            'post_type'      => 'ot_policy',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'meta_value_num title',
            'meta_key'       => '_ot_policy_sort_order',
            'order'          => 'ASC',
        ]);

        $items = [];
        foreach ($posts as $post) {
            $items[] = [
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'slug'           => $post->post_name,
                'excerpt'        => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
                'version'        => (int) get_post_meta($post->ID, '_ot_version', true) ?: 1,
                'category'       => get_post_meta($post->ID, '_ot_policy_category', true) ?: 'other',
                'effective_date' => get_post_meta($post->ID, '_ot_policy_effective_date', true) ?: '',
                'review_date'    => get_post_meta($post->ID, '_ot_policy_review_date', true) ?: '',
                'downloadable'   => (bool) get_post_meta($post->ID, '_ot_policy_downloadable', true),
                'last_modified'  => $post->post_modified,
            ];
        }

        set_transient('opentrust_policies', $items, HOUR_IN_SECONDS);
        return $items;
    }

    private function get_subprocessors(): array {
        $cached = get_transient('opentrust_subprocessors');
        if (is_array($cached)) {
            return $cached;
        }

        $posts = get_posts([
            'post_type'      => 'ot_subprocessor',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $items = [];
        foreach ($posts as $post) {
            $items[] = [
                'id'             => $post->ID,
                'name'           => $post->post_title,
                'purpose'        => get_post_meta($post->ID, '_ot_sub_purpose', true) ?: '',
                'data_processed' => get_post_meta($post->ID, '_ot_sub_data_processed', true) ?: '',
                'country'        => get_post_meta($post->ID, '_ot_sub_country', true) ?: '',
                'website'        => get_post_meta($post->ID, '_ot_sub_website', true) ?: '',
                'dpa_signed'     => (bool) get_post_meta($post->ID, '_ot_sub_dpa_signed', true),
            ];
        }

        set_transient('opentrust_subprocessors', $items, HOUR_IN_SECONDS);
        return $items;
    }

    private function get_data_practices(): array {
        $cached = get_transient('opentrust_data_practices');
        if (is_array($cached)) {
            return $cached;
        }

        $posts = get_posts([
            'post_type'      => 'ot_data_practice',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ]);

        $items = [];
        foreach ($posts as $post) {
            $items[] = [
                'id'               => $post->ID,
                'title'            => $post->post_title,
                'data_type'        => get_post_meta($post->ID, '_ot_dp_data_type', true) ?: '',
                'purpose'          => get_post_meta($post->ID, '_ot_dp_purpose', true) ?: '',
                'legal_basis'      => get_post_meta($post->ID, '_ot_dp_legal_basis', true) ?: '',
                'retention_period' => get_post_meta($post->ID, '_ot_dp_retention_period', true) ?: '',
                'shared_with'      => get_post_meta($post->ID, '_ot_dp_shared_with', true) ?: '',
                'category'         => get_post_meta($post->ID, '_ot_dp_category', true) ?: 'personal',
            ];
        }

        set_transient('opentrust_data_practices', $items, HOUR_IN_SECONDS);
        return $items;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function find_policy_by_slug(string $slug): ?\WP_Post {
        if (!$slug) {
            return null;
        }

        $posts = get_posts([
            'post_type'      => 'ot_policy',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ]);

        return $posts[0] ?? null;
    }

    private function get_policy_meta(int $post_id): array {
        return [
            'category'       => get_post_meta($post_id, '_ot_policy_category', true) ?: 'other',
            'effective_date' => get_post_meta($post_id, '_ot_policy_effective_date', true) ?: '',
            'review_date'    => get_post_meta($post_id, '_ot_policy_review_date', true) ?: '',
            'downloadable'   => (bool) get_post_meta($post_id, '_ot_policy_downloadable', true),
        ];
    }

    /**
     * Category labels for display.
     */
    public static function policy_category_labels(): array {
        return [
            'security'    => __('Security', 'opentrust'),
            'privacy'     => __('Privacy', 'opentrust'),
            'compliance'  => __('Compliance', 'opentrust'),
            'operational' => __('Operational', 'opentrust'),
            'other'       => __('General', 'opentrust'),
        ];
    }

    public static function cert_status_labels(): array {
        return [
            'active'      => __('Active', 'opentrust'),
            'in_progress' => __('In Progress', 'opentrust'),
            'expired'     => __('Expired', 'opentrust'),
        ];
    }

    public static function legal_basis_labels(): array {
        return [
            'consent'             => __('Consent', 'opentrust'),
            'contract'            => __('Contractual Necessity', 'opentrust'),
            'legitimate_interest' => __('Legitimate Interest', 'opentrust'),
            'legal_obligation'    => __('Legal Obligation', 'opentrust'),
            'vital_interest'      => __('Vital Interest', 'opentrust'),
            'public_interest'     => __('Public Interest', 'opentrust'),
        ];
    }

    public static function dp_category_labels(): array {
        return [
            'personal'  => __('Personal Data', 'opentrust'),
            'sensitive' => __('Sensitive Data', 'opentrust'),
            'usage'     => __('Usage Data', 'opentrust'),
            'technical' => __('Technical Data', 'opentrust'),
            'financial' => __('Financial Data', 'opentrust'),
        ];
    }
}
