<?php

namespace Rafoo\Geo;

use Rafoo\Http\Client;

defined( 'ABSPATH' ) || exit;

final class Link {

	const ACTION = 'rafoo_geo';

	const MAGIC = 'RG1';

	const WINDOW = 300;

	const MAX_REQUEST = 1048576;

	const MAX_REPLY = 16777216;

	public static function url( array $node ) {
		return untrailingslashit( $node['url'] ) . '/wp-admin/admin-ajax.php?action=' . self::ACTION;
	}

	public static function seal( $plain, $key ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::MAGIC . $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key );
	}

	public static function open( $sealed, $key ) {
		$sealed = (string) $sealed;
		$head   = strlen( self::MAGIC ) + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $sealed ) <= $head + SODIUM_CRYPTO_SECRETBOX_MACBYTES || 0 !== strpos( $sealed, self::MAGIC ) || 32 !== strlen( (string) $key ) ) {
			return false;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $sealed, $head ), substr( $sealed, strlen( self::MAGIC ), SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		} catch ( \Throwable $e ) {
			return false;
		}
		return false === $plain ? false : $plain;
	}

	public static function call( array $node, $op, array $payload = array(), $raw = false, $timeout = 20, $key = null ) {
		$key  = null === $key ? Store::key() : $key;
		if ( 32 !== strlen( (string) $key ) ) {
			return new \WP_Error( 'rafoo_geo_key', 'no key' );
		}
		$body  = self::seal(
			wp_json_encode(
				array(
					'op'      => (string) $op,
					'payload' => $payload,
					'time'    => time(),
					'nonce'   => bin2hex( random_bytes( 12 ) ),
					'from'    => (string) Store::get( 'self' ),
				)
			),
			$key
		);
		$start = microtime( true );
		$reply = wp_remote_post(
			self::url( $node ),
			Client::args(
				array(
					'timeout'             => $timeout,
					'redirection'         => 0,
					'headers'             => array( 'Content-Type' => 'application/octet-stream' ),
					'body'                => $body,
					'limit_response_size' => self::MAX_REPLY + 64,
					'sslverify'           => (bool) apply_filters( 'rafoo_geo_sslverify', true ),
					'rafoo_probe'         => true,
				)
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$code = (int) wp_remote_retrieve_response_code( $reply );
		if ( 200 !== $code ) {
			return new \WP_Error( 'rafoo_geo_http', 'HTTP ' . $code );
		}
		$plain = self::open( wp_remote_retrieve_body( $reply ), $key );
		if ( false === $plain ) {
			return new \WP_Error( 'rafoo_geo_sign', 'bad reply' );
		}
		if ( $raw ) {
			return $plain;
		}
		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'rafoo_geo_reply', 'bad reply' );
		}
		$data['_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
		return $data;
	}

	public static function send( array $node, $op, array $payload = array() ) {
		$key = Store::key();
		if ( 32 !== strlen( $key ) ) {
			return;
		}
		wp_remote_post(
			self::url( $node ),
			Client::args(
				array(
					'timeout'     => 0.5,
					'blocking'    => false,
					'redirection' => 0,
					'headers'     => array( 'Content-Type' => 'application/octet-stream' ),
					'body'        => self::seal(
						wp_json_encode(
							array(
								'op'      => (string) $op,
								'payload' => $payload,
								'time'    => time(),
								'nonce'   => bin2hex( random_bytes( 12 ) ),
								'from'    => (string) Store::get( 'self' ),
							)
						),
						$key
					),
					'sslverify'   => (bool) apply_filters( 'rafoo_geo_sslverify', true ),
					'rafoo_probe' => true,
				)
			)
		);
	}

	public static function endpoint() {
		$body = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_REQUEST );
		$key  = Store::key();
		$data = false;
		$join = false;
		if ( 32 === strlen( $key ) ) {
			$data = self::open( $body, $key );
		}
		if ( false === $data && 'joining' === Store::role() ) {
			$candidate = Nodes::join_key();
			if ( '' !== $candidate ) {
				$data = self::open( $body, $candidate );
				$key  = $candidate;
				$join = false !== $data;
			}
		}
		$data = false === $data ? null : json_decode( $data, true );
		if ( ! is_array( $data ) || empty( $data['op'] ) || empty( $data['nonce'] ) || abs( time() - (int) $data['time'] ) > self::WINDOW ) {
			self::deny();
		}
		$nonce = 'rafoo_gn_' . substr( preg_replace( '/[^a-f0-9]/', '', (string) $data['nonce'] ), 0, 24 );
		if ( get_transient( $nonce ) ) {
			self::deny();
		}
		set_transient( $nonce, 1, 2 * self::WINDOW );
		$op      = (string) $data['op'];
		$payload = isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();
		if ( $join && 'join' !== $op ) {
			self::deny();
		}
		$result = Ops::handle( $op, $payload, isset( $data['from'] ) ? (string) $data['from'] : '' );
		if ( null === $result ) {
			self::deny();
		}
		self::reply( $result, $key );
	}

	public static function reply( $result, $key ) {
		$plain = is_array( $result ) ? wp_json_encode( $result ) : (string) $result;
		$out   = self::seal( $plain, $key );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $out ) );
		header( 'X-Robots-Tag: noindex' );
		file_put_contents( 'php://output', $out );
		exit;
	}

	public static function token( $host, $time = null ) {
		$key  = Store::key();
		$time = null === $time ? time() : (int) $time;
		return 32 === strlen( $key ) ? $time . '.' . substr( hash_hmac( 'sha256', $time . '|' . strtolower( $host ), $key ), 0, 40 ) : '';
	}

	public static function valid_token( $token, $host ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) || abs( time() - (int) $parts[0] ) > self::WINDOW ) {
			return false;
		}
		$expected = self::token( $host, (int) $parts[0] );
		return '' !== $expected && hash_equals( $expected, (string) $token );
	}

	private static function deny() {
		status_header( 403 );
		exit;
	}

	public static function encode( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	public static function decode( $text ) {
		$text = strtr( (string) $text, '-_', '+/' );
		$data = base64_decode( $text . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true );
		return false === $data ? '' : $data;
	}
}
