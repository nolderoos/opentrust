<?php
/**
 * Plugin Name:       GovernDocs - Document Governance
 * Description:       Governance document management: Policies, Meetings, Agendas, Minutes & Reports.
 * Version:           1.0.2
 * Author:            quicksnail
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       governdocs-document-governance
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GOVERNDOCS_FILE', __FILE__ );
define( 'GOVERNDOCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOVERNDOCS_URL', plugin_dir_url( __FILE__ ) );
define( 'GOVERNDOCS_VERSION', '1.0.2' );
//define( 'GOVERNDOCS_VERSION', time() );

require_once GOVERNDOCS_DIR . 'includes/class-governdocs.php';

function governdocs() : GovernDocs\GovernDocs_Plugin {
    return GovernDocs\GovernDocs_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'GovernDocs\GovernDocs_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GovernDocs\GovernDocs_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', 'governdocs' );
