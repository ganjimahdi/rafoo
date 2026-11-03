<?php

namespace Rafoo\Mirror;

use Rafoo\Http\Client;
use Rafoo\Settings;
use Rafoo\Signature;

defined( 'ABSPATH' ) || exit;

final class Index {

	const PREFIX = '/rafoo/v1/';

	const TTL = 21600;

	const MISSING_TTL = 43200;

	const MAX_BYTES = 2097152;

	public static function available() {
		return Signature::available();
	}

	public static function npm_files( $package, $version ) {
		$data = self::load( 'npm_mirrors', 'npm/' . $package . '@' . $version . '.json' );
		return isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : null;
	}

	public static function plugin( $slug ) {
		return self::load( 'wp_mirrors', 'plugins/' . $slug . '.json' );
	}

	public static function theme( $slug ) {
		return self::load( 'wp_mirrors', 'themes/' . $slug . '.json' );
	}

	public static function core() {
		return self::load( 'wp_mirrors', 'core.json' );
	}

	public static function cached( $group, $path ) {
		$value = get_site_transient( self::cache_key( $group, $path ) );
		return false === $value ? false : ( is_array( $value ) ? $value : null );
	}

	public static function load( $group, $path ) {
		if ( ! self::available() || ! preg_match( '#^[a-z0-9@._\-/]+\.json$#i', $path ) || false !== strpos( $path, '..' ) ) {
			return null;
		}
		$cached = self::cached( $group, $path );
		if ( false !== $cached ) {
			return $cached;
		}
		$data   = null;
		$reason = 'missing';
		foreach ( (array) Settings::get( $group ) as $base ) {
			$url  = $base . self::PREFIX . $path;
			$body = Client::body( $url, array( 'timeout' => 8 ), self::MAX_BYTES );
			if ( is_wp_error( $body ) ) {
				$reason = $body->get_error_code();
				continue;
			}
			$signature = Client::body( $url . '.sig', array( 'timeout' => 8 ), 1024 );
			if ( is_wp_error( $signature ) || ! Signature::verify( $body, $signature ) ) {
				$reason = 'bad_signature';
				continue;
			}
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
				break;
			}
		}
		$transient = self::cache_key( $group, $path );
		if ( null === $data ) {
			if ( 'rafoo_http_404' === $reason || 'missing' === $reason ) {
				set_site_transient( $transient, '', self::MISSING_TTL );
			}
			return null;
		}
		set_site_transient( $transient, $data, self::TTL );
		return $data;
	}

	public static function forget( $group, $path ) {
		delete_site_transient( self::cache_key( $group, $path ) );
	}

	private static function cache_key( $group, $path ) {
		return 'rafoo_idx_' . md5( $group . '|' . $path );
	}
}
