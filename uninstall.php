<?php
/**
 * OpenTrust uninstall routine.
 *
 * Removes all plugin data from the database when the plugin is deleted
 * through the WordPress admin.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Delete all OpenTrust post types and their meta.
$post_types = ['ot_policy', 'ot_subprocessor', 'ot_certification', 'ot_data_practice'];

foreach ($post_types as $post_type) {
    $posts = get_posts([
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ]);

    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }
}

// Delete plugin options.
delete_option('opentrust_settings');

// Clean up any transients.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_opentrust_%' OR option_name LIKE '_transient_timeout_opentrust_%'");

// Flush rewrite rules.
flush_rewrite_rules();
