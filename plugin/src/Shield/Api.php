<?php

namespace Rafoo\Shield;

use Rafoo\Admin\Rest;
use Rafoo\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Api {

	public static function routes( $ns ) {
		$can = array( Rest::class, 'can_manage' );
		$ip  = array(
			'ip' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[0-9a-fA-F:./]{3,64}$',
			),
		);
		self::route( $ns, '/shield', WP_REST_Server::READABLE, 'view', $can );
		self::route(
			$ns,
			'/shield/mode',
			WP_REST_Server::CREATABLE,
			'mode',
			$can,
			array(
				'mode' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => Modes::ALL,
				),
			)
		);
		self::route(
			$ns,
			'/shield/lists',
			WP_REST_Server::CREATABLE,
			'lists',
			$can,
			array(
				'allow'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 1000 ),
				'deny'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 1000 ),
				'proxies' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 100 ),
			)
		);
		self::route(
			$ns,
			'/shield/prepend',
			WP_REST_Server::CREATABLE,
			'prepend',
			$can,
			array(
				'enabled' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			)
		);
		self::route( $ns, '/shield/release', WP_REST_Server::CREATABLE, 'release', $can, $ip );
		self::route( $ns, '/shield/block', WP_REST_Server::CREATABLE, 'block', $can, $ip );
		self::route( $ns, '/shield/allow', WP_REST_Server::CREATABLE, 'allow', $can, $ip );
		self::route( $ns, '/shield/network/sync', WP_REST_Server::CREATABLE, 'sync', $can );
		self::route(
			$ns,
			'/shield/edge',
			WP_REST_Server::CREATABLE,
			'edge',
			$can,
			array(
				'provider' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'arvan', 'cloudflare' ),
				),
				'domain'   => array(
					'type'     => 'string',
					'required' => true,
				),
				'secret'   => array(
					'type'    => 'string',
					'default' => '',
				),
				'reuse'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		self::route( $ns, '/shield/edge/remove', WP_REST_Server::CREATABLE, 'edge_remove', $can );
		self::route( $ns, '/shield/edge/push', WP_REST_Server::CREATABLE, 'edge_push', $can );
	}

	private static function route( $ns, $path, $methods, $handler, $permission, array $args = array() ) {
		register_rest_route(
			$ns,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( __CLASS__, $handler ),
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}

	public static function view() {
		Stats::compact();
		$net     = Local::network();
		$plugins = defined( 'RAFOO_FILE' ) ? plugin_basename( RAFOO_FILE ) : '';
		$active  = (array) get_option( 'active_plugins', array() );
		return array(
			'card'     => self::card(),
			'stats'    => Stats::summary( 7 ),
			'events'   => Stats::events( 60 ),
			'allow'    => array_values( (array) Local::get( 'allow' ) ),
			'deny'     => array_values( (array) Local::get( 'deny' ) ),
			'proxies'  => array_values( (array) Local::get( 'proxies' ) ),
			'prepend'  => Prepend::view(),
			'first'    => isset( $active[0] ) && $active[0] === $plugins,
			'running'  => null !== Gate::request(),
			'apcu'     => Store::apcu(),
			'you'      => Shield::ip(),
			'network'  => array(
				'enabled' => (bool) Settings::get( 'shield_network' ),
				'joined'  => (int) $net['joined'],
				'count'   => (int) $net['count'],
				'sent'    => (int) $net['sent'],
				'checked' => (int) $net['checked'],
				'rev'     => (int) $net['rev'],
				'error'   => (string) $net['error'],
				'patches' => array_map(
					static function ( $patch ) {
						return array(
							'id'     => $patch['id'],
							'plugin' => $patch['plugin'],
							'title'  => $patch['title'],
						);
					},
					Intel::active_patches( (array) $net['patches'] )
				),
			),
			'edge'     => Edge::view(),
			'now'      => time(),
		);
	}

	public static function mode( WP_REST_Request $request ) {
		$changes = array( 'shield' => (string) $request['mode'] );
		if ( 'done' === Settings::get( 'setup' ) && '' !== Settings::get( 'preset' ) ) {
			$changes['preset'] = 'custom';
		}
		Rest::apply( $changes );
		return self::view();
	}

	public static function lists( WP_REST_Request $request ) {
		$params  = (array) $request->get_json_params();
		$changes = array();
		foreach ( array( 'allow' => 1000, 'deny' => 1000, 'proxies' => 100 ) as $key => $max ) {
			if ( array_key_exists( $key, $params ) ) {
				$changes[ $key ] = Local::cidrs( (array) $params[ $key ], $max );
			}
		}
		$you = Shield::ip();
		if ( isset( $changes['deny'] ) && '' !== $you && Ip::any( $you, $changes['deny'] ) ) {
			return new WP_Error( 'rafoo_shield_self', __( 'This list includes your own address. Remove it so you do not lock yourself out.', 'rafoo' ), array( 'status' => 400 ) );
		}
		Local::update( $changes );
		Config::compile();
		return self::view();
	}

	public static function prepend( WP_REST_Request $request ) {
		$result = $request['enabled'] ? Prepend::enable() : Prepend::disable();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		Config::compile();
		return self::view();
	}

	public static function release( WP_REST_Request $request ) {
		$ip = Ip::cidr( (string) $request['ip'] );
		if ( '' === $ip ) {
			return self::invalid();
		}
		if ( false === strpos( $ip, '/' ) ) {
			Shield::store();
			Store::drop( 'ip:' . Ip::key( $ip ) );
		}
		Local::update( array( 'deny' => array_values( array_diff( (array) Local::get( 'deny' ), array( $ip ) ) ) ) );
		Config::compile();
		return self::view();
	}

	public static function block( WP_REST_Request $request ) {
		$ip = Ip::cidr( (string) $request['ip'] );
		if ( '' === $ip ) {
			return self::invalid();
		}
		$you = Shield::ip();
		if ( '' !== $you && Ip::in( $you, $ip ) ) {
			return new WP_Error( 'rafoo_shield_self', __( 'This list includes your own address. Remove it so you do not lock yourself out.', 'rafoo' ), array( 'status' => 400 ) );
		}
		$deny   = (array) Local::get( 'deny' );
		$deny[] = $ip;
		Local::update(
			array(
				'deny'  => Local::cidrs( $deny, 1000 ),
				'allow' => array_values( array_diff( (array) Local::get( 'allow' ), array( $ip ) ) ),
			)
		);
		Config::compile();
		return self::view();
	}

	public static function allow( WP_REST_Request $request ) {
		$ip = Ip::cidr( (string) $request['ip'] );
		if ( '' === $ip ) {
			return self::invalid();
		}
		if ( false === strpos( $ip, '/' ) ) {
			Shield::store();
			Store::drop( 'ip:' . Ip::key( $ip ) );
		}
		$allow   = (array) Local::get( 'allow' );
		$allow[] = $ip;
		Local::update(
			array(
				'allow' => Local::cidrs( $allow, 1000 ),
				'deny'  => array_values( array_diff( (array) Local::get( 'deny' ), array( $ip ) ) ),
			)
		);
		Config::compile();
		return self::view();
	}

	public static function sync() {
		if ( ! Settings::get( 'shield_network' ) ) {
			return new WP_Error( 'rafoo_shield_network', __( 'Turn on community defense first.', 'rafoo' ), array( 'status' => 400 ) );
		}
		$result = Intel::joined() ? Intel::pull( false ) : Intel::join();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		Intel::report();
		return self::view();
	}

	public static function edge( WP_REST_Request $request ) {
		$result = Edge::connect( (string) $request['provider'], (string) $request['domain'], (string) $request['secret'], (bool) $request['reuse'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		return self::view();
	}

	public static function edge_remove() {
		Edge::disconnect();
		return self::view();
	}

	public static function edge_push() {
		Edge::push( true );
		return self::view();
	}

	private static function invalid() {
		return new WP_Error( 'rafoo_invalid', __( 'Enter a valid IP address or range, for example 203.0.113.5 or 203.0.113.0/24.', 'rafoo' ), array( 'status' => 400 ) );
	}

	public static function card() {
		$stats = Stats::summary( 7 );
		return array(
			'mode'    => Config::mode(),
			'setting' => (string) Settings::get( 'shield' ),
			'today'   => (int) $stats['stopped_today'],
			'week'    => (int) $stats['stopped_week'],
			'network' => (bool) Settings::get( 'shield_network' ) && Intel::joined(),
			'early'   => 'active' === Prepend::status(),
		);
	}
}
