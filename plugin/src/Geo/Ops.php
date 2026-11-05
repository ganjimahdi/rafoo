<?php

namespace Rafoo\Geo;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Ops {

	public static function handle( $op, array $payload, $from ) {
		switch ( $op ) {
			case 'ping':
				return self::ping();
			case 'challenge':
				return Relay::lookup( isset( $payload['path'] ) ? (string) $payload['path'] : '' );
			case 'fingerprint':
				return Seo::fingerprint();
			case 'join':
				return Nodes::accept_join( $payload );
			case 'verify':
				return Store::active() ? array(
					'ok'     => true,
					'result' => Dns\Resolver::verify( Planner::plan() ),
				) : null;
		}
		if ( Store::is_copy() ) {
			return self::copy( $op, $payload );
		}
		if ( Store::is_primary() ) {
			return self::primary( $op, $payload, $from );
		}
		return null;
	}

	private static function ping() {
		$self = Store::self_node();
		return array(
			'ok'      => true,
			'id'      => (string) Store::get( 'self' ),
			'role'    => Store::role(),
			'status'  => $self ? (string) $self['status'] : '',
			'gen'     => Store::is_primary() ? Changes::generation() : (int) Syncer::state()['gen'],
			'time'    => time(),
			'version' => RAFOO_VERSION,
			'offline' => State::offline(),
		);
	}

	private static function copy( $op, array $payload ) {
		switch ( $op ) {
			case 'poke':
				Syncer::announce( isset( $payload['gen'] ) ? (int) $payload['gen'] : 0 );
				register_shutdown_function( array( __CLASS__, 'sync_after_reply' ) );
				return array( 'ok' => true );
			case 'config':
				return Nodes::accept_config( $payload );
			case 'leave':
				Store::reset();
				State::event( 'geo_left', array() );
				return array( 'ok' => true );
			case 'status':
				return array(
					'ok'     => true,
					'sync'   => Syncer::view(),
					'report' => Health::report(),
				);
		}
		return null;
	}

	public static function sync_after_reply() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		ignore_user_abort( true );
		Syncer::run( 20 );
	}

	private static function primary( $op, array $payload, $from ) {
		global $wpdb;
		switch ( $op ) {
			case 'changes':
				if ( '' !== $from && isset( $payload['report'] ) && is_array( $payload['report'] ) ) {
					Health::remember( $from, $payload['report'] );
				}
				if ( Lister::due() ) {
					Lister::run( 5 );
				}
				$since = Changes::since( isset( $payload['gen'] ) ? (int) $payload['gen'] : 0 );
				$all   = Db::tables();
				$names = array();
				if ( ! empty( $payload['full'] ) || 0 === (int) $payload['gen'] ) {
					$names = $all;
				} else {
					foreach ( array_keys( $since['tables'] ) as $table ) {
						if ( 0 === strpos( $table, $wpdb->base_prefix ) ) {
							$names[] = substr( $table, strlen( $wpdb->base_prefix ) );
						}
					}
				}
				$meta = Lister::meta();
				return array(
					'ok'     => true,
					'gen'    => $since['gen'],
					'prefix' => $wpdb->base_prefix,
					'tables' => array_values( array_intersect( $names, $all ) ),
					'all'    => $all,
					'files'  => $since['files'],
					'list'   => array(
						'at'    => (int) $meta['at'],
						'size'  => (int) $meta['size'],
						'files' => (int) $meta['files'],
					),
				);
			case 'describe':
				$name = self::name( $payload );
				$all  = Db::tables();
				if ( '' === $name || ! in_array( $name, $all, true ) ) {
					return array( 'ok' => true, 'info' => null );
				}
				return array(
					'ok'     => true,
					'info'   => Db::describe( $name ),
					'create' => Db::create_statement( $name ),
				);
			case 'sums':
				$info = self::info( $payload );
				return $info ? array(
					'ok'   => true,
					'sums' => Db::sums( $info ),
				) : array( 'ok' => false );
			case 'rows':
				$info = self::info( $payload );
				if ( ! $info ) {
					return null;
				}
				$buckets = array_slice( array_map( 'intval', isset( $payload['buckets'] ) ? (array) $payload['buckets'] : array() ), 0, 200 );
				return (string) gzencode( wp_json_encode( Db::rows( $info, $buckets ) ), 1 );
			case 'list':
				if ( isset( $payload['at'] ) && (int) $payload['at'] !== (int) Lister::meta()['at'] ) {
					return FileSync::CHANGED;
				}
				return Lister::chunk( isset( $payload['offset'] ) ? (int) $payload['offset'] : 0, isset( $payload['length'] ) ? (int) $payload['length'] : FileSync::CHUNK );
			case 'pack':
				return FileSync::pack( isset( $payload['paths'] ) ? (array) $payload['paths'] : array() );
			case 'file':
				return FileSync::slice( isset( $payload['path'] ) ? (string) $payload['path'] : '', isset( $payload['offset'] ) ? (int) $payload['offset'] : 0, isset( $payload['length'] ) ? (int) $payload['length'] : FileSync::CHUNK );
			case 'report':
				if ( '' !== $from && isset( $payload['report'] ) && is_array( $payload['report'] ) ) {
					Health::remember( $from, $payload['report'] );
				}
				return array( 'ok' => true );
		}
		return null;
	}

	private static function name( array $payload ) {
		return isset( $payload['name'] ) ? (string) preg_replace( '/[^A-Za-z0-9_$]/', '', (string) $payload['name'] ) : '';
	}

	private static function info( array $payload ) {
		$name = self::name( $payload );
		return '' !== $name && in_array( $name, Db::tables(), true ) ? Db::describe( $name ) : null;
	}
}
