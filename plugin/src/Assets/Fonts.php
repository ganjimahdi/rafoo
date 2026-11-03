<?php

namespace Rafoo\Assets;

use Rafoo\Http\Client;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Fonts {

	const HOST = 'fonts.googleapis.com';

	const FILES = 'fonts.gstatic.com';

	const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

	const MAX_CSS = 1048576;

	public static function enabled() {
		return (bool) Settings::get( 'fonts' );
	}

	public static function parse( $url ) {
		if ( ! is_string( $url ) || false === strpos( $url, self::HOST ) ) {
			return null;
		}
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || self::HOST !== strtolower( $parts['host'] ) || empty( $parts['path'] ) || empty( $parts['query'] ) ) {
			return null;
		}
		if ( ! in_array( $parts['path'], array( '/css', '/css2', '/icon' ), true ) ) {
			return null;
		}
		$query = array();
		foreach ( explode( '&', $parts['query'] ) as $pair ) {
			if ( '' === $pair || 0 === strpos( $pair, 'ver=' ) ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9+:;,@=._%|\-]+$/', $pair ) ) {
				return null;
			}
			$query[] = $pair;
		}
		if ( ! $query ) {
			return null;
		}
		$query = implode( '&', $query );
		$key   = self::HOST . $parts['path'] . '?' . $query;
		return array(
			'key'    => $key,
			'origin' => 'https://' . $key,
			'mirror' => '',
			'local'  => 'fonts/css/' . substr( md5( $key ), 0, 16 ) . '.css',
			'type'   => 'css',
			'cdn'    => 'google-fonts',
			'pkg'    => '',
			'ver'    => '',
		);
	}

	public static function local_url( $key ) {
		$map = Library::map();
		return self::enabled() && ! empty( $map[ $key ]['p'] ) ? Store::url( $map[ $key ]['p'] ) : '';
	}

	public static function localize( array $record, $deadline ) {
		$css = Client::body(
			$record['origin'],
			array(
				'timeout'    => 10,
				'user-agent' => self::BROWSER,
			),
			self::MAX_CSS
		);
		if ( ! is_string( $css ) || false === strpos( $css, '@font-face' ) ) {
			return Sync::failed( $record, is_wp_error( $css ) ? $css->get_error_code() : 'not_a_font_stylesheet' );
		}

		$deps     = array();
		$complete = true;
		$css      = preg_replace_callback(
			'#url\(\s*([\'"]?)(https://fonts\.gstatic\.com(/s/[a-z0-9_\-]+/v\d+/[A-Za-z0-9_\-.]+\.(?:woff2|woff|ttf|otf)))\1\s*\)#i',
			static function ( $m ) use ( &$deps, &$complete, $deadline ) {
				$local = 'fonts' . $m[3];
				if ( ! Store::exists( $local ) ) {
					if ( microtime( true ) >= $deadline ) {
						$complete = false;
						return $m[0];
					}
					$bytes = Client::body( $m[2], array( 'timeout' => 10 ), Store::MAX_BYTES );
					if ( ! is_string( $bytes ) || ! Store::write( $local, $bytes ) ) {
						return $m[0];
					}
				}
				$deps[] = $local;
				return 'url(..' . $m[3] . ')';
			},
			$css
		);

		if ( ! $complete ) {
			$record['status']  = 'pending';
			$record['next_at'] = 0;
			return $record;
		}
		if ( ! $deps ) {
			return Sync::failed( $record, 'font_files_unavailable' );
		}
		if ( false === strpos( $record['origin'], 'display=' ) ) {
			$css = preg_replace( '/@font-face\s*\{(?![^}]*font-display)/i', "@font-face {\n  font-display: swap;", $css );
		}
		if ( ! Store::write( $record['local'], $css ) ) {
			return Sync::failed( $record, 'write_failed' );
		}

		$record['deps']     = array_values( array_unique( $deps ) );
		$record['status']   = 'ready';
		$record['verified'] = 'origin';
		$record['source']   = 'origin';
		$record['sha256']   = hash( 'sha256', $css );
		$record['size']     = strlen( $css );
		$record['attempts'] = 0;
		$record['error']    = '';
		$record['next_at']  = 0;
		return $record;
	}

}
