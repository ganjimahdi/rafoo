<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Feed {

	const SIZE = 9;

	const BLOCK = 1;

	const CHALLENGE = 2;

	public static function pack( array $entries ) {
		$rows = array();
		foreach ( $entries as $ip => $entry ) {
			$raw = @inet_pton( (string) $ip );
			if ( false === $raw || 4 !== strlen( $raw ) ) {
				continue;
			}
			$rows[ $raw ] = $raw . chr( self::CHALLENGE === (int) $entry[0] ? self::CHALLENGE : self::BLOCK ) . pack( 'N', (int) $entry[1] );
		}
		ksort( $rows, SORT_STRING );
		return implode( '', $rows );
	}

	public static function find( $file, $ip, $now = null ) {
		$raw = @inet_pton( (string) $ip );
		if ( false === $raw || 4 !== strlen( $raw ) || '' === (string) $file || ! is_file( $file ) ) {
			return null;
		}
		$size  = (int) @filesize( $file );
		$count = intdiv( $size, self::SIZE );
		if ( $count < 1 ) {
			return null;
		}
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress loads; binary search in the shield's lookup file.
		if ( ! $handle ) {
			return null;
		}
		$low  = 0;
		$high = $count - 1;
		$hit  = null;
		while ( $low <= $high ) {
			$mid = ( $low + $high ) >> 1;
			fseek( $handle, $mid * self::SIZE );
			$row = (string) fread( $handle, self::SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reads one fixed-size row of the lookup file.
			if ( strlen( $row ) !== self::SIZE ) {
				break;
			}
			$cmp = strcmp( substr( $row, 0, 4 ), $raw );
			if ( 0 === $cmp ) {
				$until = unpack( 'N', substr( $row, 5, 4 ) );
				$hit   = array( ord( $row[4] ), (int) $until[1] );
				break;
			}
			if ( $cmp < 0 ) {
				$low = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen above.
		$now = null === $now ? time() : (int) $now;
		return $hit && $hit[1] > $now ? $hit : null;
	}
}
