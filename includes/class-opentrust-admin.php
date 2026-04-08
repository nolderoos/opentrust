<?php
/**
 * Admin settings page and menu registration.
 */

declare(strict_types=1);

final class OpenTrust_Admin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    // ──────────────────────────────────────────────
    // Menu
    // ──────────────────────────────────────────────

    public function register_menu(): void {
        add_menu_page(
            __('OpenTrust', 'opentrust'),
            __('OpenTrust', 'opentrust'),
            'manage_options',
            'opentrust',
            [$this, 'render_settings_page'],
            'dashicons-shield',
            30
        );

        add_submenu_page(
            'opentrust',
            __('Settings', 'opentrust'),
            __('Settings', 'opentrust'),
            'manage_options',
            'opentrust',
            [$this, 'render_settings_page']
        );
    }

    // ──────────────────────────────────────────────
    // Settings API
    // ──────────────────────────────────────────────

    public function register_settings(): void {
        register_setting('opentrust_settings_group', 'opentrust_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => OpenTrust::defaults(),
        ]);

        // General section.
        add_settings_section(
            'opentrust_general',
            __('General Settings', 'opentrust'),
            fn() => null,
            'opentrust-settings'
        );

        $this->add_field('endpoint_slug', __('Endpoint Slug', 'opentrust'), 'render_text_field', 'opentrust_general', [
            'description' => __('The URL path for your trust center (e.g., "trust-center" = yoursite.com/trust-center/).', 'opentrust'),
        ]);

        $this->add_field('page_title', __('Page Title', 'opentrust'), 'render_text_field', 'opentrust_general');

        $this->add_field('company_name', __('Company Name', 'opentrust'), 'render_text_field', 'opentrust_general');

        $this->add_field('tagline', __('Tagline', 'opentrust'), 'render_textarea_field', 'opentrust_general', [
            'description' => __('A short description displayed below the company name in the hero section.', 'opentrust'),
        ]);

        // Branding section.
        add_settings_section(
            'opentrust_branding',
            __('Branding', 'opentrust'),
            fn() => null,
            'opentrust-settings'
        );

        $this->add_field('logo_id', __('Company Logo', 'opentrust'), 'render_logo_field', 'opentrust_branding');

        $this->add_field('accent_color', __('Accent Color', 'opentrust'), 'render_color_field', 'opentrust_branding', [
            'description' => __('Used for buttons, links, and highlights. Choose a color that matches your brand.', 'opentrust'),
        ]);

        // Sections visibility.
        add_settings_section(
            'opentrust_sections',
            __('Visible Sections', 'opentrust'),
            fn() => print('<p>' . esc_html__('Choose which sections to display on the trust center.', 'opentrust') . '</p>'),
            'opentrust-settings'
        );

        $this->add_field('sections_visible', __('Sections', 'opentrust'), 'render_sections_field', 'opentrust_sections');
    }

    private function add_field(string $key, string $title, string $callback, string $section, array $extra = []): void {
        add_settings_field(
            'opentrust_' . $key,
            $title,
            [$this, $callback],
            'opentrust-settings',
            $section,
            array_merge(['key' => $key], $extra)
        );
    }

    // ──────────────────────────────────────────────
    // Field renderers
    // ──────────────────────────────────────────────

    public function render_text_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';
        printf(
            '<input type="text" id="opentrust_%1$s" name="opentrust_settings[%1$s]" value="%2$s" class="regular-text">',
            esc_attr($key),
            esc_attr($value)
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_textarea_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';
        printf(
            '<textarea id="opentrust_%1$s" name="opentrust_settings[%1$s]" rows="3" class="large-text">%2$s</textarea>',
            esc_attr($key),
            esc_textarea($value)
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_color_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $value    = $settings['accent_color'] ?? '#2563EB';
        printf(
            '<input type="text" id="opentrust_accent_color" name="opentrust_settings[accent_color]" value="%s" class="ot-color-picker" data-default-color="#2563EB">',
            esc_attr($value)
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_logo_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $logo_id  = (int) ($settings['logo_id'] ?? 0);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <div class="ot-logo-upload">
            <div class="ot-logo-preview" <?php echo $logo_url ? '' : 'style="display:none"'; ?>>
                <img src="<?php echo esc_url($logo_url); ?>" alt="" style="max-width:200px;max-height:80px">
            </div>
            <input type="hidden" id="opentrust_logo_id" name="opentrust_settings[logo_id]" value="<?php echo esc_attr((string) $logo_id); ?>">
            <button type="button" class="button ot-upload-logo"><?php esc_html_e('Select Logo', 'opentrust'); ?></button>
            <button type="button" class="button ot-remove-logo" <?php echo $logo_id ? '' : 'style="display:none"'; ?>><?php esc_html_e('Remove', 'opentrust'); ?></button>
        </div>
        <?php
    }

    public function render_sections_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $visible  = $settings['sections_visible'] ?? [];

        $sections = [
            'certifications' => __('Certifications & Compliance', 'opentrust'),
            'policies'       => __('Policies', 'opentrust'),
            'subprocessors'  => __('Subprocessors', 'opentrust'),
            'data_practices' => __('Data Practices', 'opentrust'),
        ];

        foreach ($sections as $key => $label) {
            $checked = !empty($visible[$key]);
            printf(
                '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="opentrust_settings[sections_visible][%1$s]" value="1" %2$s> %3$s</label>',
                esc_attr($key),
                checked($checked, true, false),
                esc_html($label)
            );
        }
    }

    // ──────────────────────────────────────────────
    // Sanitization
    // ──────────────────────────────────────────────

    public function sanitize_settings(mixed $input): array {
        if (!is_array($input)) {
            return OpenTrust::defaults();
        }

        $old_settings = OpenTrust::get_settings();

        $sanitized = [
            'endpoint_slug'    => sanitize_title($input['endpoint_slug'] ?? 'trust-center') ?: 'trust-center',
            'page_title'       => sanitize_text_field($input['page_title'] ?? ''),
            'company_name'     => sanitize_text_field($input['company_name'] ?? ''),
            'tagline'          => sanitize_textarea_field($input['tagline'] ?? ''),
            'logo_id'          => absint($input['logo_id'] ?? 0),
            'accent_color'     => sanitize_hex_color($input['accent_color'] ?? '#2563EB') ?: '#2563EB',
            'sections_visible' => [
                'certifications' => !empty($input['sections_visible']['certifications']),
                'policies'       => !empty($input['sections_visible']['policies']),
                'subprocessors'  => !empty($input['sections_visible']['subprocessors']),
                'data_practices' => !empty($input['sections_visible']['data_practices']),
            ],
        ];

        // Flag rewrite flush if slug changed.
        if ($sanitized['endpoint_slug'] !== ($old_settings['endpoint_slug'] ?? '')) {
            set_transient('opentrust_flush_rewrite', true);
        }

        return $sanitized;
    }

    // ──────────────────────────────────────────────
    // Settings page
    // ──────────────────────────────────────────────

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = OpenTrust::get_settings();
        $tc_url   = home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <p>
                <a href="<?php echo esc_url($tc_url); ?>" target="_blank" class="button button-secondary">
                    <?php esc_html_e('View Trust Center', 'opentrust'); ?> &rarr;
                </a>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields('opentrust_settings_group');
                do_settings_sections('opentrust-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // Assets
    // ──────────────────────────────────────────────

    public function enqueue_assets(string $hook): void {
        // Only load on our settings pages and CPT edit screens.
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $is_ot_screen = str_starts_with($screen->id, 'toplevel_page_opentrust')
            || str_starts_with($screen->id, 'opentrust_page_')
            || in_array($screen->post_type, ['ot_policy', 'ot_subprocessor', 'ot_certification', 'ot_data_practice'], true);

        if (!$is_ot_screen) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_media();

        wp_enqueue_style(
            'opentrust-admin',
            OPENTRUST_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OPENTRUST_VERSION
        );

        wp_enqueue_script(
            'opentrust-admin',
            OPENTRUST_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-color-picker', 'jquery'],
            OPENTRUST_VERSION,
            true
        );
    }
}
