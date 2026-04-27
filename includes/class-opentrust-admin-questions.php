<?php
/**
 * "Questions" admin screen — visitor chat log viewer + the three
 * admin-post handlers that drive its toolbar (CSV export, full clear,
 * logging toggle).
 *
 * Lives on its own submenu under the OpenTrust top-level menu. Visibility
 * of that submenu is gated in OpenTrust_Admin::register_menu() on the
 * `ai_enabled` setting. Once the submenu is registered, this class owns
 * the page render and all handler endpoints.
 *
 * Identifiers in the underlying log table are pre-hashed by
 * OpenTrust_Chat_Log; nothing in this screen surfaces raw IPs/sessions.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Admin_Questions {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        add_action('admin_post_opentrust_ai_questions_export', [$this, 'handle_export']);
        add_action('admin_post_opentrust_ai_questions_clear',  [$this, 'handle_clear']);
        add_action('admin_post_opentrust_ai_toggle_logging',   [$this, 'handle_toggle_logging']);
    }

    // ──────────────────────────────────────────────
    // Page render
    // ──────────────────────────────────────────────

    public function render_page(): void {
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
    // admin-post handlers
    // ──────────────────────────────────────────────

    public function handle_export(): void {
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

    public function handle_clear(): void {
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

    public function handle_toggle_logging(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'opentrust'), '', ['response' => 403]);
        }
        check_admin_referer('opentrust_ai_toggle_logging');

        $settings = OpenTrust::get_settings();
        $settings['ai_logging_enabled'] = empty($settings['ai_logging_enabled']);
        OpenTrust_Admin_Settings::instance()->save_settings_raw($settings);

        set_transient(
            'opentrust_ai_notice_' . get_current_user_id(),
            ['type' => 'success', 'message' => $settings['ai_logging_enabled'] ? __('Logging enabled.', 'opentrust') : __('Logging disabled.', 'opentrust')],
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(admin_url('admin.php?page=opentrust-questions'));
        exit;
    }
}
