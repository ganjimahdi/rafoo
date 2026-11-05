<?php

namespace Rafoo\Geo\Dns;

use Rafoo\Geo\Planner;

defined( 'ABSPATH' ) || exit;

final class Arvan extends Driver {

	const NAME = 'arvan';

	const BASE = 'https://napi.arvancloud.ir/cdn/4.0';

	private function headers() {
		$key = trim( (string) ( isset( $this->secrets['key'] ) ? $this->secrets['key'] : '' ) );
		return array( 'Authorization' => 0 === stripos( $key, 'apikey ' ) ? $key : 'Apikey ' . $key );
	}

	public function check( $host ) {
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->request( 'GET', $this->base() . '/domains/' . rawurlencode( $zone ), $this->headers() );
			if ( $this->ok( $reply ) && isset( $reply['json']['data'] ) ) {
				$data = (array) $reply['json']['data'];
				return array(
					'zone'        => $zone,
					'nameservers' => isset( $data['ns_keys'] ) ? array_values( (array) $data['ns_keys'] ) : array(),
					'status'      => isset( $data['status'] ) ? (string) $data['status'] : '',
				);
			}
			if ( ! is_wp_error( $reply ) && in_array( $reply['code'], array( 401, 403 ), true ) ) {
				return $this->fail( $reply, 'ArvanCloud' );
			}
		}
		return new \WP_Error( 'rafoo_geo_zone', 'ArvanCloud: domain not found in this account' );
	}

	private function records( $zone, $name ) {
		$reply = $this->request( 'GET', $this->base() . '/domains/' . rawurlencode( $zone ) . '/dns-records?type=a&search=' . rawurlencode( $name ) . '&per_page=100', $this->headers() );
		if ( ! $this->ok( $reply ) ) {
			return $this->fail( $reply, 'ArvanCloud records' );
		}
		$out = array();
		foreach ( isset( $reply['json']['data'] ) ? (array) $reply['json']['data'] : array() as $record ) {
			if ( isset( $record['type'], $record['name'] ) && 'a' === strtolower( (string) $record['type'] ) && strtolower( (string) $record['name'] ) === strtolower( $name ) ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	private function put( $zone, $name, array $values, $geo, $ttl ) {
		$existing = $this->records( $zone, $name );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$body = array(
			'type'           => 'a',
			'name'           => $name,
			'ttl'            => $ttl,
			'cloud'          => false,
			'upstream_https' => 'default',
			'ip_filter_mode' => array(
				'count'      => 'single',
				'order'      => 'none',
				'geo_filter' => $geo ? 'location' : 'none',
			),
			'value'          => $values,
		);
		if ( $existing ) {
			$first = array_shift( $existing );
			$reply = $this->request( 'PUT', $this->base() . '/domains/' . rawurlencode( $zone ) . '/dns-records/' . rawurlencode( (string) $first['id'] ), $this->headers(), $body );
			foreach ( $existing as $extra ) {
				$this->request( 'DELETE', $this->base() . '/domains/' . rawurlencode( $zone ) . '/dns-records/' . rawurlencode( (string) $extra['id'] ), $this->headers() );
			}
		} else {
			$reply = $this->request( 'POST', $this->base() . '/domains/' . rawurlencode( $zone ) . '/dns-records', $this->headers(), $body );
		}
		return $this->ok( $reply ) ? true : $this->fail( $reply, 'ArvanCloud ' . $name );
	}

	public function apply( array $plan ) {
		$zone = $this->check( $plan['host'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$values = array();
		foreach ( self::up( $plan ) as $node ) {
			$values[] = array(
				'ip'      => $node['ip'],
				'port'    => null,
				'weight'  => 100,
				'country' => $node['country'],
			);
		}
		$result = $this->put( $zone['zone'], Planner::relative( $plan['host'], $zone['zone'] ), $values, true, $plan['ttl'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( $this->subs( $plan, $zone['zone'] ) as $host => $ip ) {
			$result = $this->put( $zone['zone'], Planner::relative( $host, $zone['zone'] ), array( array( 'ip' => $ip, 'port' => null, 'weight' => 100 ) ), false, $plan['ttl'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}
}
