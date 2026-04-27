<?php
/**
 * Policy version control.
 *
 * User-controlled versioning: admins explicitly choose when to publish
 * a new version via a checkbox in the meta box. Regular saves/edits
 * do not change the version number.
 *
 * This matches industry practice (Vanta, Drata, Secureframe) and
 * compliance requirements (SOC 2, ISO 27001) where only formally
 * published versions are tracked, not every internal edit.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenTrust_Version {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('add_meta_boxes', [$this, 'add_version_history_meta_box']);
    }

    // ──────────────────────────────────────────────
    // Version bump (called from save_policy_meta)
    // ──────────────────────────────────────────────

    /**
     * Increment the version number and tag the latest revision
     * with the OLD version so it's preserved in history.
     */
    public static function bump_version(int $post_id, string $change_summary = ''): void {
        $current_version = (int) get_post_meta($post_id, '_ot_version', true) ?: 1;
        $new_version     = $current_version + 1;

        // Find an untagged revision that holds the OLD content (pre-update).
        // Walk revisions newest-first and tag the first one whose content
        // differs from the now-saved post.
        $post      = get_post($post_id);
        $revisions = wp_get_post_revisions($post_id, [
            'orderby' => 'ID',
            'order'   => 'DESC',
        ]);

        if ($revisions && $post) {
            foreach ($revisions as $rev) {
                $existing = get_post_meta($rev->ID, '_ot_version', true);
                if (!empty($existing) && (int) $existing > 0) {
                    continue; // already tagged, skip
                }
                if ($rev->post_content === $post->post_content) {
                    continue; // same as current, not the old version
                }

                // This revision has old content and no tag — it's the
                // pre-update snapshot. Tag it with the old version
                // and copy over the old version's change summary.
                global $wpdb;
                // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- Admin-only postmeta operations on revisions
                $wpdb->delete($wpdb->postmeta, [
                    'post_id'  => $rev->ID,
                    'meta_key' => '_ot_version',
                ]);
                $wpdb->insert($wpdb->postmeta, [
                    'post_id'    => $rev->ID,
                    'meta_key'   => '_ot_version',
                    'meta_value' => (string) $current_version,
                ]);

                // Copy old version's summary and effective date to the revision.
                foreach (['_ot_version_summary', '_ot_policy_effective_date'] as $meta_key) {
                    $old_val = get_post_meta($post_id, $meta_key, true);
                    if ($old_val) {
                        $wpdb->delete($wpdb->postmeta, [
                            'post_id'  => $rev->ID,
                            'meta_key' => $meta_key,
                        ]);
                        $wpdb->insert($wpdb->postmeta, [
                            'post_id'    => $rev->ID,
                            'meta_key'   => $meta_key,
                            'meta_value' => $old_val,
                        ]);
                    }
                }
                // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

                wp_cache_delete($rev->ID, 'post_meta');
                break;
            }
        }

        // Bump the main post to the new version.
        update_post_meta($post_id, '_ot_version', $new_version);

        // Store change summary for this version.
        if ($change_summary !== '') {
            update_post_meta($post_id, '_ot_version_summary', $change_summary);
        } else {
            delete_post_meta($post_id, '_ot_version_summary');
        }
    }

    /**
     * Ensure a first-publish post gets v1.
     */
    public static function ensure_initial_version(int $post_id): void {
        $version = get_post_meta($post_id, '_ot_version', true);
        if (!$version) {
            update_post_meta($post_id, '_ot_version', 1);
        }
    }

    // ──────────────────────────────────────────────
    // Version history meta box (admin sidebar)
    // ──────────────────────────────────────────────

    public function add_version_history_meta_box(): void {
        add_meta_box(
            'ot_version_history',
            __('Version History', 'opentrust'),
            [$this, 'render_version_history'],
            'ot_policy',
            'side',
            'default'
        );
    }

    public function render_version_history(\WP_Post $post): void {
        $current_version = (int) get_post_meta($post->ID, '_ot_version', true) ?: 1;
        $revisions       = wp_get_post_revisions($post->ID, [
            'orderby' => 'ID',
            'order'   => 'DESC',
        ]);

        $settings  = OpenTrust::get_settings();
        $slug      = $settings['endpoint_slug'] ?? OpenTrust::DEFAULT_ENDPOINT_SLUG;
        $post_slug = $post->post_name ?: sanitize_title($post->post_title);

        if (empty($revisions)) {
            printf(
                '<p>%s <strong>v%d</strong></p>',
                esc_html__('Current version:', 'opentrust'),
                intval( $current_version ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer cast via %d format specifier and intval()
            );
            echo '<p class="description">' . esc_html__('Version history will appear after the first update.', 'opentrust') . '</p>';
            return;
        }
        ?>
        <div class="ot-version-history">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e('Version', 'opentrust'); ?></th>
                        <th><?php esc_html_e('Date', 'opentrust'); ?></th>
                        <th><?php esc_html_e('Actions', 'opentrust'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="ot-version-badge">v<?php echo esc_html((string) $current_version); ?></span></td>
                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->post_modified))); ?></td>
                        <td><em><?php esc_html_e('Current', 'opentrust'); ?></em></td>
                    </tr>
                    <?php foreach ($revisions as $rev):
                        $rev_version = (int) get_post_meta($rev->ID, '_ot_version', true);
                        if (!$rev_version) continue;
                        if ($rev_version === $current_version) continue;

                        $view_url = home_url("/{$slug}/policy/{$post_slug}/version/{$rev_version}/");
                        $compare_url = admin_url("revision.php?revision={$rev->ID}");
                    ?>
                    <tr>
                        <td>v<?php echo esc_html((string) $rev_version); ?></td>
                        <td><?php echo esc_html(wp_date(get_option('date_format'), strtotime($rev->post_modified))); ?></td>
                        <td>
                            <a href="<?php echo esc_url($view_url); ?>" target="_blank" title="<?php esc_attr_e('View', 'opentrust'); ?>">
                                <?php esc_html_e('View', 'opentrust'); ?>
                            </a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url($compare_url); ?>" title="<?php esc_attr_e('Compare', 'opentrust'); ?>">
                                <?php esc_html_e('Diff', 'opentrust'); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
