<?php

namespace Rafoo\Drill;

defined( 'ABSPATH' ) || exit;

final class Beacon {

	const RESOURCES = 150;

	const BLOCKED = 60;

	const ERRORS = 20;

	const BROKEN = 30;

	const INITIATORS = array( 'script', 'link', 'css', 'img', 'image', 'iframe', 'fetch', 'xmlhttprequest', 'beacon', 'video', 'audio', 'track', 'object', 'embed', 'font', 'other', 'navigation', 'input', 'use', 'icon' );

	public static function clean( array $data ) {
		$out = array(
			'u' => self::url( isset( $data['u'] ) ? $data['u'] : '' ),
			's' => isset( $data['s'] ) ? max( 0, min( 999, (int) $data['s'] ) ) : 0,
			'l' => isset( $data['l'] ) ? max( 0, min( 600000, (int) $data['l'] ) ) : 0,
			'r' => array(),
			'x' => array(),
			'e' => array(),
			'g' => array(),
		);
		foreach ( self::items( $data, 'r', self::RESOURCES ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = self::url( isset( $item['u'] ) ? $item['u'] : '' );
			if ( '' === $url ) {
				continue;
			}
			$initiator = isset( $item['i'] ) ? strtolower( (string) $item['i'] ) : 'other';
			$out['r'][] = array(
				'u' => $url,
				'b' => empty( $item['b'] ) ? 0 : 1,
				'i' => in_array( $initiator, self::INITIATORS, true ) ? $initiator : 'other',
				'd' => isset( $item['d'] ) ? max( 0, min( 600000, (int) $item['d'] ) ) : 0,
			);
		}
		foreach ( self::items( $data, 'x', self::BLOCKED ) as $item ) {
			$url = self::url( $item );
			if ( '' === $url && is_string( $item ) && preg_match( '/^[a-z0-9.\-]{1,253}$/i', $item ) ) {
				$url = 'https://' . strtolower( $item ) . '/';
			}
			if ( '' !== $url ) {
				$out['x'][] = $url;
			}
		}
		foreach ( self::items( $data, 'e', self::ERRORS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$message = isset( $item['m'] ) ? self::text( $item['m'], 300 ) : '';
			if ( '' === $message ) {
				continue;
			}
			$out['e'][] = array(
				'm' => $message,
				'f' => self::url( isset( $item['f'] ) ? $item['f'] : '' ),
				'l' => isset( $item['l'] ) ? max( 0, (int) $item['l'] ) : 0,
			);
		}
		foreach ( self::items( $data, 'g', self::BROKEN ) as $item ) {
			$url = self::url( $item );
			if ( '' !== $url ) {
				$out['g'][] = $url;
			}
		}
		$out['x'] = array_values( array_unique( $out['x'] ) );
		$out['g'] = array_values( array_unique( $out['g'] ) );
		return $out;
	}

	public static function url( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$value = substr( trim( $value ), 0, 600 );
		if ( 0 === strpos( $value, '//' ) ) {
			$value = 'https:' . $value;
		}
		if ( ! preg_match( '#^(https?|wss?)://[^\s"\'<>]+$#i', $value ) ) {
			return '';
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! preg_match( '/^[a-z0-9.\-\[\]:]{1,253}$/i', $host ) ) {
			return '';
		}
		return $value;
	}

	public static function text( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( trim( (string) $text ), 0, $max ) : substr( trim( (string) $text ), 0, $max );
	}

	private static function items( array $data, $key, $max ) {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? array_slice( array_values( $data[ $key ] ), 0, $max ) : array();
	}
}
