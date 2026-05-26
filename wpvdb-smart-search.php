<?php
/**
 * Plugin Name:      WPVDB Smart Search
 * Plugin URI:       https://github.com/rbcorrales/wpvdb-smart-search
 * Description:      Adds a public Smart Search page, REST endpoint, and optional site search replacement for wpvdb dense, sparse, and hybrid search.
 * Version:          0.1.2
 * Author:           Automattic, Ramon Corrales
 * Author URI:       https://automattic.com/
 * Requires at least: 6.9
 * Requires PHP:     8.3
 * Requires Plugins: wpvdb, wpvdb-search
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      wpvdb-smart-search
 * Domain Path:      /languages
 *
 * @package WPVDB_Smart_Search
 */

namespace WPVDB_Smart_Search;

defined( 'ABSPATH' ) || exit;

define( 'WPVDB_SMART_SEARCH_VERSION', '0.1.2' );
define( 'WPVDB_SMART_SEARCH_FILE', __FILE__ );
define( 'WPVDB_SMART_SEARCH_DIR', __DIR__ );
define( 'WPVDB_SMART_SEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-content.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-template.php';
require_once __DIR__ . '/includes/surfaces/class-site-search.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-cli.php';
}

register_activation_hook( __FILE__, [ Template::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Template::class, 'deactivate' ] );

/**
 * Show an admin notice when wpvdb-search is missing.
 */
function dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'WPVDB Smart Search requires the WPVDB Search plugin to be active.', 'wpvdb-smart-search' ); ?></p>
	</div>
	<?php
}

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'wpvdb-smart-search',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		Settings::init();

		if ( ! class_exists( '\WPVDB_Search\Search' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
			return;
		}

		Rest::init();
		Template::init();
		Surfaces\Site_Search::init();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( __NAMESPACE__ . '\\CLI' ) ) {
			CLI::init();
		}
	}
);
