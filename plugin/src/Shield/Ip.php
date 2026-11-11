<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Ip {

	const CLOUDFLARE = array( '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' );

	const ARVAN = array( '185.143.232.0/22', '188.229.116.16/30', '94.101.182.0/27', '2.144.3.128/28', '37.32.16.0/27', '37.32.17.0/27', '37.32.18.0/27', '37.32.19.0/27', '185.215.232.0/22', '178.131.120.48/28', '94.101.183.0/28', '78.157.36.112/28', '95.38.61.80/28', '193.24.119.0/29' );

	public static function valid( $ip ) {
		return is_string( $ip ) && '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	public static function local( $ip ) {
		return self::valid( $ip ) && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	public static function cidr( $value ) {
		$value = trim( (string) $value );
		$parts = explode( '/', $value, 2 );
		if ( ! self::valid( $parts[0] ) ) {
			return '';
		}
		$max = false === strpos( $parts[0], ':' ) ? 32 : 128;
		if ( ! isset( $parts[1] ) ) {
			return $parts[0];
		}
		if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max || (int) $parts[1] < ( 32 === $max ? 8 : 16 ) ) {
			return '';
		}
		return (int) $parts[1] === $max ? $parts[0] : $parts[0] . '/' . (int) $parts[1];
	}

	public static function in( $ip, $cidr ) {
		$parts = explode( '/', (string) $cidr, 2 );
		$addr  = @inet_pton( (string) $ip );
		$net   = @inet_pton( $parts[0] );
		if ( false === $addr || false === $net || strlen( $addr ) !== strlen( $net ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $addr ) * 8;
		$full = intdiv( $bits, 8 );
		if ( 0 !== strncmp( $addr, $net, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = 0xff << ( 8 - $rest ) & 0xff;
		return ( ord( $addr[ $full ] ) & $mask ) === ( ord( $net[ $full ] ) & $mask );
	}

	public static function any( $ip, array $list ) {
		foreach ( $list as $cidr ) {
			if ( self::in( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function key( $ip ) {
		if ( false === strpos( (string) $ip, ':' ) ) {
			return (string) $ip;
		}
		$raw = @inet_pton( (string) $ip );
		return false === $raw ? (string) $ip : bin2hex( substr( $raw, 0, 8 ) ) . '::/64';
	}

	public static function resolve( array $server, array $config ) {
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		if ( ! self::valid( $remote ) ) {
			return '';
		}
		$relayed = self::relayed( $server, $config );
		if ( '' !== $relayed ) {
			return $relayed;
		}
		if ( isset( $server['HTTP_CF_CONNECTING_IP'] ) && self::valid( trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) ) && self::any( $remote, self::CLOUDFLARE ) ) {
			return trim( (string) $server['HTTP_CF_CONNECTING_IP'] );
		}
		if ( isset( $server['HTTP_AR_REAL_IP'] ) && self::valid( trim( (string) $server['HTTP_AR_REAL_IP'] ) ) && self::any( $remote, self::ARVAN ) ) {
			return trim( (string) $server['HTTP_AR_REAL_IP'] );
		}
		$proxies = isset( $config['proxies'] ) ? (array) $config['proxies'] : array();
		if ( self::local( $remote ) || ( $proxies && self::any( $remote, $proxies ) ) ) {
			$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? array_reverse( array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) ) : array();
			foreach ( $forwarded as $hop ) {
				if ( self::valid( $hop ) && ! self::local( $hop ) && ! ( $proxies && self::any( $hop, $proxies ) ) ) {
					return $hop;
				}
			}
			if ( isset( $server['HTTP_X_REAL_IP'] ) && self::valid( trim( (string) $server['HTTP_X_REAL_IP'] ) ) ) {
				return trim( (string) $server['HTTP_X_REAL_IP'] );
			}
		}
		return $remote;
	}

	private static function relayed( array $server, array $config ) {
		if ( empty( $config['geo'] ) || ! isset( $server['HTTP_X_RAFOO_RELAY'], $server['HTTP_X_RAFOO_RELAY_SIGN'] ) ) {
			return '';
		}
		$key = hex2bin( (string) $config['geo'] );
		if ( false === $key || 32 !== strlen( $key ) ) {
			return '';
		}
		$value  = (string) $server['HTTP_X_RAFOO_RELAY'];
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET';
		$uri    = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		if ( ! hash_equals( hash_hmac( 'sha256', $value . '|' . $method . '|' . $uri, $key ), (string) $server['HTTP_X_RAFOO_RELAY_SIGN'] ) ) {
			return '';
		}
		$data = json_decode( (string) base64_decode( $value, true ), true );
		if ( ! is_array( $data ) || ! isset( $data['i'], $data['t'] ) || abs( time() - (int) $data['t'] ) > 300 ) {
			return '';
		}
		return self::valid( (string) $data['i'] ) ? (string) $data['i'] : '';
	}
}
