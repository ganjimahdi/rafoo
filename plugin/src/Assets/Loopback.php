<?php

namespace Rafoo\Assets;

defined( 'ABSPATH' ) || exit;

final class Loopback {

	const TYPES = array(
		'js'  => array( 'javascript', 'ecmascript' ),
		'css' => array( 'text/css' ),
	);

	public static function cookies() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || empty( $_COOKIE ) ) {
			return array();
		}
		$out = array();
		foreach ( array( LOGGED_IN_COOKIE, AUTH_COOKIE, SECURE_AUTH_COOKIE ) as $name ) {
			if ( isset( $_COOKIE[ $name ] ) ) {
				$out[ $name ] = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
			}
		}
		return $out;
	}

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
