<?php
/**
 * Plugin Name: OpenTrust
 * Plugin URI:  https://github.com/opentrust/opentrust
 * Description: A self-hosted, open-source trust center for publishing security policies, subprocessors, certifications, and data practices.
 * Version:     0.9.6
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Author:      OpenTrust
 * Author URI:  https://github.com/opentrust
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: opentrust
 * Domain Path: /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('OPENTRUST_VERSION', '0.9.6');
define('OPENTRUST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPENTRUST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OPENTRUST_PLUGIN_FILE', __FILE__);
define('OPENTRUST_DB_VERSION', 10);

require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-admin.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-cpt.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-catalog.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-render.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-version.php';

// Chat (OTC) — policy chat feature.
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat-secrets.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/providers/class-opentrust-chat-provider.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/providers/class-opentrust-chat-provider-anthropic.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/providers/class-opentrust-chat-provider-openai.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/providers/class-opentrust-chat-provider-openrouter.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat-search.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat-corpus.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat-budget.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat-log.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat-summarizer.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-chat.php';

add_action('plugins_loaded', static function (): void {
    OpenTrust::instance();
});

register_activation_hook(__FILE__, static function (): void {
    // Register CPTs before flushing so rewrite rules include them.
    OpenTrust_CPT::register_post_types();

    // Set default settings if not present.
    if (false === get_option('opentrust_settings')) {
        update_option('opentrust_settings', OpenTrust::defaults());
    }

    // Defensive cleanup: remove any legacy weekly-digest and notification state
    // from earlier plugin versions (subscriptions feature lives on the
    // feature/subscriptions-broadcasts branch and is not shipped in this build).
    wp_clear_scheduled_hook('opentrust_weekly_digest');
    delete_option('opentrust_notification_queue');

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with dynamic table prefix cannot use prepare()
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}opentrust_subscribers");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL with dynamic table prefix cannot use prepare()
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}opentrust_notification_log");

    // Create chat log table.
    OpenTrust_Chat_Log::create_table();

    // Run data migration.
    OpenTrust_CPT::migrate_data_practices_v2();
    update_option('opentrust_db_version', OPENTRUST_DB_VERSION);

    // Seed default FAQs on first activation. Gated internally so deletions
    // stick and re-activation will not recreate them.
    OpenTrust_Catalog::seed_default_faqs();

    // Schedule crons.
    OpenTrust_Chat_Log::schedule_cron();

    // Add rewrite rules and flush.
    OpenTrust::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('opentrust_weekly_digest');
    OpenTrust_Chat_Log::unschedule_cron();
    flush_rewrite_rules();
});
