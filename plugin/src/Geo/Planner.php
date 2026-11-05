<?php

namespace Rafoo\Geo;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Planner {

	const HOOK = 'rafoo_geo_plan';

	const PROVIDERS = array( 'arvan', 'cloudflare', 'route53', 'gcore', 'bunny' );

	const PANELS = array( 'cpanel', 'directadmin' );

	const RETRY = 600;

	public static function plan( $copy_view = false, $only_primary = false ) {
		$nodes = array();
		foreach ( Store::nodes() as $id => $node ) {
			if ( '' === (string) $node['ip'] || ( $only_primary && empty( $node['primary'] ) ) ) {
				continue;
			}
			$place        = Countries::get( $node['country'] );
			$place        = $place ? $place : Countries::get( 'DE' );
			$nodes[ $id ] = array(
				'id'      => $id,
				'ip'      => (string) $node['ip'],
				'country' => (string) $node['country'],
				'lat'     => $place['lat'],
				'lon'     => $place['lon'],
				'primary' => ! empty( $node['primary'] ),
				'up'      => Health::up( $id, $copy_view ),
				'host'    => Store::node_host( $node ),
			);
		}
		$up      = array_filter(
			$nodes,
			static function ( $node ) {
				return $node['up'];
			}
		);
		$copies  = array_filter(
			$up,
			static function ( $node ) {
				return ! $node['primary'];
			}
		);
		$primary = null;
		foreach ( $nodes as $node ) {
			if ( $node['primary'] ) {
				$primary = $node;
			}
		}
		$pool      = $copies ? $copies : $up;
		$countries = array();
		foreach ( $up as $node ) {
			if ( ! isset( $countries[ $node['country'] ] ) || $node['primary'] ) {
				$countries[ $node['country'] ] = $node['id'];
			}
		}
		$continents = array();
		foreach ( Countries::CONTINENTS as $continent ) {
			$continents[ $continent ] = self::nearest( $pool, Countries::centre( $continent ) );
		}
		$default = self::nearest( $pool, Countries::centre( 'EU' ) );
		if ( null === $default && $primary ) {
			$default = $primary['id'];
		}
		$subs = array();
		foreach ( $nodes as $node ) {
			$subs[ (string) preg_replace( '/:\d+$/', '', $node['host'] ) ] = $node['ip'];
		}
		return array(
			'host'       => preg_replace( '/:\d+$/', '', Store::main_host() ),
			'ttl'        => max( 60, (int) Store::get( 'method' )['ttl'] ),
			'nodes'      => $nodes,
			'countries'  => $countries,
			'continents' => array_filter( $continents ),
			'default'    => $default,
			'subs'       => $subs,
		);
	}

	private static function nearest( array $nodes, array $point ) {
		$best = null;
		$dist = INF;
		foreach ( $nodes as $node ) {
			$d = Countries::distance( $node, $point );
			if ( $d < $dist ) {
				$dist = $d;
				$best = $node['id'];
			}
		}
		return $best;
	}

	public static function routed( array $plan, $id ) {
		$out = array(
			'countries'  => array(),
			'continents' => array(),
			'default'    => $plan['default'] === $id,
		);
		foreach ( $plan['countries'] as $country => $node ) {
			if ( $node === $id ) {
				$out['countries'][] = $country;
			}
		}
		foreach ( $plan['continents'] as $continent => $node ) {
			if ( $node === $id ) {
				$out['continents'][] = $continent;
			}
		}
		return $out;
	}

	public static function hash( array $plan ) {
		$nodes = array();
		foreach ( $plan['nodes'] as $id => $node ) {
			$nodes[ $id ] = array( $node['ip'], $node['up'], $node['country'] );
		}
		return md5( wp_json_encode( array( $plan['host'], $plan['ttl'], $nodes, $plan['countries'], $plan['continents'], $plan['default'], $plan['subs'], Store::get( 'method' ) ) ) );
	}

