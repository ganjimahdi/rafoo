<?php

namespace Rafoo\Shield;

use Rafoo\Assets\Store as Files;
use Rafoo\Geo\Store as Geo;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Config {

	const FILE = 'config.php';

	const FEED = 'feed.bin';

	public static function dir() {
		return Files::private_dir( 'shield' );
	}

	public static function mode() {
		$mode = (string) Settings::get( 'shield' );
		return Settings::paused() || ! in_array( $mode, Modes::ALL, true ) ? 'off' : $mode;
	}

	public static function build() {
		$dir     = self::dir();
		$network = Local::network();
		$geo     = Geo::active() && Geo::is_primary() ? Geo::key() : '';
		return array(
			'v'       => 1,
			'mode'    => self::mode(),
			'secret'  => Local::secret(),
			'dir'     => $dir,
			'site'    => substr( md5( home_url( '/' ) ), 0, 8 ),
			'home'    => home_url( '/' ),
			'allow'   => array_values( (array) Local::get( 'allow' ) ),
			'deny'    => array_values( (array) Local::get( 'deny' ) ),
			'proxies' => array_values( (array) Local::get( 'proxies' ) ),
			'admins'  => array_fill_keys( array_keys( (array) Local::get( 'admins' ) ), 1 ),
			'geo'     => 32 === strlen( $geo ) ? bin2hex( $geo ) : '',
			'network' => (bool) Settings::get( 'shield_network' ) && ! empty( $network['joined'] ),
			'feed'    => '' !== $dir ? $dir . '/' . self::FEED : '',
			'feed6'   => (array) $network['ipv6'],
			'patches' => Intel::active_patches( (array) $network['patches'] ),
			'text'    => self::text(),
		);
	}

	public static function compile() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return false;
		}
		$config = self::build();
		$body   = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn " . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- builds the firewall's own config file.
		$file   = $dir . '/' . self::FILE;
		$temp   = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === file_put_contents( $temp, $body, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the firewall config must be written before WordPress loads; WP_Filesystem may ask for credentials.
			return false;
		}
		if ( ! rename( $temp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of the firewall config.
			wp_delete_file( $temp );
			return false;
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}
		$boot = array(
			'file' => $file,
			'mode' => $config['mode'],
			'at'   => time(),
		);
		if ( get_option( Local::BOOT ) !== $boot ) {
			update_option( Local::BOOT, $boot, true );
		}
		return true;
	}

	public static function remove() {
		$dir = self::dir();
		foreach ( array( self::FILE, self::FEED, Log::FILE, Log::FILE . '.1' ) as $name ) {
			if ( '' !== $dir && is_file( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
		delete_option( Local::BOOT );
	}

	public static function text() {
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		$text     = self::strings();
		if ( $switched ) {
			restore_previous_locale();
		}
		return $text;
	}

	private static function strings() {
		$rtl = function_exists( 'is_rtl' ) && is_rtl();
		return array(
			'lang'             => str_replace( '_', '-', (string) get_locale() ),
			'dir'              => $rtl ? 'rtl' : 'ltr',
			'block_title'      => __( 'This request was stopped', 'rafoo' ),
			'block_text'       => __( 'The site’s security shield stopped this request because it looked like an attack. If you think this is a mistake, send the reference code below to the site administrator.', 'rafoo' ),
			'limit_title'      => __( 'Too many requests', 'rafoo' ),
			'limit_text'       => __( 'Requests from your connection arrived too quickly. Please wait a minute and try again.', 'rafoo' ),
			'locked_title'     => __( 'Signing in is paused', 'rafoo' ),
			'locked_text'      => __( 'There were too many failed sign-in attempts from your connection. Please try again later.', 'rafoo' ),
			'challenge_title'  => __( 'Checking your browser', 'rafoo' ),
			'challenge_text'   => __( 'Your browser is completing a short security check. The page opens by itself in a few seconds.', 'rafoo' ),
			'challenge_failed' => __( 'The check could not be completed. Reload the page to try again.', 'rafoo' ),
			'noscript'         => __( 'Turn on JavaScript to continue.', 'rafoo' ),
			'reference'        => __( 'Reference', 'rafoo' ),
			'home'             => __( 'Back to the home page', 'rafoo' ),
		);
	}
}
