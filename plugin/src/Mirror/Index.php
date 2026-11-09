<?php

namespace Rafoo\Mirror;

use Rafoo\Http\Client;
use Rafoo\Signature;

defined( 'ABSPATH' ) || exit;

final class Index {

	const API = 'https://rafoo.ir/api/v1/mirror/';

	const TTL = 21600;

	const MISSING_TTL = 43200;

	const MAX_BYTES = 4194304;

	public static function available() {
		return Signature::available();
	}

	public static function npm_files( $package, $version ) {
		$data = self::load( 'npm', array( 'package' => (string) $package, 'version' => (string) $version ) );
		return isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : null;
	}

	public static function plugin( $slug ) {
		return self::load( 'plugin', array( 'slug' => (string) $slug ) );
	}

	public static function theme( $slug ) {
		return self::load( 'theme', array( 'slug' => (string) $slug ) );
	}

	public static function core() {
		return self::load( 'core', array( 'slug' => 'wordpress' ) );
	}

	public static function address( $kind, array $key ) {
		$base = (string) apply_filters( 'rafoo_mirror_index', self::API );
		if ( 'npm' === $kind ) {
			return add_query_arg( array_map( 'rawurlencode', $key ), $base . 'npm' );
		}
		if ( 'core' === $kind ) {
			return $base . 'core';
		}
		return $base . $kind . '/' . rawurlencode( $key['slug'] );
	}

	public static function cached( $kind, array $key ) {
		$value = get_site_transient( self::cache_key( $kind, $key ) );
		return false === $value ? false : ( is_array( $value ) ? $value : null );
	}

	public static function load( $kind, array $key ) {
		if ( ! self::available() || ! self::valid( $kind, $key ) ) {
			return null;
		}
		$cached = self::cached( $kind, $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$response = wp_remote_get( self::address( $kind, $key ), Client::args( array( 'timeout' => 10, 'redirection' => 0 ) ) );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data     = 200 === $code ? Signature::open( substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_BYTES ) ) : null;
		if ( is_array( $data ) && ! self::matches( $data, $kind, $key ) ) {
			$data = null;
		}
		$transient = self::cache_key( $kind, $key );
		if ( ! is_array( $data ) ) {
			if ( 404 === $code ) {
				set_site_transient( $transient, '', self::MISSING_TTL );
			}
			return null;
		}
		set_site_transient( $transient, $data, self::TTL );
		return $data;
	}

	public static function matches( array $data, $kind, array $key ) {
		if ( 'rafoo-mirror-index' !== ( isset( $data['type'] ) ? $data['type'] : '' ) || $kind !== ( isset( $data['kind'] ) ? $data['kind'] : '' ) ) {
			return false;
		}
		if ( 'npm' === $kind ) {
			return isset( $data['package'], $data['version'] ) && $data['package'] === $key['package'] && $data['version'] === $key['version'];
		}
		return isset( $data['slug'] ) && $data['slug'] === $key['slug'];
	}

	public static function forget( $kind, array $key ) {
		delete_site_transient( self::cache_key( $kind, $key ) );
	}

	private static function valid( $kind, array $key ) {
		if ( 'npm' === $kind ) {
			return isset( $key['package'], $key['version'] ) && preg_match( '#^(@[a-z0-9][a-z0-9._\-]*/)?[a-z0-9][a-z0-9._\-]*$#', $key['package'] ) && preg_match( '/^[0-9][0-9A-Za-z.\-]*$/', $key['version'] );
		}
		return in_array( $kind, array( 'plugin', 'theme', 'core' ), true ) && isset( $key['slug'] ) && preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]{0,99}$/', $key['slug'] );
	}

	private static function cache_key( $kind, array $key ) {
		return 'rafoo_mix_' . md5( $kind . '|' . implode( '|', $key ) );
	}
}
