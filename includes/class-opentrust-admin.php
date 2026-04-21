<?php
/**
 * Admin settings page and menu registration.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenTrust_Admin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('submenu_file', [$this, 'fix_submenu_highlight']);

        // AI tab: custom admin-post.php handlers for key save/refresh/forget.
        add_action('admin_post_opentrust_ai_save_key',        [$this, 'handle_ai_save_key']);
        add_action('admin_post_opentrust_ai_forget_key',      [$this, 'handle_ai_forget_key']);
        add_action('admin_post_opentrust_ai_refresh_models',  [$this, 'handle_ai_refresh_models']);

        // AI questions log: export + clear.
        add_action('admin_post_opentrust_ai_questions_export',  [$this, 'handle_ai_questions_export']);
        add_action('admin_post_opentrust_ai_questions_clear',   [$this, 'handle_ai_questions_clear']);
        add_action('admin_post_opentrust_ai_toggle_logging',    [$this, 'handle_ai_toggle_logging']);

        // Warn admins on every OpenTrust admin page when the site is on Plain
        // permalinks — the plugin's pretty URLs all 404 in that mode.
        add_action('admin_notices', [$this, 'render_plain_permalinks_notice']);
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

        // AI Questions — only visible once AI is enabled.
        $settings = OpenTrust::get_settings();
        if (!empty($settings['ai_enabled'])) {
            add_submenu_page(
                'opentrust',
                __('Questions', 'opentrust'),
                __('Questions', 'opentrust'),
                'manage_options',
                'opentrust-questions',
                [$this, 'render_questions_page']
            );
        }
    }

    /**
     * On "Add New" screens for our CPTs, highlight the correct submenu item.
     *
     * WP core's _add_post_type_submenus() and our register_menu() both hook
     * admin_menu at priority 10. Core runs first, calling add_submenu_page()
     * before add_menu_page('opentrust') has populated $admin_page_hooks, so
     * the CPT submenus end up in $_registered_pages under admin_page_* keys
     * instead of opentrust_page_*. post-new.php looks for the opentrust_page_*
     * key to fall back to highlighting edit.php?post_type=X; the lookup
     * misses, $submenu_file collapses to the parent slug 'opentrust', and
     * the Settings submenu (which uses the same slug) steals the highlight.
     */
    public function fix_submenu_highlight(?string $submenu_file): ?string {
        global $pagenow, $post_type;

        if ($pagenow !== 'post-new.php') {
            return $submenu_file;
        }

        $ot_types = ['ot_policy', 'ot_certification', 'ot_subprocessor', 'ot_data_practice', 'ot_faq'];
        if (in_array($post_type, $ot_types, true)) {
            return "edit.php?post_type={$post_type}";
        }

        return $submenu_file;
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

        // ── General tab ──────────────────────────────────────────────
        add_settings_section(
            'opentrust_general',
            __('General Settings', 'opentrust'),
            fn() => null,
            'opentrust-settings-general'
        );

        $this->add_field('endpoint_slug', __('Endpoint Slug', 'opentrust'), 'render_text_field', 'opentrust_general', 'opentrust-settings-general', [
            'description' => __('The URL path for your trust center (e.g., "trust-center" = yoursite.com/trust-center/).', 'opentrust'),
        ]);

        $this->add_field('page_title', __('Page Title', 'opentrust'), 'render_text_field', 'opentrust_general', 'opentrust-settings-general');

        $this->add_field('company_name', __('Company Name', 'opentrust'), 'render_text_field', 'opentrust_general', 'opentrust-settings-general');

        $this->add_field('tagline', __('Tagline', 'opentrust'), 'render_textarea_field', 'opentrust_general', 'opentrust-settings-general', [
            'description' => __('A short description displayed below the company name in the hero section.', 'opentrust'),
        ]);

        // Branding section (General tab).
        add_settings_section(
            'opentrust_branding',
            __('Branding', 'opentrust'),
            fn() => null,
            'opentrust-settings-general'
        );

        $this->add_field('logo_id', __('Logo', 'opentrust'), 'render_logo_field', 'opentrust_branding', 'opentrust-settings-general');
        $this->add_field('avatar_id', __('AI Avatar', 'opentrust'), 'render_avatar_field', 'opentrust_branding', 'opentrust-settings-general');

        $this->add_field('accent_color', __('Accent Color', 'opentrust'), 'render_color_field', 'opentrust_branding', 'opentrust-settings-general', [
            'description' => __('Used for buttons, links, and highlights. Choose a color that matches your brand.', 'opentrust'),
        ]);

        // Sections visibility (General tab).
        add_settings_section(
            'opentrust_sections',
            __('Visible Sections', 'opentrust'),
            fn() => print('<p>' . esc_html__('Choose which sections to display on the trust center.', 'opentrust') . '</p>'),
            'opentrust-settings-general'
        );

        $this->add_field('sections_visible', __('Sections', 'opentrust'), 'render_sections_field', 'opentrust_sections', 'opentrust-settings-general');

        // ── Contact tab ──────────────────────────────────────────────
        // Fields are optional — the frontend block renders only when at least one field below is populated.
        add_settings_section(
            'opentrust_contact',
            __('Get in touch', 'opentrust'),
            fn() => print('<p>' . esc_html__('Publish a dark-accent "Get in touch" block on the trust center. Every field is optional — the block only appears if at least one is filled in.', 'opentrust') . '</p>'),
            'opentrust-settings-contact'
        );

        $this->add_field('company_description', __('Company Description', 'opentrust'), 'render_textarea_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Two or three sentences describing what the company does. Rendered under the "Get in touch" section title.', 'opentrust'),
        ]);

        $this->add_field('dpo_name', __('DPO Name', 'opentrust'), 'render_text_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Data Protection Officer name. Required under GDPR for many organisations.', 'opentrust'),
        ]);

        $this->add_field('dpo_email', __('DPO Email', 'opentrust'), 'render_email_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Dedicated DPO mailbox. Rendered as a mailto link.', 'opentrust'),
        ]);

        $this->add_field('security_email', __('Security Contact Email', 'opentrust'), 'render_email_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('For vulnerability reports and security questions. Often separate from the DPO.', 'opentrust'),
        ]);

        $this->add_field('contact_form_url', __('Contact Form URL', 'opentrust'), 'render_url_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Optional link to a gated contact form (e.g. HubSpot, Typeform).', 'opentrust'),
        ]);

        $this->add_field('contact_address', __('Mailing Address', 'opentrust'), 'render_textarea_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Postal address for formal GDPR / legal notices.', 'opentrust'),
        ]);

        $this->add_field('pgp_key_url', __('PGP Public Key URL', 'opentrust'), 'render_url_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Optional link to your security team\'s PGP public key.', 'opentrust'),
        ]);

        $this->add_field('company_registration', __('Company Registration Number', 'opentrust'), 'render_text_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('Chamber of Commerce (KvK), Companies House, Handelsregister, EIN, or equivalent business registration.', 'opentrust'),
        ]);

        $this->add_field('vat_number', __('VAT / Tax ID', 'opentrust'), 'render_text_field', 'opentrust_contact', 'opentrust-settings-contact', [
            'description' => __('VAT number, sales-tax ID, or equivalent international tax identifier.', 'opentrust'),
        ]);

    }

    private function add_field(string $key, string $title, string $callback, string $section, string $page = 'opentrust-settings-general', array $extra = []): void {
        add_settings_field(
            'opentrust_' . $key,
            $title,
            [$this, $callback],
            $page,
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

    public function render_email_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';
        printf(
            '<input type="email" id="opentrust_%1$s" name="opentrust_settings[%1$s]" value="%2$s" class="regular-text" autocomplete="off">',
            esc_attr($key),
            esc_attr($value)
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_url_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';
        printf(
            '<input type="url" id="opentrust_%1$s" name="opentrust_settings[%1$s]" value="%2$s" class="regular-text" placeholder="https://" autocomplete="off">',
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
        $settings     = OpenTrust::get_settings();
        $value        = $settings['accent_color'] ?? '#2563EB';
        $force_exact  = !empty($settings['accent_force_exact']);
        printf(
            '<input type="text" id="opentrust_accent_color" name="opentrust_settings[accent_color]" value="%s" class="ot-color-picker" data-default-color="#2563EB">',
            esc_attr($value)
        );
        ?>
        <div id="opentrust-accent-warning" class="ot-accent-warning<?php echo $force_exact ? ' ot-accent-warning--override' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded class ?>" hidden>
            <svg class="ot-accent-warning__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div class="ot-accent-warning__body">
                <strong class="ot-accent-warning__heading ot-accent-warning__heading--auto"><?php esc_html_e('Low contrast on white backgrounds', 'opentrust'); ?></strong>
                <strong class="ot-accent-warning__heading ot-accent-warning__heading--override"><?php esc_html_e('Using your exact color on white backgrounds', 'opentrust'); ?></strong>

                <p class="ot-accent-warning__copy ot-accent-warning__copy--auto">
                    <?php esc_html_e('Your chosen color is too light for buttons, links, and borders on white sections. On those surfaces OpenTrust will use a darker, on-brand variant:', 'opentrust'); ?>
                </p>
                <p class="ot-accent-warning__copy ot-accent-warning__copy--override">
                    <?php esc_html_e("You've chosen to keep your exact color on white backgrounds. Buttons, links, and borders in those sections may be hard to read.", 'opentrust'); ?>
                </p>

                <div class="ot-accent-warning__preview">
                    <span class="ot-accent-warning__swatch ot-accent-warning__swatch--chosen" aria-hidden="true"></span>
                    <code class="ot-accent-warning__hex ot-accent-warning__hex--chosen"></code>
                    <span class="ot-accent-warning__arrow" aria-hidden="true">→</span>
                    <span class="ot-accent-warning__swatch ot-accent-warning__swatch--adjusted" aria-hidden="true"></span>
                    <code class="ot-accent-warning__hex ot-accent-warning__hex--adjusted"></code>
                </div>

                <p class="ot-accent-warning__note ot-accent-warning__note--auto">
                    <?php esc_html_e('The hero and navigation still use your exact color.', 'opentrust'); ?>
                </p>

                <label class="ot-accent-warning__override">
                    <input
                        type="checkbox"
                        id="opentrust_accent_force_exact"
                        name="opentrust_settings[accent_force_exact]"
                        value="1"
                        <?php checked($force_exact); ?>
                    >
                    <span><?php esc_html_e('Use my exact color anyway — skip the contrast adjustment.', 'opentrust'); ?></span>
                </label>
            </div>
        </div>
        <?php
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_logo_field(array $args): void {
        $this->render_media_field(
            'logo_id',
            __('Select Logo', 'opentrust'),
            __('Used in the hero and sticky nav. A white version is recommended — it sits on a dark background.', 'opentrust')
        );
    }

    public function render_avatar_field(array $args): void {
        $this->render_media_field(
            'avatar_id',
            __('Select Avatar', 'opentrust'),
            __('Square image used as the avatar on AI chat responses. Use a colored background with a light or dark favicon or logo on top.', 'opentrust')
        );
    }

    private function render_media_field(string $key, string $button_label, string $description): void {
        $settings  = OpenTrust::get_settings();
        $media_id  = (int) ($settings[$key] ?? 0);
        $media_url = $media_id ? wp_get_attachment_image_url($media_id, 'medium') : '';
        ?>
        <div class="ot-logo-upload" data-ot-media-field>
            <div class="ot-logo-preview" <?php echo $media_url ? '' : 'style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>>
                <img src="<?php echo esc_url($media_url); ?>" alt="" style="max-width:200px;max-height:80px">
            </div>
            <input type="hidden" name="opentrust_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $media_id); ?>" data-ot-media-input>
            <button type="button" class="button" data-ot-media-upload><?php echo esc_html($button_label); ?></button>
            <button type="button" class="button" data-ot-media-remove <?php echo $media_id ? '' : 'style="display:none"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>><?php esc_html_e('Remove', 'opentrust'); ?></button>
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <?php
    }

    public function render_password_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';
        $masked   = $value ? str_repeat('•', 20) : '';
        printf(
            '<input type="password" id="opentrust_%1$s" name="opentrust_settings[%1$s]" value="%2$s" class="regular-text" autocomplete="off" placeholder="%3$s">',
            esc_attr($key),
            esc_attr($value ? '••••••••••••••••••••' : ''),
            esc_attr__('Enter secret key…', 'opentrust')
        );
        if ($value) {
            echo ' <span class="description" style="color:#16a34a">&#10003; ' . esc_html__('Key saved', 'opentrust') . '</span>';
        }
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    public function render_sections_field(array $args): void {
        $settings = OpenTrust::get_settings();
        $visible  = $settings['sections_visible'] ?? [];

        $sections = [
            'certifications' => __('Certifications & Compliance', 'opentrust'),
            'policies'       => __('Policies', 'opentrust'),
            'subprocessors'  => __('Subprocessors', 'opentrust'),
            'data_practices' => __('Data Practices', 'opentrust'),
            'faqs'           => __('FAQs', 'opentrust'),
            'contact'        => __('Contact & DPO', 'opentrust'),
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

        // Each tab's form carries a sentinel (`__<tab>_tab_save`) so this callback
        // can tell which subset of keys came from the submission. Any field outside
        // the active tab's scope is carried forward from $old_settings byte-for-byte,
        // so saving one tab never clobbers another tab's values.
        $sanitized = [];

        // ── General tab (General + Branding + Visible Sections) ──
        if (!empty($input['__general_tab_save'])) {
            $sanitized['endpoint_slug']      = sanitize_title($input['endpoint_slug'] ?? 'trust-center') ?: 'trust-center';
            $sanitized['page_title']         = sanitize_text_field($input['page_title'] ?? '');
            $sanitized['company_name']       = sanitize_text_field($input['company_name'] ?? '');
            $sanitized['tagline']            = sanitize_textarea_field($input['tagline'] ?? '');
            $sanitized['logo_id']            = absint($input['logo_id'] ?? 0);
            $sanitized['avatar_id']          = absint($input['avatar_id'] ?? 0);
            $sanitized['accent_color']       = sanitize_hex_color($input['accent_color'] ?? '#2563EB') ?: '#2563EB';
            $sanitized['accent_force_exact'] = !empty($input['accent_force_exact']);
            $sanitized['sections_visible']   = [
                'certifications' => !empty($input['sections_visible']['certifications']),
                'policies'       => !empty($input['sections_visible']['policies']),
                'subprocessors'  => !empty($input['sections_visible']['subprocessors']),
                'data_practices' => !empty($input['sections_visible']['data_practices']),
                'faqs'           => !empty($input['sections_visible']['faqs']),
                'contact'        => !empty($input['sections_visible']['contact']),
            ];
        } else {
            $sanitized['endpoint_slug']      = (string) ($old_settings['endpoint_slug'] ?? 'trust-center');
            $sanitized['page_title']         = (string) ($old_settings['page_title'] ?? '');
            $sanitized['company_name']       = (string) ($old_settings['company_name'] ?? '');
            $sanitized['tagline']            = (string) ($old_settings['tagline'] ?? '');
            $sanitized['logo_id']            = (int) ($old_settings['logo_id'] ?? 0);
            $sanitized['avatar_id']          = (int) ($old_settings['avatar_id'] ?? 0);
            $sanitized['accent_color']       = (string) ($old_settings['accent_color'] ?? '#2563EB');
            $sanitized['accent_force_exact'] = !empty($old_settings['accent_force_exact']);
            $sanitized['sections_visible']   = is_array($old_settings['sections_visible'] ?? null)
                ? $old_settings['sections_visible']
                : [
                    'certifications' => true,
                    'policies'       => true,
                    'subprocessors'  => true,
                    'data_practices' => true,
                    'faqs'           => true,
                    'contact'        => true,
                ];
        }

        // ── Contact tab (Get in touch block) ──
        if (!empty($input['__contact_tab_save'])) {
            $sanitized['company_description']  = sanitize_textarea_field($input['company_description'] ?? '');
            $sanitized['dpo_name']             = sanitize_text_field($input['dpo_name']                ?? '');
            $sanitized['dpo_email']            = sanitize_email($input['dpo_email']                    ?? '');
            $sanitized['security_email']       = sanitize_email($input['security_email']               ?? '');
            $sanitized['contact_form_url']     = esc_url_raw($input['contact_form_url']                ?? '');
            $sanitized['contact_address']      = sanitize_textarea_field($input['contact_address']     ?? '');
            $sanitized['pgp_key_url']          = esc_url_raw($input['pgp_key_url']                     ?? '');
            $sanitized['company_registration'] = sanitize_text_field($input['company_registration']    ?? '');
            $sanitized['vat_number']           = sanitize_text_field($input['vat_number']              ?? '');
        } else {
            $sanitized['company_description']  = (string) ($old_settings['company_description']  ?? '');
            $sanitized['dpo_name']             = (string) ($old_settings['dpo_name']             ?? '');
            $sanitized['dpo_email']            = (string) ($old_settings['dpo_email']            ?? '');
            $sanitized['security_email']       = (string) ($old_settings['security_email']       ?? '');
            $sanitized['contact_form_url']     = (string) ($old_settings['contact_form_url']     ?? '');
            $sanitized['contact_address']      = (string) ($old_settings['contact_address']      ?? '');
            $sanitized['pgp_key_url']          = (string) ($old_settings['pgp_key_url']          ?? '');
            $sanitized['company_registration'] = (string) ($old_settings['company_registration'] ?? '');
            $sanitized['vat_number']           = (string) ($old_settings['vat_number']           ?? '');
        }

        // ── Per-site salt ─────────────────────────────────────────
        // Written out-of-band by OpenTrust_Chat_Budget::site_salt(). Never
        // sourced from the form — carry forward byte-for-byte so saving settings
        // doesn't force a salt regeneration (which would invalidate all
        // in-flight rate-limit hashes and Turnstile bypass transients).
        if (isset($old_settings['opentrust_site_salt']) && is_string($old_settings['opentrust_site_salt'])) {
            $sanitized['opentrust_site_salt'] = $old_settings['opentrust_site_salt'];
        }

        // ── AI chat (OTC) ──────────────────────────
        // `ai_enabled`, `ai_provider`, and `ai_model_list_cached_at` are
        // server-controlled by the key-save handler — never sourced from the form.
        $sanitized['ai_enabled']              = !empty($old_settings['ai_enabled']);
        $sanitized['ai_provider']             = sanitize_key($old_settings['ai_provider'] ?? '');
        $sanitized['ai_model_list_cached_at'] = (int) ($old_settings['ai_model_list_cached_at'] ?? 0);

        // The AI tab's save form carries a sentinel flag so we only parse AI
        // fields from the submission when we're on that tab. On other tabs,
        // AI fields are absent from $input and must be preserved from old settings.
        if (!empty($input['__ai_tab_save'])) {
            $sanitized['ai_model']                  = sanitize_text_field($input['ai_model'] ?? ($old_settings['ai_model'] ?? ''));
            $sanitized['ai_daily_token_budget']     = max(0, absint($input['ai_daily_token_budget']   ?? 500000));
            $sanitized['ai_monthly_token_budget']   = max(0, absint($input['ai_monthly_token_budget'] ?? 10000000));
            $sanitized['ai_rate_limit_per_ip']      = max(0, min(1000,  absint($input['ai_rate_limit_per_ip']      ?? 10)));
            $sanitized['ai_rate_limit_per_session'] = max(0, min(10000, absint($input['ai_rate_limit_per_session'] ?? 50)));
            $sanitized['ai_max_message_length']     = max(100, min(4000, absint($input['ai_max_message_length'] ?? 1000)));
            $sanitized['ai_contact_url']            = esc_url_raw($input['ai_contact_url'] ?? '');
            $sanitized['ai_show_model_attribution'] = !empty($input['ai_show_model_attribution']);
            $sanitized['ai_logging_enabled']        = !empty($input['ai_logging_enabled']);
            $sanitized['ai_turnstile_enabled']      = !empty($input['ai_turnstile_enabled']);
            $sanitized['turnstile_site_key']        = sanitize_text_field($input['turnstile_site_key'] ?? '');
            $sanitized['turnstile_secret_key']      = self::sanitize_secret_field(
                $input['turnstile_secret_key'] ?? '',
                $old_settings['turnstile_secret_key'] ?? ''
            );
        } else {
            $sanitized['ai_model']                  = sanitize_text_field($old_settings['ai_model'] ?? '');
            $sanitized['ai_daily_token_budget']     = (int) ($old_settings['ai_daily_token_budget']     ?? 500000);
            $sanitized['ai_monthly_token_budget']   = (int) ($old_settings['ai_monthly_token_budget']   ?? 10000000);
            $sanitized['ai_rate_limit_per_ip']      = (int) ($old_settings['ai_rate_limit_per_ip']      ?? 10);
            $sanitized['ai_rate_limit_per_session'] = (int) ($old_settings['ai_rate_limit_per_session'] ?? 50);
            $sanitized['ai_max_message_length']     = (int) ($old_settings['ai_max_message_length']     ?? 1000);
            $sanitized['ai_contact_url']            = (string) ($old_settings['ai_contact_url']         ?? '');
            $sanitized['ai_show_model_attribution'] = !empty($old_settings['ai_show_model_attribution']);
            $sanitized['ai_logging_enabled']        = !empty($old_settings['ai_logging_enabled']);
            $sanitized['ai_turnstile_enabled']      = !empty($old_settings['ai_turnstile_enabled']);
            $sanitized['turnstile_site_key']        = (string) ($old_settings['turnstile_site_key']   ?? '');
            $sanitized['turnstile_secret_key']      = (string) ($old_settings['turnstile_secret_key'] ?? '');
        }

        // Flag rewrite flush if slug changed.
        if ($sanitized['endpoint_slug'] !== ($old_settings['endpoint_slug'] ?? '')) {
            set_transient('opentrust_flush_rewrite', true);
        }

        return $sanitized;
    }

    /**
     * Persist a form-submitted secret as libsodium ciphertext.
     *
     * If the submitted value is empty or the masked-bullet placeholder, the
     * existing stored ciphertext is preserved unchanged. A real new value is
     * text-sanitized and then encrypted with OpenTrust_Chat_Secrets so the
     * option never carries the plaintext — even though opentrust_settings is
     * autoloaded, the on-disk value is useless without AUTH_KEY.
     */
    private static function sanitize_secret_field(string $new_value, string $old_value): string {
        // Masked placeholder — user didn't change it.
        if ($new_value === '' || $new_value === str_repeat('•', 20) || str_starts_with($new_value, '••••')) {
            return $old_value;
        }
        $clean = sanitize_text_field($new_value);
        if ($clean === '') {
            return $old_value;
        }
        return OpenTrust_Chat_Secrets::encrypt($clean);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch on admin settings page.
        $tab      = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'general';
        if (!in_array($tab, ['general', 'contact', 'ai'], true)) {
            $tab = 'general';
        }
        $base_url = admin_url('admin.php?page=opentrust');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <p>
                <a href="<?php echo esc_url($tc_url); ?>" target="_blank" class="button button-secondary">
                    <?php esc_html_e('View Trust Center', 'opentrust'); ?> &rarr;
                </a>
            </p>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base_url); ?>"
                   class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>">
                    <?php esc_html_e('General', 'opentrust'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'contact', $base_url)); ?>"
                   class="nav-tab <?php echo $tab === 'contact' ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>">
                    <?php esc_html_e('Contact', 'opentrust'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'ai', $base_url)); ?>"
                   class="nav-tab <?php echo $tab === 'ai' ? 'nav-tab-active' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded string ?>">
                    <?php esc_html_e('AI Chat', 'opentrust'); ?>
                    <?php if (!empty($settings['ai_enabled'])): ?>
                        <span class="ot-pill ot-pill--live" style="margin-left:6px;padding:2px 8px;background:#dcfce7;color:#166534;border-radius:10px;font-size:11px;font-weight:600;vertical-align:middle">
                            <?php esc_html_e('Live', 'opentrust'); ?>
                        </span>
                    <?php endif; ?>
                </a>
            </h2>

            <?php if ($tab === 'ai'): ?>
                <?php $this->render_ai_tab($settings); ?>
            <?php elseif ($tab === 'contact'): ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('opentrust_settings_group');
                    echo '<input type="hidden" name="opentrust_settings[__contact_tab_save]" value="1">';
                    do_settings_sections('opentrust-settings-contact');
                    submit_button();
                    ?>
                </form>
            <?php else: ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('opentrust_settings_group');
                    echo '<input type="hidden" name="opentrust_settings[__general_tab_save]" value="1">';
                    do_settings_sections('opentrust-settings-general');
                    submit_button();
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }


    // ──────────────────────────────────────────────
    // Assets
    // ──────────────────────────────────────────────

    // ──────────────────────────────────────────────
    // AI tab — rendering
    // ──────────────────────────────────────────────

    private function render_ai_tab(array $settings): void {
        $stored_keys     = OpenTrust_Chat_Secrets::get_all();
        $active_provider = $settings['ai_provider'] ?? '';
        $has_active_key  = $active_provider !== '' && isset($stored_keys[$active_provider]);
        $is_non_anthropic_active = $has_active_key && $active_provider !== 'anthropic';

        // Surface any transient notice from the admin-post handlers.
        $notice = get_transient('opentrust_ai_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('opentrust_ai_notice_' . get_current_user_id());
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                esc_attr($class),
                esc_html((string) $notice['message'])
            );
        }

        ?>
        <?php if ($is_non_anthropic_active): ?>
            <div class="ot-ai-active-warning">
                <strong><?php esc_html_e('Heads up: citation fidelity is not guaranteed on your active provider.', 'opentrust'); ?></strong>
                <p>
                    <?php
                    printf(
                        /* translators: %s: provider label, e.g. OpenAI */
                        esc_html__('You are currently using %s. Only Anthropic uses a structural Citations API — every other provider relies on prompted citation tags the model can ignore or fabricate. For a published trust center, switch to Anthropic below.', 'opentrust'),
                        '<strong>' . esc_html(ucfirst($active_provider)) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <p class="ot-ai-intro">
            <?php
            echo wp_kses(
                __('OpenTrust uses <strong>Anthropic Claude with the native Citations API</strong> to answer visitor questions about your trust center. Every claim the assistant makes is tied to an exact quote from one of your published documents — so no policy text is invented and nothing is paraphrased into something you did not actually publish.', 'opentrust'),
                ['strong' => []]
            );
            ?>
        </p>

        <details class="ot-ai-rationale">
            <summary><?php esc_html_e('Why Anthropic, and not OpenAI or any other provider?', 'opentrust'); ?></summary>
            <div class="ot-ai-rationale__body">
                <p>
                    <?php
                    echo wp_kses(
                        __('A trust center is a <strong>compliance surface</strong>. If the assistant invents a security commitment you never made, that is not a UX papercut — it is a misrepresentation of your security posture, and your customers and auditors will hold you to it.', 'opentrust'),
                        ['strong' => []]
                    );
                    ?>
                </p>
                <p>
                    <?php
                    echo wp_kses(
                        __('Anthropic is the <strong>only major provider</strong> that exposes a structural Citations API. Documents are sent as typed blocks and the model emits citations as first-class events containing the exact source document and the exact quoted text. The model literally cannot return a citation for text that is not in your source documents.', 'opentrust'),
                        ['strong' => []]
                    );
                    ?>
                </p>
                <p>
                    <?php esc_html_e('Every other provider (including OpenAI and any model accessed via OpenRouter) relies on prompted citation tags that we parse out of the answer after the fact. That works most of the time, but the model can ignore the instructions, make up document IDs, or attach a citation to a sentence it actually hallucinated. We support these providers as an escape hatch for organizations that genuinely cannot use Anthropic for procurement or data-residency reasons — but we very, very strongly recommend you do not run a public trust center on them.', 'opentrust'); ?>
                </p>
            </div>
        </details>

        <?php $this->render_ai_provider_picker($settings, $stored_keys); ?>

        <?php if ($has_active_key): ?>
            <?php $this->render_ai_settings_form($settings); ?>
        <?php endif; ?>
        <?php
    }

    private function render_ai_provider_picker(array $settings, array $stored_keys): void {
        $providers       = OpenTrust_Chat_Provider::available();
        $active_provider = $settings['ai_provider'] ?? '';

        // Partition: Anthropic is the primary, everything else is "advanced".
        $primary  = null;
        $advanced = [];
        foreach ($providers as $provider) {
            if ($provider['slug'] === 'anthropic') {
                $primary = $provider;
            } else {
                $advanced[] = $provider;
            }
        }

        // Defensive fallback: if Anthropic is somehow not registered, render
        // everything flat so the tab never breaks.
        if ($primary === null) {
            echo '<h3 class="ot-ai-section-heading">' . esc_html__('Choose a provider and add your key', 'opentrust') . '</h3>';
            echo '<div class="ot-ai-advanced__grid">';
            foreach ($providers as $provider) {
                $this->render_provider_card($provider, $stored_keys, $active_provider, 'advanced');
            }
            echo '</div>';
            return;
        }

        $is_anthropic_active = $active_provider === 'anthropic' && isset($stored_keys['anthropic']);
        $advanced_open       = $active_provider !== '' && $active_provider !== 'anthropic';
        ?>
        <h3 class="ot-ai-section-heading"><?php esc_html_e('Step 1 — Connect Anthropic', 'opentrust'); ?></h3>

        <?php $this->render_provider_card($primary, $stored_keys, $active_provider, 'primary'); ?>

        <?php if (!empty($advanced)): ?>
            <details class="ot-ai-advanced"<?php echo $advanced_open ? ' open' : ''; ?>>
                <summary><?php esc_html_e('Advanced: use a different provider (not recommended)', 'opentrust'); ?></summary>

                <div class="ot-ai-advanced__warning">
                    <strong><?php esc_html_e('These providers cannot guarantee citation fidelity.', 'opentrust'); ?></strong>
                    <p>
                        <?php esc_html_e('OpenAI and OpenRouter rely on prompted [[cite:document-id]] tags that we parse out of the answer after generation. The model can ignore the instruction, invent document IDs, or attach a citation to a sentence it actually hallucinated. We cannot detect when this happens.', 'opentrust'); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Do not use these providers for a published trust center', 'opentrust'); ?></strong>
                        <?php esc_html_e('unless your organization genuinely cannot use Anthropic for procurement, contractual, or data-residency reasons. Inaccurate claims about your security posture are a real compliance risk.', 'opentrust'); ?>
                    </p>
                </div>

                <div class="ot-ai-advanced__grid">
                    <?php foreach ($advanced as $provider): ?>
                        <?php $this->render_provider_card($provider, $stored_keys, $active_provider, 'advanced'); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <?php
    }

    /**
     * Render a single provider card. The 'primary' variant is the full-width
     * Anthropic card with descriptive copy; the 'advanced' variant is the
     * smaller, muted card used inside the advanced disclosure.
     *
     * @param array<string, mixed> $provider
     * @param array<string, string> $stored_keys
     */
    private function render_provider_card(array $provider, array $stored_keys, string $active_provider, string $variant): void {
        $slug      = (string) $provider['slug'];
        $label     = (string) $provider['label'];
        $key_url   = (string) $provider['key_url'];
        $is_active = $slug === $active_provider;
        $has_key   = isset($stored_keys[$slug]);
        $masked    = $has_key ? OpenTrust_Chat_Secrets::mask($stored_keys[$slug]) : '';

        $card_classes = ['ot-ai-card', 'ot-ai-card--' . $variant];
        if ($is_active) {
            $card_classes[] = 'is-active';
        }
        ?>
        <div class="<?php echo esc_attr(implode(' ', $card_classes)); ?>">
            <div class="ot-ai-card__header">
                <h4 class="ot-ai-card__title"><?php echo esc_html($label); ?></h4>
                <?php if ($variant === 'primary'): ?>
                    <span class="ot-ai-card__badge"><?php esc_html_e('Required for citation fidelity', 'opentrust'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($variant === 'primary'): ?>
                <p class="ot-ai-card__description">
                    <?php esc_html_e('Uses Claude with the native Citations API. Every quote the assistant attributes to one of your documents is structurally guaranteed to come from that document.', 'opentrust'); ?>
                </p>
            <?php endif; ?>

            <p class="ot-ai-card__keylink">
                <a href="<?php echo esc_url($key_url); ?>" target="_blank" rel="noopener">
                    <?php
                    /* translators: %s: provider name (e.g. Anthropic) */
                    printf(esc_html__('Get a %s API key', 'opentrust'), esc_html($label));
                    ?> ↗
                </a>
            </p>

            <?php if ($has_key && $is_active): ?>
                <div class="ot-ai-card__saved">
                    ✓ <?php echo esc_html($masked); ?>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('opentrust_ai_forget_key'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_forget_key">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                    <button type="submit" class="button-link ot-ai-card__forget" onclick="return confirm('<?php echo esc_js(__('Remove the saved key for this provider?', 'opentrust')); ?>')">
                        <?php esc_html_e('Replace key', 'opentrust'); ?>
                    </button>
                </form>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('opentrust_ai_save_key'); ?>
                    <input type="hidden" name="action" value="opentrust_ai_save_key">
                    <input type="hidden" name="provider" value="<?php echo esc_attr($slug); ?>">
                    <input type="password" name="api_key" class="ot-ai-card__input" autocomplete="off" placeholder="<?php echo esc_attr(sprintf(
                        /* translators: %s: provider name (e.g. Anthropic) */
                        __('Paste your %s API key…', 'opentrust'),
                        $label
                    )); ?>" required>
                    <button type="submit" class="button button-primary ot-ai-card__submit">
                        <?php esc_html_e('Validate & save', 'opentrust'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_ai_settings_form(array $settings): void {
        $active_provider = $settings['ai_provider'];
        $models          = $this->get_cached_model_list($active_provider);
        $current_model   = $settings['ai_model'] ?? '';
        $refresh_url     = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_refresh_models&provider=' . rawurlencode($active_provider)),
            'opentrust_ai_refresh_models'
        );
        ?>
        <h3 style="margin-top:32px"><?php esc_html_e('Step 2 — Pick a model and tune defaults', 'opentrust'); ?></h3>

        <form method="post" action="options.php">
            <?php settings_fields('opentrust_settings_group'); ?>

            <?php // Sentinel so sanitize_settings knows the AI tab is submitting. ?>
            <input type="hidden" name="opentrust_settings[__ai_tab_save]" value="1">

            <?php // Carry forward every non-AI setting so the options.php save doesn't wipe General tab. ?>
            <?php foreach ($settings as $k => $v): ?>
                <?php if (str_starts_with((string) $k, 'ai_')) { continue; } ?>
                <?php if (is_array($v)): ?>
                    <?php foreach ($v as $sub_k => $sub_v): ?>
                        <input type="hidden" name="opentrust_settings[<?php echo esc_attr($k); ?>][<?php echo esc_attr((string) $sub_k); ?>]" value="<?php echo esc_attr((string) $sub_v); ?>">
                    <?php endforeach; ?>
                <?php else: ?>
                    <input type="hidden" name="opentrust_settings[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr((string) $v); ?>">
                <?php endif; ?>
            <?php endforeach; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="opentrust_ai_model"><?php esc_html_e('Active model', 'opentrust'); ?></label></th>
                    <td>
                        <?php if (empty($models)): ?>
                            <p class="description" style="color:#b91c1c">
                                <?php esc_html_e('No cached models found. Use Refresh to re-fetch the model list.', 'opentrust'); ?>
                            </p>
                        <?php else: ?>
                            <select id="opentrust_ai_model" name="opentrust_settings[ai_model]" style="min-width:360px">
                                <?php foreach ($models as $model): ?>
                                    <option value="<?php echo esc_attr($model['id']); ?>" <?php selected($current_model, $model['id']); ?>>
                                        <?php echo esc_html($model['display_name']); ?>
                                        <?php if (!empty($model['recommended'])): ?>
                                            — ★ <?php esc_html_e('Recommended', 'opentrust'); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <a href="<?php echo esc_url($refresh_url); ?>" class="button" style="margin-left:8px">
                            <?php esc_html_e('Refresh models', 'opentrust'); ?>
                        </a>
                        <?php
                        $cached_at = (int) ($settings['ai_model_list_cached_at'] ?? 0);
                        if ($cached_at > 0):
                            $diff = human_time_diff($cached_at);
                            ?>
                            <p class="description">
                                <?php
                                /* translators: %s: human-readable time difference (e.g. "5 minutes") */
                                printf(esc_html__('Model list cached %s ago.', 'opentrust'), esc_html($diff));
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="opentrust_ai_daily_token_budget"><?php esc_html_e('Daily token budget', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_daily_token_budget" name="opentrust_settings[ai_daily_token_budget]" value="<?php echo esc_attr((string) ($settings['ai_daily_token_budget'] ?? 500000)); ?>" min="0" step="10000" class="regular-text">
                        <p class="description"><?php esc_html_e('Hard cap per site per day. Default 500,000 tokens (~$12/day at Sonnet 4.5 rates).', 'opentrust'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_monthly_token_budget"><?php esc_html_e('Monthly token budget', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_monthly_token_budget" name="opentrust_settings[ai_monthly_token_budget]" value="<?php echo esc_attr((string) ($settings['ai_monthly_token_budget'] ?? 10000000)); ?>" min="0" step="100000" class="regular-text">
                        <p class="description"><?php esc_html_e('Hard cap per site per month. Default 10,000,000 tokens.', 'opentrust'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_rate_limit_per_ip"><?php esc_html_e('Rate limit — per IP', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_rate_limit_per_ip" name="opentrust_settings[ai_rate_limit_per_ip]" value="<?php echo esc_attr((string) ($settings['ai_rate_limit_per_ip'] ?? 10)); ?>" min="0" max="1000" step="1" class="small-text"> <span class="description"><?php esc_html_e('messages per minute', 'opentrust'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_rate_limit_per_session"><?php esc_html_e('Rate limit — per session', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_rate_limit_per_session" name="opentrust_settings[ai_rate_limit_per_session]" value="<?php echo esc_attr((string) ($settings['ai_rate_limit_per_session'] ?? 50)); ?>" min="0" max="10000" step="1" class="small-text"> <span class="description"><?php esc_html_e('messages per hour', 'opentrust'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_ai_max_message_length"><?php esc_html_e('Max message length', 'opentrust'); ?></label></th>
                    <td>
                        <input type="number" id="opentrust_ai_max_message_length" name="opentrust_settings[ai_max_message_length]" value="<?php echo esc_attr((string) ($settings['ai_max_message_length'] ?? 1000)); ?>" min="100" max="4000" step="100" class="small-text"> <span class="description"><?php esc_html_e('characters', 'opentrust'); ?></span>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="opentrust_ai_contact_url"><?php esc_html_e('Refuse-to-answer contact URL', 'opentrust'); ?></label></th>
                    <td>
                        <input type="url" id="opentrust_ai_contact_url" name="opentrust_settings[ai_contact_url]" value="<?php echo esc_attr((string) ($settings['ai_contact_url'] ?? '')); ?>" class="regular-text" placeholder="https://example.com/contact">
                        <p class="description"><?php esc_html_e('When the AI cannot confidently answer a question, it links here. Leave blank to use the trust center home.', 'opentrust'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Visitor display', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_show_model_attribution]" value="1" <?php checked(!empty($settings['ai_show_model_attribution'])); ?>>
                            <?php esc_html_e('Show "Powered by {model}" under the chat input', 'opentrust'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Analytics logging', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_logging_enabled]" value="1" <?php checked(!empty($settings['ai_logging_enabled'])); ?>>
                            <?php esc_html_e('Log anonymized visitor questions for admin review (90-day auto-purge, no PII)', 'opentrust'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:24px"><?php esc_html_e('Advanced — Turnstile anti-abuse', 'opentrust'); ?></h3>
            <p class="description" style="max-width:720px">
                <?php esc_html_e('Cloudflare Turnstile is optional but recommended for public sites. It challenges suspicious visitors on the first message of each session. You need a free Cloudflare account to get site/secret keys.', 'opentrust'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Turnstile for chat', 'opentrust'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opentrust_settings[ai_turnstile_enabled]" value="1" <?php checked(!empty($settings['ai_turnstile_enabled'])); ?>>
                            <?php esc_html_e('Require Turnstile verification on first chat message', 'opentrust'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_turnstile_site_key"><?php esc_html_e('Turnstile Site Key', 'opentrust'); ?></label></th>
                    <td>
                        <input type="text" id="opentrust_turnstile_site_key" name="opentrust_settings[turnstile_site_key]" value="<?php echo esc_attr((string) ($settings['turnstile_site_key'] ?? '')); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Public site key from your Cloudflare Turnstile widget.', 'opentrust'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="opentrust_turnstile_secret_key"><?php esc_html_e('Turnstile Secret Key', 'opentrust'); ?></label></th>
                    <td>
                        <?php $ot_secret_saved = !empty($settings['turnstile_secret_key']); ?>
                        <input type="password" id="opentrust_turnstile_secret_key" name="opentrust_settings[turnstile_secret_key]" value="<?php echo esc_attr($ot_secret_saved ? '••••••••••••••••••••' : ''); ?>" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Enter secret key…', 'opentrust'); ?>">
                        <?php if ($ot_secret_saved): ?>
                            <span class="description" style="color:#16a34a">&#10003; <?php esc_html_e('Key saved', 'opentrust'); ?></span>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Secret key from Cloudflare Turnstile. Stored server-side — never exposed to the frontend.', 'opentrust'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save AI settings', 'opentrust')); ?>
        </form>
        <?php
    }

    /**
     * Persist an updated settings array bypassing sanitize_settings.
     *
     * The sanitize callback intentionally treats `ai_enabled`, `ai_provider`,
     * and `ai_model_list_cached_at` as server-controlled — it always carries
     * them forward from $old_settings so a form submission cannot spoof them.
     * The admin-post key-save handlers ARE the authoritative server path for
     * those fields, so they must write them without the callback clobbering
     * the change. We detach the filter for a single update_option call and
     * reattach immediately. No security regression: every form-submission path
     * still runs through the full sanitize filter.
     */
    private function save_settings_raw(array $settings): void {
        remove_filter('sanitize_option_opentrust_settings', [$this, 'sanitize_settings']);
        update_option('opentrust_settings', $settings);
        add_filter('sanitize_option_opentrust_settings', [$this, 'sanitize_settings']);
    }

    /**
     * Read the cached model list for a provider. Returns an empty array if missing.
     */
    private function get_cached_model_list(string $provider): array {
        if ($provider === '') {
            return [];
        }
        $stored_keys = OpenTrust_Chat_Secrets::get_all();
        if (!isset($stored_keys[$provider])) {
            return [];
        }
        $fingerprint = OpenTrust_Chat_Secrets::fingerprint($stored_keys[$provider]);
        $cached      = get_transient('opentrust_models_' . $provider . '_' . $fingerprint);
        return is_array($cached) && isset($cached['models']) && is_array($cached['models'])
            ? $cached['models']
            : [];
    }

    // ──────────────────────────────────────────────
    // AI tab — admin-post handlers
    // ──────────────────────────────────────────────

    public function handle_ai_save_key(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_save_key');

        $provider = isset($_POST['provider']) ? sanitize_key((string) wp_unslash($_POST['provider'])) : '';
        $api_key  = isset($_POST['api_key'])  ? trim((string) wp_unslash($_POST['api_key']))          : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Trimmed secret token; sanitize_text_field() would strip characters valid in API keys.

        $adapter = OpenTrust_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }
        if ($api_key === '') {
            $this->ai_notice('error', __('API key cannot be empty.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $result = $adapter->validate_and_list_models($api_key);

        if (empty($result['ok'])) {
            $error = $result['error'] ?? __('Validation failed.', 'opentrust');
            /* translators: 1: provider label, 2: provider error message */
            $msg = sprintf(__('%1$s rejected the key: %2$s', 'opentrust'), $adapter->label(), $error);
            $this->ai_notice('error', $msg);
            $this->redirect_to_ai_tab();
        }

        // Persist the key (encrypted).
        OpenTrust_Chat_Secrets::put($provider, $api_key);

        // Cache the model list keyed by fingerprint, NOT by key.
        $fingerprint = OpenTrust_Chat_Secrets::fingerprint($api_key);
        set_transient(
            'opentrust_models_' . $provider . '_' . $fingerprint,
            ['models' => $result['models'], 'fetched_at' => time()],
            24 * HOUR_IN_SECONDS
        );

        // Update settings: mark AI enabled, record provider + cache timestamp,
        // and if no model is selected yet, pre-pick the first recommended model.
        $settings = OpenTrust::get_settings();
        $settings['ai_enabled']              = true;
        $settings['ai_provider']             = $provider;
        $settings['ai_model_list_cached_at'] = time();
        if (empty($settings['ai_model'])) {
            foreach ($result['models'] as $model) {
                if (!empty($model['recommended'])) {
                    $settings['ai_model'] = $model['id'];
                    break;
                }
            }
            if (empty($settings['ai_model']) && !empty($result['models'][0]['id'])) {
                $settings['ai_model'] = $result['models'][0]['id'];
            }
        }
        $this->save_settings_raw($settings);

        /* translators: 1: provider label, 2: number of models */
        $count_msg = sprintf(__('%1$s key validated. Found %2$d model(s).', 'opentrust'), $adapter->label(), count($result['models']));
        $this->ai_notice('success', $count_msg);
        $this->redirect_to_ai_tab();
    }

    public function handle_ai_forget_key(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_forget_key');

        $provider = isset($_POST['provider']) ? sanitize_key((string) wp_unslash($_POST['provider'])) : '';

        $adapter = OpenTrust_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        // Clear cached model list for this key before forgetting.
        $existing = OpenTrust_Chat_Secrets::get($provider);
        if ($existing !== null) {
            $fingerprint = OpenTrust_Chat_Secrets::fingerprint($existing);
            delete_transient('opentrust_models_' . $provider . '_' . $fingerprint);
        }

        OpenTrust_Chat_Secrets::forget($provider);

        // If the forgotten provider was the active one, disable chat and clear the model.
        $settings = OpenTrust::get_settings();
        if (($settings['ai_provider'] ?? '') === $provider) {
            $settings['ai_enabled']  = false;
            $settings['ai_provider'] = '';
            $settings['ai_model']    = '';
            $settings['ai_model_list_cached_at'] = 0;
            $this->save_settings_raw($settings);
        }

        $this->ai_notice('success', __('Key removed.', 'opentrust'));
        $this->redirect_to_ai_tab();
    }

    public function handle_ai_refresh_models(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_refresh_models');

        $provider = isset($_GET['provider']) ? sanitize_key((string) wp_unslash($_GET['provider'])) : '';
        $adapter  = OpenTrust_Chat_Provider::for($provider);
        if (!$adapter) {
            $this->ai_notice('error', __('Unknown provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $api_key = OpenTrust_Chat_Secrets::get($provider);
        if ($api_key === null) {
            $this->ai_notice('error', __('No key on file for this provider.', 'opentrust'));
            $this->redirect_to_ai_tab();
        }

        $result = $adapter->validate_and_list_models($api_key);
        if (empty($result['ok'])) {
            $error = $result['error'] ?? __('Refresh failed.', 'opentrust');
            /* translators: %s: error message from the provider */
            $this->ai_notice('error', sprintf(__('Refresh failed: %s', 'opentrust'), $error));
            $this->redirect_to_ai_tab();
        }

        $fingerprint = OpenTrust_Chat_Secrets::fingerprint($api_key);
        set_transient(
            'opentrust_models_' . $provider . '_' . $fingerprint,
            ['models' => $result['models'], 'fetched_at' => time()],
            24 * HOUR_IN_SECONDS
        );

        $settings = OpenTrust::get_settings();
        $settings['ai_model_list_cached_at'] = time();
        $this->save_settings_raw($settings);

        /* translators: %d: number of models */
        $this->ai_notice('success', sprintf(__('Model list refreshed. Found %d model(s).', 'opentrust'), count($result['models'])));
        $this->redirect_to_ai_tab();
    }

    private function ai_notice(string $type, string $message): void {
        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => $type, 'message' => $message],
            MINUTE_IN_SECONDS
        );
    }

    private function redirect_to_ai_tab(): never {
        wp_safe_redirect(admin_url('admin.php?page=opentrust&tab=ai'));
        exit;
    }

    // ──────────────────────────────────────────────
    // AI Questions screen
    // ──────────────────────────────────────────────

    public function render_questions_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = OpenTrust::get_settings();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter params on admin display page.
        $filters = [
            'search'    => isset($_GET['q'])         ? sanitize_text_field((string) wp_unslash($_GET['q']))         : '',
            'model'     => isset($_GET['model'])     ? sanitize_text_field((string) wp_unslash($_GET['model']))     : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) wp_unslash($_GET['date_from'])) : '',
            'date_to'   => isset($_GET['date_to'])   ? sanitize_text_field((string) wp_unslash($_GET['date_to']))   : '',
            'page'      => isset($_GET['paged'])     ? max(1, (int) $_GET['paged'])                                 : 1,
            'per_page'  => 25,
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $result  = OpenTrust_Chat_Log::query($filters);
        $total   = $result['total'];
        $rows    = $result['rows'];
        $pages   = max(1, (int) ceil($total / $filters['per_page']));
        $models  = OpenTrust_Chat_Log::distinct_models();
        $counts  = OpenTrust_Chat_Log::total_count();

        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_questions_export&' . http_build_query(array_filter($filters + ['paged' => 0]))),
            'opentrust_ai_questions_export'
        );
        $clear_url  = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_questions_clear'),
            'opentrust_ai_questions_clear'
        );
        $toggle_url = wp_nonce_url(
            admin_url('admin-post.php?action=opentrust_ai_toggle_logging'),
            'opentrust_ai_toggle_logging'
        );

        $notice = get_transient('opentrust_ai_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('opentrust_ai_notice_' . get_current_user_id());
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html((string) $notice['message']));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Questions', 'opentrust'); ?></h1>

            <p style="color:#50575e;max-width:720px">
                <?php esc_html_e('Questions visitors have asked your trust center chat. Identifiers are hashed and rows auto-purge after 90 days.', 'opentrust'); ?>
            </p>

            <div style="display:flex;align-items:center;gap:16px;margin:16px 0;padding:12px 16px;background:<?php echo !empty($settings['ai_logging_enabled']) ? '#dcfce7' : '#fef2f2'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;border-radius:6px">
                <strong>
                    <?php if (!empty($settings['ai_logging_enabled'])): ?>
                        ✓ <?php esc_html_e('Logging is ON', 'opentrust'); ?>
                    <?php else: ?>
                        ✗ <?php esc_html_e('Logging is OFF', 'opentrust'); ?>
                    <?php endif; ?>
                </strong>
                <span style="color:#50575e">
                    <?php
                    /* translators: %d: number of questions */
                    printf(esc_html(_n('%d question logged in the last 90 days', '%d questions logged in the last 90 days', (int) $counts, 'opentrust')), (int) $counts);
                    ?>
                </span>
                <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small" style="margin-left:auto"
                   onclick="return confirm('<?php echo esc_js(__('Toggle visitor question logging?', 'opentrust')); ?>')">
                    <?php echo !empty($settings['ai_logging_enabled']) ? esc_html__('Disable logging', 'opentrust') : esc_html__('Enable logging', 'opentrust'); ?>
                </a>
            </div>

            <form method="get" action="" style="margin:16px 0">
                <input type="hidden" name="page" value="opentrust-questions">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase"><?php esc_html_e('Search', 'opentrust'); ?></label>
                        <input type="text" name="q" value="<?php echo esc_attr($filters['search']); ?>" class="regular-text" placeholder="<?php esc_attr_e('Search questions…', 'opentrust'); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase"><?php esc_html_e('Model', 'opentrust'); ?></label>
                        <select name="model">
                            <option value=""><?php esc_html_e('Any', 'opentrust'); ?></option>
                            <?php foreach ($models as $m): ?>
                                <option value="<?php echo esc_attr($m); ?>" <?php selected($filters['model'], $m); ?>><?php echo esc_html($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase"><?php esc_html_e('From', 'opentrust'); ?></label>
                        <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase"><?php esc_html_e('To', 'opentrust'); ?></label>
                        <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
                    </div>
                    <button type="submit" class="button"><?php esc_html_e('Filter', 'opentrust'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=opentrust-questions')); ?>" class="button"><?php esc_html_e('Reset', 'opentrust'); ?></a>
                    <a href="<?php echo esc_url($export_url); ?>" class="button" style="margin-left:auto"><?php esc_html_e('Download CSV', 'opentrust'); ?></a>
                </div>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width:150px"><?php esc_html_e('Date', 'opentrust'); ?></th>
                        <th scope="col"><?php esc_html_e('Question', 'opentrust'); ?></th>
                        <th scope="col" style="width:140px"><?php esc_html_e('Model', 'opentrust'); ?></th>
                        <th scope="col" style="width:80px"><?php esc_html_e('Cites', 'opentrust'); ?></th>
                        <th scope="col" style="width:120px"><?php esc_html_e('Tokens', 'opentrust'); ?></th>
                        <th scope="col" style="width:90px"><?php esc_html_e('Latency', 'opentrust'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6"><?php esc_html_e('No questions logged yet.', 'opentrust'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row):
                            $row_bg = $row->refused ? 'background:#fef9c3' : '';
                        ?>
                            <tr style="<?php echo esc_attr($row_bg); ?>">
                                <td><?php echo esc_html(wp_date('M j, Y H:i', strtotime($row->created_at . ' UTC'))); ?></td>
                                <td>
                                    <?php if ($row->refused): ?>
                                        <span style="display:inline-block;padding:1px 6px;background:#fde68a;color:#854d0e;border-radius:8px;font-size:10px;font-weight:700;margin-right:6px"><?php esc_html_e('REFUSED', 'opentrust'); ?></span>
                                    <?php endif; ?>
                                    <?php echo esc_html($row->question); ?>
                                </td>
                                <td style="font-size:11px;font-family:monospace"><?php echo esc_html($row->model); ?></td>
                                <td><?php echo (int) $row->citation_count; ?></td>
                                <td style="font-size:11px;color:#50575e">
                                    ↓<?php echo (int) $row->tokens_in; ?> / ↑<?php echo (int) $row->tokens_out; ?>
                                </td>
                                <td style="font-size:11px;color:#50575e">
                                    <?php echo (int) $row->response_ms; ?>ms
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($pages > 1):
                $base = add_query_arg($filters + ['page' => 'opentrust-questions'], admin_url('admin.php'));
                $base = remove_query_arg('paged', $base);
                ?>
                <div class="tablenav" style="margin-top:16px">
                    <div class="tablenav-pages">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe HTML
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%', $base),
                            'format'    => '',
                            'current'   => $filters['page'],
                            'total'     => $pages,
                            'prev_text' => '‹',
                            'next_text' => '›',
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <hr style="margin:32px 0">
            <h3 style="color:#b91c1c"><?php esc_html_e('Danger zone', 'opentrust'); ?></h3>
            <p><a href="<?php echo esc_url($clear_url); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('Permanently delete all logged questions? This cannot be undone.', 'opentrust')); ?>')">
                <?php esc_html_e('Clear entire question log', 'opentrust'); ?>
            </a></p>
        </div>
        <?php
    }

    // ──────────────────────────────────────────────
    // AI Questions admin-post handlers
    // ──────────────────────────────────────────────

    public function handle_ai_questions_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_questions_export');

        $filters = [
            'search'    => isset($_GET['search'])    ? sanitize_text_field((string) wp_unslash($_GET['search']))    : '',
            'model'     => isset($_GET['model'])     ? sanitize_text_field((string) wp_unslash($_GET['model']))     : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field((string) wp_unslash($_GET['date_from'])) : '',
            'date_to'   => isset($_GET['date_to'])   ? sanitize_text_field((string) wp_unslash($_GET['date_to']))   : '',
            'page'      => 1,
            'per_page'  => 10000, // hard cap — nobody exports >10k rows per page
        ];

        $result = OpenTrust_Chat_Log::query($filters);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=opentrust-questions-' . gmdate('Y-m-d') . '.csv');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to php://output, not filesystem
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date (UTC)', 'Question', 'Model', 'Provider', 'Citations', 'Tokens In', 'Tokens Out', 'Response ms', 'Refused']);
        foreach ($result['rows'] as $row) {
            fputcsv($out, [
                $row->created_at,
                $row->question,
                $row->model,
                $row->provider,
                $row->citation_count,
                $row->tokens_in,
                $row->tokens_out,
                $row->response_ms,
                $row->refused ? 'yes' : 'no',
            ]);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Writing to php://output stream
        fclose($out);
        exit;
    }

    public function handle_ai_questions_clear(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_questions_clear');

        OpenTrust_Chat_Log::clear_all();

        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => 'success', 'message' => __('Question log cleared.', 'opentrust')],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust-questions'));
        exit;
    }

    public function handle_ai_toggle_logging(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_toggle_logging');

        $settings = OpenTrust::get_settings();
        $settings['ai_logging_enabled'] = empty($settings['ai_logging_enabled']);
        $this->save_settings_raw($settings);

        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => 'success', 'message' => $settings['ai_logging_enabled'] ? __('Logging enabled.', 'opentrust') : __('Logging disabled.', 'opentrust')],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust-questions'));
        exit;
    }

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

        // Localize the handful of admin strings that admin.js renders directly
        // (e.g. wp.media modal titles). Catalog-screen strings are shipped
        // separately below via window.OpenTrustCatalog.
        wp_add_inline_script(
            'opentrust-admin',
            'window.OpenTrustAdmin = ' . wp_json_encode([
                'i18n' => [
                    'selectBadgeImage' => __('Select Badge Image', 'opentrust'),
                    'useAsBadge'       => __('Use as Badge', 'opentrust'),
                    'selectArtifact'   => __('Select Proof Artifact', 'opentrust'),
                    'useAsArtifact'    => __('Use This File', 'opentrust'),
                    'uploadArtifact'   => __('Upload File', 'opentrust'),
                    'replaceArtifact'  => __('Replace File', 'opentrust'),
                ],
            ]) . ';',
            'before'
        );

        // Catalog autofill: ship the bundled vendor / practice catalog only on
        // the new-post screen for the two CPTs that support it. Edit screens
        // are deliberately excluded so we never stomp existing values.
        $screen = get_current_screen();
        if ($hook === 'post-new.php' && $screen && in_array($screen->post_type, ['ot_subprocessor', 'ot_data_practice', 'ot_certification'], true)) {
            $payload = [
                'postType' => $screen->post_type,
                'catalog'  => OpenTrust_Catalog::for_js($screen->post_type),
                'i18n'     => [
                    'noMatchHint' => __('No match in catalog, just keep typing to add manually.', 'opentrust'),
                    'helpFact'    => __('Auto-filled from catalog, you may want to verify this.', 'opentrust'),
                    'helpReview'  => __('Auto-filled template, please verify this matches how you use this service.', 'opentrust'),
                    'optionHint'  => __('click to autofill', 'opentrust'),
                    'suggestions' => __('Catalog suggestions', 'opentrust'),
                ],
            ];
            wp_add_inline_script(
                'opentrust-admin',
                'window.OpenTrustCatalog = ' . wp_json_encode($payload) . ';',
                'before'
            );
        }
    }


    /**
     * Show a persistent warning on every OpenTrust admin screen when the
     * WordPress permalink structure is "Plain" (i.e. empty). In that mode
     * all of the plugin's pretty URLs (/trust-center/, /trust-center/policy/...,
     * /trust-center/ask/) return 404.
     */
    public function render_plain_permalinks_notice(): void {
        if ((string) get_option('permalink_structure', '') !== '') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Limit the noise to OpenTrust-owned screens: top-level plugin pages,
        // subpages, and the four content CPTs. Bail on every other admin screen.
        $is_opentrust_screen =
            str_contains((string) $screen->id, 'opentrust') ||
            in_array($screen->post_type ?? '', ['ot_policy', 'ot_certification', 'ot_subprocessor', 'ot_data_practice'], true);

        if (!$is_opentrust_screen) {
            return;
        }

        $permalinks_url = admin_url('options-permalink.php');
        $home_url       = home_url('/');
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('OpenTrust requires pretty permalinks.', 'opentrust'); ?></strong>
                <?php
                printf(
                    /* translators: %s: link to Settings → Permalinks */
                    esc_html__('Your site is using "Plain" permalinks. Please go to %s and choose any other option (Post name is the WordPress default).', 'opentrust'),
                    '<a href="' . esc_url($permalinks_url) . '">' . esc_html__('Settings → Permalinks', 'opentrust') . '</a>'
                );
                ?>
            </p>
            <p style="font-size:12px;color:#50575e">
                <?php esc_html_e('Without pretty permalinks, every link OpenTrust generates returns 404 — including the trust center page itself. Visitors will not be able to reach your policies, certifications, or chat.', 'opentrust'); ?>
            </p>
            <details style="margin-top:8px">
                <summary style="cursor:pointer;font-size:12px;color:#50575e">
                    <?php esc_html_e('Read-only fallback if you cannot change permalinks', 'opentrust'); ?>
                </summary>
                <div style="margin-top:8px;padding:10px 14px;background:#f6f7f7;border-left:3px solid #dcdcde;font-size:12px;color:#50575e">
                    <p style="margin:0 0 6px">
                        <?php esc_html_e('You can preview the trust center via raw query-string URLs:', 'opentrust'); ?>
                    </p>
                    <ul style="margin:0 0 0 18px;list-style:disc">
                        <li><code><?php echo esc_html($home_url); ?>?opentrust=main</code></li>
                        <li><code><?php echo esc_html($home_url); ?>?opentrust=policy&amp;ot_policy_slug=YOUR-POLICY-SLUG</code></li>
                        <li><code><?php echo esc_html($home_url); ?>?opentrust=ask</code></li>
                    </ul>
                    <p style="margin:6px 0 0">
                        <strong><?php esc_html_e('This is for testing only.', 'opentrust'); ?></strong>
                        <?php esc_html_e('Switching to pretty permalinks is the only supported configuration.', 'opentrust'); ?>
                    </p>
                </div>
            </details>
        </div>
        <?php
    }

}
