<?php

namespace Rafoo;

defined( 'ABSPATH' ) || exit;

final class Vault {

	const PREFIX = 'rv1:';

	public static function seal( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . bin2hex( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function open( $sealed ) {
		$sealed = (string) $sealed;
		if ( 0 !== strpos( $sealed, self::PREFIX ) ) {
			return '';
		}
		$hex = substr( $sealed, strlen( self::PREFIX ) );
		$raw = ctype_xdigit( $hex ) && 0 === strlen( $hex ) % 2 ? hex2bin( $hex ) : false;
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Exception $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}

	private static function key() {
		return sodium_crypto_generichash( wp_salt( 'secure_auth' ) . '|rafoo-vault', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
