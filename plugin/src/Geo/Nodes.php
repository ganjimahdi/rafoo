<?php

namespace Rafoo\Geo;

use Rafoo\Backup\Job;
use Rafoo\Backup\Sender;
use Rafoo\Backup\Transfer;
use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Nodes {

	const PREFIX = 'RAFOOG1-';

	const JOIN_LIFETIME = 86400;

	public static function new_id() {
		return 'n' . strtolower( wp_generate_password( 6, false ) );
	}

	public static function clean_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	public static function join_code( $url = '' ) {
		if ( is_multisite() ) {
			return new \WP_Error( 'rafoo_geo_multisite', __( 'A WordPress network (Multisite) cannot be a copy.', 'rafoo' ) );
		}
		if ( Store::active() ) {
			return new \WP_Error( 'rafoo_geo_busy', __( 'This site is already part of a group of hosts. Leave the group first.', 'rafoo' ) );
		}
		$url = '' !== $url ? self::clean_url( $url ) : self::clean_url( home_url() );
		if ( '' === $url ) {
			return new \WP_Error( 'rafoo_geo_url', __( 'Enter the technical address of this host, for example https://de.example.com.', 'rafoo' ) );
		}
		$key = random_bytes( 32 );
		Store::update(
			array(
				'role' => 'joining',
				'join' => array(
					'key'     => Vault::seal( bin2hex( $key ) ),
					'url'     => $url,
					'expires' => time() + self::JOIN_LIFETIME,
				),
			)
		);
		return array(
			'code'    => self::PREFIX . Link::encode(
				wp_json_encode(
					array(
						'u' => $url,
						'k' => Link::encode( $key ),
					)
				)
			),
			'url'     => $url,
			'expires' => time() + self::JOIN_LIFETIME,
		);
	}

	public static function join_key() {
		$join = Store::get( 'join' );
		if ( ! is_array( $join ) || (int) $join['expires'] < time() ) {
			return '';
		}
		$hex = Vault::open( (string) $join['key'] );
		return 64 === strlen( $hex ) ? hex2bin( $hex ) : '';
	}

	public static function cancel_join() {
		if ( 'joining' === Store::role() ) {
			Store::update(
				array(
					'role' => '',
					'join' => null,
					'key'  => '',
				)
			);
		}
	}

	public static function parse( $code ) {
		$code = preg_replace( '/\s+/', '', (string) $code );
		if ( 0 !== strpos( $code, self::PREFIX ) ) {
			return new \WP_Error( 'rafoo_geo_code', __( 'This code is not valid. Copy it again from the other host.', 'rafoo' ) );
		}
		$data = json_decode( Link::decode( substr( $code, strlen( self::PREFIX ) ) ), true );
		$url  = is_array( $data ) && isset( $data['u'] ) ? self::clean_url( $data['u'] ) : '';
		$key  = is_array( $data ) && isset( $data['k'] ) ? Link::decode( $data['k'] ) : '';
		if ( '' === $url || 32 !== strlen( $key ) ) {
			return new \WP_Error( 'rafoo_geo_code', __( 'This code is not valid. Copy it again from the other host.', 'rafoo' ) );
		}
		return array(
			'url' => $url,
			'key' => $key,
		);
	}

	public static function setup_primary( $url, $label, $country ) {
		if ( is_multisite() ) {
			return new \WP_Error( 'rafoo_geo_multisite', __( 'Hosts in several countries are not available for a WordPress network (Multisite) yet.', 'rafoo' ) );
		}
		if ( Store::is_copy() || 'joining' === Store::role() ) {
			return new \WP_Error( 'rafoo_geo_busy', __( 'This site is already part of a group of hosts. Leave the group first.', 'rafoo' ) );
		}
		$url = self::clean_url( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'rafoo_geo_url', __( 'Enter the technical address of this host, for example https://ir.example.com.', 'rafoo' ) );
		}
		$main = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $main ) {
			return new \WP_Error( 'rafoo_geo_url', __( 'The technical address must be a separate subdomain, not the site address itself.', 'rafoo' ) );
		}
		$was   = Store::is_primary();
		$id    = $was ? (string) Store::get( 'self' ) : self::new_id();
		$key   = Store::is_primary() ? Store::key() : random_bytes( 32 );
		$port  = wp_parse_url( home_url(), PHP_URL_PORT );
		$nodes = Store::is_primary() ? Store::nodes() : array();
		$node  = array(
			'id'      => $id,
			'label'   => sanitize_text_field( (string) $label ),
			'url'     => $url,
			'ip'      => Dns\Resolver::ipv4( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
			'country' => Countries::valid( $country ) ? strtoupper( $country ) : 'IR',
			'primary' => true,
			'status'  => 'ready',
			'added'   => isset( $nodes[ $id ]['added'] ) ? $nodes[ $id ]['added'] : time(),
		);
		$nodes[ $id ] = $node;
		Store::update(
			array(
				'role'    => 'primary',
				'self'    => $id,
				'primary' => $id,
				'host'    => $main . ( $port ? ':' . (int) $port : '' ),
				'scheme'  => (string) wp_parse_url( home_url(), PHP_URL_SCHEME ),
				'nodes'   => $nodes,
				'key'     => Store::seal_key( $key ),
				'since'   => (int) Store::get( 'since' ) ? (int) Store::get( 'since' ) : time(),
			)
		);
		$check = Link::call( $node, 'ping', array( 'probe' => 'self' ), false, 15 );
		if ( is_wp_error( $check ) || ! isset( $check['id'] ) || $check['id'] !== $id ) {
			if ( ! $was ) {
				Store::reset();
			}
			return new \WP_Error(
				'rafoo_geo_self',
				__( 'This host could not be reached at its technical address. Check that the subdomain points to this host and opens this same site.', 'rafoo' ),
				array( 'detail' => is_wp_error( $check ) ? $check->get_error_message() : 'other site' )
			);
		}
		Changes::start();
		State::event( 'geo_primary', array( 'name' => $url ) );
		return Store::all();
	}

	public static function add( $code, $label, $country, $ip = '' ) {
		if ( ! Store::is_primary() ) {
			return new \WP_Error( 'rafoo_geo_primary', __( 'First set up this site as the main host.', 'rafoo' ) );
		}
		$copies = array_filter(
			Store::nodes(),
			static function ( $node ) {
				return empty( $node['primary'] );
			}
		);
		if ( count( $copies ) >= Store::MAX_COPIES ) {
			return new \WP_Error( 'rafoo_geo_full', __( 'A site can have at most three copies.', 'rafoo' ) );
		}
		if ( Job::running() ) {
			return new \WP_Error( 'rafoo_busy', __( 'Another backup task is running. Wait until it finishes.', 'rafoo' ) );
		}
		$peer = self::parse( $code );
		if ( is_wp_error( $peer ) ) {
			return $peer;
		}
		foreach ( Store::nodes() as $node ) {
			if ( $node['url'] === $peer['url'] ) {
				return new \WP_Error( 'rafoo_geo_twice', __( 'This host is already in the group.', 'rafoo' ) );
			}
		}
		$id    = self::new_id();
		$probe = array(
			'id'  => $id,
			'url' => $peer['url'],
		);
		$reply = Link::call(
			$probe,
			'join',
			array(
				'id'      => $id,
				'key'     => Link::encode( Store::key() ),
				'host'    => Store::main_host(),
				'scheme'  => Store::get( 'scheme' ),
				'primary' => Store::get( 'self' ),
				'nodes'   => self::public_nodes(),
				'prefix'  => $GLOBALS['wpdb']->base_prefix,
			),
			false,
			30,
			$peer['key']
		);
		if ( is_wp_error( $reply ) ) {
			return new \WP_Error( 'rafoo_geo_peer', __( 'The other host did not answer at its technical address. Check that Rafoo is active there and the code is still valid.', 'rafoo' ), array( 'detail' => $reply->get_error_message() ) );
		}
		if ( empty( $reply['ok'] ) ) {
			return self::refused( isset( $reply['error'] ) ? (string) $reply['error'] : '' );
		}
		$node = array(
			'id'      => $id,
			'label'   => sanitize_text_field( (string) $label ),
			'url'     => $peer['url'],
			'ip'      => '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : Dns\Resolver::ipv4( (string) wp_parse_url( $peer['url'], PHP_URL_HOST ) ),
			'country' => Countries::valid( $country ) ? strtoupper( $country ) : 'DE',
			'primary' => false,
			'status'  => 'seeding',
			'added'   => time(),
			'version' => isset( $reply['version'] ) ? (string) $reply['version'] : '',
		);
		Store::put_node( $node );
		$job = Sender::start(
			(string) $reply['transfer'],
			false,
			array(
				'geo'     => $id,
				'gen'     => Changes::generation(),
			)
		);
		if ( is_wp_error( $job ) ) {
			Store::drop_node( $id );
			Link::call( $node, 'leave', array(), false, 10 );
			return $job;
		}
		State::event( 'geo_added', array( 'name' => $node['url'] ) );
		return $node;
	}

	private static function refused( $error ) {
		$messages = array(
			'multisite' => __( 'The other host runs a WordPress network (Multisite), which cannot be a copy.', 'rafoo' ),
			'busy'      => __( 'The other host is busy with a backup task. Try again when it finishes.', 'rafoo' ),
			'config'    => __( 'The other host uses a different table prefix and its wp-config.php is not writable, so Rafoo cannot match it. Make wp-config.php writable there, or install WordPress there with the same table prefix.', 'rafoo' ),
			'old'       => __( 'Rafoo on the other host is too old. Update it to version 1.4 or newer.', 'rafoo' ),
		);
		return new \WP_Error( 'rafoo_geo_refused', isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The other host refused to join.', 'rafoo' ) );
	}

	public static function seeded( array $job ) {
		$d  = isset( $job['data'] ) ? $job['data'] : array();
		$id = isset( $d['geo'] ) ? (string) $d['geo'] : '';
		if ( '' === $id || ! Store::is_primary() ) {
			return;
		}
		$node = Store::node( $id );
		if ( ! $node ) {
			return;
		}
		if ( 'done' !== $job['status'] ) {
			$node['status'] = 'failed';
			$node['error']  = (string) $job['error'];
			Store::put_node( $node );
			return;
		}
		$node['status'] = 'ready';
		$node['error']  = '';
		Store::put_node( $node );
		self::broadcast();
		Planner::changed();
		State::event( 'geo_ready', array( 'name' => $node['url'] ) );
	}

	public static function remove( $id ) {
		$node = Store::node( $id );
		if ( ! Store::is_primary() || ! $node || ! empty( $node['primary'] ) ) {
			return new \WP_Error( 'rafoo_not_found', __( 'This host is no longer in the group.', 'rafoo' ) );
		}
		Link::call( $node, 'leave', array(), false, 10 );
		Store::drop_node( $id );
		Store::update( array( 'key' => Store::seal_key( self::rotate() ) ) );
		Planner::changed();
		State::event( 'geo_removed', array( 'name' => $node['url'] ) );
		return Store::all();
	}

	private static function rotate() {
		$next = random_bytes( 32 );
		foreach ( Store::others() as $node ) {
			Link::call( $node, 'config', array_merge( self::config_payload(), array( 'key' => Link::encode( $next ) ) ), false, 15 );
		}
		return $next;
	}

	public static function dissolve() {
		if ( Store::is_primary() ) {
			foreach ( Store::others() as $node ) {
				Link::call( $node, 'leave', array(), false, 10 );
			}
			Planner::release();
		}
		Store::reset();
		State::event( 'geo_left', array() );
	}

	public static function public_nodes() {
		$out = array();
		foreach ( Store::nodes() as $id => $node ) {
			$out[ $id ] = array_intersect_key( $node, array_flip( array( 'id', 'label', 'url', 'ip', 'country', 'primary', 'status', 'added' ) ) );
		}
		return $out;
	}

	public static function config_payload() {
		return array(
			'nodes'   => self::public_nodes(),
			'host'    => Store::main_host(),
			'scheme'  => Store::get( 'scheme' ),
			'primary' => Store::get( 'self' ),
			'method'  => Store::get( 'method' ),
			'gen'     => Changes::generation(),
			'dynamic' => self::dynamic_paths(),
		);
	}

	public static function dynamic_paths() {
		$paths = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
					if ( '' !== $path && '/' !== $path ) {
						$paths[] = trailingslashit( $path );
					}
				}
			}
		}
		return array_values( array_unique( array_merge( $paths, array_map( 'strval', (array) apply_filters( 'rafoo_geo_dynamic_paths', array() ) ) ) ) );
	}

	public static function broadcast() {
		$payload = self::config_payload();
		$secrets = Planner::shared_secrets();
		if ( $secrets ) {
			$payload['secrets'] = Link::encode( Link::seal( wp_json_encode( $secrets ), Store::key() ) );
		}
		foreach ( Store::others() as $node ) {
			Link::call( $node, 'config', $payload, false, 15 );
		}
	}

	public static function update_node( $id, array $changes ) {
		$node = Store::node( $id );
		if ( ! Store::is_primary() || ! $node ) {
			return new \WP_Error( 'rafoo_not_found', __( 'This host is no longer in the group.', 'rafoo' ) );
		}
		if ( isset( $changes['label'] ) ) {
			$node['label'] = sanitize_text_field( (string) $changes['label'] );
		}
		if ( isset( $changes['country'] ) && Countries::valid( $changes['country'] ) ) {
			$node['country'] = strtoupper( (string) $changes['country'] );
		}
		if ( isset( $changes['ip'] ) && filter_var( $changes['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$node['ip'] = (string) $changes['ip'];
		}
		Store::put_node( $node );
		self::broadcast();
		Planner::changed();
		return $node;
	}

	public static function accept_join( array $payload ) {
		if ( 'joining' !== Store::role() ) {
			return array(
				'ok'    => false,
				'error' => 'state',
			);
		}
		if ( is_multisite() ) {
			return array(
				'ok'    => false,
				'error' => 'multisite',
			);
		}
		if ( Job::running() ) {
			return array(
				'ok'    => false,
				'error' => 'busy',
			);
		}
		global $wpdb;
		$prefix = isset( $payload['prefix'] ) ? (string) $payload['prefix'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', $prefix ) ) {
			return array(
				'ok'    => false,
				'error' => 'state',
			);
		}
		if ( $prefix !== $wpdb->base_prefix && ! Config::writable() ) {
			return array(
				'ok'    => false,
				'error' => 'config',
			);
		}
		$key = Link::decode( isset( $payload['key'] ) ? (string) $payload['key'] : '' );
		if ( 32 !== strlen( $key ) || empty( $payload['id'] ) || empty( $payload['host'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'state',
			);
		}
		$join  = Store::get( 'join' );
		$nodes = self::sanitize_nodes( isset( $payload['nodes'] ) ? (array) $payload['nodes'] : array() );
		$self  = sanitize_key( (string) $payload['id'] );
		$nodes[ $self ] = array(
			'id'      => $self,
			'label'   => '',
			'url'     => is_array( $join ) ? (string) $join['url'] : self::clean_url( home_url() ),
			'ip'      => '',
			'country' => '',
			'primary' => false,
			'status'  => 'seeding',
			'added'   => time(),
		);
		Store::update(
			array(
				'role'    => 'copy',
				'self'    => $self,
				'primary' => sanitize_key( (string) $payload['primary'] ),
				'host'    => self::clean_host( (string) $payload['host'] ),
				'scheme'  => 'http' === ( isset( $payload['scheme'] ) ? $payload['scheme'] : '' ) ? 'http' : 'https',
				'nodes'   => $nodes,
				'key'     => Store::seal_key( $key ),
				'join'    => array_merge( is_array( $join ) ? $join : array(), array( 'prefix' => $prefix ) ),
				'since'   => 0,
			)
		);
		$invite = Transfer::invite( false, is_array( $join ) && ! empty( $join['url'] ) ? untrailingslashit( (string) $join['url'] ) . '/wp-admin/admin-ajax.php' : '' );
		return array(
			'ok'       => true,
			'home'     => home_url(),
			'prefix'   => $wpdb->base_prefix,
			'version'  => RAFOO_VERSION,
			'php'      => PHP_VERSION,
			'transfer' => $invite['code'],
		);
	}

	public static function accept_config( array $payload ) {
		if ( ! Store::is_copy() ) {
			return null;
		}
		$changes = array();
		if ( isset( $payload['nodes'] ) ) {
			$nodes = self::sanitize_nodes( (array) $payload['nodes'] );
			$self  = (string) Store::get( 'self' );
			if ( isset( $nodes[ $self ] ) ) {
				$current         = Store::self_node();
				$nodes[ $self ]  = array_merge( $nodes[ $self ], array( 'status' => $current ? $current['status'] : $nodes[ $self ]['status'] ) );
			}
			$changes['nodes'] = $nodes;
		}
		if ( isset( $payload['host'] ) ) {
			$changes['host'] = self::clean_host( (string) $payload['host'] );
		}
		if ( isset( $payload['method'] ) && is_array( $payload['method'] ) ) {
			$changes['method'] = array_intersect_key( $payload['method'], Store::defaults()['method'] );
		}
		if ( isset( $payload['dynamic'] ) ) {
			$changes['dynamic'] = array_values(
				array_filter(
					array_map(
						static function ( $path ) {
							return preg_match( '#^/[A-Za-z0-9/_\-.%]*$#', (string) $path ) ? (string) $path : '';
						},
						(array) $payload['dynamic']
					)
				)
			);
		}
		if ( ! empty( $payload['secrets'] ) ) {
			$plain = Link::open( Link::decode( (string) $payload['secrets'] ), Store::key() );
			$data  = false === $plain ? null : json_decode( $plain, true );
			if ( is_array( $data ) ) {
				Store::save_secrets( $data );
			}
		}
		if ( ! empty( $payload['key'] ) ) {
			$key = Link::decode( (string) $payload['key'] );
			if ( 32 === strlen( $key ) ) {
				$changes['key'] = Store::seal_key( $key );
			}
		}
		if ( isset( $payload['gen'] ) ) {
			Syncer::announce( (int) $payload['gen'] );
		}
		Store::update( $changes );
		return array( 'ok' => true );
	}

	public static function received( array $job ) {
		$d = isset( $job['data'] ) ? $job['data'] : array();
		if ( empty( $d['geo'] ) || ! Store::is_copy() ) {
			return;
		}
		$self = Store::self_node();
		if ( ! $self ) {
			return;
		}
		$self['status'] = 'done' === $job['status'] ? 'ready' : 'failed';
		Store::put_node( $self );
		if ( 'done' === $job['status'] ) {
			Store::update(
				array(
					'since' => time(),
					'join'  => null,
				)
			);
			Syncer::seeded( isset( $d['geo_gen'] ) ? (int) $d['geo_gen'] : 0 );
		}
	}

	private static function sanitize_nodes( array $nodes ) {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) || empty( $node['url'] ) ) {
				continue;
			}
			$id         = sanitize_key( (string) $node['id'] );
			$out[ $id ] = array(
				'id'      => $id,
				'label'   => sanitize_text_field( isset( $node['label'] ) ? (string) $node['label'] : '' ),
				'url'     => self::clean_url( $node['url'] ),
				'ip'      => isset( $node['ip'] ) && filter_var( $node['ip'], FILTER_VALIDATE_IP ) ? (string) $node['ip'] : '',
				'country' => isset( $node['country'] ) && Countries::valid( $node['country'] ) ? strtoupper( (string) $node['country'] ) : '',
				'primary' => ! empty( $node['primary'] ),
				'status'  => isset( $node['status'] ) ? sanitize_key( (string) $node['status'] ) : 'ready',
				'added'   => isset( $node['added'] ) ? (int) $node['added'] : time(),
			);
		}
		return $out;
	}

	private static function clean_host( $host ) {
		$host = strtolower( trim( $host ) );
		return preg_match( '/^[a-z0-9.\-]+(:\d{1,5})?$/', $host ) ? $host : '';
	}
}
