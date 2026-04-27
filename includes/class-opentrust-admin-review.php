<?php
/**
 * Admin review prompt.
 *
 * A small, opt-out-able nudge asking long-time admins to leave a rating on
 * the WordPress.org plugin directory. Two surfaces, both scoped strictly to
 * OpenTrust admin screens (never the dashboard, plugin list, or other
 * plugins' pages):
 *
 *   1. Footer text — replaces the default "Thank you for creating with
 *      WordPress" line on OT screens with a single quiet sentence.
 *
 *   2. Milestone notice — a one-time `notice notice-info` rendered when the
 *      admin has actually built something with the plugin (>= 3 published
 *      policies and >= 14 days since first admin visit on this version).
 *      Three actions: leave a review (external), already-did (permanent
 *      dismissal), or not-now (30-day snooze). Dismissal is per-user, keyed
 *      with a versioned meta key so we can re-prompt on a future major
 *      release without losing the audit trail.
 *
 * Designed to comply with the wp.org Detailed Plugin Guidelines — no
 * incentives, fully dismissable, scope-limited.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Admin_Review {

    private const DISMISS_META_KEY  = '_opentrust_review_dismissed_v1';
    private const FIRST_SEEN_OPTION = 'opentrust_first_activated_at';
    private const ACTION            = 'opentrust_dismiss_review_notice';
    private const POLICY_THRESHOLD  = 3;
    private const DAYS_THRESHOLD    = 14;
    private const SNOOZE_DAYS       = 30;

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_init',                                [$this, 'capture_first_seen']);
        add_filter('admin_footer_text',                         [$this, 'footer_text']);
        add_action('admin_notices',                             [$this, 'render_milestone_notice']);
        add_action('admin_post_' . self::ACTION,                [$this, 'handle_dismiss']);
    }

    // ──────────────────────────────────────────────
    // First-seen capture
    // ──────────────────────────────────────────────

    /**
     * Lazily record when this admin first loaded the plugin. Used as the
     * floor for the 14-day milestone gate. Recorded on first admin page
     * load (not activation) so the delay starts the first time someone
     * actually visits an admin screen.
     */
    public function capture_first_seen(): void {
        if (false === get_option(self::FIRST_SEEN_OPTION, false)) {
            add_option(self::FIRST_SEEN_OPTION, time(), '', false);
        }
    }

    // ──────────────────────────────────────────────
    // Footer
    // ──────────────────────────────────────────────

    public function footer_text(string $text): string {
        if (!self::is_opentrust_screen()) {
            return $text;
        }

        $link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(self::review_url()),
            esc_html__('★★★★★ review on WordPress.org', 'opentrust')
        );

        // The static English string contains no HTML; the only insertion is
        // the pre-escaped link above. Translators who add markup here own
        // the result — same risk model as core's admin_footer_text usage.
        return sprintf(
            /* translators: %s: link to leave a 5-star review on WordPress.org */
            __('OpenTrust is built and maintained in the open. If it is helping your team, a %s keeps the project moving.', 'opentrust'),
            $link
        );
    }

    // ──────────────────────────────────────────────
    // Milestone notice
    // ──────────────────────────────────────────────

    public function render_milestone_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!self::is_opentrust_screen()) {
            return;
        }
        if (!$this->should_show_milestone_notice()) {
            return;
        }

        $review_url    = self::review_url();
        $not_now_url   = self::dismiss_url('snooze');
        $already_url   = self::dismiss_url('permanent');
        ?>
        <div class="notice notice-info opentrust-review-notice">
            <p>
                <strong><?php esc_html_e('Your trust center is up and running.', 'opentrust'); ?></strong>
                <?php esc_html_e('OpenTrust is fully open-source with no paid tier — reviews on WordPress.org are how the project gets seen. If it has earned a kind word, we would be grateful.', 'opentrust'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
                    <?php esc_html_e('Leave a review', 'opentrust'); ?>
                </a>
                <a href="<?php echo esc_url($already_url); ?>" class="button">
                    <?php esc_html_e('Already did, thanks', 'opentrust'); ?>
                </a>
                <a href="<?php echo esc_url($not_now_url); ?>" class="button-link" style="margin-left:8px">
                    <?php esc_html_e('Not now', 'opentrust'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Site-level filter so admins can hard-disable the prompt without code
     * edits, and a per-user dismissal check.
     */
    private function should_show_milestone_notice(): bool {
        if (!apply_filters('opentrust_show_review_notice', true)) {
            return false;
        }

        $dismissed = (string) get_user_meta(get_current_user_id(), self::DISMISS_META_KEY, true);
        if ($dismissed === 'permanent') {
            return false;
        }
        if ($dismissed !== '' && (int) $dismissed > time()) {
            return false; // still inside the snooze window
        }

        $first_seen = (int) get_option(self::FIRST_SEEN_OPTION, 0);
        if ($first_seen <= 0 || (time() - $first_seen) < self::DAYS_THRESHOLD * DAY_IN_SECONDS) {
            return false;
        }

        $counts = wp_count_posts('ot_policy');
        $published = (int) ($counts->publish ?? 0);

        return $published >= self::POLICY_THRESHOLD;
    }

    // ──────────────────────────────────────────────
    // Dismiss handler
    // ──────────────────────────────────────────────

    public function handle_dismiss(): void {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to dismiss this notice.', 'opentrust'),
                '',
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION);

        $type  = isset($_GET['type']) ? sanitize_key((string) wp_unslash($_GET['type'])) : 'snooze';
        $value = $type === 'permanent'
            ? 'permanent'
            : (string) (time() + self::SNOOZE_DAYS * DAY_IN_SECONDS);

        update_user_meta(get_current_user_id(), self::DISMISS_META_KEY, $value);

        $referer = wp_get_referer();
        wp_safe_redirect($referer ?: admin_url('admin.php?page=opentrust'));
        exit;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Match the scoping rule used by render_plain_permalinks_notice in
     * OpenTrust_Admin: any screen whose id contains "opentrust" plus the
     * five content CPTs. Identical pattern keeps both notices in lockstep.
     */
    private static function is_opentrust_screen(): bool {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        return str_contains((string) $screen->id, 'opentrust')
            || in_array($screen->post_type ?? '', OpenTrust_CPT::CORPUS, true);
    }

    private static function review_url(): string {
        $url = 'https://wordpress.org/support/plugin/opentrust/reviews/?rate=5#new-post';
        return (string) apply_filters('opentrust_review_url', $url);
    }

    private static function dismiss_url(string $type): string {
        return wp_nonce_url(
            add_query_arg(
                ['action' => self::ACTION, 'type' => $type],
                admin_url('admin-post.php')
            ),
            self::ACTION
        );
    }
}
