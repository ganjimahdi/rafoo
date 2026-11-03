<?php

namespace Rafoo\Services;

use Rafoo\Assets\Rewriter;
use Rafoo\Data;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Analytics {

	const SCRIPT = '#<script\b([^>]*)>(.*?)</script\s*>#is';

	const NOSCRIPT = '#<noscript\b[^>]*>(.*?)</noscript\s*>#is';

	const LOADER_LIMIT = 8192;

	const STUBS = array( 'gtag', 'ga', 'fbq', '_fbq', 'hj', 'clarity', 'ym', 'ttq', 'twq', 'lintrk', 'pintrk', 'mixpanel' );

	private static $needles = null;

	public static function active() {
		return Settings::on( 'analytics' );
	}

	public static function needles() {
		if ( null === self::$needles ) {
			$lists         = Data::get( 'hosts' );
			$needles       = (array) apply_filters( 'rafoo_analytics_hosts', isset( $lists['analytics'] ) ? $lists['analytics'] : array() );
			self::$needles = array_values( array_filter( array_map( 'strval', $needles ) ) );
		}
		return self::$needles;
	}

	public static function mentions( $html ) {
		foreach ( self::needles() as $needle ) {
			if ( false !== stripos( $html, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function strip( $html ) {
		if ( ! is_string( $html ) || ! self::mentions( $html ) ) {
			return $html;
		}
		$removed = false;
		$out     = preg_replace_callback(
			self::SCRIPT,
			static function ( $m ) use ( &$removed ) {
				if ( ! self::tracking_script( $m[1], $m[2] ) ) {
					return $m[0];
				}
				$removed = true;
				return '';
			},
			$html
		);
		if ( null === $out ) {
			return $html;
		}
		$out = self::drop( self::NOSCRIPT, $out, array( __CLASS__, 'tracking_pixel' ) );
		$out = self::drop( Rewriter::HINT, $out, array( __CLASS__, 'mentions' ) );
		return $removed ? self::stub( $out ) : $out;
	}

	public static function tracking_script( $attributes, $body ) {
		if ( preg_match( '#\bsrc\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $attributes, $src ) ) {
			return self::mentions( $src[2] );
		}
		if ( preg_match( '#\btype\s*=\s*["\']?(?:application/(?:ld\+)?json|text/template|text/x-template)#i', $attributes ) ) {
			return false;
		}
		return strlen( $body ) <= self::LOADER_LIMIT && false !== stripos( $body, 'createElement' ) && self::mentions( $body );
	}

	public static function tracking_pixel( $html ) {
		return preg_match( '#<(?:img|iframe)\b#i', $html ) && self::mentions( $html );
	}

	private static function drop( $pattern, $html, $test ) {
		$out = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $test ) {
				return call_user_func( $test, $m[0] ) ? '' : $m[0];
			},
			$html
		);
		return null === $out ? $html : $out;
	}

	private static function stub( $html ) {
		if ( ! preg_match( '#<head\b[^>]*>#i', $html, $head, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$script = sprintf(
			'(function(w,n){var p=new Proxy(function(){},{get:function(t,k){return k==="then"||typeof k==="symbol"?undefined:p},apply:function(){}});n.forEach(function(k){if(!(k in w)){w[k]=p}});w.dataLayer=w.dataLayer||[];w._paq=w._paq||[]})(window,%s);',
			wp_json_encode( self::STUBS )
		);
		$at = $head[0][1] + strlen( $head[0][0] );
		return substr( $html, 0, $at ) . wp_get_inline_script_tag( $script, array( 'id' => 'rafoo-analytics-stub' ) ) . substr( $html, $at );
	}
}
