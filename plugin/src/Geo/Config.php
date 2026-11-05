<?php

namespace Rafoo\Geo;

use Rafoo\Assets\Store as Files;

defined( 'ABSPATH' ) || exit;

final class Config {

	const PATTERN = '/(\$table_prefix\s*=\s*)([\'"])([A-Za-z0-9_]*)\2(\s*;)/';

	public static function path() {
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return is_file( $up ) && ! is_file( dirname( ABSPATH ) . '/wp-settings.php' ) ? $up : '';
	}

	public static function writable() {
		$path = self::path();
		if ( '' === $path || ! wp_is_writable( $path ) ) {
			return false;
		}
		return 1 === preg_match( self::PATTERN, (string) Files::fs()->get_contents( $path ) );
	}

	public static function set_prefix( $prefix ) {
		global $wpdb;
		$prefix = (string) $prefix;
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', $prefix ) ) {
			return new \WP_Error( 'rafoo_geo_prefix', 'bad prefix' );
		}
		$path = self::path();
		$text = '' !== $path ? (string) Files::fs()->get_contents( $path ) : '';
		if ( ! preg_match( self::PATTERN, $text, $match ) ) {
			return new \WP_Error( 'rafoo_geo_config', 'wp-config.php not found' );
		}
		if ( $match[3] === $prefix ) {
			return '';
		}
		$next = preg_replace( self::PATTERN, '${1}${2}' . $prefix . '${2}${4}', $text, 1 );
		if ( ! is_string( $next ) || ! Files::fs()->put_contents( $path, $next, fileperms( $path ) & 0777 ) ) {
			return new \WP_Error( 'rafoo_geo_config', 'wp-config.php not writable' );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
		$wpdb->set_prefix( $prefix );
		return $match[3];
	}
}
