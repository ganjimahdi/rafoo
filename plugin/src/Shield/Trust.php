<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Trust {

	const COOKIE = 'rafoo_shield_trust';

	const USER = 0;
	const EDITOR = 1;
	const ADMIN = 2;

	public static function make( $secret, $user, $level, $expires ) {
		$body = (int) $user . '.' . (int) $level . '.' . (int) $expires;
		return $body . '.' . hash_hmac( 'sha256', 'trust|' . $body, (string) $secret );
	}

	public static function read( $secret, $cookie, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$parts = explode( '.', (string) $cookie );
		if ( 4 !== count( $parts ) || '' === (string) $secret ) {
			return -1;
		}
		list( $user, $level, $expires, $sig ) = $parts;
		if ( ! ctype_digit( $user ) || ! ctype_digit( $level ) || ! ctype_digit( $expires ) || (int) $expires < $now ) {
			return -1;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'trust|' . $user . '.' . $level . '.' . $expires, (string) $secret ), $sig ) ) {
			return -1;
		}
		return min( self::ADMIN, (int) $level );
	}
}
