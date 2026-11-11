<?php

namespace Rafoo\Shield;

use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Local {

	const OPTION = 'rafoo_shield';

	const BOOT = 'rafoo_shield_boot';

	const STATS = 'rafoo_shield_stats';

	const EVENTS = 'rafoo_shield_events';

	const NETWORK = 'rafoo_shield_network';

	const EDGE = 'rafoo_shield_edge';

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			$data        = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $data ) ? $data : array() );
		}
		return self::$cache;
	}

	public static function defaults() {
		return array(
			'secret'   => '',
			'allow'    => array(),
			'deny'     => array(),
			'proxies'  => array(),
			'prepend'  => false,
			'admins'   => array(),
			'released' => array(),
		);
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $changes ) {
		self::$cache = array_merge( self::all(), $changes );
		update_option( self::OPTION, self::$cache, false );
		return self::$cache;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function secret() {
		$sealed = (string) self::get( 'secret' );
		$plain  = '' !== $sealed ? Vault::open( $sealed ) : '';
		if ( 64 === strlen( $plain ) && ctype_xdigit( $plain ) ) {
			return $plain;
		}
		$plain = bin2hex( random_bytes( 32 ) );
		self::update( array( 'secret' => Vault::seal( $plain ) ) );
		return $plain;
	}

	public static function cidrs( $value, $max = 500 ) {
		$lines = is_string( $value ) ? preg_split( '/[\s,]+/', $value ) : (array) $value;
		$out   = array();
		foreach ( $lines as $line ) {
			$cidr = Ip::cidr( (string) $line );
			if ( '' !== $cidr ) {
				$out[] = $cidr;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $max );
	}

	public static function remember_admin( $ip ) {
		if ( ! Ip::valid( $ip ) ) {
			return;
		}
		$admins = (array) self::get( 'admins' );
		$now    = time();
		if ( isset( $admins[ $ip ] ) && $now - (int) $admins[ $ip ] < 3600 ) {
			return;
		}
		$admins[ $ip ] = $now;
		foreach ( $admins as $known => $at ) {
			if ( $now - (int) $at > 30 * DAY_IN_SECONDS ) {
				unset( $admins[ $known ] );
			}
		}
		arsort( $admins );
		self::update( array( 'admins' => array_slice( $admins, 0, 20, true ) ) );
		Config::compile();
	}

	public static function network() {
		$data = get_option( self::NETWORK, array() );
		return array_merge(
			array(
				'joined'   => 0,
				'rev'      => 0,
				'checked'  => 0,
				'reported' => 0,
				'sent'     => 0,
				'count'    => 0,
				'ipv6'     => array(),
				'patches'  => array(),
				'error'    => '',
				'remote'   => 0,
			),
			is_array( $data ) ? $data : array()
		);
	}

	public static function save_network( array $changes ) {
		$data = array_merge( self::network(), $changes );
		update_option( self::NETWORK, $data, false );
		return $data;
	}
}
