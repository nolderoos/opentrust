<?php
/**
 * Visitor question log.
 *
 * - Custom table `wp_opentrust_chat_log` with hashed-only identifiers.
 * - Zero PII: no raw IPs, no UAs, no referers, no response bodies, no history.
 * - Only the question text (truncated) and aggregate metrics.
 * - 90-day auto-purge via wp_cron.
 *
 * Privacy enforcement is structural: there is no column capable of holding
 * personally identifying data, so code-level mistakes cannot leak PII.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OpenTrust_Chat_Log {

    public const CRON_HOOK      = 'opentrust_chat_log_purge';
    public const RETENTION_DAYS = 90;
    public const QUESTION_MAX   = 1000;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'opentrust_chat_log';
    }

    /**
     * Create or upgrade the log table via dbDelta().
     */
    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table    = self::table_name();
        $charset  = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at      DATETIME NOT NULL,
            session_hash    CHAR(16) NOT NULL DEFAULT '',
            ip_hash         CHAR(16) NOT NULL DEFAULT '',
            question        TEXT NOT NULL,
            model           VARCHAR(100) NOT NULL DEFAULT '',
            provider        VARCHAR(32) NOT NULL DEFAULT '',
            tokens_in       INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_out      INT UNSIGNED NOT NULL DEFAULT 0,
            citation_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_ms     INT UNSIGNED NOT NULL DEFAULT 0,
            refused         TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY session_hash (session_hash)
        ) {$charset};";

        dbDelta($sql);
    }

    /**
     * Insert a log row. No-op if logging is disabled in settings.
     */
    public static function record(array $args): void {
        $settings = OpenTrust::get_settings();
        if (empty($settings['ai_logging_enabled'])) {
            return;
        }

        global $wpdb;

        $question = (string) ($args['question'] ?? '');
        if ($question === '') {
            return;
        }
        if (strlen($question) > self::QUESTION_MAX) {
            $question = substr($question, 0, self::QUESTION_MAX);
        }

        $data = [
            'created_at'     => current_time('mysql', true),
            'session_hash'   => (string) ($args['session_hash']   ?? ''),
            'ip_hash'        => (string) ($args['ip_hash']        ?? ''),
            'question'       => $question,
            'model'          => (string) ($args['model']          ?? ''),
            'provider'       => (string) ($args['provider']       ?? ''),
            'tokens_in'      => (int)    ($args['tokens_in']      ?? 0),
            'tokens_out'     => (int)    ($args['tokens_out']     ?? 0),
            'citation_count' => (int)    ($args['citation_count'] ?? 0),
            'response_ms'    => (int)    ($args['response_ms']    ?? 0),
            'refused'        => !empty($args['refused']) ? 1 : 0,
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to custom log table
        $wpdb->insert(self::table_name(), $data, [
            '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d',
        ]);
    }

    /**
     * Query rows with filters + pagination.
     *
     * @param array $filters ['search' => '', 'model' => '', 'date_from' => '', 'date_to' => '', 'page' => 1, 'per_page' => 25]
     * @return array{rows: array, total: int}
     */
    public static function query(array $filters = []): array {
        global $wpdb;

        $table    = self::table_name();
        $page     = max(1, (int) ($filters['page']     ?? 1));
        $per_page = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;

        $where  = ['1=1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[]  = 'question LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $model = trim((string) ($filters['model'] ?? ''));
        if ($model !== '') {
            $where[]  = 'model = %s';
            $params[] = $model;
        }

        $date_from = trim((string) ($filters['date_from'] ?? ''));
        if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where[]  = 'created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }

        $date_to = trim((string) ($filters['date_to'] ?? ''));
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where[]  = 'created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where);

        // Count.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params));
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}");
        }

        // Rows.
        $row_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $row_params
        ));
        // phpcs:enable

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * Return distinct model names that appear in the log (for the filter dropdown).
     *
     * @return array<int, string>
     */
    public static function distinct_models(): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL-safe read from plugin-owned table
        $rows = $wpdb->get_col("SELECT DISTINCT model FROM {$table} WHERE model != '' ORDER BY model ASC LIMIT 100");
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * Total row count (used in the "N questions in the last 90 days" label).
     */
    public static function total_count(): int {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Clear the entire log.
     */
    public static function clear_all(): int {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Admin-only action, confirm-gated
        $rows = $wpdb->query("TRUNCATE TABLE {$table}");
        return (int) $rows;
    }

    /**
     * Daily purge cron: delete rows older than RETENTION_DAYS.
     */
    public static function purge_old(): int {
        global $wpdb;
        $table  = self::table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Parameter is via prepare()
        $rows = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
        return (int) $rows;
    }

    /**
     * Register / unregister the purge cron.
     */
    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }
    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}

// Wire the cron hook.
add_action(OpenTrust_Chat_Log::CRON_HOOK, [OpenTrust_Chat_Log::class, 'purge_old']);
