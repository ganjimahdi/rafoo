<?php

namespace Rafoo\Geo;

use Rafoo\Admin\Rest;
use Rafoo\Backup\Job;
use Rafoo\Backup\Ledger;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Api {

	const FIELDS = array(
		'arvan'      => array( 'key' ),
		'cloudflare' => array( 'token' ),
		'route53'    => array( 'access', 'secret' ),
		'gcore'      => array( 'token' ),
		'bunny'      => array( 'key' ),
	);

	public static function routes( $namespace ) {
		$can    = array( Rest::class, 'can_manage_network' );
		$text   = array( 'type' => 'string' );
		$id     = array(
			'id' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[a-z0-9]{1,20}$',
			),
		);
		$routes = array(
			array( '/geo', WP_REST_Server::READABLE, 'status', array() ),
			array(
				'/geo/primary',
				WP_REST_Server::CREATABLE,
				'primary',
				array(
					'url'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'label'   => $text,
					'country' => $text,
				),
			),
			array( '/geo/join', WP_REST_Server::CREATABLE, 'join', array( 'url' => $text ) ),
			array( '/geo/join/cancel', WP_REST_Server::CREATABLE, 'cancel_join', array() ),
			array(
				'/geo/add',
				WP_REST_Server::CREATABLE,
				'add',
				array(
					'code'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'label'   => $text,
					'country' => $text,
					'ip'      => $text,
				),
			),
			array(
				'/geo/node',
				WP_REST_Server::CREATABLE,
				'node',
				$id + array(
					'label'   => $text,
					'country' => $text,
					'ip'      => $text,
				),
			),
			array( '/geo/remove', WP_REST_Server::CREATABLE, 'remove', $id ),
			array( '/geo/leave', WP_REST_Server::CREATABLE, 'leave', array() ),
			array(
				'/geo/method',
				WP_REST_Server::CREATABLE,
				'method',
				array(
					'type'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( '', 'provider', 'panel', 'manual' ),
					),
					'provider' => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Planner::PROVIDERS ),
					),
					'panel'    => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Planner::PANELS ),
					),
					'ttl'      => array( 'type' => 'integer' ),
					'secrets'  => array( 'type' => 'object' ),
				),
			),
			array( '/geo/test', WP_REST_Server::CREATABLE, 'test', array() ),
			array( '/geo/apply', WP_REST_Server::CREATABLE, 'apply', array() ),
			array( '/geo/verify', WP_REST_Server::CREATABLE, 'verify', array() ),
			array( '/geo/checks', WP_REST_Server::CREATABLE, 'checks', array() ),
			array( '/geo/sync', WP_REST_Server::CREATABLE, 'sync', array() ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				$namespace,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( __CLASS__, $route[2] ),
					'permission_callback' => $can,
					'args'                => $route[3],
				)
			);
		}
	}

	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array_merge( array( 'status' => 400 ), $data ) );
		}
		return self::status();
	}

	public static function status() {
		global $wpdb;
		$all     = Store::all();
		$state   = Store::state();
		$health  = Store::active() ? Health::view() : array();
		$nodes   = array();
		$plan    = Store::active() ? Planner::plan() : null;
		$secrets = Store::secrets();
		foreach ( Store::nodes() as $id => $node ) {
			$nodes[] = array(
				'id'      => $id,
				'label'   => (string) $node['label'],
				'url'     => (string) $node['url'],
				'ip'      => (string) $node['ip'],
				'country' => (string) $node['country'],
				'primary' => ! empty( $node['primary'] ),
				'status'  => (string) $node['status'],
				'error'   => isset( $node['error'] ) ? (string) $node['error'] : '',
				'self'    => $id === $all['self'],
				'added'   => (int) $node['added'],
				'health'  => isset( $health[ $id ] ) ? $health[ $id ] : null,
				'routes'  => $plan ? Planner::routed( $plan, $id ) : null,
				'panel'   => self::panel_view( $secrets, $id ),
			);
		}
		$join = $all['join'];
		return array(
			'role'      => (string) $all['role'],
			'host'      => Store::main_host(),
			'home'      => home_url(),
			'nodes'     => $nodes,
			'method'    => $all['method'],
			'secrets'   => self::provider_view( $secrets ),
			'dns'       => (array) $state['dns'],
			'checks'    => (array) $state['checks'],
			'verify'    => isset( $state['verify'] ) ? (array) $state['verify'] : null,
			'sync'      => Store::is_copy() ? Syncer::view() : null,
			'gen'       => Store::is_primary() ? Changes::generation() : 0,
			'list'      => Store::is_primary() ? array_intersect_key( Lister::meta(), array_flip( array( 'at', 'files', 'bytes' ) ) ) : null,
			'join'      => 'joining' === $all['role'] && is_array( $join ) ? array(
				'url'     => (string) $join['url'],
				'expires' => (int) $join['expires'],
			) : null,
			'job'       => Job::view(),
			'multisite' => is_multisite(),
			'prefix'    => $wpdb->base_prefix,
			'config'    => Config::writable(),
			'suggest'   => self::suggest(),
			'relayed'   => isset( $state['relayed'] ) ? (array) $state['relayed'] : null,
			'max'       => Store::MAX_COPIES,
			'disk'      => Ledger::free_space(),
			'countries' => array_keys( Countries::all() ),
		);
	}

	private static function suggest() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$base = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		return array(
			'primary' => wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://ir.' . $base,
			'copy'    => wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . $host,
		);
	}

	private static function provider_view( array $secrets ) {
		$out = array();
		foreach ( self::FIELDS as $provider => $fields ) {
			foreach ( $fields as $field ) {
				$out[ $provider ][ $field ] = ! empty( $secrets[ $provider ][ $field ] );
			}
		}
		return $out;
	}

	private static function panel_view( array $secrets, $id ) {
		$out = array();
		foreach ( Planner::PANELS as $panel ) {
			$creds         = isset( $secrets[ $panel ][ $id ] ) ? (array) $secrets[ $panel ][ $id ] : array();
			$out[ $panel ] = array(
				'url'   => isset( $creds['url'] ) ? (string) $creds['url'] : '',
				'user'  => isset( $creds['user'] ) ? (string) $creds['user'] : '',
				'token' => ! empty( $creds['token'] ),
			);
		}
		return $out;
	}

	public static function primary( WP_REST_Request $request ) {
		return self::answer( Nodes::setup_primary( (string) $request['url'], (string) $request['label'], (string) $request['country'] ) );
	}

	public static function join( WP_REST_Request $request ) {
		$result = Nodes::join_code( (string) $request['url'] );
		if ( is_wp_error( $result ) ) {
			return self::answer( $result );
		}
		return array_merge( self::status(), array( 'code' => $result['code'] ) );
	}

	public static function cancel_join() {
		Nodes::cancel_join();
		return self::status();
	}

	public static function add( WP_REST_Request $request ) {
		return self::answer( Nodes::add( (string) $request['code'], (string) $request['label'], (string) $request['country'], (string) $request['ip'] ) );
	}

	public static function node( WP_REST_Request $request ) {
		$changes = array();
		foreach ( array( 'label', 'country', 'ip' ) as $field ) {
			if ( null !== $request[ $field ] ) {
				$changes[ $field ] = (string) $request[ $field ];
			}
		}
		return self::answer( Nodes::update_node( (string) $request['id'], $changes ) );
	}

	public static function remove( WP_REST_Request $request ) {
		return self::answer( Nodes::remove( (string) $request['id'] ) );
	}

	public static function leave() {
		Nodes::dissolve();
		return self::status();
	}

	public static function method( WP_REST_Request $request ) {
		if ( ! Store::is_primary() ) {
			return self::answer( new \WP_Error( 'rafoo_geo_primary', __( 'First set up this site as the main host.', 'rafoo' ) ) );
		}
		$type   = (string) $request['type'];
		$method = array(
			'type'     => $type,
			'provider' => 'provider' === $type ? (string) $request['provider'] : '',
			'panel'    => 'panel' === $type ? (string) $request['panel'] : '',
			'ttl'      => max( 60, min( 3600, (int) ( null !== $request['ttl'] ? $request['ttl'] : 120 ) ) ),
		);
		if ( 'provider' === $type && '' === $method['provider'] ) {
			return self::answer( new \WP_Error( 'rafoo_geo_method', __( 'Choose a DNS service.', 'rafoo' ) ) );
		}
		if ( 'panel' === $type && '' === $method['panel'] ) {
			return self::answer( new \WP_Error( 'rafoo_geo_method', __( 'Choose the control panel of your hosts.', 'rafoo' ) ) );
		}
		$secrets = Store::secrets();
		$given   = is_array( $request['secrets'] ) ? $request['secrets'] : array();
		if ( 'provider' === $type ) {
			$provider = $method['provider'];
			foreach ( self::FIELDS[ $provider ] as $field ) {
				$value = isset( $given[ $field ] ) ? trim( (string) $given[ $field ] ) : '';
				if ( '' !== $value ) {
					$secrets[ $provider ][ $field ] = $value;
				}
			}
		}
		if ( 'panel' === $type ) {
			$panel = $method['panel'];
			foreach ( Store::nodes() as $id => $node ) {
				if ( ! isset( $given[ $id ] ) || ! is_array( $given[ $id ] ) ) {
					continue;
				}
				$current = isset( $secrets[ $panel ][ $id ] ) ? (array) $secrets[ $panel ][ $id ] : array();
				foreach ( array( 'url', 'user', 'token' ) as $field ) {
					$value = isset( $given[ $id ][ $field ] ) ? trim( (string) $given[ $id ][ $field ] ) : '';
					if ( '' !== $value ) {
						$current[ $field ] = 'url' === $field ? esc_url_raw( preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value ) : sanitize_text_field( $value );
					}
				}
				$secrets[ $panel ][ $id ] = $current;
			}
		}
		Store::save_secrets( $secrets );
		Store::update( array( 'method' => $method ) );
		Nodes::broadcast();
		Planner::changed();
		return self::status();
	}

	public static function test() {
		$driver = Planner::driver();
		if ( ! $driver ) {
			return array_merge( self::status(), array( 'test' => null ) );
		}
		$method = Store::get( 'method' );
		$host   = Store::main_host();
		$out    = array();
		if ( 'panel' === $method['type'] ) {
			$secrets = Store::secrets();
			foreach ( Store::nodes() as $id => $node ) {
				$creds = isset( $secrets[ $method['panel'] ][ $id ] ) ? (array) $secrets[ $method['panel'] ][ $id ] : array();
				if ( ! $creds ) {
					continue;
				}
				$result     = $driver->check( $host, $creds );
				$out[ $id ] = is_wp_error( $result ) ? array( 'ok' => false, 'error' => $result->get_error_message() ) : array(
					'ok'          => true,
					'zone'        => $result['zone'],
					'nameservers' => $result['nameservers'],
				);
			}
		} else {
			$result      = $driver->check( $host );
			$out['zone'] = is_wp_error( $result ) ? array( 'ok' => false, 'error' => $result->get_error_message() ) : array(
				'ok'          => true,
				'zone'        => $result['zone'],
				'nameservers' => isset( $result['nameservers'] ) ? $result['nameservers'] : array(),
			);
		}
		return array_merge( self::status(), array( 'test' => $out ) );
	}

	public static function apply() {
		if ( ! Store::is_primary() ) {
			return self::answer( new \WP_Error( 'rafoo_geo_primary', __( 'First set up this site as the main host.', 'rafoo' ) ) );
		}
		$result = Planner::apply();
		if ( is_wp_error( $result ) ) {
			return self::answer( $result );
		}
		return self::status();
	}

	public static function verify() {
		$plan    = Planner::plan();
		$results = array( (string) Store::get( 'self' ) => Dns\Resolver::verify( $plan ) );
		foreach ( Store::others() as $id => $node ) {
			if ( 'ready' !== $node['status'] ) {
				continue;
			}
			$reply          = Link::call( $node, 'verify', array(), false, 40 );
			$results[ $id ] = is_wp_error( $reply ) || empty( $reply['result'] ) ? null : $reply['result'];
		}
		Store::save_state(
			array(
				'verify' => array(
					'at'      => time(),
					'results' => $results,
				),
			)
		);
		return self::status();
	}

	public static function checks() {
		Seo::checks();
		return self::status();
	}

	public static function sync() {
		if ( Store::is_copy() ) {
			Syncer::run( 20 );
		} elseif ( Store::is_primary() ) {
			Lister::run( 8 );
			Changes::poke();
		}
		return self::status();
	}
}
