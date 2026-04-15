<?php
/**
 * Core plugin class — singleton that wires up all hooks and sub-systems.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenTrust {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('init', [self::class, 'add_rewrite_rules']);
        // Translations are auto-loaded by WordPress 4.6+ for plugins with a Text Domain header.
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_render_trust_center']);

        // Flush rewrite rules when settings change (transient flag).
        add_action('init', [$this, 'maybe_flush_rewrites'], 99);

        // Check for DB schema upgrades.
        add_action('init', [$this, 'maybe_upgrade'], 5);

        // Invalidate frontend cache when any CPT is saved.
        foreach (['ot_policy', 'ot_subprocessor', 'ot_certification', 'ot_data_practice', 'ot_faq'] as $cpt) {
            add_action("save_post_{$cpt}", [$this, 'invalidate_cache']);
        }

        // Boot sub-systems.
        OpenTrust_CPT::instance();
        OpenTrust_Version::instance();
        OpenTrust_Notify::instance();
        OpenTrust_Chat::instance();

        if (is_admin()) {
            OpenTrust_Admin::instance();
        }
    }

    // ──────────────────────────────────────────────
    // Defaults
    // ──────────────────────────────────────────────

    public static function defaults(): array {
        return [
            'endpoint_slug'    => 'trust-center',
            // Leave page_title and tagline empty by default so render-time
            // __() fallbacks follow the active site locale. Admins can still
            // override either value to brand the page in any language.
            'page_title'       => '',
            'company_name'     => get_bloginfo('name'),
            'tagline'          => '',
            'logo_id'          => 0,
            'avatar_id'        => 0,
            'accent_color'        => '#2563EB',
            'accent_force_exact'  => false,
            'sections_visible' => [
                'certifications' => true,
                'policies'       => true,
                'subprocessors'  => true,
                'data_practices' => true,
                'faqs'           => true,
                'contact'        => true,
            ],

            // ── Contact / "Get in touch" block ────────
            // Rendered only if at least one field is populated.
            'company_description'  => '',
            'dpo_name'             => '',
            'dpo_email'            => '',
            'security_email'       => '',
            'contact_form_url'     => '',
            'contact_address'      => '',
            'pgp_key_url'          => '',
            'company_registration' => '',
            'vat_number'           => '',

            'notifications_enabled'   => false,
            'notification_from_name'  => '',
            'notification_reply_to'   => '',
            'rate_limit_per_hour'     => 5,
            'turnstile_site_key'      => '',
            'turnstile_secret_key'    => '',

            // ── AI chat (OTC) ──────────────────────────
            'ai_enabled'                => false,
            'ai_provider'               => '',
            'ai_model'                  => '',
            'ai_model_list_cached_at'   => 0,
            'ai_daily_token_budget'     => 500000,
            'ai_monthly_token_budget'   => 10000000,
            'ai_rate_limit_per_ip'      => 10,
            'ai_rate_limit_per_session' => 50,
            'ai_max_message_length'     => 1000,
            'ai_contact_url'            => '',
            'ai_show_model_attribution' => true,
            'ai_logging_enabled'        => true,
            'ai_turnstile_enabled'      => false,
        ];
    }

    public static function get_settings(): array {
        $saved = get_option('opentrust_settings', []);
        return wp_parse_args($saved, self::defaults());
    }

    // ──────────────────────────────────────────────
    // Rewrite rules
    // ──────────────────────────────────────────────

    public static function add_rewrite_rules(): void {
        $slug = self::get_settings()['endpoint_slug'];
        $slug = sanitize_title($slug) ?: 'trust-center';

        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/?$',
            'index.php?opentrust=main',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/policy/([^/]+)/?$',
            'index.php?opentrust=policy&ot_policy_slug=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/policy/([^/]+)/version/([0-9]+)/?$',
            'index.php?opentrust=policy_version&ot_policy_slug=$matches[1]&ot_version=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/policy/([^/]+)/pdf/?$',
            'index.php?opentrust=policy_pdf&ot_policy_slug=$matches[1]',
            'top'
        );

        // AI chat (OTC).
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/ask/?$',
            'index.php?opentrust=ask',
            'top'
        );

        // Notification endpoints.
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/subscribe/?$',
            'index.php?opentrust=subscribe',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/confirm/([a-f0-9]{64})/?$',
            'index.php?opentrust=confirm&ot_token=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/unsubscribe/([a-f0-9]{64})/?$',
            'index.php?opentrust=unsubscribe&ot_token=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/preferences/([a-f0-9]{64})/?$',
            'index.php?opentrust=preferences&ot_token=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/feed/?$',
            'index.php?opentrust=feed',
            'top'
        );
    }

    public function register_query_vars(array $vars): array {
        $vars[] = 'opentrust';
        $vars[] = 'ot_policy_slug';
        $vars[] = 'ot_version';
        $vars[] = 'ot_token';
        return $vars;
    }

    public function maybe_upgrade(): void {
        $current = (int) get_option('opentrust_db_version', 1);
        if ($current < OPENTRUST_DB_VERSION) {
            // v6: notification log schema moved from digest columns to broadcast columns.
            if ($current < 6) {
                OpenTrust_Notify::drop_log_table();
                wp_clear_scheduled_hook('opentrust_weekly_digest');
                delete_option('opentrust_notification_queue');
            }

            OpenTrust_Notify::create_tables();
            OpenTrust_CPT::migrate_data_practices_v2();

            // Chat (OTC) schema migration.
            if (class_exists('OpenTrust_Chat_Log')) {
                OpenTrust_Chat_Log::create_table();
                OpenTrust_Chat_Log::schedule_cron();
            }

            // Trigger a rewrite flush so new endpoints register.
            set_transient('opentrust_flush_rewrite', true);

            update_option('opentrust_db_version', OPENTRUST_DB_VERSION);
            $this->invalidate_cache();
        }
    }

    public function maybe_flush_rewrites(): void {
        if (get_transient('opentrust_flush_rewrite')) {
            delete_transient('opentrust_flush_rewrite');
            flush_rewrite_rules();
        }
    }

    // ──────────────────────────────────────────────
    // Frontend dispatch
    // ──────────────────────────────────────────────

    public function maybe_render_trust_center(): void {
        $page = get_query_var('opentrust');
        if (!$page) {
            return;
        }

        OpenTrust_Render::instance()->dispatch($page);
        exit;
    }

    // ──────────────────────────────────────────────
    // Cache
    // ──────────────────────────────────────────────

    public function invalidate_cache(): void {
        // Bump a single version counter instead of deleting locale-specific
        // transient keys one by one. OpenTrust_Render::cache_key() includes
        // this version in every key, so every cached locale variant is
        // instantly stale after the bump. Stale transients expire naturally
        // on their existing TTL and are garbage-collected by WordPress.
        $version = (int) get_option('opentrust_cache_version', 1);
        update_option('opentrust_cache_version', $version + 1, false);
    }


    // ──────────────────────────────────────────────
    // Utilities
    // ──────────────────────────────────────────────

    /**
     * Convert a hex colour to HSL components.
     *
     * @return array{h: int, s: int, l: int}
     */
    public static function hex_to_hsl(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        // 255.0 forces float division — otherwise PHP returns int(1) for
        // hexdec('ff') / 255 and the achromatic shortcut below misses pure
        // white/black/greys (int(0) !== float(0.0)).
        $r = hexdec(substr($hex, 0, 2)) / 255.0;
        $g = hexdec(substr($hex, 2, 2)) / 255.0;
        $b = hexdec(substr($hex, 4, 2)) / 255.0;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l   = ($max + $min) / 2;
        $d   = $max - $min;

        if ($d == 0) {
            return ['h' => 0, 's' => 0, 'l' => (int) round($l * 100)];
        }

        $s = $l > 0.5
            ? $d / (2 - $max - $min)
            : $d / ($max + $min);

        $h = match ($max) {
            $r     => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6,
            $g     => (($b - $r) / $d + 2) / 6,
            default => (($r - $g) / $d + 4) / 6,
        };

        return [
            'h' => (int) round($h * 360),
            's' => (int) round($s * 100),
            'l' => (int) round($l * 100),
        ];
    }

    /**
     * Parse a hex colour into 0–255 RGB channels.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * HSL components (H: 0–360, S/L: 0–100) back to 0–255 RGB.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function hsl_to_rgb(int $h, int $s, int $l): array {
        $sf = $s / 100;
        $lf = $l / 100;
        $c  = (1 - abs(2 * $lf - 1)) * $sf;
        $x  = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m  = $lf - $c / 2;

        $r = $g = $b = 0.0;
        if     ($h < 60)  { $r = $c; $g = $x; }
        elseif ($h < 120) { $r = $x; $g = $c; }
        elseif ($h < 180) { $g = $c; $b = $x; }
        elseif ($h < 240) { $g = $x; $b = $c; }
        elseif ($h < 300) { $r = $x; $b = $c; }
        else              { $r = $c; $b = $x; }

        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }

    /**
     * WCAG 2.x relative luminance for an sRGB colour.
     */
    public static function relative_luminance(int $r, int $g, int $b): float {
        $channel = static function (int $c): float {
            $v = $c / 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * WCAG contrast ratio between a colour and pure white.
     */
    public static function contrast_vs_white(int $r, int $g, int $b): float {
        $lum = self::relative_luminance($r, $g, $b);
        return 1.05 / ($lum + 0.05);
    }

    /**
     * Find the largest HSL lightness (≤ original L) where the resulting colour
     * hits ≥ 4.5:1 contrast against white — the WCAG AA threshold for normal
     * text. Returns the original lightness unchanged if it already passes.
     *
     * Hue and saturation are preserved, so the adjusted colour stays on-brand.
     */
    public static function accent_safe_lightness(string $hex): int {
        [$r, $g, $b] = self::hex_to_rgb($hex);
        $hsl = self::hex_to_hsl($hex);

        if (self::contrast_vs_white($r, $g, $b) >= 4.5) {
            return $hsl['l'];
        }

        for ($l = $hsl['l']; $l >= 0; $l--) {
            [$r2, $g2, $b2] = self::hsl_to_rgb($hsl['h'], $hsl['s'], $l);
            if (self::contrast_vs_white($r2, $g2, $b2) >= 4.5) {
                return $l;
            }
        }
        return 0;
    }
}
