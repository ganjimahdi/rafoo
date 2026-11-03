<?php

namespace Rafoo;

defined( 'ABSPATH' ) || exit;

final class Signature {

	const PUBLIC_KEY = 'luWKblSfyFmOSN9JIv5loTSs2evI6AsMd1KA49sZF1w=';

	public static function available() {
		return 32 === strlen( self::key() ) && self::ready();
	}

	public static function verify( $message, $signature ) {
		if ( ! self::available() || ! is_string( $message ) || ! is_string( $signature ) ) {
			return false;
		}
		$raw = base64_decode( trim( $signature ), true );
		if ( false === $raw || 64 !== strlen( $raw ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $raw, $message, self::key() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function open( $body ) {
		$envelope = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $envelope ) || ! isset( $envelope['payload'], $envelope['sig'] ) || ! is_string( $envelope['payload'] ) ) {
			return null;
		}
		$payload = base64_decode( $envelope['payload'], true );
		if ( false === $payload || ! self::verify( $payload, (string) $envelope['sig'] ) ) {
			return null;
		}
		$data = json_decode( $payload, true );
		return is_array( $data ) ? $data : null;
	}

	private static function key() {
		$encoded = defined( 'RAFOO_SIGNING_KEY' ) ? (string) RAFOO_SIGNING_KEY : self::PUBLIC_KEY;
		$raw     = base64_decode( $encoded, true );
		return false === $raw ? '' : $raw;
	}

	private static function ready() {
		if ( function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return true;
		}
		$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';
		if ( is_readable( $compat ) ) {
			require_once $compat;
		}
		return function_exists( 'sodium_crypto_sign_verify_detached' );
	}
}
