<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Store {

	private static $dir = '';

	private static $apcu = null;

	private static $prefix = 'rafoo_shield:';

	public static function init( $dir, $prefix = '' ) {
		self::$dir    = rtrim( (string) $dir, '/\\' );
		self::$prefix = 'rafoo_shield:' . $prefix . ':';
		self::$apcu   = null;
	}

	public static function apcu() {
		if ( null === self::$apcu ) {
			self::$apcu = function_exists( 'apcu_fetch' ) && function_exists( 'apcu_enabled' ) && apcu_enabled() && 'cli' !== PHP_SAPI;
		}
		return self::$apcu;
	}

	public static function force_files() {
		self::$apcu = false;
	}

	public static function get( $key ) {
		if ( self::apcu() ) {
			$ok    = false;
			$value = apcu_fetch( self::$prefix . $key, $ok );
			return $ok && is_array( $value ) ? $value : array();
		}
		$file = self::file( $key );
		if ( '' === $file || ! is_file( $file ) ) {
			return array();
		}
		$raw  = (string) @file_get_contents( $file );
		$data = '' === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || ( isset( $data['_e'] ) && (int) $data['_e'] < time() ) ) {
			return array();
		}
		return $data;
	}

	public static function put( $key, array $value, $ttl ) {
		$ttl = max( 60, (int) $ttl );
		if ( self::apcu() ) {
			return apcu_store( self::$prefix . $key, $value, $ttl );
		}
		$file = self::file( $key );
		if ( '' === $file ) {
			return false;
		}
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress loads; the folder is inside the shield's private folder.
			return false;
		}
		$value['_e'] = time() + $ttl;
		return false !== @file_put_contents( $file, json_encode( $value ), LOCK_EX );
	}

	public static function drop( $key ) {
		if ( self::apcu() ) {
			apcu_delete( self::$prefix . $key );
			return;
		}
		$file = self::file( $key );
		if ( '' !== $file && is_file( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress loads; the file is inside the shield's private folder.
		}
	}

	public static function sweep( $limit = 4000 ) {
		if ( self::apcu() || '' === self::$dir || ! is_dir( self::$dir . '/c' ) ) {
			return 0;
		}
		$now     = time();
		$removed = 0;
		$seen    = 0;
		foreach ( (array) glob( self::$dir . '/c/*/*.json' ) as $file ) {
			if ( ++$seen > $limit ) {
				break;
			}
			$data = json_decode( (string) @file_get_contents( $file ), true );
			if ( ! is_array( $data ) || ! isset( $data['_e'] ) || (int) $data['_e'] < $now ) {
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- expired counter file inside the shield's private folder.
				++$removed;
			}
		}
		return $removed;
	}

	private static function file( $key ) {
		if ( '' === self::$dir ) {
			return '';
		}
		$hash = md5( (string) $key );
		return self::$dir . '/c/' . substr( $hash, 0, 2 ) . '/' . $hash . '.json';
	}
}
