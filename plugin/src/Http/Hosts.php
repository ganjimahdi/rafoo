<?php

namespace Rafoo\Http;

use Rafoo\Data;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Hosts {

	const SERVICE = array( 'rafoo.ir' );

	private static $own = null;

	private static $lists = null;

	public static function of( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( rtrim( $host, '.' ) ) : '';
	}

	public static function local( $host ) {
		if ( '' === $host || 'localhost' === $host || self::ends_with( $host, '.localhost' ) || self::ends_with( $host, '.local' ) || self::ends_with( $host, '.test' ) ) {
			return true;
		}
		$ip = trim( $host, '[]' );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return in_array( $host, self::own(), true );
	}

	public static function domestic( $host ) {
		if ( 'ir' === $host || self::ends_with( $host, '.ir' ) || in_array( $host, self::SERVICE, true ) ) {
			return true;
		}
		foreach ( array_merge( Settings::get( 'npm_mirrors' ), Settings::get( 'wp_mirrors' ) ) as $mirror ) {
			if ( self::of( $mirror ) === $host ) {
				return true;
			}
		}
		return self::listed( $host, 'domestic' );
	}

	public static function foreign( $host ) {
		return ! self::domestic( $host ) && self::listed( $host, 'foreign' );
	}

	public static function allowed( $host ) {
		$host = strtolower( (string) $host );
		if ( self::local( $host ) || self::domestic( $host ) ) {
			return true;
		}
		foreach ( Settings::get( 'allow_hosts' ) as $rule ) {
			if ( self::matches( $host, $rule ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'rafoo_allowed_host', false, $host );
	}

	public static function blocked( $url ) {
		$rules = Settings::get( 'block_hosts' );
		if ( ! $rules ) {
			return false;
		}
		$url  = (string) $url;
		$url  = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
		$host = self::of( $url );
		if ( '' === $host || self::local( $host ) ) {
			return false;
		}
		$plain = preg_replace( '#^https?://#i', '', strtolower( $url ) );
		foreach ( $rules as $rule ) {
			if ( false === strpos( $rule, '/' ) ) {
				if ( self::matches( $host, $rule ) ) {
					return true;
				}
				continue;
			}
			if ( 0 === strpos( $plain, preg_replace( '#^https?://#i', '', strtolower( $rule ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function matches( $host, $rule ) {
		if ( 0 === strpos( $rule, '*.' ) ) {
			$base = substr( $rule, 2 );
			return $host === $base || self::ends_with( $host, '.' . $base );
		}
		return $host === $rule;
	}

	public static function own() {
		if ( null === self::$own ) {
			$hosts = array( self::of( home_url() ), self::of( site_url() ) );
			if ( is_multisite() && function_exists( 'get_network' ) ) {
				$network = get_network();
				if ( $network ) {
					$hosts[] = strtolower( $network->domain );
				}
			}
			self::$own = array_values( array_filter( array_unique( $hosts ) ) );
		}
		return self::$own;
	}

	public static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		return $length > 0 && strlen( $haystack ) >= $length && substr( $haystack, -$length ) === $needle;
	}

	public static function rules( $group ) {
		if ( null === self::$lists ) {
			$lists       = Data::get( 'hosts' );
			self::$lists = array(
				'domestic' => (array) apply_filters( 'rafoo_domestic_hosts', isset( $lists['domestic'] ) ? $lists['domestic'] : array() ),
				'foreign'  => (array) apply_filters( 'rafoo_foreign_hosts', isset( $lists['foreign'] ) ? $lists['foreign'] : array() ),
			);
		}
		return isset( self::$lists[ $group ] ) ? array_values( array_map( 'strval', self::$lists[ $group ] ) ) : array();
	}

	private static function listed( $host, $group ) {
		foreach ( self::rules( $group ) as $rule ) {
			if ( self::matches( $host, (string) $rule ) ) {
				return true;
			}
		}
		return false;
	}
}
