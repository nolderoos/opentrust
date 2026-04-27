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

        // Warn admins on every OpenTrust admin page when the site is on Plain
        // permalinks — the plugin's pretty URLs all 404 in that mode.
        add_action('admin_notices', [$this, 'render_plain_permalinks_notice']);

        // Sub-admin systems own their own hooks.
        OpenTrust_Admin_Questions::instance();
        OpenTrust_Admin_AI::instance();
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
                [OpenTrust_Admin_Questions::instance(), 'render_page']
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

        if (in_array($post_type, OpenTrust_CPT::ALL, true)) {
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
        $this->render_input_field('text', $args);
    }

    public function render_email_field(array $args): void {
        $this->render_input_field('email', $args, ['autocomplete' => 'off']);
    }

    public function render_url_field(array $args): void {
        $this->render_input_field('url', $args, ['placeholder' => 'https://', 'autocomplete' => 'off']);
    }

    public function render_textarea_field(array $args): void {
        $this->render_input_field('textarea', $args);
    }

    /**
     * Shared renderer for the Settings API string-input field family.
     * One unified path so escaping rules and id/name conventions can't drift
     * between text/email/url/textarea variants.
     *
     * @param 'text'|'email'|'url'|'textarea' $type
     * @param array{key:string, description?:string} $args
     * @param array<string,string> $extra_attrs
     */
    private function render_input_field(string $type, array $args, array $extra_attrs = []): void {
        $settings = OpenTrust::get_settings();
        $key      = $args['key'];
        $value    = $settings[$key] ?? '';

        $attr_html = '';
        foreach ($extra_attrs as $name => $val) {
            $attr_html .= ' ' . $name . '="' . esc_attr($val) . '"';
        }

        if ($type === 'textarea') {
            printf(
                '<textarea id="opentrust_%1$s" name="opentrust_settings[%1$s]" rows="3" class="large-text"%3$s>%2$s</textarea>',
                esc_attr($key),
                esc_textarea($value),
                $attr_html  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute values escaped above; names are hardcoded
            );
        } else {
            printf(
                '<input type="%4$s" id="opentrust_%1$s" name="opentrust_settings[%1$s]" value="%2$s" class="regular-text"%3$s>',
                esc_attr($key),
                esc_attr($value),
                $attr_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute values escaped above; names are hardcoded
                esc_attr($type)
            );
        }

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

        $old = OpenTrust::get_settings();
        $sanitized = [];

        // Schema-driven dispatch. Each tab's form carries a save sentinel
        // (`__<tab>_tab_save`); fields belonging to the active tab come from
        // the form and run through their sanitize callback, while other tabs'
        // fields are pulled from $old. Either way the same sanitize closure
        // runs as a type-coercion guard, so saving one tab never clobbers
        // another and the produced array is always shape-stable.
        foreach (self::settings_schema() as $key => $spec) {
            $sentinel  = '__' . $spec['tab'] . '_tab_save';
            $from_form = !empty($input[$sentinel]);
            $value     = $from_form ? ($input[$key] ?? null) : ($old[$key] ?? $spec['default']);
            $sanitized[$key] = ($spec['sanitize'])($value, $old[$key] ?? $spec['default']);
        }

        // Server-controlled fields — set by the key-save / model-refresh
        // handlers, never sourced from a settings form.
        $sanitized['ai_enabled']              = !empty($old['ai_enabled']);
        $sanitized['ai_provider']             = sanitize_key($old['ai_provider'] ?? '');
        $sanitized['ai_model_list_cached_at'] = (int) ($old['ai_model_list_cached_at'] ?? 0);

        // Per-site salt — written out-of-band by OpenTrust_Chat_Budget::site_salt().
        // Carry forward byte-for-byte so saving settings doesn't force a
        // regeneration (which would invalidate all in-flight rate-limit
        // hashes and Turnstile bypass transients).
        if (isset($old['opentrust_site_salt']) && is_string($old['opentrust_site_salt'])) {
            $sanitized['opentrust_site_salt'] = $old['opentrust_site_salt'];
        }

        // Flag rewrite flush if slug changed.
        if ($sanitized['endpoint_slug'] !== ($old['endpoint_slug'] ?? '')) {
            set_transient('opentrust_flush_rewrite', true);
        }

        return $sanitized;
    }

    /**
     * Settings schema: per-key {tab, default, sanitize}. The sanitize
     * callback receives `($value, $old_value)` and must be idempotent on
     * its own output — it runs both on form input (active tab) and on
     * already-stored values (inactive tab) without intermediate type drift.
     *
     * Adding a new setting is a single entry here — no edits to the
     * tab-dispatch loop, no tracking which else-branch needs updating.
     *
     * Three settings are deliberately excluded:
     *   - `ai_enabled`, `ai_provider`, `ai_model_list_cached_at` — server-
     *     controlled by the key-save handler.
     *   - `opentrust_site_salt` — written out-of-band by Chat_Budget.
     *
     * @return array<string, array{tab:string, default:mixed, sanitize:callable}>
     */
    private static function settings_schema(): array {
        // Shared sanitizers. All idempotent on already-sanitized data so the
        // inactive-tab path (which feeds previously-stored values back through
        // the same callback) doesn't drift on type or shape.
        $string   = static fn($v): string => sanitize_text_field((string) ($v ?? ''));
        $textarea = static fn($v): string => sanitize_textarea_field((string) ($v ?? ''));
        $email    = static fn($v): string => sanitize_email((string) ($v ?? ''));
        $url      = static fn($v): string => esc_url_raw((string) ($v ?? ''));
        $bool     = static fn($v): bool   => !empty($v);
        $abs_int  = static fn($v): int    => absint($v ?? 0);

        // Bounded-int factory: clamps to [$min, $max], defaulting missing
        // values to $default before clamping.
        $bounded_int = static fn(int $min, int $max, int $default): callable =>
            static fn($v): int => max($min, min($max, absint($v ?? $default)));

        // Section visibility — the form sends nested array keys; the inactive
        // path may receive an already-flat associative array of bools. Either
        // shape collapses to the same structured set of bools.
        $sections_default = [
            'certifications' => true,
            'policies'       => true,
            'subprocessors'  => true,
            'data_practices' => true,
            'faqs'           => true,
            'contact'        => true,
        ];

        return [
            // ── General tab ──
            'endpoint_slug' => [
                'tab' => 'general',
                'default' => OpenTrust::DEFAULT_ENDPOINT_SLUG,
                'sanitize' => static fn($v) => sanitize_title((string) ($v ?? '')) ?: OpenTrust::DEFAULT_ENDPOINT_SLUG,
            ],
            'page_title'         => ['tab' => 'general', 'default' => '',        'sanitize' => $string],
            'company_name'       => ['tab' => 'general', 'default' => '',        'sanitize' => $string],
            'tagline'            => ['tab' => 'general', 'default' => '',        'sanitize' => $textarea],
            'logo_id'            => ['tab' => 'general', 'default' => 0,         'sanitize' => $abs_int],
            'avatar_id'          => ['tab' => 'general', 'default' => 0,         'sanitize' => $abs_int],
            'accent_color'       => [
                'tab' => 'general',
                'default' => '#2563EB',
                'sanitize' => static fn($v) => sanitize_hex_color((string) ($v ?? '#2563EB')) ?: '#2563EB',
            ],
            'accent_force_exact' => ['tab' => 'general', 'default' => false,     'sanitize' => $bool],
            'sections_visible' => [
                'tab' => 'general',
                'default' => $sections_default,
                'sanitize' => static fn($v) => is_array($v) ? [
                    'certifications' => !empty($v['certifications']),
                    'policies'       => !empty($v['policies']),
                    'subprocessors'  => !empty($v['subprocessors']),
                    'data_practices' => !empty($v['data_practices']),
                    'faqs'           => !empty($v['faqs']),
                    'contact'        => !empty($v['contact']),
                ] : $sections_default,
            ],

            // ── Contact tab ──
            'company_description'  => ['tab' => 'contact', 'default' => '', 'sanitize' => $textarea],
            'dpo_name'             => ['tab' => 'contact', 'default' => '', 'sanitize' => $string],
            'dpo_email'            => ['tab' => 'contact', 'default' => '', 'sanitize' => $email],
            'security_email'       => ['tab' => 'contact', 'default' => '', 'sanitize' => $email],
            'contact_form_url'     => ['tab' => 'contact', 'default' => '', 'sanitize' => $url],
            'contact_address'      => ['tab' => 'contact', 'default' => '', 'sanitize' => $textarea],
            'pgp_key_url'          => ['tab' => 'contact', 'default' => '', 'sanitize' => $url],
            'company_registration' => ['tab' => 'contact', 'default' => '', 'sanitize' => $string],
            'vat_number'           => ['tab' => 'contact', 'default' => '', 'sanitize' => $string],

            // ── AI tab ──
            'ai_model' => ['tab' => 'ai', 'default' => '', 'sanitize' => $string],
            'ai_daily_token_budget' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET,
                'sanitize' => static fn($v): int => max(0, absint($v ?? OpenTrust_Chat_Budget::DEFAULT_DAILY_TOKEN_BUDGET)),
            ],
            'ai_monthly_token_budget' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET,
                'sanitize' => static fn($v): int => max(0, absint($v ?? OpenTrust_Chat_Budget::DEFAULT_MONTHLY_TOKEN_BUDGET)),
            ],
            'ai_rate_limit_per_ip' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_IP,
                'sanitize' => $bounded_int(0, 1000, OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_IP),
            ],
            'ai_rate_limit_per_session' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_SESSION,
                'sanitize' => $bounded_int(0, 10000, OpenTrust_Chat_Budget::DEFAULT_RATE_LIMIT_PER_SESSION),
            ],
            'ai_max_message_length' => [
                'tab' => 'ai',
                'default' => OpenTrust_Chat::DEFAULT_MAX_MESSAGE_LENGTH,
                'sanitize' => $bounded_int(100, 4000, OpenTrust_Chat::DEFAULT_MAX_MESSAGE_LENGTH),
            ],
            'ai_contact_url'            => ['tab' => 'ai', 'default' => '',    'sanitize' => $url],
            'ai_show_model_attribution' => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'ai_logging_enabled'        => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'ai_turnstile_enabled'      => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'ai_auto_summarize'         => ['tab' => 'ai', 'default' => false, 'sanitize' => $bool],
            'turnstile_site_key'        => ['tab' => 'ai', 'default' => '',    'sanitize' => $string],
            'turnstile_secret_key'      => [
                'tab' => 'ai',
                'default' => '',
                // sanitize_secret_field is the only callback that needs $old:
                // a real new plaintext gets encrypted; the masked-bullet
                // placeholder + already-encrypted ciphertext both pass through
                // unchanged (the latter via the ot_enc_v1: idempotency guard).
                'sanitize' => static fn($v, $old) => self::sanitize_secret_field((string) ($v ?? ''), (string) ($old ?? '')),
            ],
        ];
    }

    /**
     * Persist a form-submitted secret as libsodium ciphertext.
     *
     * Three input shapes are passed through unchanged:
     *  - empty / masked-bullet placeholder (user didn't change the field) →
     *    return the existing stored ciphertext.
     *  - already-encrypted `ot_enc_v1:` blob (the schema-driven sanitize
     *    feeds the OLD value back through this callback on the inactive-tab
     *    path; without this guard re-sanitization would clobber the
     *    ciphertext) → return as-is.
     *
     * Anything else is text-sanitized and then encrypted via
     * OpenTrust_Chat_Secrets, so the option never carries the plaintext.
     */
    private static function sanitize_secret_field(string $new_value, string $old_value): string {
        // Idempotency: already-encrypted ciphertext passes through. Stored
        // values created by OpenTrust_Chat_Secrets::encrypt() always carry
        // this prefix, so trusting it here costs nothing real-world.
        if (str_starts_with($new_value, 'ot_enc_v1:')) {
            return $new_value;
        }
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
        $tc_url   = home_url('/' . ($settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG) . '/');
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
                <?php OpenTrust_Admin_AI::instance()->render_ai_tab($settings); ?>
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
    /**
     * Skip-sanitize write of the settings option. Public so out-of-class
     * subsystems (Questions, AI key handlers) can flip a single setting
     * without round-tripping through the full sanitize_settings cascade.
     */
    public function save_settings_raw(array $settings): void {
        remove_filter('sanitize_option_opentrust_settings', [$this, 'sanitize_settings']);
        update_option('opentrust_settings', $settings, false);
        add_filter('sanitize_option_opentrust_settings', [$this, 'sanitize_settings']);
    }




    public function enqueue_assets(string $hook): void {
        // Only load on our settings pages and CPT edit screens.
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $is_ot_screen = str_starts_with($screen->id, 'toplevel_page_opentrust')
            || str_starts_with($screen->id, 'opentrust_page_')
            || in_array($screen->post_type, OpenTrust_CPT::CORPUS, true);

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
            in_array($screen->post_type ?? '', OpenTrust_CPT::CORPUS, true);

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
