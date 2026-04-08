<?php
/**
 * Plugin Name: OpenTrust
 * Plugin URI:  https://github.com/opentrust/opentrust
 * Description: A self-hosted, open-source trust center for publishing security policies, subprocessors, certifications, and data practices.
 * Version:     0.1.0
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

define('OPENTRUST_VERSION', '0.1.0');
define('OPENTRUST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OPENTRUST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OPENTRUST_PLUGIN_FILE', __FILE__);

require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-admin.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-cpt.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-render.php';
require_once OPENTRUST_PLUGIN_DIR . 'includes/class-opentrust-version.php';

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

    // Add rewrite rules and flush.
    OpenTrust::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});
