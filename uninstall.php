<?php
/**
 * OpenTrust uninstall routine.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * through the WordPress admin.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local scope, this file runs standalone

global $wpdb;

// Delete all OpenTrust post types and their meta.
//
// uninstall.php is intentionally self-contained — WordPress invokes it without
// loading the rest of the plugin, so we cannot reference OpenTrust_CPT::ALL
// here. The list below MUST stay in sync with that constant; if a CPT is
// added or renamed there, mirror the change here.
$ot_post_types = ['ot_policy', 'ot_subprocessor', 'ot_certification', 'ot_data_practice', 'ot_faq'];

foreach ($ot_post_types as $ot_post_type) {
    $posts = get_posts([
        'post_type'      => $ot_post_type,
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ]);

    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// Drop notification tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL with dynamic table prefix cannot use prepare()
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}opentrust_notification_log");
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL with dynamic table prefix cannot use prepare()
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}opentrust_subscribers");

// Drop chat log table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- DDL with dynamic table prefix cannot use prepare()
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}opentrust_chat_log");

// Unschedule chat log purge cron.
$ot_timestamp = wp_next_scheduled('opentrust_chat_log_purge');
if ($ot_timestamp) {
    wp_unschedule_event($ot_timestamp, 'opentrust_chat_log_purge');
}

// Clear any pending policy-summary single-events. Pending events would otherwise
// fire post-uninstall and fatal because the OpenTrust_Chat_Summarizer class is
// gone — wp_clear_scheduled_hook() removes every scheduled occurrence.
wp_clear_scheduled_hook('opentrust_generate_policy_summary');

// Delete plugin options.
delete_option('opentrust_settings');
delete_option('opentrust_provider_keys');
delete_option('opentrust_db_version');
delete_option('opentrust_cache_version');
delete_option('opentrust_faqs_seeded');

// Legacy option from the removed weekly-digest system.
delete_option('opentrust_notification_queue');

// Legacy cron event from the removed weekly-digest system.
$ot_legacy_digest = wp_next_scheduled('opentrust_weekly_digest');
if ($ot_legacy_digest) {
    wp_unschedule_event($ot_legacy_digest, 'opentrust_weekly_digest');
}

// Clean up any transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bulk cleanup of plugin transients on uninstall, no user input
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_opentrust_%' OR option_name LIKE '_transient_timeout_opentrust_%'");

// Flush rewrite rules.
flush_rewrite_rules();
