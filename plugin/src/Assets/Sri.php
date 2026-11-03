<?php

namespace Rafoo\Assets;

defined( 'ABSPATH' ) || exit;

final class Sri {

	const ALGORITHMS = array( 'sha512', 'sha384', 'sha256' );

	public static function normalize( $integrity ) {
		$tokens = array();
		foreach ( preg_split( '/\s+/', trim( (string) $integrity ) ) as $token ) {
			if ( preg_match( '#^(sha256|sha384|sha512)-([A-Za-z0-9+/]+={0,2})(\?.*)?$#', $token, $m ) ) {
				$tokens[] = $m[1] . '-' . $m[2];
			}
		}
		return implode( ' ', array_unique( $tokens ) );
	}

	public static function matches( $bytes, $integrity ) {
		$integrity = self::normalize( $integrity );
		if ( '' === $integrity || ! is_string( $bytes ) ) {
			return false;
		}
		$by_algorithm = array();
		foreach ( explode( ' ', $integrity ) as $token ) {
			list( $algorithm, $digest ) = explode( '-', $token, 2 );
			$by_algorithm[ $algorithm ][] = $digest;
		}
		foreach ( self::ALGORITHMS as $algorithm ) {
			if ( empty( $by_algorithm[ $algorithm ] ) ) {
				continue;
			}
			$actual = base64_encode( hash( $algorithm, $bytes, true ) );
			foreach ( $by_algorithm[ $algorithm ] as $expected ) {
				if ( hash_equals( $expected, $actual ) ) {
					return true;
				}
			}
			return false;
		}
		return false;
	}

	public static function of( $bytes ) {
		return 'sha384-' . base64_encode( hash( 'sha384', $bytes, true ) );
	}
}
