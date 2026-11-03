<?php

namespace Rafoo\Assets;

defined( 'ABSPATH' ) || exit;

final class Loopback {

	const TYPES = array(
		'js'  => array( 'javascript', 'ecmascript' ),
		'css' => array( 'text/css' ),
	);

	public static function serves( $url, $type ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' === $content_type || ! isset( self::TYPES[ $type ] ) ) {
			return true;
		}
		foreach ( self::TYPES[ $type ] as $needle ) {
			if ( false !== strpos( $content_type, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
