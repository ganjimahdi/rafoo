<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Challenge {

	const FIELD = 'rafoo_shield_pow';

	const PASS = 'rafoo_shield_pass';

	const LIFETIME = 300;

	public static function scope( $ip ) {
		if ( false === strpos( (string) $ip, ':' ) ) {
			$parts = explode( '.', (string) $ip );
			return 4 === count( $parts ) ? $parts[0] . '.' . $parts[1] . '.' . $parts[2] : (string) $ip;
		}
		return Ip::key( $ip );
	}

	public static function create( $secret, $ip, $max, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$expires = $now + self::LIFETIME;
		$salt    = bin2hex( random_bytes( 12 ) ) . '.' . $expires;
		$number  = random_int( 0, max( 1000, (int) $max ) );
		$hash    = hash( 'sha256', $salt . $number );
		return array(
			'salt'      => $salt,
			'challenge' => $hash,
			'max'       => max( 1000, (int) $max ),
			'sig'       => hash_hmac( 'sha256', 'pow|' . $hash . '|' . self::scope( $ip ), (string) $secret ),
		);
	}

	public static function verify( $secret, $ip, $payload, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$data = is_string( $payload ) && strlen( $payload ) < 1024 ? json_decode( $payload, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['salt'], $data['challenge'], $data['sig'], $data['number'] ) ) {
			return '';
		}
		$salt  = (string) $data['salt'];
		$hash  = (string) $data['challenge'];
		$parts = explode( '.', $salt );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < $now || (int) $parts[1] > $now + self::LIFETIME + 5 ) {
			return '';
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! is_numeric( $data['number'] ) || (int) $data['number'] < 0 ) {
			return '';
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'pow|' . $hash . '|' . self::scope( $ip ), (string) $secret ), (string) $data['sig'] ) ) {
			return '';
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $salt . (int) $data['number'] ) ) ) {
			return '';
		}
		return $hash;
	}

	public static function pass( $secret, $ip, $agent, $hours, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$expires = $now + max( 1, (int) $hours ) * 3600;
		return $expires . '.' . hash_hmac( 'sha256', 'pass|' . self::scope( $ip ) . '|' . md5( (string) $agent ) . '|' . $expires, (string) $secret );
	}

	public static function passed( $secret, $ip, $agent, $cookie, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$parts = explode( '.', (string) $cookie, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < $now ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', 'pass|' . self::scope( $ip ) . '|' . md5( (string) $agent ) . '|' . $parts[0], (string) $secret ), $parts[1] );
	}
}
