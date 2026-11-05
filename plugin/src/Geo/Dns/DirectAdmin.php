<?php

namespace Rafoo\Geo\Dns;

use Rafoo\Geo\Planner;

defined( 'ABSPATH' ) || exit;

final class DirectAdmin extends Cpanel {

	const NAME = 'directadmin';

	const PORT = 2222;

	private function call( array $creds, array $query ) {
		$root  = (string) apply_filters( 'rafoo_geo_api_base', $this->account( $creds ), static::NAME );
		$reply = $this->request(
			'POST',
			untrailingslashit( $root ) . '/CMD_API_DNS_CONTROL?json=yes',
			array(
				'Authorization' => 'Basic ' . base64_encode( (string) $creds['user'] . ':' . trim( (string) $creds['token'] ) ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			http_build_query( $query, '', '&' ),
			30
		);
		if ( ! $this->ok( $reply ) ) {
			return $this->fail( $reply, 'DirectAdmin' );
		}
		if ( is_array( $reply['json'] ) && ! empty( $reply['json']['error'] ) && '0' !== (string) $reply['json']['error'] ) {
			return new \WP_Error( 'rafoo_geo_dns', 'DirectAdmin: ' . ( isset( $reply['json']['details'] ) ? (string) $reply['json']['details'] : (string) $reply['json']['text'] ) );
		}
		return is_array( $reply['json'] ) ? $reply['json'] : array();
	}

	private static function records( array $data ) {
		$out = array();
		foreach ( array( 'records', 'record' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				foreach ( (array) $data[ $key ] as $record ) {
					if ( is_array( $record ) && isset( $record['type'], $record['name'], $record['value'] ) ) {
						$out[] = $record;
					}
				}
			}
		}
		return $out;
	}

	private static function full( $name, $zone ) {
		$name = strtolower( rtrim( (string) $name, '.' ) );
		if ( '' === $name || '@' === $name || $name === $zone ) {
			return $zone;
		}
		return substr( $name, -strlen( '.' . $zone ) ) === '.' . $zone ? $name : $name . '.' . $zone;
	}

	public function check( $host, array $creds = array() ) {
		$creds = $creds ? $creds : (array) reset( $this->secrets );
		if ( '' === $this->account( $creds ) || empty( $creds['user'] ) || empty( $creds['token'] ) ) {
			return new \WP_Error( 'rafoo_geo_creds', 'DirectAdmin: address, username and login key are needed' );
		}
		$last = null;
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->call( $creds, array( 'domain' => $zone ) );
			if ( is_wp_error( $reply ) ) {
				$last = $reply;
				continue;
			}
			$records = self::records( $reply );
			if ( ! $records ) {
				continue;
			}
			$servers = array();
			foreach ( $records as $record ) {
				if ( 'NS' === strtoupper( (string) $record['type'] ) && self::full( $record['name'], $zone ) === $zone ) {
					$servers[] = rtrim( (string) $record['value'], '.' );
				}
			}
			return array(
				'zone'        => $zone,
				'nameservers' => $servers,
				'records'     => $records,
			);
		}
		return $last ? $last : new \WP_Error( 'rafoo_geo_zone', 'DirectAdmin: zone not found' );
	}

	protected function apply_node( array $plan, array $node, array $creds ) {
		$zone = $this->check( $plan['host'], $creds );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$wanted = array_merge( array( $plan['host'] => $node['ip'] ), $this->subs( $plan, $zone['zone'] ) );
		foreach ( $wanted as $host => $ip ) {
			$has   = false;
			$clean = array();
			foreach ( $zone['records'] as $record ) {
				if ( 'A' !== strtoupper( (string) $record['type'] ) || self::full( $record['name'], $zone['zone'] ) !== strtolower( $host ) ) {
					continue;
				}
				if ( (string) $record['value'] === $ip && ! $has ) {
					$has = true;
					continue;
				}
				$clean[] = 'name=' . $record['name'] . '&value=' . $record['value'];
			}
			if ( $clean ) {
				$query = array(
					'domain' => $zone['zone'],
					'action' => 'select',
					'delete' => 'yes',
				);
				foreach ( $clean as $index => $selector ) {
					$query[ 'arecs' . $index ] = $selector;
				}
				$reply = $this->call( $creds, $query );
				if ( is_wp_error( $reply ) ) {
					return $reply;
				}
			}
			if ( ! $has ) {
				$relative = Planner::relative( $host, $zone['zone'] );
				$reply    = $this->call(
					$creds,
					array(
						'domain' => $zone['zone'],
						'action' => 'add',
						'type'   => 'A',
						'name'   => '@' === $relative ? $zone['zone'] . '.' : $relative,
						'value'  => $ip,
						'ttl'    => $plan['ttl'],
					)
				);
				if ( is_wp_error( $reply ) ) {
					return $reply;
				}
			}
		}
		return true;
	}
}
