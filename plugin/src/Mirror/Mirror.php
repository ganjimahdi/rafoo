<?php

namespace Rafoo\Mirror;

use Rafoo\Assets\Store;
use Rafoo\Http\Client;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Mirror {

	public static function url( $base, $path ) {
		$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $path, '/' ) ) ) );
		return $base . '/' . str_replace( '%40', '@', $encoded );
	}

	public static function get( $path, $timeout = 10 ) {
		$path = ltrim( (string) $path, '/' );
		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return null;
		}
		foreach ( (array) Settings::get( 'npm_mirrors' ) as $base ) {
			$body = Client::body( self::url( $base, $path ), array( 'timeout' => $timeout ), Store::MAX_BYTES );
			if ( is_string( $body ) ) {
				return $body;
			}
		}
		return null;
	}
}
