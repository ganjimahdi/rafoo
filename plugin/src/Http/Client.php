<?php

namespace Rafoo\Http;

use WpOrg\Requests\Requests;

defined( 'ABSPATH' ) || exit;

final class Client {

	public static function user_agent() {
		return 'Rafoo/' . RAFOO_VERSION . ' (WordPress; ' . RAFOO_EDITION . ')';
	}

	public static function args( array $extra = array() ) {
		return array_merge(
			array(
				'timeout'     => 10,
				'redirection' => 2,
				'user-agent'  => self::user_agent(),
				'sslverify'   => true,
			),
			$extra
		);
	}

	public static function get( $url, array $extra = array() ) {
		return wp_safe_remote_get( $url, self::args( $extra ) );
	}

	public static function body( $url, array $extra = array(), $max_bytes = 0 ) {
		$response = self::get( $url, $extra );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'rafoo_http_' . $code, sprintf( 'HTTP %d', $code ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $max_bytes > 0 && strlen( $body ) > $max_bytes ) {
			return new \WP_Error( 'rafoo_too_large', 'Response too large' );
		}
		return $body;
	}

	public static function multi( array $requests, $timeout ) {
		if ( ! class_exists( Requests::class ) ) {
			return self::sequential( $requests, $timeout );
		}
		$options = array(
			'timeout'          => $timeout,
			'connect_timeout'  => $timeout,
			'useragent'        => self::user_agent(),
			'follow_redirects' => false,
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		);
		$proxy = self::proxy();
		if ( $proxy ) {
			$options['proxy'] = $proxy;
		}
		$batch = array();
		foreach ( $requests as $id => $request ) {
			$batch[ $id ] = array(
				'url'     => $request['url'],
				'type'    => isset( $request['method'] ) ? $request['method'] : 'GET',
				'headers' => array(),
				'data'    => array(),
				'options' => $options,
			);
		}
		$out = array();
		try {
			$responses = Requests::request_multiple( $batch, $options );
		} catch ( \Throwable $e ) {
			return self::sequential( $requests, $timeout );
		}
		foreach ( $requests as $id => $request ) {
			$response   = isset( $responses[ $id ] ) ? $responses[ $id ] : null;
			$out[ $id ] = is_object( $response ) && isset( $response->status_code ) && ! ( $response instanceof \Throwable )
				? array(
					'code' => (int) $response->status_code,
					'body' => (string) $response->body,
				)
				: array(
					'code' => 0,
					'body' => '',
				);
		}
		return $out;
	}

	private static function sequential( array $requests, $timeout ) {
		$out = array();
		foreach ( $requests as $id => $request ) {
			$response   = wp_remote_request(
				$request['url'],
				self::args(
					array(
						'method'      => isset( $request['method'] ) ? $request['method'] : 'GET',
						'timeout'     => $timeout,
						'redirection' => 0,
						'rafoo_probe' => true,
					)
				)
			);
			$out[ $id ] = is_wp_error( $response )
				? array(
					'code' => 0,
					'body' => '',
				)
				: array(
					'code' => (int) wp_remote_retrieve_response_code( $response ),
					'body' => (string) wp_remote_retrieve_body( $response ),
				);
		}
		return $out;
	}

	private static function proxy() {
		if ( ! class_exists( '\WP_HTTP_Proxy' ) ) {
			return null;
		}
		$proxy = new \WP_HTTP_Proxy();
		if ( ! $proxy->is_enabled() || ! class_exists( '\WpOrg\Requests\Proxy\Http' ) ) {
			return null;
		}
		$address = $proxy->host() . ':' . $proxy->port();
		if ( $proxy->use_authentication() ) {
			return new \WpOrg\Requests\Proxy\Http( array( $address, $proxy->username(), $proxy->password() ) );
		}
		return new \WpOrg\Requests\Proxy\Http( $address );
	}
}
