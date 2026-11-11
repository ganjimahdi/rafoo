<?php

namespace Rafoo\Geo;

use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Store {

	const OPTION = 'rafoo_geo';

	const SECRETS = 'rafoo_geo_secrets';

	const STATE = 'rafoo_geo_state';

	const MAX_COPIES = 3;

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
			'role'    => '',
			'self'    => '',
			'host'    => '',
			'scheme'  => 'https',
			'nodes'   => array(),
			'join'    => null,
			'method'  => array(
				'type'     => '',
				'provider' => '',
				'panel'    => '',
				'ttl'      => 120,
			),
			'primary' => '',
			'key'     => '',
			'since'   => 0,
			'dynamic' => array(),
		);
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $changes ) {
		$all = array_merge( self::all(), $changes );
		update_option( self::OPTION, $all, true );
		self::$cache = $all;
		return $all;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function role() {
		return (string) self::get( 'role' );
	}

	public static function active() {
		return in_array( self::role(), array( 'primary', 'copy' ), true );
	}

	public static function is_copy() {
		return 'copy' === self::role();
	}

	public static function is_primary() {
		return 'primary' === self::role();
	}

	public static function nodes() {
		return (array) self::get( 'nodes' );
	}

	public static function node( $id ) {
		$nodes = self::nodes();
		return isset( $nodes[ $id ] ) ? $nodes[ $id ] : null;
	}

	public static function self_node() {
		return self::node( (string) self::get( 'self' ) );
	}

	public static function primary_node() {
		foreach ( self::nodes() as $node ) {
			if ( ! empty( $node['primary'] ) ) {
				return $node;
			}
		}
		return null;
	}

	public static function others() {
		$self = (string) self::get( 'self' );
		return array_filter(
			self::nodes(),
			static function ( $node ) use ( $self ) {
				return $node['id'] !== $self;
			}
		);
	}

	public static function put_node( array $node ) {
		$nodes                = self::nodes();
		$nodes[ $node['id'] ] = $node;
		self::update( array( 'nodes' => $nodes ) );
	}

	public static function drop_node( $id ) {
		$nodes = self::nodes();
		unset( $nodes[ $id ] );
		self::update( array( 'nodes' => $nodes ) );
	}

	public static function key() {
		$hex = Vault::open( (string) self::get( 'key' ) );
		return 64 === strlen( $hex ) ? hex2bin( $hex ) : '';
	}

	public static function seal_key( $raw ) {
		return Vault::seal( bin2hex( $raw ) );
	}

	public static function main_host() {
		$host = (string) self::get( 'host' );
		return '' !== $host ? $host : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function main_url( $path = '/' ) {
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		return self::get( 'scheme' ) . '://' . self::main_host() . ( $port && ! in_array( (int) $port, array( 80, 443 ), true ) && false === strpos( self::main_host(), ':' ) ? ':' . $port : '' ) . $path;
	}

	public static function node_host( array $node ) {
		return strtolower( (string) wp_parse_url( $node['url'], PHP_URL_HOST ) . ( wp_parse_url( $node['url'], PHP_URL_PORT ) ? ':' . wp_parse_url( $node['url'], PHP_URL_PORT ) : '' ) );
	}

	public static function secrets() {
		$raw  = Vault::open( (string) get_option( self::SECRETS, '' ) );
		$data = '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	public static function save_secrets( array $secrets ) {
		if ( ! $secrets ) {
			delete_option( self::SECRETS );
			return;
		}
		update_option( self::SECRETS, Vault::seal( wp_json_encode( $secrets ) ), false );
	}

	public static function state() {
		$state = get_option( self::STATE, array() );
		return array_merge(
			array(
				'health'  => array(),
				'sync'    => array(),
				'dns'     => array(),
				'contact' => 0,
				'reports' => array(),
				'crawl'   => array(),
				'checks'  => array(),
			),
			is_array( $state ) ? $state : array()
		);
	}

	public static function save_state( array $changes ) {
		$state = array_merge( self::state(), $changes );
		update_option( self::STATE, $state, false );
		return $state;
	}

	public static function reset() {
		foreach ( array( self::OPTION, self::SECRETS, self::STATE, Changes::OPTION ) as $name ) {
			delete_option( $name );
		}
		self::$cache = null;
	}

	public static function local_options() {
		return array( self::OPTION, self::SECRETS, self::STATE, Changes::OPTION, 'rafoo_private', 'rafoo_backups', 'rafoo_backup_destinations', 'rafoo_backup_secret', 'rafoo_transfer_invite', 'rafoo_transfer_outgoing', 'rafoo_state', 'rafoo_events', 'rafoo_peers', 'rafoo_shield_boot', 'rafoo_shield_stats', 'rafoo_shield_events', 'rafoo_shield_network', 'rafoo_shield_outbox', 'rafoo_shield_edge', 'rafoo_shield_day', 'cron', 'recently_activated', 'auto_updater.lock', 'core_updater.lock' );
	}
}
