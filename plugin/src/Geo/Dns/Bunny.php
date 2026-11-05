<?php

namespace Rafoo\Geo\Dns;

use Rafoo\Geo\Planner;

defined( 'ABSPATH' ) || exit;

final class Bunny extends Driver {

	const NAME = 'bunny';

	const BASE = 'https://api.bunny.net';

	private function headers() {
		return array( 'AccessKey' => trim( (string) ( isset( $this->secrets['key'] ) ? $this->secrets['key'] : '' ) ) );
	}

	public function check( $host ) {
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->request( 'GET', $this->base() . '/dnszone?search=' . rawurlencode( $zone ), $this->headers() );
			if ( ! $this->ok( $reply ) ) {
				return $this->fail( $reply, 'Bunny DNS' );
			}
			foreach ( isset( $reply['json']['Items'] ) ? (array) $reply['json']['Items'] : array() as $item ) {
				if ( isset( $item['Domain'] ) && strtolower( (string) $item['Domain'] ) === $zone ) {
					return array(
						'zone'        => $zone,
						'id'          => (int) $item['Id'],
						'nameservers' => array_values( array_filter( array( isset( $item['Nameserver1'] ) ? (string) $item['Nameserver1'] : '', isset( $item['Nameserver2'] ) ? (string) $item['Nameserver2'] : '' ) ) ),
					);
				}
			}
		}
		return new \WP_Error( 'rafoo_geo_zone', 'Bunny DNS: zone not found for this key' );
	}

	public function apply( array $plan ) {
		$zone = $this->check( $plan['host'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$details = $this->request( 'GET', $this->base() . '/dnszone/' . (int) $zone['id'], $this->headers() );
		if ( ! $this->ok( $details ) ) {
			return $this->fail( $details, 'Bunny DNS zone' );
		}
		$main    = Planner::relative( $plan['host'], $zone['zone'] );
		$main    = '@' === $main ? '' : $main;
		$records = isset( $details['json']['Records'] ) ? (array) $details['json']['Records'] : array();
		$mine    = array();
		$plain   = array();
		foreach ( $records as $record ) {
			if ( 0 !== (int) $record['Type'] ) {
				continue;
			}
			$comment = isset( $record['Comment'] ) ? (string) $record['Comment'] : '';
			if ( 0 === strpos( $comment, 'rafoo:' ) ) {
				$mine[ $comment ] = $record;
			} elseif ( strtolower( (string) $record['Name'] ) === $main ) {
				$plain[] = $record;
			}
		}
		$wanted = array();
		foreach ( self::up( $plan ) as $id => $node ) {
			$wanted[ 'rafoo:' . $id ] = array(
				'Type'                 => 0,
				'Name'                 => $main,
				'Value'                => $node['ip'],
				'Ttl'                  => $plan['ttl'],
				'SmartRoutingType'     => 2,
				'GeolocationLatitude'  => $node['lat'],
				'GeolocationLongitude' => $node['lon'],
				'Comment'              => 'rafoo:' . $id,
			);
		}
		foreach ( $this->subs( $plan, $zone['zone'] ) as $host => $ip ) {
			$name                         = Planner::relative( $host, $zone['zone'] );
			$wanted[ 'rafoo:sub:' . $name ] = array(
				'Type'    => 0,
				'Name'    => $name,
				'Value'   => $ip,
				'Ttl'     => $plan['ttl'],
				'Comment' => 'rafoo:sub:' . $name,
			);
		}
		foreach ( $wanted as $comment => $body ) {
			$reply = isset( $mine[ $comment ] )
				? $this->request( 'POST', $this->base() . '/dnszone/' . (int) $zone['id'] . '/records/' . (int) $mine[ $comment ]['Id'], $this->headers(), $body )
				: $this->request( 'PUT', $this->base() . '/dnszone/' . (int) $zone['id'] . '/records', $this->headers(), $body );
			if ( ! $this->ok( $reply ) ) {
				return $this->fail( $reply, 'Bunny DNS ' . $comment );
			}
		}
		foreach ( $mine as $comment => $record ) {
			if ( ! isset( $wanted[ $comment ] ) ) {
				$this->request( 'DELETE', $this->base() . '/dnszone/' . (int) $zone['id'] . '/records/' . (int) $record['Id'], $this->headers() );
			}
		}
		foreach ( $plain as $record ) {
			$this->request( 'DELETE', $this->base() . '/dnszone/' . (int) $zone['id'] . '/records/' . (int) $record['Id'], $this->headers() );
		}
		return true;
	}
}
