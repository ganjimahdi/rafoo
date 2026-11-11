<?php
/**
 * Plugin Name:       Rafoo Complete
 * Plugin URI:        https://rafoo.ir
 * Description:       Keeps WordPress fast and working when the international internet is cut off, and updates WordPress, plugins and themes from the domestic mirror.
 * Version:           1.6.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Wbweb
 * Author URI:        https://wbweb.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rafoo
 * Domain Path:       /languages
 * Update URI:        https://rafoo.ir/rafoo-complete
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RAFOO_FILE' ) ) {
	if ( 'complete' !== RAFOO_EDITION ) {
		add_action(
			'admin_init',
			static function () {
				deactivate_plugins( 'rafoo/rafoo.php', true );
			}
		);
	}
	return;
}

define( 'RAFOO_FILE', __FILE__ );
define( 'RAFOO_VERSION', '1.6.0' );
define( 'RAFOO_EDITION', 'complete' );

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( 'Rafoo\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Rafoo\\Plugin', 'deactivate' ) );

Rafoo\Plugin::instance()->boot();
