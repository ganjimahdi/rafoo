<?php

namespace Rafoo\Peers;

use Rafoo\Http\Client;

defined( 'ABSPATH' ) || exit;

final class Checker {

	const TIMEOUT = 8;

	public static function run( array $targets ) {
		if ( empty( $targets ) ) {
			return array();
		}
		$requests = array();
		$nonces   = array();
		foreach ( $targets as $t ) {
			$id            = (int) $t['id'];
			$nonces[ $id ] = Keys::nonce();
			$requests[ $id ] = array(
				'url'     => apply_filters( 'rafoo_peers_fetch_url', self::url( $t['url'], $nonces[ $id ] ), $t ),
				'type'    => 'GET',
				'headers' => array(
					'User-Agent' => Client::user_agent(),
					'Accept'     => 'application/json',
					'Host'       => Keys::host( $t['url'] ),
				),
			);
		}
		$start = microtime( true );
		$took  = array();
		$options = array(
			'timeout'          => self::TIMEOUT,
			'connect_timeout'  => 6,
			'follow_redirects' => false,
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'complete'         => static function ( $response, $id ) use ( &$took, $start ) {
				$took[ $id ] = (int) round( ( microtime( true ) - $start ) * 1000 );
			},
		);
		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		} catch ( \Throwable $e ) {
			return self::sequential( $targets, $nonces );
		}
		$out = array();
		foreach ( $targets as $t ) {
			$id   = (int) $t['id'];
			$resp = isset( $responses[ $id ] ) ? $responses[ $id ] : null;
			$ms   = isset( $took[ $id ] ) ? $took[ $id ] : (int) round( ( microtime( true ) - $start ) * 1000 );
			if ( $resp instanceof \WpOrg\Requests\Response ) {
				$out[ $id ] = self::judge( $t, $nonces[ $id ], (int) $resp->status_code, (string) $resp->body, $ms );
			} else {
				$out[ $id ] = array(
					'ok'   => false,
					'ms'   => $ms,
					'code' => 0,
				);
			}
		}
		return self::filtering( $targets, $out );
	}

	public static function filtering( array $targets, array $out ) {
		foreach ( $targets as $t ) {
			$id = (int) $t['id'];
			if ( ! isset( $out[ $id ] ) || $out[ $id ]['ok'] || -2 === $out[ $id ]['code'] ) {
				continue;
			}
			$host = wp_parse_url( $t['url'], PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) && self::filtered_ips( (array) gethostbynamel( $host ) ) ) {
				$out[ $id ]['code'] = -2;
			}
		}
		return $out;
	}

	public static function filtered_ips( array $ips ) {
		foreach ( $ips as $ip ) {
			if ( 0 === strpos( (string) $ip, '10.10.34.' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function filter_page( $body ) {
		$body = strtolower( substr( (string) $body, 0, 4096 ) );
		return false !== strpos( $body, '10.10.34.3' ) || false !== strpos( $body, 'peyvandha.ir' ) || false !== strpos( $body, 'internet.ir/403' );
	}

	public static function judge( array $target, $nonce, $code, $body, $ms ) {
		$fail = array(
			'ok'   => false,
			'ms'   => (int) $ms,
			'code' => (int) $code,
		);
		if ( self::filter_page( $body ) ) {
			$fail['code'] = -2;
			return $fail;
		}
		if ( 200 !== (int) $code ) {
			return $fail;
		}
		$data = json_decode( substr( (string) $body, 0, 4096 ), true );
		if ( ! is_array( $data ) || 1 !== ( isset( $data['v'] ) ? (int) $data['v'] : 0 ) || ! isset( $data['n'], $data['t'], $data['s'] ) || $data['n'] !== $nonce ) {
			$fail['code'] = -1;
			return $fail;
		}
		$message = 'rafoo-ping|' . $nonce . '|' . (int) $data['t'] . '|' . Keys::host( $target['url'] );
		if ( abs( time() - (int) $data['t'] ) > 600 || ! Keys::verify( $message, $data['s'], $target['key'] ) ) {
			$fail['code'] = -1;
			return $fail;
		}
		return array(
			'ok'   => true,
			'ms'   => (int) $ms,
			'code' => 200,
		);
	}

	public static function url( $site, $nonce ) {
		return $site . ( false === strpos( $site, '?' ) ? '?' : '&' ) . 'rafoo-ping=' . rawurlencode( $nonce );
	}

	private static function sequential( array $targets, array $nonces ) {
		$out = array();
		foreach ( $targets as $t ) {
			$id    = (int) $t['id'];
			$start = microtime( true );
			$resp  = wp_remote_get(
				apply_filters( 'rafoo_peers_fetch_url', self::url( $t['url'], $nonces[ $id ] ), $t ),
				Client::args(
					array(
						'timeout'     => self::TIMEOUT,
						'redirection' => 0,
						'rafoo_probe' => true,
					)
				)
			);
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			if ( is_wp_error( $resp ) ) {
				$out[ $id ] = array(
					'ok'   => false,
					'ms'   => $ms,
					'code' => 0,
				);
				continue;
			}
			$out[ $id ] = self::judge( $t, $nonces[ $id ], (int) wp_remote_retrieve_response_code( $resp ), (string) wp_remote_retrieve_body( $resp ), $ms );
		}
		return self::filtering( $targets, $out );
	}
}
