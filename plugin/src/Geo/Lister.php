<?php

namespace Rafoo\Geo;

use Rafoo\Assets\Store as Assets;
use Rafoo\Backup\Files;
use Rafoo\Backup\Ledger;

defined( 'ABSPATH' ) || exit;

final class Lister {

	const HOOK = 'rafoo_geo_list';

	const REFRESH = 1800;

	const SKIP = array( '.htaccess', '.user.ini', 'php.ini', 'wp-config.php', 'wp-content/object-cache.php', 'wp-content/db.php', '.well-known' );

	public static function dir() {
		return Assets::private_dir( 'geo' );
	}

	public static function path( $name ) {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . sanitize_file_name( $name );
	}

	public static function rules() {
		$rules = Files::rules( false );
		foreach ( self::SKIP as $path ) {
			$rules['paths'][ $path ] = true;
		}
		foreach ( (array) apply_filters( 'rafoo_geo_exclude', array() ) as $path ) {
			$rules['paths'][ trim( (string) $path, '/' ) ] = true;
		}
		return $rules;
	}

	public static function skipped( $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		foreach ( self::SKIP as $path ) {
			if ( $relative === $path || 0 === strpos( $relative, $path . '/' ) ) {
				return true;
			}
		}
		return Files::protected_path( $relative );
	}

	public static function soon() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	public static function meta() {
		$file = self::path( 'files.json' );
		$data = '' !== $file && is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $data ) ? $data : array(
			'at'    => 0,
			'gen'   => 0,
			'files' => 0,
			'bytes' => 0,
			'size'  => 0,
			'scan'  => null,
		);
	}

	private static function save_meta( array $meta ) {
		$file = self::path( 'files.json' );
		if ( '' !== $file ) {
			file_put_contents( $file, wp_json_encode( $meta ) );
		}
	}

	public static function due() {
		$meta = self::meta();
		$data = Changes::data();
		return null !== $meta['scan'] || ! is_file( self::path( 'files.list' ) ) || (int) $data['files'] > (int) $meta['gen'] || time() - (int) $meta['at'] > self::REFRESH;
	}

	public static function run( $seconds = 8 ) {
		if ( ! Store::is_primary() || '' === self::dir() || ! Ledger::lock( 'geo-list' ) ) {
			return self::meta();
		}
		$meta     = self::meta();
		$next     = self::path( 'files.next' );
		$deadline = microtime( true ) + $seconds;
		$warnings = array();
		if ( null === $meta['scan'] ) {
			$meta['scan']     = Files::start_scan( $next );
			$meta['scan_gen'] = Changes::generation();
		}
		$meta['scan'] = Files::scan( $next, $meta['scan'], $deadline, $warnings, self::rules() );
		if ( ! empty( $meta['scan']['done'] ) ) {
			Ledger::move( $next, self::path( 'files.list' ) );
			$meta['at']    = time();
			$meta['gen']   = (int) $meta['scan_gen'];
			$meta['files'] = (int) $meta['scan']['files'];
			$meta['bytes'] = (int) $meta['scan']['bytes'];
			$meta['size']  = (int) filesize( self::path( 'files.list' ) );
			$meta['scan']  = null;
			unset( $meta['scan']['stack'] );
		}
		self::save_meta( $meta );
		Ledger::unlock( 'geo-list' );
		if ( null !== $meta['scan'] ) {
			self::soon();
		}
		return $meta;
	}

	public static function chunk( $offset, $length ) {
		$file = self::path( 'files.list' );
		if ( '' === $file || ! is_file( $file ) ) {
			return '';
		}
		return (string) file_get_contents( $file, false, null, max( 0, (int) $offset ), max( 1, min( 4194304, (int) $length ) ) );
	}
}
