<?php

namespace Rafoo\Geo\Dns;

use Rafoo\Geo\Planner;

defined( 'ABSPATH' ) || exit;

class Cpanel extends Driver {

	const NAME = 'cpanel';

	const PORT = 2083;

	protected function account( array $creds ) {
		$url = trim( (string) ( isset( $creds['url'] ) ? $creds['url'] : '' ) );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts = wp_parse_url( $url );
		return empty( $parts['host'] ) ? '' : $parts['scheme'] . '://' . $parts['host'] . ':' . ( isset( $parts['port'] ) ? (int) $parts['port'] : static::PORT );
	}

	protected function base() {
		return '';
	}

	private function uapi( array $creds, $function, array $query ) {
		$parts = array();
		foreach ( $query as $key => $value ) {
			foreach ( (array) $value as $item ) {
				$parts[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $item );
			}
		}
		$root  = (string) apply_filters( 'rafoo_geo_api_base', $this->account( $creds ), static::NAME );
		$reply = $this->request(
			'POST',
			untrailingslashit( $root ) . '/execute/DNS/' . $function,
			array(
				'Authorization' => 'cpanel ' . (string) $creds['user'] . ':' . trim( (string) $creds['token'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			implode( '&', $parts ),
			30
		);
		if ( ! $this->ok( $reply ) ) {
			return $this->fail( $reply, 'cPanel ' . $function );
		}
		if ( empty( $reply['json']['status'] ) ) {
			$errors = isset( $reply['json']['errors'] ) ? implode( '; ', (array) $reply['json']['errors'] ) : 'refused';
			return new \WP_Error( 'rafoo_geo_dns', 'cPanel ' . $function . ': ' . $errors );
		}
		return $reply['json'];
	}

	public function check( $host, array $creds = array() ) {
		$creds = $creds ? $creds : (array) reset( $this->secrets );
		if ( '' === $this->account( $creds ) || empty( $creds['user'] ) || empty( $creds['token'] ) ) {
			return new \WP_Error( 'rafoo_geo_creds', 'cPanel: address, username and API token are needed' );
		}
		$last = null;
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->uapi( $creds, 'parse_zone', array( 'zone' => $zone ) );
			if ( ! is_wp_error( $reply ) ) {
				$servers = array();
				foreach ( (array) $reply['data'] as $record ) {
					if ( isset( $record['record_type'] ) && 'NS' === $record['record_type'] && self::name( $record ) === $zone ) {
						$servers[] = rtrim( (string) base64_decode( (string) $record['data_b64'][0] ), '.' );
					}
				}
				return array(
					'zone'        => $zone,
					'nameservers' => $servers,
					'records'     => (array) $reply['data'],
				);
			}
			$last = $reply;
		}
		return $last ? $last : new \WP_Error( 'rafoo_geo_zone', 'cPanel: zone not found' );
	}

	private static function name( array $record ) {
		return rtrim( strtolower( (string) base64_decode( isset( $record['dname_b64'] ) ? (string) $record['dname_b64'] : '' ) ), '.' );
	}

	private static function serial( array $records ) {
		foreach ( $records as $record ) {
			if ( isset( $record['record_type'] ) && 'SOA' === $record['record_type'] && isset( $record['data_b64'][2] ) ) {
				return (string) base64_decode( (string) $record['data_b64'][2] );
			}
		}
		return '';
	}

	public function apply( array $plan ) {
		$done = 0;
		foreach ( $plan['nodes'] as $id => $node ) {
			if ( empty( $this->secrets[ $id ] ) ) {
				continue;
			}
			$result = $this->apply_node( $plan, $node, (array) $this->secrets[ $id ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			++$done;
		}
		return $done ? true : new \WP_Error( 'rafoo_geo_creds', 'cPanel: no host has a login' );
	}

	protected function apply_node( array $plan, array $node, array $creds ) {
		$zone = $this->check( $plan['host'], $creds );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$wanted = array_merge( array( $plan['host'] => $node['ip'] ), $this->subs( $plan, $zone['zone'] ) );
		$add    = array();
		$edit   = array();
		$remove = array();
		foreach ( $wanted as $host => $ip ) {
			$found = false;
			foreach ( $zone['records'] as $record ) {
				if ( ! isset( $record['record_type'] ) || 'A' !== $record['record_type'] || self::name( $record ) !== strtolower( $host ) ) {
					continue;
				}
				if ( ! $found ) {
					$found  = true;
					$edit[] = wp_json_encode(
						array(
							'line_index'  => (int) $record['line_index'],
							'dname'       => Planner::relative( $host, $zone['zone'] ),
							'ttl'         => $plan['ttl'],
							'record_type' => 'A',
							'data'        => array( $ip ),
						)
					);
				} else {
					$remove[] = (int) $record['line_index'];
				}
			}
			if ( ! $found ) {
				$add[] = wp_json_encode(
					array(
						'dname'       => Planner::relative( $host, $zone['zone'] ),
						'ttl'         => $plan['ttl'],
						'record_type' => 'A',
						'data'        => array( $ip ),
					)
				);
			}
		}
		$reply = $this->uapi(
			$creds,
			'mass_edit_zone',
			array(
				'zone'   => $zone['zone'],
				'serial' => self::serial( $zone['records'] ),
				'add'    => $add,
				'edit'   => $edit,
				'remove' => $remove,
			)
		);
		return is_wp_error( $reply ) ? $reply : true;
	}
}
