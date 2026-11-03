<?php

namespace Rafoo\Assets;

defined( 'ABSPATH' ) || exit;

final class CssLinks {

	const LIMIT = 150;

	public static function references( $css ) {
		$found = array();
		if ( preg_match_all( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $m ) ) {
			$found = array_merge( $found, $m[2] );
		}
		if ( preg_match_all( '/@import\s+([\'"])([^\'"]+)\1/i', $css, $m ) ) {
			$found = array_merge( $found, $m[2] );
		}
		$out = array();
		foreach ( $found as $reference ) {
			$reference = trim( $reference );
			if ( '' === $reference || preg_match( '#^(data:|https?:|//|\#|/|about:)#i', $reference ) ) {
				continue;
			}
			$out[] = $reference;
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::LIMIT );
	}

	public static function within( $path, $root ) {
		return '' !== $path && '' !== $root && 0 === strpos( $path, $root );
	}

	public static function root( array $record ) {
		$local = $record['local'];
		if ( preg_match( '#^(npm/(?:@[^/]+/)?[^/@]+@[^/]+/)#', $local, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#^((?:cdnjs|google)/[^/]+/[^/]+/)#', $local, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
