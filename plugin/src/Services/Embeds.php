<?php

namespace Rafoo\Services;

use Rafoo\Drill\Session;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Embeds {

	const NEEDLES = array( 'youtube.com', 'youtube-nocookie.com', 'youtu.be', 'vimeo.com', 'google.com/maps', 'maps.google.', 'google.com/maps/embed' );

	const IFRAME = '#<iframe\b([^>]*)>.*?</iframe>#is';

	public static function enabled() {
		return (bool) Settings::get( 'embeds' );
	}

	public static function content( $html ) {
		if ( ! is_string( $html ) || ! self::enabled() || ! self::mentions( $html ) || ! State::offline() ) {
			return $html;
		}
		return self::replace( $html );
	}

	public static function mentions( $html ) {
		if ( false === stripos( $html, '<iframe' ) ) {
			return false;
		}
		foreach ( self::NEEDLES as $needle ) {
			if ( false !== stripos( $html, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function replace( $html ) {
		$out = preg_replace_callback( self::IFRAME, array( __CLASS__, 'placeholder' ), $html );
		return null === $out ? $html : $out;
	}

	public static function placeholder( $m ) {
		$attributes = $m[1];
		$src        = '';
		foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $name ) {
			if ( preg_match( '#\b' . $name . '\s*=\s*(["\'])([^"\']+)\1#i', $attributes, $found ) && false !== strpos( $found[2], '//' ) ) {
				$src = html_entity_decode( $found[2], ENT_QUOTES, 'UTF-8' );
				break;
			}
		}
		$link = self::original( $src );
		if ( '' === $link ) {
			return $m[0];
		}
		Session::asset( 'embed', $link );

		$width  = preg_match( '#\bwidth\s*=\s*["\']?(\d{2,4})#i', $attributes, $w ) ? (int) $w[1] : 0;
		$height = preg_match( '#\bheight\s*=\s*["\']?(\d{2,4})#i', $attributes, $h ) ? (int) $h[1] : 0;
		$ratio  = $width > 0 && $height > 0 ? $width . '/' . $height : '16/9';
		$style  = 'box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5em;width:100%;'
			. ( $width > 0 ? 'max-width:' . $width . 'px;' : '' )
			. 'aspect-ratio:' . $ratio . ';padding:1em;border:1px solid currentColor;border-radius:8px;text-align:center;font-size:.95em;line-height:1.6;opacity:.85';

		return sprintf(
			'<div class="rafoo-embed" style="%1$s"><p style="margin:0">%2$s</p><a href="%3$s" target="_blank" rel="noopener nofollow">%4$s</a></div>',
			esc_attr( $style ),
			esc_html__( 'This content is not available while the international internet is down.', 'rafoo' ),
			esc_url( $link ),
			esc_html__( 'Open the original link', 'rafoo' )
		);
	}

	public static function original( $src ) {
		if ( '' === $src ) {
			return '';
		}
		$url   = 0 === strpos( $src, '//' ) ? 'https:' . $src : $src;
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com', 'm.youtube.com' ), true ) ) {
			return preg_match( '#^/embed/([A-Za-z0-9_\-]{6,})#', $path, $m ) ? 'https://www.youtube.com/watch?v=' . $m[1] : 'https://www.youtube.com' . $path;
		}
		if ( 'player.vimeo.com' === $host ) {
			return preg_match( '#^/video/(\d+)#', $path, $m ) ? 'https://vimeo.com/' . $m[1] : 'https://vimeo.com/';
		}
		if ( ( 'google.com' === $host || 'maps.google.com' === $host ) && false !== strpos( $path, '/maps' ) ) {
			return $url;
		}
		return '';
	}
}
