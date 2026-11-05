<?php

namespace Rafoo\Geo\Dns;

defined( 'ABSPATH' ) || exit;

final class Cloudflare extends Driver {

	const NAME = 'cloudflare';

	const BASE = 'https://api.cloudflare.com/client/v4';

	const REGIONS = array(
		'NA' => array( 'WNAM', 'ENAM' ),
		'SA' => array( 'NSAM', 'SSAM' ),
		'EU' => array( 'WEU', 'EEU' ),
		'AF' => array( 'NAF', 'SAF' ),
		'AS' => array( 'ME', 'SAS', 'SEAS', 'NEAS' ),
		'OC' => array( 'OC' ),
	);

	private function headers() {
		return array( 'Authorization' => 'Bearer ' . trim( (string) ( isset( $this->secrets['token'] ) ? $this->secrets['token'] : '' ) ) );
	}

	public function check( $host ) {
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->request( 'GET', $this->base() . '/zones?name=' . rawurlencode( $zone ), $this->headers() );
			if ( ! $this->ok( $reply ) ) {
				return $this->fail( $reply, 'Cloudflare' );
			}
			$result = isset( $reply['json']['result'][0] ) ? (array) $reply['json']['result'][0] : null;
			if ( $result ) {
				return array(
					'zone'        => $zone,
					'id'          => (string) $result['id'],
					'account'     => isset( $result['account']['id'] ) ? (string) $result['account']['id'] : '',
					'nameservers' => isset( $result['name_servers'] ) ? array_values( (array) $result['name_servers'] ) : array(),
					'status'      => isset( $result['status'] ) ? (string) $result['status'] : '',
				);
			}
		}
		return new \WP_Error( 'rafoo_geo_zone', 'Cloudflare: zone not found for this token' );
	}

	private function pools( array $zone, array $plan ) {
		$list = $this->request( 'GET', $this->base() . '/accounts/' . rawurlencode( $zone['account'] ) . '/load_balancers/pools', $this->headers() );
		if ( ! $this->ok( $list ) ) {
			return $this->fail( $list, 'Cloudflare pools' );
		}
		$existing = array();
		foreach ( isset( $list['json']['result'] ) ? (array) $list['json']['result'] : array() as $pool ) {
			$existing[ (string) $pool['name'] ] = (string) $pool['id'];
		}
		$ids = array();
		foreach ( $plan['nodes'] as $id => $node ) {
			$name = 'rafoo-' . preg_replace( '/[^a-z0-9]/', '-', strtolower( $plan['host'] ) ) . '-' . $id;
			$body = array(
				'name'            => $name,
				'enabled'         => (bool) $node['up'],
				'minimum_origins' => 1,
				'origins'         => array(
					array(
						'name'    => $id,
						'address' => $node['ip'],
						'enabled' => true,
						'weight'  => 1,
					),
				),
			);
			$reply = isset( $existing[ $name ] )
				? $this->request( 'PUT', $this->base() . '/accounts/' . rawurlencode( $zone['account'] ) . '/load_balancers/pools/' . rawurlencode( $existing[ $name ] ), $this->headers(), $body )
				: $this->request( 'POST', $this->base() . '/accounts/' . rawurlencode( $zone['account'] ) . '/load_balancers/pools', $this->headers(), $body );
			if ( ! $this->ok( $reply ) || empty( $reply['json']['result']['id'] ) ) {
				return $this->fail( $reply, 'Cloudflare pool ' . $id );
			}
			$ids[ $id ] = (string) $reply['json']['result']['id'];
		}
		return $ids;
	}

	public function apply( array $plan ) {
		$zone = $this->check( $plan['host'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$pools = $this->pools( $zone, $plan );
		if ( is_wp_error( $pools ) ) {
			return $pools;
		}
		$primary = null;
		foreach ( $plan['nodes'] as $id => $node ) {
			if ( $node['primary'] ) {
				$primary = $pools[ $id ];
			}
		}
		$order = array();
		if ( $plan['default'] ) {
			$order[] = $pools[ $plan['default'] ];
		}
		foreach ( $pools as $pool ) {
			if ( ! in_array( $pool, $order, true ) ) {
				$order[] = $pool;
			}
		}
		$countries = array();
		foreach ( $plan['countries'] as $country => $id ) {
			$countries[ $country ] = array_values( array_unique( array_merge( array( $pools[ $id ] ), $order ) ) );
		}
		$regions = array();
		foreach ( $plan['continents'] as $continent => $id ) {
			foreach ( isset( self::REGIONS[ $continent ] ) ? self::REGIONS[ $continent ] : array() as $region ) {
				$regions[ $region ] = array_values( array_unique( array_merge( array( $pools[ $id ] ), $order ) ) );
			}
		}
		$body = array(
			'name'            => $plan['host'],
			'description'     => 'Rafoo',
			'ttl'             => $plan['ttl'],
			'proxied'         => false,
			'enabled'         => true,
			'steering_policy' => 'geo',
			'default_pools'   => $order,
			'fallback_pool'   => $primary ? $primary : $order[0],
			'country_pools'   => (object) $countries,
			'region_pools'    => (object) $regions,
		);
		$list = $this->request( 'GET', $this->base() . '/zones/' . rawurlencode( $zone['id'] ) . '/load_balancers', $this->headers() );
		if ( ! $this->ok( $list ) ) {
			return $this->fail( $list, 'Cloudflare load balancers (is Load Balancing turned on for this account?)' );
		}
		$found = '';
		foreach ( isset( $list['json']['result'] ) ? (array) $list['json']['result'] : array() as $balancer ) {
			if ( strtolower( (string) $balancer['name'] ) === strtolower( $plan['host'] ) ) {
				$found = (string) $balancer['id'];
			}
		}
		$reply = '' !== $found
			? $this->request( 'PUT', $this->base() . '/zones/' . rawurlencode( $zone['id'] ) . '/load_balancers/' . rawurlencode( $found ), $this->headers(), $body )
			: $this->request( 'POST', $this->base() . '/zones/' . rawurlencode( $zone['id'] ) . '/load_balancers', $this->headers(), $body );
		if ( ! $this->ok( $reply ) ) {
			return $this->fail( $reply, 'Cloudflare load balancer' );
		}
		foreach ( $this->subs( $plan, $zone['zone'] ) as $host => $ip ) {
			$records = $this->request( 'GET', $this->base() . '/zones/' . rawurlencode( $zone['id'] ) . '/dns_records?type=A&name=' . rawurlencode( $host ), $this->headers() );
			if ( ! $this->ok( $records ) ) {
				return $this->fail( $records, 'Cloudflare records' );
			}
			$body   = array(
				'type'    => 'A',
				'name'    => $host,
				'content' => $ip,
				'ttl'     => $plan['ttl'],
				'proxied' => false,
				'comment' => 'Rafoo technical address',
			);
			$first  = isset( $records['json']['result'][0]['id'] ) ? (string) $records['json']['result'][0]['id'] : '';
			$result = '' !== $first
				? $this->request( 'PUT', $this->base() . '/zones/' . rawurlencode( $zone['id'] ) . '/dns_records/' . rawurlencode( $first ), $this->headers(), $body )
				: $this->request( 'POST', $this->base() . '/zones/' . rawurlencode( $zone['id'] ) . '/dns_records', $this->headers(), $body );
			if ( ! $this->ok( $result ) ) {
				return $this->fail( $result, 'Cloudflare ' . $host );
			}
		}
		return true;
	}
}
