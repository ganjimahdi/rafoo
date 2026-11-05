<?php

namespace Rafoo\Geo\Dns;

use Rafoo\Geo\Planner;
use Rafoo\Http\Client;

defined( 'ABSPATH' ) || exit;

abstract class Driver {

	const NAME = '';

	const BASE = '';

	protected $secrets;

	public function __construct( array $secrets ) {
		$this->secrets = $secrets;
	}

	abstract public function check( $host );

	abstract public function apply( array $plan );

	protected function base() {
		return untrailingslashit( (string) apply_filters( 'rafoo_geo_api_base', static::BASE, static::NAME ) );
	}

	protected function request( $method, $url, array $headers = array(), $body = null, $timeout = 25 ) {
		if ( is_array( $body ) ) {
			$body                    = wp_json_encode( $body );
			$headers['Content-Type'] = 'application/json';
		}
		$reply = wp_remote_request(
			$url,
			Client::args(
				array(
					'method'      => $method,
					'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
					'body'        => $body,
					'timeout'     => $timeout,
					'redirection' => 0,
					'rafoo_probe' => true,
				)
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$raw  = (string) wp_remote_retrieve_body( $reply );
		$json = json_decode( $raw, true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $reply ),
			'json' => is_array( $json ) ? $json : null,
			'raw'  => $raw,
		);
	}

	protected function fail( $reply, $what ) {
		if ( is_wp_error( $reply ) ) {
			return new \WP_Error( 'rafoo_geo_dns', $what . ': ' . $reply->get_error_message() );
		}
		$detail = '';
		if ( is_array( $reply['json'] ) ) {
			foreach ( array( 'message', 'error', 'Message', 'errors' ) as $key ) {
				if ( isset( $reply['json'][ $key ] ) ) {
					$detail = is_string( $reply['json'][ $key ] ) ? $reply['json'][ $key ] : wp_json_encode( $reply['json'][ $key ] );
					break;
				}
			}
		}
		if ( '' === $detail ) {
			$detail = substr( wp_strip_all_tags( (string) $reply['raw'] ), 0, 200 );
		}
		return new \WP_Error( 'rafoo_geo_dns', $what . ': HTTP ' . (int) $reply['code'] . ( '' !== $detail ? ' ' . $detail : '' ) );
	}

	protected function ok( $reply ) {
		return ! is_wp_error( $reply ) && $reply['code'] >= 200 && $reply['code'] < 300;
	}

	protected function inside( $host, $zone ) {
		$host = strtolower( $host );
		$zone = strtolower( $zone );
		return $host === $zone || substr( $host, -strlen( '.' . $zone ) ) === '.' . $zone;
	}

	protected function subs( array $plan, $zone ) {
		$out = array();
		foreach ( $plan['subs'] as $host => $ip ) {
			if ( $this->inside( $host, $zone ) && strtolower( $host ) !== strtolower( $plan['host'] ) ) {
				$out[ $host ] = $ip;
			}
		}
		return $out;
	}

	protected function candidates( $host ) {
		return Planner::zone_candidates( $host );
	}

	public static function up( array $plan ) {
		return array_filter(
			$plan['nodes'],
			static function ( $node ) {
				return $node['up'];
			}
		);
	}
}
