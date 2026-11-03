<?php

namespace Rafoo\Backup;

use Rafoo\Assets\Store;

defined( 'ABSPATH' ) || exit;

final class Ledger {

	const FOLDER = 'backups';

	private static $locks = array();

	public static function dir() {
		return Store::private_dir( self::FOLDER );
	}

	public static function path( $name ) {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . sanitize_file_name( $name );
	}

	public static function read( $name ) {
		$file = self::path( $name . '.json' );
		if ( '' === $file || ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}

	public static function write( $name, $data ) {
		$file = self::path( $name . '.json' );
		if ( '' === $file ) {
			return false;
		}
		if ( null === $data ) {
			wp_delete_file( $file );
			return true;
		}
		$temp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === file_put_contents( $temp, wp_json_encode( $data ) ) ) {
			return false;
		}
		return self::move( $temp, $file );
	}

	public static function lock( $name ) {
		$file = self::path( $name . '.lock' );
		if ( '' === $file ) {
			return false;
		}
		$handle = self::open( $file, 'c' );
		if ( ! $handle || ! $handle->flock( LOCK_EX | LOCK_NB ) ) {
			return false;
		}
		self::$locks[ $name ] = $handle;
		return true;
	}

	public static function unlock( $name ) {
		if ( isset( self::$locks[ $name ] ) ) {
			self::$locks[ $name ]->flock( LOCK_UN );
			unset( self::$locks[ $name ] );
		}
	}

	public static function open( $path, $mode ) {
		try {
			return new \SplFileObject( $path, $mode );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public static function move( $from, $to ) {
		return Store::fs()->move( $from, $to, true );
	}

	public static function temp( $name = '' ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return wp_tempnam( $name );
	}

	public static function remove_dir( $dir ) {
		$base = self::dir();
		if ( '' !== $base && is_dir( $dir ) && 0 === strpos( wp_normalize_path( $dir ), wp_normalize_path( $base ) . '/' ) ) {
			Store::fs()->rmdir( $dir, true );
		}
	}

	public static function free_space() {
		$dir  = self::dir();
		$free = '' !== $dir && function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false;
		return false === $free ? -1 : (int) $free;
	}

	public static function budget() {
		$limit = (int) ini_get( 'max_execution_time' );
		$limit = $limit > 0 ? $limit : 60;
		return (int) max( 5, min( (int) apply_filters( 'rafoo_backup_step_seconds', 20 ), $limit - 10 ) );
	}
}
