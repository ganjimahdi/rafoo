<?php

namespace Rafoo\Captcha;

defined( 'ABSPATH' ) || exit;

final class Pow {

	const ALGORITHM = 'SHA-256';

	const LIFETIME = 1800;

	const FAILS = 'rafoo_captcha_fails';

	const SPENT = 'rafoo_cap_';

	const LEVELS = array(
		0  => 40000,
		10 => 150000,
		50 => 500000,
	);

	public static function create( $now = null, $max = null ) {
		$now     = null === $now ? time() : (int) $now;
		$max     = null === $max ? self::difficulty() : max( 1000, (int) $max );
		$expires = $now + self::LIFETIME;
		$salt    = bin2hex( random_bytes( 12 ) ) . '.' . $expires;
		$number  = random_int( 0, $max );
		$hash    = hash( 'sha256', $salt . $number );
		return array(
			'algorithm' => self::ALGORITHM,
			'challenge' => $hash,
			'salt'      => $salt,
			'signature' => self::sign( $hash ),
			'maxnumber' => $max,
			'expires'   => $expires,
		);
	}

	public static function check( $payload, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$data = self::decode( $payload );
		if ( ! $data ) {
			return '';
		}
		$parts = explode( '.', $data['salt'] );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < $now ) {
			return '';
		}
		if ( ! hash_equals( self::sign( $data['challenge'] ), $data['signature'] ) ) {
			return '';
		}
		if ( ! hash_equals( $data['challenge'], hash( 'sha256', $data['salt'] . $data['number'] ) ) ) {
			return '';
		}
		return $data['challenge'];
	}

	public static function verify( $payload, $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$challenge = self::check( $payload, $now );
		if ( '' === $challenge ) {
			self::failed();
			return false;
		}
		$key = self::SPENT . substr( $challenge, 0, 40 );
		if ( get_transient( $key ) ) {
			self::failed();
			return false;
		}
		set_transient( $key, 1, self::LIFETIME + 60 );
		return true;
	}

	public static function decode( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload || strlen( $payload ) > 1024 ) {
			return null;
		}
		$json = base64_decode( strtr( $payload, '-_', '+/' ), true );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'algorithm', 'challenge', 'salt', 'signature' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				return null;
			}
		}
		if ( self::ALGORITHM !== $data['algorithm'] || ! isset( $data['number'] ) || ! is_int( $data['number'] ) || $data['number'] < 0 ) {
			return null;
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $data['challenge'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $data['signature'] ) ) {
			return null;
		}
		return $data;
	}

	public static function difficulty() {
		$fails = (int) get_transient( self::FAILS );
		$max   = self::LEVELS[0];
		foreach ( self::LEVELS as $threshold => $level ) {
			if ( $fails >= $threshold ) {
				$max = $level;
			}
		}
		return (int) apply_filters( 'rafoo_captcha_difficulty', $max, $fails );
	}

	private static function failed() {
		set_transient( self::FAILS, (int) get_transient( self::FAILS ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	private static function sign( $challenge ) {
		return hash_hmac( 'sha256', $challenge, wp_salt( 'nonce' ) . '|rafoo-captcha' );
	}
}
