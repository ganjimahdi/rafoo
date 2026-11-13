<?php
/**
 * Plugin Name:       Rafoo – Offline-Ready Assets & Updates
 * Plugin URI:        https://rafoo.ir
 * Description:       Keeps WordPress fast and working when the international internet is cut off: serves CDN libraries and fonts from your own site, fails unreachable outside requests instantly and replaces services that cannot be mirrored.
 * Version:           1.6.5
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Wbweb
 * Author URI:        https://wbweb.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rafoo
 * Domain Path:       /languages
 * Update URI:        https://rafoo.ir/rafoo
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RAFOO_FILE' ) ) {
	return;
}

define( 'RAFOO_FILE', __FILE__ );
define( 'RAFOO_VERSION', '1.6.5' );
define( 'RAFOO_EDITION', 'standard' );

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( 'Rafoo\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Rafoo\\Plugin', 'deactivate' ) );

Rafoo\Plugin::instance()->boot();
