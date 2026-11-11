<?php

namespace Rafoo\Peers;

use Rafoo\Signature;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Keys {

	const OPTION = 'rafoo_peer';

	private static $cache = null;

	public static function data() {
		if ( null === self::$cache ) {
			$value       = get_option( self::OPTION, array() );
			self::$cache = is_array( $value ) ? $value : array();
		}
		return self::$cache;
	}

	public static function save( array $data ) {
		self::$cache = $data;
		update_option( self::OPTION, $data, false );
	}

	public static function update( array $changes ) {
		self::save( array_merge( self::data(), $changes ) );
	}

	public static function member() {
		$data = self::data();
		return ! empty( $data['member'] ) && ! empty( $data['pk'] );
	}

	public static function ready() {
		return Signature::ready() && function_exists( 'sodium_crypto_sign_keypair' ) && function_exists( 'sodium_crypto_secretbox' );
	}

	public static function ensure() {
		$data = self::data();
		if ( ! empty( $data['pk'] ) && ! empty( $data['sk'] ) && '' !== self::secret() ) {
			return true;
		}
		if ( ! self::ready() ) {
			return false;
		}
		try {
			$pair   = sodium_crypto_sign_keypair();
			$public = sodium_crypto_sign_publickey( $pair );
			$secret = sodium_crypto_sign_secretkey( $pair );
		} catch ( \Throwable $e ) {
			return false;
		}
		$data['pk'] = base64_encode( $public );
		$data['sk'] = Vault::seal( base64_encode( $secret ) );
		self::save( $data );
		return '' !== $data['sk'];
	}

	public static function public_key() {
		$data = self::data();
		return isset( $data['pk'] ) ? (string) $data['pk'] : '';
	}

	public static function sign( $message ) {
		$secret = self::secret();
		if ( '' === $secret || ! self::ready() ) {
			return '';
		}
		try {
			return base64_encode( sodium_crypto_sign_detached( (string) $message, $secret ) );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	public static function verify( $message, $signature, $public ) {
		$sig = base64_decode( (string) $signature, true );
		$key = base64_decode( (string) $public, true );
		if ( false === $sig || false === $key || 64 !== strlen( $sig ) || 32 !== strlen( $key ) || ! self::ready() ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, (string) $message, $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function host( $url ) {
		$parts = wp_parse_url( (string) $url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( '' !== $host && ! empty( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}
		return $host;
	}

	public static function nonce() {
		return rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
	}

	public static function forget() {
		$data = self::data();
		$keep = ! empty( $data['pk'] ) && ! empty( $data['sk'] ) && (bool) apply_filters( 'rafoo_peer_key_needed', false );
		self::$cache = $keep ? array(
			'pk' => $data['pk'],
			'sk' => $data['sk'],
		) : array();
		if ( $keep ) {
			update_option( self::OPTION, self::$cache, false );
		} else {
			delete_option( self::OPTION );
		}
		delete_option( Book::OPTION );
		delete_transient( Engine::STATUS );
		delete_transient( Engine::LOCK );
	}

	private static function secret() {
		$data = self::data();
		if ( empty( $data['sk'] ) || ! self::ready() ) {
			return '';
		}
		$raw = base64_decode( Vault::open( $data['sk'] ), true );
		return false === $raw || 64 !== strlen( $raw ) ? '' : $raw;
	}
}
