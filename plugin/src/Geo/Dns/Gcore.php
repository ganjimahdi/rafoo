<?php

namespace Rafoo\Geo\Dns;

defined( 'ABSPATH' ) || exit;

final class Gcore extends Driver {

	const NAME = 'gcore';

	const BASE = 'https://api.gcore.com/dns/v2';

	private function headers() {
		$token = trim( (string) ( isset( $this->secrets['token'] ) ? $this->secrets['token'] : '' ) );
		return array( 'Authorization' => 0 === stripos( $token, 'apikey ' ) ? $token : 'APIKey ' . $token );
	}

	public function check( $host ) {
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->request( 'GET', $this->base() . '/zones/' . rawurlencode( $zone ), $this->headers() );
			if ( $this->ok( $reply ) && is_array( $reply['json'] ) ) {
				$servers = array();
				foreach ( array( 'nameservers', 'nameserver' ) as $key ) {
					if ( isset( $reply['json'][ $key ] ) ) {
						$servers = array_values( (array) $reply['json'][ $key ] );
					}
				}
				return array(
					'zone'        => $zone,
					'nameservers' => $servers,
				);
			}
			if ( ! is_wp_error( $reply ) && in_array( $reply['code'], array( 401, 403 ), true ) ) {
				return $this->fail( $reply, 'Gcore' );
			}
		}
		return new \WP_Error( 'rafoo_geo_zone', 'Gcore: zone not found for this token' );
	}

	private function put( $zone, $name, array $body ) {
		$url   = $this->base() . '/zones/' . rawurlencode( $zone ) . '/' . rawurlencode( $name ) . '/A';
		$reply = $this->request( 'PUT', $url, $this->headers(), $body );
		if ( ! $this->ok( $reply ) && ! is_wp_error( $reply ) && 404 === $reply['code'] ) {
			$reply = $this->request( 'POST', $url, $this->headers(), $body );
		}
		return $this->ok( $reply ) ? true : $this->fail( $reply, 'Gcore ' . $name );
	}

	public function apply( array $plan ) {
		$zone = $this->check( $plan['host'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$records = array();
		foreach ( self::up( $plan ) as $id => $node ) {
			$routes    = \Rafoo\Geo\Planner::routed( $plan, $id );
			$records[] = array(
				'content' => array( $node['ip'] ),
				'enabled' => true,
				'meta'    => array_filter(
					array(
						'countries'  => array_map( 'strtolower', $routes['countries'] ),
						'continents' => array_map( 'strtolower', $routes['continents'] ),
						'default'    => $routes['default'] ? true : null,
					)
				),
			);
		}
		$result = $this->put(
			$zone['zone'],
			$plan['host'],
			array(
				'ttl'              => $plan['ttl'],
				'pickers'          => array(
					array(
						'type'   => 'geodns',
						'strict' => false,
					),
					array( 'type' => 'default' ),
					array(
						'type'  => 'first_n',
						'limit' => 1,
					),
				),
				'resource_records' => $records,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( $this->subs( $plan, $zone['zone'] ) as $host => $ip ) {
			$result = $this->put(
				$zone['zone'],
				$host,
				array(
					'ttl'              => $plan['ttl'],
					'resource_records' => array( array( 'content' => array( $ip ) ) ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}
}
