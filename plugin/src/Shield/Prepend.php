<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Prepend {

	const FILE = 'rafoo-shield.php';

	const MARKER = 'Rafoo Shield';

	public static function path() {
		return ABSPATH . self::FILE;
	}

	public static function method() {
		$sapi = PHP_SAPI;
		if ( 'apache2handler' === $sapi || 0 === strpos( $sapi, 'litespeed' ) ) {
			return 'htaccess';
		}
		if ( in_array( $sapi, array( 'cgi-fcgi', 'fpm-fcgi', 'cgi' ), true ) ) {
			return 'user_ini';
		}
		return '';
	}

	public static function status() {
		$current = (string) ini_get( 'auto_prepend_file' );
		$ours    = self::path();
		$running = defined( 'RAFOO_SHIELD' ) && is_string( RAFOO_SHIELD );
		if ( '' !== $current && wp_normalize_path( $current ) === wp_normalize_path( $ours ) ) {
			return $running ? 'active' : 'broken';
		}
		if ( '' !== $current ) {
			return 'conflict';
		}
		if ( Local::get( 'prepend' ) ) {
			return 'pending';
		}
		return '' === self::method() ? 'unsupported' : 'off';
	}

	public static function view() {
		return array(
			'status'  => self::status(),
			'method'  => self::method(),
			'enabled' => (bool) Local::get( 'prepend' ),
			'other'   => 'conflict' === self::status() ? basename( (string) ini_get( 'auto_prepend_file' ) ) : '',
			'cache'   => (int) ini_get( 'user_ini.cache_ttl' ),
		);
	}

	public static function enable() {
		$method = self::method();
		if ( '' === $method ) {
			return new \WP_Error( 'rafoo_shield_prepend', __( 'This server does not let a site set up protection before WordPress. Protection still runs as the first plugin.', 'rafoo' ) );
		}
		if ( 'conflict' === self::status() ) {
			return new \WP_Error( 'rafoo_shield_prepend', __( 'Another security tool already runs before WordPress on this site. Rafoo keeps running as the first plugin so both work together.', 'rafoo' ) );
		}
		if ( ! self::write_loader() ) {
			return new \WP_Error( 'rafoo_shield_prepend', __( 'Rafoo could not write to the site’s main folder. Check the folder permissions and try again.', 'rafoo' ) );
		}
		$ok = 'htaccess' === $method ? self::htaccess( true ) : self::user_ini( true );
		if ( ! $ok ) {
			self::delete_loader();
			return new \WP_Error( 'rafoo_shield_prepend', __( 'Rafoo could not write to the site’s main folder. Check the folder permissions and try again.', 'rafoo' ) );
		}
		Local::update( array( 'prepend' => true ) );
		\Rafoo\State::event( 'shield_prepend_on' );
		return true;
	}

	public static function disable() {
		self::htaccess( false );
		self::user_ini( false );
		self::delete_loader();
		if ( Local::get( 'prepend' ) ) {
			Local::update( array( 'prepend' => false ) );
			\Rafoo\State::event( 'shield_prepend_off' );
		}
		return true;
	}

	public static function refresh() {
		if ( Local::get( 'prepend' ) && is_file( self::path() ) ) {
			self::write_loader();
		}
	}

	public static function loader() {
		$early  = wp_normalize_path( dirname( __FILE__ ) . '/early.php' );
		$config = wp_normalize_path( Config::dir() . '/' . Config::FILE );
		$root   = trailingslashit( wp_normalize_path( ABSPATH ) );
		return "<?php\n\nif ( ! defined( 'RAFOO_SHIELD' ) && is_file( " . var_export( $early, true ) . ' ) && is_file( ' . var_export( $config, true ) . " ) ) {\n\tdefined( 'ABSPATH' ) || define( 'ABSPATH', " . var_export( $root, true ) . " );\n\tdefine( 'RAFOO_SHIELD', " . var_export( $config, true ) . " );\n\tinclude_once " . var_export( $early, true ) . ";\n}\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- quotes the paths for the loader file.
	}

	private static function write_loader() {
		$body = self::loader();
		if ( is_file( self::path() ) && file_get_contents( self::path() ) === $body ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the site root.
			return true;
		}
		return false !== file_put_contents( self::path(), $body, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the loader must sit in the site root and run before WordPress.
	}

	private static function delete_loader() {
		if ( is_file( self::path() ) ) {
			wp_delete_file( self::path() );
		}
	}

	private static function htaccess( $on ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$file = ABSPATH . '.htaccess';
		if ( ! $on && ( ! is_file( $file ) || false === strpos( (string) file_get_contents( $file ), self::MARKER ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the site root.
			return true;
		}
		$path  = self::path();
		$lines = $on ? array(
			'<IfModule mod_php.c>',
			'php_value auto_prepend_file "' . $path . '"',
			'</IfModule>',
			'<IfModule mod_php7.c>',
			'php_value auto_prepend_file "' . $path . '"',
			'</IfModule>',
			'<IfModule mod_php8.c>',
			'php_value auto_prepend_file "' . $path . '"',
			'</IfModule>',
			'<IfModule lsapi_module>',
			'php_value auto_prepend_file "' . $path . '"',
			'</IfModule>',
			'<Files "' . self::FILE . '">',
			'<IfModule mod_authz_core.c>',
			'Require all denied',
			'</IfModule>',
			'</Files>',
		) : array();
		return (bool) insert_with_markers( $file, self::MARKER, $lines );
	}

	private static function user_ini( $on ) {
		$file    = ABSPATH . '.user.ini';
		$current = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the site root.
		$clean   = (string) preg_replace( '/\n?; BEGIN ' . self::MARKER . '.*?; END ' . self::MARKER . '\n?/s', "\n", $current );
		if ( ! $on && $clean === $current ) {
			return true;
		}
		$next = rtrim( $clean ) . ( $on ? "\n; BEGIN " . self::MARKER . "\nauto_prepend_file = \"" . self::path() . "\"\n; END " . self::MARKER . "\n" : "\n" );
		if ( ! $on && '' === trim( $next ) ) {
			wp_delete_file( $file );
			return true;
		}
		return false !== file_put_contents( $file, ltrim( $next ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- PHP reads .user.ini from the site root.
	}
}
