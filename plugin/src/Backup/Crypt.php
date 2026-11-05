<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Crypt {

	const MAGIC = 'RAFOOBK1';

	const CHUNK = 1048576;

	const PASSWORD = 'P';

	const KEY = 'K';

	public static function available() {
		return function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) && function_exists( 'sodium_crypto_pwhash' );
	}

	public static function begin( $target, $secret, $mode ) {
		$salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
		$key  = self::key( $secret, $mode, $salt );
		list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
		$out = Ledger::open( $target, 'wb' );
		if ( ! $out ) {
			return null;
		}
		$out->fwrite( self::MAGIC . $mode . $salt . $header );
		$out = null;
		return array(
			'state'  => bin2hex( $state ),
			'offset' => 0,
		);
	}

	public static function push( $source, $target, array $job, $deadline ) {
		$in  = Ledger::open( $source, 'rb' );
		$out = Ledger::open( $target, 'ab' );
		if ( ! $in || ! $out ) {
			return false;
		}
		$size  = (int) filesize( $source );
		$state = hex2bin( $job['state'] );
		$in->fseek( $job['offset'] );
		while ( $job['offset'] < $size && microtime( true ) < $deadline ) {
			$plain          = (string) $in->fread( self::CHUNK );
			$job['offset'] += strlen( $plain );
			$tag            = $job['offset'] >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
			$out->fwrite( sodium_crypto_secretstream_xchacha20poly1305_push( $state, $plain, '', $tag ) );
		}
		$job['state'] = bin2hex( $state );
		$job['done']  = $job['offset'] >= $size;
		return $job;
	}

	public static function open( $source, $secret ) {
		$head = self::head( $source );
		if ( 0 !== strpos( $head, self::MAGIC ) ) {
			return null;
		}
		$mode   = substr( $head, strlen( self::MAGIC ), 1 );
		$salt   = substr( $head, strlen( self::MAGIC ) + 1, SODIUM_CRYPTO_PWHASH_SALTBYTES );
		$header = substr( $head, strlen( self::MAGIC ) + 1 + SODIUM_CRYPTO_PWHASH_SALTBYTES );
		try {
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, self::key( $secret, $mode, $salt ) );
		} catch ( \Exception $e ) {
			return null;
		}
		return array(
			'state'  => bin2hex( $state ),
			'offset' => strlen( $head ),
		);
	}

	public static function pull( $source, $target, array $job, $deadline ) {
		$in  = Ledger::open( $source, 'rb' );
		$out = Ledger::open( $target, empty( $job['written'] ) ? 'wb' : 'ab' );
		if ( ! $in || ! $out ) {
			return false;
		}
		$size  = (int) filesize( $source );
		$state = hex2bin( $job['state'] );
		$in->fseek( $job['offset'] );
		$job['done'] = false;
		while ( $job['offset'] < $size && microtime( true ) < $deadline ) {
			$cipher = (string) $in->fread( self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES );
			$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $cipher );
			if ( false === $result ) {
				return false;
			}
			list( $plain, $tag ) = $result;
			$out->fwrite( $plain );
			$job['offset'] += strlen( $cipher );
			$job['written'] = ( isset( $job['written'] ) ? $job['written'] : 0 ) + strlen( $plain );
			if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag ) {
				$job['done'] = true;
				break;
			}
		}
		if ( ! $job['done'] && $job['offset'] >= $size ) {
			return false;
		}
		$job['state'] = bin2hex( $state );
		return $job;
	}

	public static function derive( $secret, $mode, $salt ) {
		return self::key( $secret, $mode, $salt );
	}

	public static function encrypted( $file ) {
		return is_file( $file ) && 0 === strpos( self::head( $file ), self::MAGIC );
	}

	private static function head( $file ) {
		$in = Ledger::open( $file, 'rb' );
		return $in ? (string) $in->fread( strlen( self::MAGIC ) + 1 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES ) : '';
	}

	private static function key( $secret, $mode, $salt ) {
		if ( self::KEY === $mode ) {
			return sodium_crypto_generichash( $secret . $salt, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES );
		}
		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
			(string) $secret,
			$salt,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		);
	}
}
