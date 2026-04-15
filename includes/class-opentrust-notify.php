<?php
/**
 * Email notification system: subscriber storage, double opt-in, and the
 * manual policy-broadcast path triggered from the policy edit screen.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenTrust_Notify {

    private static ?self $instance = null;

    /** Human-readable labels for subscriber category preferences. */
    public static function category_labels(): array {
        return [
            'policies'       => __('Policy Updates', 'opentrust'),
            'certifications' => __('Certification Updates', 'opentrust'),
            'subprocessors'  => __('Subprocessor Changes', 'opentrust'),
            'data_practices' => __('Data Practice Changes', 'opentrust'),
        ];
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        // Broadcasts are triggered from the policy save handler in class-opentrust-cpt.php.
    }

    // ──────────────────────────────────────────────
    // Database
    // ──────────────────────────────────────────────

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'opentrust_subscribers';
    }

    public static function log_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'opentrust_notification_log';
    }

    /**
     * Create database tables on plugin activation.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $table   = self::table_name();
        $log     = self::log_table_name();

        $subscribers_ddl = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(255) NOT NULL,
  name varchar(255) NOT NULL DEFAULT '',
  company varchar(255) NOT NULL DEFAULT '',
  token varchar(64) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  categories text NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  confirmed_at datetime DEFAULT NULL,
  unsubscribed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY token (token),
  KEY status (status)
) {$charset};";

        $log_ddl = "CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscriber_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  sent_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  status varchar(20) NOT NULL DEFAULT 'sent',
  error_message text NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY subscriber_id (subscriber_id),
  KEY post_id (post_id)
) {$charset};";

        // SQLite (WordPress Studio): dbDelta() can leave tables without
        // column metadata in the information schema, which breaks all
        // INSERT/UPDATE queries. Detect and rebuild when needed.
        if (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite') {
            self::ensure_sqlite_table($table, $subscribers_ddl);
            self::ensure_sqlite_table($log, $log_ddl);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($subscribers_ddl . "\n" . $log_ddl);
    }

    /**
     * Ensure a SQLite table has proper information-schema metadata.
     *
     * If the raw table exists but the translator's metadata is missing,
     * drop and recreate through $wpdb->query() so the translator
     * registers column info and INSERT/UPDATE queries work.
     */
    private static function ensure_sqlite_table(string $table, string $ddl): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQLite metadata check, no WP API alternative
        $has_meta = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM _wp_sqlite_mysql_information_schema_columns WHERE table_name = %s",
            $table
        ));

        if ($has_meta > 0) {
            return;
        }

        // Drop the raw table (if any) and recreate through the
        // translator so it registers column metadata.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL with dynamic table name
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL cannot use prepare()
        $wpdb->query($ddl);
    }

    /**
     * Drop the notification log table. Used when migrating from the digest
     * schema (post_id/post_type/change_type) to the update broadcast schema
     * (update_id/status/error_message). Called from OpenTrust::maybe_upgrade()
     * before create_tables() rebuilds it with the new columns.
     *
     * On SQLite we also clear the translator's information-schema metadata
     * for this table, so ensure_sqlite_table() on the subsequent rebuild
     * sees the "fresh install" state and actually recreates the columns.
     */
    public static function drop_log_table(): void {
        global $wpdb;
        $log = self::log_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL cannot use prepare()
        $wpdb->query("DROP TABLE IF EXISTS {$log}");

        if (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clear SQLite translator metadata
            $wpdb->query($wpdb->prepare(
                "DELETE FROM _wp_sqlite_mysql_information_schema_columns WHERE table_name = %s",
                $log
            ));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Clear SQLite translator metadata (tables)
            $wpdb->query($wpdb->prepare(
                "DELETE FROM _wp_sqlite_mysql_information_schema_tables WHERE table_name = %s",
                $log
            ));
        }
    }

    /**
     * Drop tables on uninstall.
     */
    public static function drop_tables(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL cannot use prepare()
        $wpdb->query("DROP TABLE IF EXISTS " . self::log_table_name());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL cannot use prepare()
        $wpdb->query("DROP TABLE IF EXISTS " . self::table_name());
    }

    // ──────────────────────────────────────────────
    // Subscriber CRUD
    // ──────────────────────────────────────────────

    /**
     * Subscribe an email address (pending confirmation).
     *
     * @return array{success: bool, message: string, token?: string}
     */
    public function subscribe(string $email, string $name = '', string $company = '', array $categories = []): array {
        global $wpdb;

        $email = sanitize_email($email);
        if (!is_email($email)) {
            return ['success' => false, 'message' => __('Please enter a valid email address.', 'opentrust')];
        }

        // Check if already subscribed.
        $existing = $this->get_subscriber_by_email($email);

        if ($existing && $existing->status === 'active') {
            return ['success' => false, 'message' => __('This email is already subscribed.', 'opentrust')];
        }

        $token = $this->generate_token($email);

        // Default: all categories.
        if (empty($categories)) {
            $categories = array_keys(self::category_labels());
        }

        if ($existing) {
            // Re-subscribe or update pending.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
            $result = $wpdb->update(
                self::table_name(),
                [
                    'name'       => sanitize_text_field($name),
                    'company'    => sanitize_text_field($company),
                    'token'      => $token,
                    'status'     => 'pending',
                    'categories' => wp_json_encode($categories),
                    'created_at' => current_time('mysql'),
                    'confirmed_at'    => null,
                    'unsubscribed_at' => null,
                ],
                ['id' => $existing->id],
                ['%s', '%s', '%s', '%s', '%s', '%s', null, null],
                ['%d']
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
            $result = $wpdb->insert(
                self::table_name(),
                [
                    'email'      => $email,
                    'name'       => sanitize_text_field($name),
                    'company'    => sanitize_text_field($company),
                    'token'      => $token,
                    'status'     => 'pending',
                    'categories' => wp_json_encode($categories),
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }

        if (false === $result) {
            return ['success' => false, 'message' => __('Something went wrong. Please try again later.', 'opentrust')];
        }

        // Send confirmation email.
        $this->send_confirmation_email($email, $name, $token);

        return [
            'success' => true,
            'message' => __('Please check your inbox and click the confirmation link.', 'opentrust'),
            'token'   => $token,
        ];
    }

    /**
     * Confirm a pending subscription via token.
     */
    public function confirm(string $token): bool {
        global $wpdb;

        $subscriber = $this->get_subscriber_by_token($token);
        if (!$subscriber || $subscriber->status !== 'pending') {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        return (bool) $wpdb->update(
            self::table_name(),
            [
                'status'       => 'active',
                'confirmed_at' => current_time('mysql'),
            ],
            ['id' => $subscriber->id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Admin action: flip a subscriber to active without sending a confirmation
     * email. Preserves the token so unsubscribe / preferences links keep working.
     *
     * Works from any source status (pending or unsubscribed). The admin is
     * asserting out-of-band consent; legal review is the admin's responsibility.
     * Already-active rows are treated as a no-op success.
     */
    public function admin_verify_subscriber(int $id): bool {
        global $wpdb;

        $subscriber = $this->get_subscriber_by_id($id);
        if (!$subscriber) {
            return false;
        }

        if ($subscriber->status === 'active') {
            return true; // No-op.
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        $ok = (bool) $wpdb->update(
            self::table_name(),
            [
                'status'          => 'active',
                'confirmed_at'    => current_time('mysql'),
                'unsubscribed_at' => null,
            ],
            ['id' => $subscriber->id],
            ['%s', '%s', null],
            ['%d']
        );

        if ($ok) {
            /**
             * Fires after an admin manually verifies a pending subscriber.
             *
             * @param int $subscriber_id
             * @param int $user_id       Admin who performed the action.
             */
            do_action('opentrust_subscriber_verified', $subscriber->id, get_current_user_id());
        }

        return $ok;
    }

    /**
     * Direct subscriber creation for admin and import paths. Does NOT send
     * a confirmation email. Token is always generated server-side.
     *
     * @return int|false Insert ID on success, false on failure.
     */
    public function create_subscriber_direct(
        string $email,
        string $name = '',
        string $company = '',
        array $categories = [],
        string $status = 'active',
        ?string $created_at = null,
        ?string $confirmed_at = null
    ): int|false {
        global $wpdb;

        $email = sanitize_email($email);
        if (!is_email($email)) {
            return false;
        }

        $valid_status = ['active', 'pending', 'unsubscribed'];
        if (!in_array($status, $valid_status, true)) {
            $status = 'active';
        }

        // Default: all categories.
        if (empty($categories)) {
            $categories = array_keys(self::category_labels());
        }

        $created_at = $created_at ?: current_time('mysql');

        // Auto-populate confirmed_at for active rows if not given.
        if ($confirmed_at === null && $status === 'active') {
            $confirmed_at = current_time('mysql');
        }

        $data = [
            'email'      => $email,
            'name'       => sanitize_text_field($name),
            'company'    => sanitize_text_field($company),
            'token'      => $this->generate_token($email),
            'status'     => $status,
            'categories' => wp_json_encode($categories),
            'created_at' => $created_at,
        ];
        $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($confirmed_at !== null) {
            $data['confirmed_at'] = $confirmed_at;
            $format[] = '%s';
        }

        if ($status === 'unsubscribed') {
            $data['unsubscribed_at'] = current_time('mysql');
            $format[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        $ok = $wpdb->insert(self::table_name(), $data, $format);

        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Direct subscriber update for admin and import paths. Whitelists columns
     * and never touches email or token.
     *
     * @param array $fields          Column → value. Unknown keys are dropped.
     * @param bool  $merge_non_empty When true, skip any field whose value is ''.
     */
    public function update_subscriber_direct(int $id, array $fields, bool $merge_non_empty = false): bool {
        global $wpdb;

        $allowed = [
            'name'            => '%s',
            'company'         => '%s',
            'status'          => '%s',
            'categories'      => '%s',
            'created_at'      => '%s',
            'confirmed_at'    => '%s',
            'unsubscribed_at' => '%s',
        ];

        $data   = [];
        $format = [];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            if ($merge_non_empty && ($value === '' || $value === null)) {
                continue;
            }
            $data[$key]  = $value;
            $format[]    = $allowed[$key];
        }

        if (empty($data)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        return false !== $wpdb->update(
            self::table_name(),
            $data,
            ['id' => $id],
            $format,
            ['%d']
        );
    }

    /**
     * Import subscribers from a CSV file.
     *
     * Accepts the exact columns the exporter produces (round-trip). Headers
     * are case-insensitive and order-independent. Required: email. Optional:
     * name, company, status, categories, subscribed, confirmed.
     *
     * @param string $tmp_path       Server path to the uploaded CSV.
     * @param string $conflict       'skip' | 'update' | 'replace'.
     * @param bool   $mark_verified  When true, imported rows are status=active, no email sent.
     *
     * @return array{imported:int, updated:int, skipped:int, errors:array<int,string>}
     */
    public function import_subscribers_csv(string $tmp_path, string $conflict, bool $mark_verified): array {
        $summary = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        if (!in_array($conflict, ['skip', 'update', 'replace'], true)) {
            $conflict = 'skip';
        }

        if (!is_readable($tmp_path)) {
            $summary['errors'][] = __('Could not read the uploaded file.', 'opentrust');
            return $summary;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a just-uploaded CSV from a temp path
        $handle = fopen($tmp_path, 'r');
        if (!$handle) {
            $summary['errors'][] = __('Could not open the uploaded file.', 'opentrust');
            return $summary;
        }

        // Generous memory + time for modest imports.
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- safe-mode hosts ignore this
        }

        $headers      = null;
        $header_map   = [];
        $row_number   = 0;
        $seen_emails  = [];
        $valid_cats   = array_keys(self::category_labels());
        $cat_labels   = self::category_labels();
        $label_to_key = array_flip(array_map('strtolower', $cat_labels));
        $max_rows     = 5000;

        while (($row = fgetcsv($handle)) !== false) {
            $row_number++;

            if ($row_number > $max_rows) {
                $summary['errors'][] = sprintf(
                    /* translators: %d: row cap */
                    __('Import stopped at row %d (maximum row cap reached).', 'opentrust'),
                    $max_rows
                );
                break;
            }

            // Skip blank lines.
            if (count($row) === 0 || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            // Strip BOM from the first cell of the first row.
            if ($row_number === 1 && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }

            // First non-empty row = headers.
            if ($headers === null) {
                $headers    = array_map(fn($h) => strtolower(trim((string) $h)), $row);
                $header_map = array_flip($headers);
                if (!isset($header_map['email'])) {
                    $summary['errors'][] = __('CSV is missing a required "email" column.', 'opentrust');
                    break;
                }
                continue;
            }

            $get = function (string $key) use ($row, $header_map): string {
                if (!isset($header_map[$key])) {
                    return '';
                }
                $idx = $header_map[$key];
                return isset($row[$idx]) ? trim((string) $row[$idx]) : '';
            };

            $email = sanitize_email($get('email'));
            if (!$email || !is_email($email)) {
                $summary['errors'][] = sprintf(
                    /* translators: 1: row number */
                    __('Row %d: invalid or missing email.', 'opentrust'),
                    $row_number
                );
                continue;
            }

            if (isset($seen_emails[$email])) {
                $summary['errors'][] = sprintf(
                    /* translators: 1: row number, 2: email */
                    __('Row %1$d: duplicate email %2$s in CSV — skipped.', 'opentrust'),
                    $row_number,
                    $email
                );
                continue;
            }
            $seen_emails[$email] = true;

            $name    = sanitize_text_field($get('name'));
            $company = sanitize_text_field($get('company'));

            // Parse categories: split on ; or , , accept keys or labels.
            $cats_raw = $get('categories');
            $categories = [];
            if ($cats_raw !== '') {
                foreach (preg_split('/[;,]/', $cats_raw) as $piece) {
                    $piece = strtolower(trim((string) $piece));
                    if ($piece === '') {
                        continue;
                    }
                    if (in_array($piece, $valid_cats, true)) {
                        $categories[] = $piece;
                    } elseif (isset($label_to_key[$piece])) {
                        $categories[] = $label_to_key[$piece];
                    }
                    // Unknown values silently dropped.
                }
                $categories = array_values(array_unique($categories));
            }

            // Determine status.
            $status_in = strtolower($get('status'));
            if ($mark_verified) {
                $status = 'active';
            } elseif (in_array($status_in, ['active', 'pending', 'unsubscribed'], true)) {
                $status = $status_in;
            } else {
                $status = 'pending';
            }

            // Parse dates (best effort).
            $created_at   = $this->normalize_date($get('subscribed'));
            $confirmed_at = $this->normalize_date($get('confirmed'));
            if ($status === 'active' && $confirmed_at === null) {
                $confirmed_at = current_time('mysql');
            }

            $existing = $this->get_subscriber_by_email($email);

            if ($existing) {
                if ($conflict === 'skip') {
                    $summary['skipped']++;
                    continue;
                }

                $fields = [
                    'name'       => $name,
                    'company'    => $company,
                    'status'     => $status,
                    'categories' => wp_json_encode($categories ?: json_decode($existing->categories, true) ?: $valid_cats),
                ];
                if ($created_at !== null) {
                    $fields['created_at'] = $created_at;
                }
                if ($confirmed_at !== null) {
                    $fields['confirmed_at'] = $confirmed_at;
                }
                if ($status === 'unsubscribed') {
                    $fields['unsubscribed_at'] = current_time('mysql');
                }

                $ok = $this->update_subscriber_direct(
                    (int) $existing->id,
                    $fields,
                    $conflict === 'update'
                );

                if ($ok) {
                    $summary['updated']++;
                } else {
                    $summary['errors'][] = sprintf(
                        /* translators: 1: row number, 2: email */
                        __('Row %1$d: failed to update %2$s.', 'opentrust'),
                        $row_number,
                        $email
                    );
                }
                continue;
            }

            // New row.
            $insert_id = $this->create_subscriber_direct(
                $email,
                $name,
                $company,
                $categories,
                $status,
                $created_at,
                $confirmed_at
            );

            if ($insert_id) {
                $summary['imported']++;
            } else {
                $summary['errors'][] = sprintf(
                    /* translators: 1: row number, 2: email */
                    __('Row %1$d: failed to insert %2$s.', 'opentrust'),
                    $row_number,
                    $email
                );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Matching fopen
        fclose($handle);

        return $summary;
    }

    /**
     * Normalize a user-supplied date string into MySQL datetime format.
     * Returns null if the value is empty or unparseable.
     */
    private function normalize_date(string $value): ?string {
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Unsubscribe via token.
     */
    public function unsubscribe(string $token): bool {
        global $wpdb;

        $subscriber = $this->get_subscriber_by_token($token);
        if (!$subscriber) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        return (bool) $wpdb->update(
            self::table_name(),
            [
                'status'          => 'unsubscribed',
                'unsubscribed_at' => current_time('mysql'),
            ],
            ['id' => $subscriber->id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Update subscriber category preferences.
     */
    public function update_preferences(string $token, array $categories): bool {
        global $wpdb;

        $subscriber = $this->get_subscriber_by_token($token);
        if (!$subscriber || $subscriber->status !== 'active') {
            return false;
        }

        $valid = array_keys(self::category_labels());
        $categories = array_values(array_intersect($categories, $valid));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        return (bool) $wpdb->update(
            self::table_name(),
            ['categories' => wp_json_encode($categories)],
            ['id' => $subscriber->id],
            ['%s'],
            ['%d']
        );
    }

    public function get_subscriber_by_id(int $id): ?object {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Custom table name from trusted $wpdb->prefix
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE id = %d",
            $id
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $row ?: null;
    }

    public function get_subscriber_by_email(string $email): ?object {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Custom table name from trusted $wpdb->prefix
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE email = %s",
            $email
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $row ?: null;
    }

    public function get_subscriber_by_token(string $token): ?object {
        global $wpdb;
        if (!$token) {
            return null;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Custom table name from trusted $wpdb->prefix
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE token = %s",
            $token
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $row ?: null;
    }

    /**
     * Get active subscribers for a given category.
     */
    public function get_subscribers_for_category(string $category): array {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Custom table name from trusted $wpdb->prefix
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE status = 'active' AND categories LIKE %s",
            '%' . $wpdb->esc_like('"' . $category . '"') . '%'
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $results ?: [];
    }

    /**
     * Get all subscribers with optional status filter.
     */
    public function get_all_subscribers(string $status = ''): array {
        global $wpdb;
        $table = self::table_name();
        if ($status) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Custom table, table name from trusted prefix
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC",
                $status
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
        } else {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Custom table, no user input in query
            $results = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY created_at DESC"
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
        }
        return $results ?: [];
    }

    /**
     * Get subscriber counts by status.
     */
    public function get_counts(): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no user input in query
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$table} GROUP BY status");
        $counts = ['active' => 0, 'pending' => 0, 'unsubscribed' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] = (int) $row->count;
            $counts['total'] += (int) $row->count;
        }
        return $counts;
    }

    /**
     * Delete a subscriber by ID.
     */
    public function delete_subscriber(int $id): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
        return (bool) $wpdb->delete(self::table_name(), ['id' => $id], ['%d']);
    }

    // ──────────────────────────────────────────────
    // Token
    // ──────────────────────────────────────────────

    private function generate_token(string $email): string {
        return hash('sha256', $email . wp_salt('auth') . wp_rand());
    }

    // ──────────────────────────────────────────────
    // Policy broadcast (one-shot email on admin save)
    // ──────────────────────────────────────────────

    /**
     * Email every active subscriber opted into "policies" that a policy has
     * been updated. Triggered directly from the policy save handler when the
     * "Broadcast this change" checkbox is ticked. One-shot, synchronous, no
     * queue, no state beyond a last-broadcast-at meta field on the policy.
     *
     * @return array{sent:int, failed:int}
     */
    public function broadcast_policy_change(\WP_Post $policy): array {
        global $wpdb;

        $subscribers = $this->get_all_subscribers('active');
        $sent   = 0;
        $failed = 0;

        foreach ($subscribers as $subscriber) {
            $sub_cats = json_decode($subscriber->categories, true) ?: [];
            if (!in_array('policies', $sub_cats, true)) {
                continue;
            }

            $ok = $this->send_policy_broadcast_email($policy, $subscriber);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uses format array for escaping
            $wpdb->insert(
                self::log_table_name(),
                [
                    'subscriber_id' => $subscriber->id,
                    'post_id'       => $policy->ID,
                    'sent_at'       => current_time('mysql'),
                    'status'        => $ok ? 'sent' : 'failed',
                    'error_message' => '',
                ],
                ['%d', '%d', '%s', '%s', '%s']
            );

            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        update_post_meta($policy->ID, '_ot_policy_last_broadcast_at', current_time('mysql'));
        update_post_meta($policy->ID, '_ot_policy_last_broadcast_sent', $sent);
        update_post_meta($policy->ID, '_ot_policy_last_broadcast_failed', $failed);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Render and send the policy broadcast email to a single subscriber.
     */
    private function send_policy_broadcast_email(\WP_Post $policy, object $subscriber): bool {
        $settings     = OpenTrust::get_settings();
        $company_name = $settings['company_name'] ?: get_bloginfo('name');
        $base_url     = home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/');

        $effective_date = get_post_meta($policy->ID, '_ot_policy_effective_date', true);
        $effective_display = $effective_date
            ? wp_date(get_option('date_format', 'F j, Y'), strtotime($effective_date))
            : '';

        $greeting = $subscriber->name
            /* translators: %s: subscriber name */
            ? sprintf(__('Hi %s,', 'opentrust'), esc_html($subscriber->name))
            : __('Hi,', 'opentrust');

        $policy_url      = $base_url . 'policy/' . $policy->post_name . '/';
        $unsubscribe_url = $base_url . 'unsubscribe/' . $subscriber->token . '/';
        $preferences_url = $base_url . 'preferences/' . $subscriber->token . '/';

        $subject = sprintf(
            /* translators: 1: company name, 2: policy title */
            __('[%1$s] Policy updated: %2$s', 'opentrust'),
            $company_name,
            $policy->post_title
        );

        $body = $this->render_email_template('policy-broadcast', [
            'ot_company_name'    => $company_name,
            'ot_greeting'        => $greeting,
            'ot_policy_title'    => $policy->post_title,
            'ot_policy_url'      => $policy_url,
            'ot_effective_date'  => $effective_display,
            'ot_base_url'        => $base_url,
            'ot_unsubscribe_url' => $unsubscribe_url,
            'ot_preferences_url' => $preferences_url,
            'ot_accent_color'    => $settings['accent_color'] ?? '#2563EB',
        ]);

        $headers = $this->build_email_headers($settings);
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'List-Unsubscribe: <' . $unsubscribe_url . '>';
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';

        return (bool) wp_mail($subscriber->email, $subject, $body, $headers);
    }

    // ──────────────────────────────────────────────
    // Email sending
    // ──────────────────────────────────────────────

    /**
     * Send confirmation (double opt-in) email.
     */
    private function send_confirmation_email(string $email, string $name, string $token): void {
        $settings     = OpenTrust::get_settings();
        $company_name = $settings['company_name'] ?: get_bloginfo('name');
        $base_url     = home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/');
        $confirm_url  = $base_url . 'confirm/' . $token . '/';

        $subject = sprintf(
            /* translators: %s: company name */
            __('Confirm your subscription to %s Trust Center updates', 'opentrust'),
            $company_name
        );

        $greeting = $name
            /* translators: %s: subscriber name */
            ? sprintf(__('Hi %s,', 'opentrust'), esc_html($name))
            : __('Hi,', 'opentrust');

        $body = $this->render_email_template('confirmation', [
            'ot_company_name' => $company_name,
            'ot_confirm_url'  => $confirm_url,
            'ot_greeting'     => $greeting,
            'ot_accent_color' => $settings['accent_color'] ?? '#2563EB',
        ]);

        $headers = $this->build_email_headers($settings);
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        wp_mail($email, $subject, $body, $headers);
    }

    private function build_email_headers(array $settings): array {
        $headers = [];
        $from_name  = !empty($settings['notification_from_name'])
            ? $settings['notification_from_name']
            : ($settings['company_name'] ?: get_bloginfo('name'));
        $from_email = !empty($settings['notification_reply_to'])
            ? $settings['notification_reply_to']
            : get_option('admin_email');

        $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
        return $headers;
    }

    /**
     * Render an email template.
     */
    private function render_email_template(string $template, array $vars): string {
        extract($vars, EXTR_SKIP);
        ob_start();
        $path = OPENTRUST_PLUGIN_DIR . 'templates/emails/' . $template . '.php';
        if (file_exists($path)) {
            include $path;
        }
        return ob_get_clean() ?: '';
    }

    // ──────────────────────────────────────────────
    // Notification log
    // ──────────────────────────────────────────────

    /**
     * Get recent notification log entries.
     */
    public function get_notification_log(int $limit = 50): array {
        global $wpdb;
        $table     = self::log_table_name();
        $sub_table = self::table_name();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Custom tables, table names from trusted prefix
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, s.email, s.name as subscriber_name
             FROM {$table} l
             LEFT JOIN {$sub_table} s ON l.subscriber_id = s.id
             ORDER BY l.sent_at DESC
             LIMIT %d",
            $limit
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
        return $results ?: [];
    }

    // ──────────────────────────────────────────────
    // RSS Feed
    // ──────────────────────────────────────────────

    /**
     * Output an RSS 2.0 feed of trust center changes.
     */
    public function render_rss_feed(): void {
        $settings     = OpenTrust::get_settings();
        $company_name = $settings['company_name'] ?: get_bloginfo('name');
        $base_url     = home_url('/' . ($settings['endpoint_slug'] ?? 'trust-center') . '/');

        // Gather recent published items across all trust center CPTs.
        $posts = get_posts([
            'post_type'      => array_keys(self::CPT_CATEGORIES),
            'posts_per_page' => 50,
            'post_status'    => 'publish',
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);

        header('Content-Type: application/rss+xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title><?php echo esc_html($company_name); ?> — <?php echo esc_html($settings['page_title'] ?? 'Trust Center'); ?></title>
    <link><?php echo esc_url($base_url); ?></link>
    <description><?php echo esc_html($settings['tagline'] ?? ''); ?></description>
    <language><?php echo esc_attr(get_bloginfo('language')); ?></language>
    <lastBuildDate><?php echo esc_html(gmdate('r')); ?></lastBuildDate>
    <atom:link href="<?php echo esc_url($base_url . 'feed/'); ?>" rel="self" type="application/rss+xml" />
    <?php foreach ($posts as $post):
        $category = self::CPT_CATEGORIES[$post->post_type] ?? '';
        $labels   = self::category_labels();
        $cat_label = $labels[$category] ?? '';

        $item_url = match ($post->post_type) {
            'ot_policy' => $base_url . 'policy/' . $post->post_name . '/',
            default     => $base_url . '#ot-' . str_replace('_', '-', $category),
        };

        $description = match ($post->post_type) {
            'ot_policy'        => wp_trim_words($post->post_excerpt ?: $post->post_content, 60),
            'ot_subprocessor'  => get_post_meta($post->ID, '_ot_sub_purpose', true) ?: '',
            'ot_certification' => get_post_meta($post->ID, '_ot_cert_description', true) ?: '',
            'ot_data_practice' => get_post_meta($post->ID, '_ot_dp_purpose', true) ?: '',
            default            => '',
        };
    ?>
    <item>
        <title><?php echo esc_html($post->post_title); ?></title>
        <link><?php echo esc_url($item_url); ?></link>
        <guid isPermaLink="false">opentrust-<?php echo esc_attr((string) $post->ID); ?>-<?php echo esc_attr($post->post_modified_gmt); ?></guid>
        <pubDate><?php echo esc_html(get_post_modified_time('r', true, $post)); ?></pubDate>
        <category><?php echo esc_html($cat_label); ?></category>
        <description><![CDATA[<?php echo wp_kses_post($description); ?>]]></description>
    </item>
    <?php endforeach; ?>
</channel>
</rss>
        <?php
    }

    // ──────────────────────────────────────────────
    // Frontend: handle form submissions / endpoints
    // ──────────────────────────────────────────────

    /**
     * Handle the subscribe form POST.
     */
    public function handle_subscribe_post(): array {
        if (empty($_POST['_ot_subscribe_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ot_subscribe_nonce'] ) ), 'opentrust_subscribe')) {
            return ['success' => false, 'message' => __('Invalid request.', 'opentrust')];
        }

        $settings = OpenTrust::get_settings();

        // Rate limiting.
        $rate_check = $this->check_rate_limit($settings);
        if ($rate_check !== null) {
            return $rate_check;
        }

        // Turnstile verification.
        $turnstile_check = $this->verify_turnstile($settings);
        if ($turnstile_check !== null) {
            return $turnstile_check;
        }

        $email      = sanitize_email( wp_unslash( $_POST['ot_email'] ?? '' ) );
        $name       = sanitize_text_field( wp_unslash( $_POST['ot_name'] ?? '' ) );
        $company    = sanitize_text_field( wp_unslash( $_POST['ot_company'] ?? '' ) );
        $categories = [];

        if (!empty($_POST['ot_categories']) && is_array($_POST['ot_categories'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below
            $valid = array_keys(self::category_labels());
            foreach (wp_unslash( $_POST['ot_categories'] ) as $cat) {
                $cat = sanitize_text_field($cat);
                if (in_array($cat, $valid, true)) {
                    $categories[] = $cat;
                }
            }
        }

        // Increment rate limit counter after validation passes.
        $this->increment_rate_limit();

        return $this->subscribe($email, $name, $company, $categories);
    }

    // ──────────────────────────────────────────────
    // Rate limiting
    // ──────────────────────────────────────────────

    /**
     * Check IP-based rate limit.
     *
     * @return array|null Error array if rate limited, null if OK.
     */
    private function check_rate_limit(array $settings): ?array {
        $max = (int) ($settings['rate_limit_per_hour'] ?? 5);
        if ($max <= 0) {
            return null; // Disabled.
        }

        $ip  = $this->get_client_ip();
        $key = 'opentrust_rl_' . md5($ip);
        $attempts = (int) get_transient($key);

        if ($attempts >= $max) {
            return [
                'success' => false,
                'message' => __('Too many attempts. Please try again later.', 'opentrust'),
            ];
        }

        return null;
    }

    /**
     * Increment the rate limit counter for the current IP.
     */
    private function increment_rate_limit(): void {
        $settings = OpenTrust::get_settings();
        $max = (int) ($settings['rate_limit_per_hour'] ?? 5);
        if ($max <= 0) {
            return;
        }

        $ip  = $this->get_client_ip();
        $key = 'opentrust_rl_' . md5($ip);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
    }

    /**
     * Get the client IP, respecting common proxy headers.
     */
    private function get_client_ip(): string {
        // Check standard proxy headers (safe — only used for rate limiting, not auth).
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = strtok( sanitize_text_field( wp_unslash( $_SERVER[$header] ) ), ',');
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    }

    // ──────────────────────────────────────────────
    // Turnstile verification
    // ──────────────────────────────────────────────

    /**
     * Verify Cloudflare Turnstile response token.
     *
     * @return array|null Error array if verification fails, null if OK or disabled.
     */
    private function verify_turnstile(array $settings): ?array {
        $secret = $settings['turnstile_secret_key'] ?? '';
        $site_key = $settings['turnstile_site_key'] ?? '';

        // Turnstile not configured — skip.
        if ($secret === '' || $site_key === '') {
            return null;
        }

        $token = sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in calling method before this helper is invoked
        if ($token === '') {
            return [
                'success' => false,
                'message' => __('Please complete the security check.', 'opentrust'),
            ];
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body'    => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $this->get_client_ip(),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            // Network failure — fail open to avoid blocking legitimate users.
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['success'])) {
            return [
                'success' => false,
                'message' => __('Security verification failed. Please try again.', 'opentrust'),
            ];
        }

        return null;
    }

    /**
     * Handle preferences update POST.
     */
    public function handle_preferences_post(string $token): array {
        if (empty($_POST['_ot_preferences_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ot_preferences_nonce'] ) ), 'opentrust_preferences')) {
            return ['success' => false, 'message' => __('Invalid request.', 'opentrust')];
        }

        $categories = [];
        if (!empty($_POST['ot_categories']) && is_array($_POST['ot_categories'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in loop below
            $valid = array_keys(self::category_labels());
            foreach (wp_unslash( $_POST['ot_categories'] ) as $cat) {
                $cat = sanitize_text_field($cat);
                if (in_array($cat, $valid, true)) {
                    $categories[] = $cat;
                }
            }
        }

        if (empty($categories)) {
            return ['success' => false, 'message' => __('Please select at least one category.', 'opentrust')];
        }

        $updated = $this->update_preferences($token, $categories);
        return $updated
            ? ['success' => true, 'message' => __('Your preferences have been updated.', 'opentrust')]
            : ['success' => false, 'message' => __('Could not update preferences. Please try again.', 'opentrust')];
    }
}
