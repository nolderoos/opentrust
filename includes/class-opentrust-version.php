<?php
/**
 * Policy version control.
 *
 * Manages auto-incrementing version numbers on policy edits,
 * stores version metadata on revisions, and provides an admin
 * version history meta box.
 */

declare(strict_types=1);

final class OpenTrust_Version {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('wp_after_insert_post', [$this, 'maybe_increment_version'], 10, 4);
        add_action('add_meta_boxes', [$this, 'add_version_history_meta_box']);
    }

    // ──────────────────────────────────────────────
    // Version incrementing
    // ──────────────────────────────────────────────

    public function maybe_increment_version(int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before): void {
        if ('ot_policy' !== $post->post_type) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if ('publish' !== $post->post_status) {
            return;
        }

        $current_version = (int) get_post_meta($post_id, '_ot_version', true);

        // First publish — set to v1.
        if (!$update || $current_version === 0) {
            update_post_meta($post_id, '_ot_version', 1);
            $this->tag_latest_revision($post_id, 1);
            return;
        }

        // Only increment if content or title actually changed.
        if ($post_before
            && $post->post_content === $post_before->post_content
            && $post->post_title === $post_before->post_title
        ) {
            return;
        }

        $new_version = $current_version + 1;
        update_post_meta($post_id, '_ot_version', $new_version);
        $this->tag_latest_revision($post_id, $new_version);
    }

    /**
     * Tag the most recent revision with the version number.
     */
    private function tag_latest_revision(int $post_id, int $version): void {
        $revisions = wp_get_post_revisions($post_id, [
            'posts_per_page' => 1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ]);

        if ($revisions) {
            $latest = reset($revisions);
            update_post_meta($latest->ID, '_ot_version', $version);
        }
    }

    // ──────────────────────────────────────────────
    // Version history meta box
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
        $slug      = $settings['endpoint_slug'] ?? 'trust-center';
        $post_slug = $post->post_name ?: sanitize_title($post->post_title);

        if (empty($revisions)) {
            printf(
                '<p>%s <strong>v%d</strong></p>',
                esc_html__('Current version:', 'opentrust'),
                $current_version
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
