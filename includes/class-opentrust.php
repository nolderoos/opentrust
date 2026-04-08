<?php
/**
 * Core plugin class — singleton that wires up all hooks and sub-systems.
 */

declare(strict_types=1);

final class OpenTrust {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('init', [self::class, 'add_rewrite_rules']);
        add_action('init', [$this, 'load_textdomain']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_render_trust_center']);

        // Flush rewrite rules when settings change (transient flag).
        add_action('init', [$this, 'maybe_flush_rewrites'], 99);

        // Invalidate frontend cache when any CPT is saved.
        foreach (['ot_policy', 'ot_subprocessor', 'ot_certification', 'ot_data_practice'] as $cpt) {
            add_action("save_post_{$cpt}", [$this, 'invalidate_cache']);
        }

        // Boot sub-systems.
        OpenTrust_CPT::instance();
        OpenTrust_Version::instance();

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
            'page_title'       => 'Trust Center',
            'company_name'     => get_bloginfo('name'),
            'tagline'          => 'Transparency and security you can trust.',
            'logo_id'          => 0,
            'accent_color'     => '#2563EB',
            'sections_visible' => [
                'certifications' => true,
                'policies'       => true,
                'subprocessors'  => true,
                'data_practices' => true,
            ],
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
    }

    public function register_query_vars(array $vars): array {
        $vars[] = 'opentrust';
        $vars[] = 'ot_policy_slug';
        $vars[] = 'ot_version';
        return $vars;
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
        delete_transient('opentrust_certifications');
        delete_transient('opentrust_policies');
        delete_transient('opentrust_subprocessors');
        delete_transient('opentrust_data_practices');
    }

    // ──────────────────────────────────────────────
    // i18n
    // ──────────────────────────────────────────────

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'opentrust',
            false,
            dirname(plugin_basename(OPENTRUST_PLUGIN_FILE)) . '/languages'
        );
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

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l   = ($max + $min) / 2;
        $d   = $max - $min;

        if ($d === 0.0) {
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
}
