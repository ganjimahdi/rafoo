<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Patches {

	const WHERE = array( 'path', 'query', 'body', 'any', 'method', 'cookie', 'header', 'action', 'route' );

	const OPS = array( 'exists', 'equals', 'contains', 'regex', 'missing' );

	public static function check( array $patches, Request $r, array $get, array $post ) {
		foreach ( $patches as $patch ) {
			if ( ! is_array( $patch ) || empty( $patch['when'] ) || ! is_array( $patch['when'] ) ) {
				continue;
			}
			$all = true;
			foreach ( $patch['when'] as $cond ) {
				if ( ! self::holds( (array) $cond, $r, $get, $post ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				$hit           = Rules::hit( 'v_' . ( isset( $patch['id'] ) ? preg_replace( '/[^a-z0-9_]/', '', (string) $patch['id'] ) : 'patch' ), 'patch', 100, isset( $patch['plugin'] ) ? (string) $patch['plugin'] : '' );
				$hit['plugin'] = isset( $patch['plugin'] ) ? (string) $patch['plugin'] : '';
				return $hit;
			}
		}
		return null;
	}

	public static function valid( array $cond ) {
		if ( ! isset( $cond['in'], $cond['op'] ) || ! in_array( $cond['in'], self::WHERE, true ) || ! in_array( $cond['op'], self::OPS, true ) ) {
			return false;
		}
		if ( 'regex' === $cond['op'] ) {
			$value = isset( $cond['value'] ) ? (string) $cond['value'] : '';
			return '' !== $value && strlen( $value ) <= 500 && false !== @preg_match( '~' . str_replace( '~', '\~', $value ) . '~i', '' );
		}
		return true;
	}

	private static function holds( array $cond, Request $r, array $get, array $post ) {
		if ( ! self::valid( $cond ) ) {
			return false;
		}
		$key    = isset( $cond['key'] ) ? (string) $cond['key'] : '';
		$values = self::values( $cond['in'], $key, $r, $get, $post );
		if ( 'missing' === $cond['op'] ) {
			return ! $values;
		}
		if ( 'exists' === $cond['op'] ) {
			return (bool) $values;
		}
		$needle = isset( $cond['value'] ) ? (string) $cond['value'] : '';
		foreach ( $values as $value ) {
			$value = (string) $value;
			if ( 'equals' === $cond['op'] && 0 === strcasecmp( $value, $needle ) ) {
				return true;
			}
			if ( 'contains' === $cond['op'] && '' !== $needle && false !== stripos( Request::normalize( $value ), strtolower( $needle ) ) ) {
				return true;
			}
			if ( 'regex' === $cond['op'] && @preg_match( '~' . str_replace( '~', '\~', $needle ) . '~i', Request::normalize( $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function values( $in, $key, Request $r, array $get, array $post ) {
		switch ( $in ) {
			case 'path':
				return array( $r->path );
			case 'method':
				return array( $r->method );
			case 'route':
				return '' !== $r->rest_route() ? array( $r->rest_route() ) : array();
			case 'action':
				$action = isset( $post['action'] ) ? $post['action'] : ( isset( $get['action'] ) ? $get['action'] : null );
				return is_string( $action ) ? array( $action ) : array();
			case 'header':
				$value = $r->header( $key );
				return '' !== $value ? array( $value ) : array();
			case 'cookie':
				$value = $r->cookie( $key );
				return '' !== $value ? array( $value ) : array();
			case 'query':
				return self::pick( $get, $key );
			case 'body':
				return self::pick( $post, $key );
			default:
				return array_merge( self::pick( $get, $key ), self::pick( $post, $key ) );
		}
	}

	private static function pick( array $data, $key ) {
		if ( '' === $key ) {
			$out = array();
			array_walk_recursive(
				$data,
				static function ( $value ) use ( &$out ) {
					if ( is_scalar( $value ) && count( $out ) < 300 ) {
						$out[] = (string) $value;
					}
				}
			);
			return $out;
		}
		if ( ! array_key_exists( $key, $data ) ) {
			return array();
		}
		$value = $data[ $key ];
		if ( is_array( $value ) ) {
			return self::pick( $value, '' ) ? self::pick( $value, '' ) : array( '' );
		}
		return array( (string) $value );
	}
}