	public static function driver( $type = null ) {
		$method = Store::get( 'method' );
		$type   = null === $type ? ( 'provider' === $method['type'] ? $method['provider'] : ( 'panel' === $method['type'] ? $method['panel'] : '' ) ) : $type;
		$map    = array(
			'arvan'       => Dns\Arvan::class,
			'cloudflare'  => Dns\Cloudflare::class,
			'route53'     => Dns\Route53::class,
			'gcore'       => Dns\Gcore::class,
			'bunny'       => Dns\Bunny::class,
			'cpanel'      => Dns\Cpanel::class,
			'directadmin' => Dns\DirectAdmin::class,
		);
		if ( ! isset( $map[ $type ] ) ) {
			return null;
		}
		$class   = $map[ $type ];
		$secrets = Store::secrets();
		return new $class( isset( $secrets[ $type ] ) ? (array) $secrets[ $type ] : array() );
	}

	public static function apply( $copy_view = false, $only_primary = false ) {
		$driver = self::driver();
		$plan   = self::plan( $copy_view, $only_primary );
		$hash   = self::hash( $plan );
		if ( ! $driver ) {
			Store::save_state(
				array(
					'dns' => array(
						'hash'  => $hash,
						'at'    => time(),
						'ok'    => true,
						'error' => '',
						'auto'  => false,
					),
				)
			);
			return true;
		}
		$result = $driver->apply( $plan );
		$error  = is_wp_error( $result ) ? $result->get_error_message() : '';
		Store::save_state(
			array(
				'dns' => array(
					'hash'  => $hash,
					'at'    => time(),
					'ok'    => '' === $error,
					'error' => substr( $error, 0, 400 ),
					'auto'  => true,
					'by'    => (string) Store::get( 'self' ),
				),
			)
		);
		if ( '' !== $error ) {
			State::event( 'geo_dns_failed', array( 'name' => substr( $error, 0, 120 ) ) );
			return $result;
		}
		State::event( 'geo_dns_applied', array( 'name' => $plan['host'] ) );
		return true;
	}

	public static function tick( $copy_view = false ) {
		$method = Store::get( 'method' );
		if ( '' === $method['type'] ) {
			return;
		}
		$plan = self::plan( $copy_view );
		$hash = self::hash( $plan );
		$dns  = (array) Store::state()['dns'];
		$due  = ! isset( $dns['hash'] ) || $dns['hash'] !== $hash || ( empty( $dns['ok'] ) && time() - (int) $dns['at'] > self::RETRY ) || time() - (int) ( isset( $dns['at'] ) ? $dns['at'] : 0 ) > DAY_IN_SECONDS;
		if ( $due ) {
			self::apply( $copy_view );
		}
	}

	public static function changed() {
		$state        = Store::state();
		$dns          = (array) $state['dns'];
		$dns['hash']  = '';
		Store::save_state( array( 'dns' => $dns ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	public static function scheduled() {
		if ( Store::is_primary() ) {
			self::apply();
		}
	}

	public static function release() {
		if ( Store::is_primary() && self::driver() ) {
			self::apply( false, true );
		}
	}

	public static function shared_secrets() {
		$method = Store::get( 'method' );
		if ( 'provider' !== $method['type'] ) {
			return array();
		}
		$secrets = Store::secrets();
		return isset( $secrets[ $method['provider'] ] ) ? array( $method['provider'] => $secrets[ $method['provider'] ] ) : array();
	}

	public static function zone_candidates( $host ) {
		$labels = explode( '.', strtolower( (string) preg_replace( '/:\d+$/', '', $host ) ) );
		$out    = array();
		for ( $i = 0; $i < count( $labels ) - 1; $i++ ) {
			$out[] = implode( '.', array_slice( $labels, $i ) );
		}
		return $out;
	}

	public static function relative( $name, $zone ) {
		$name = strtolower( rtrim( $name, '.' ) );
		$zone = strtolower( rtrim( $zone, '.' ) );
		if ( $name === $zone ) {
			return '@';
		}
		return substr( $name, -strlen( '.' . $zone ) ) === '.' . $zone ? substr( $name, 0, -strlen( '.' . $zone ) ) : $name;
	}
}
