<?php

namespace Rafoo\Safety;

defined( 'ABSPATH' ) || exit;

final class Health {

	const FATAL = '#<b>\s*(?:Fatal|Parse) error\s*</b>\s*:|PHP (?:Fatal|Parse) error:|id=["\']error-page["\']#i';

	const MAINTENANCE = 'Briefly unavailable for scheduled maintenance';

	public static function targets() {
		$targets = array(
			'home'  => add_query_arg( 'rafoo-health', wp_rand(), home_url( '/' ) ),
			'login' => add_query_arg( 'rafoo-health', wp_rand(), wp_login_url() ),
		);
		if ( self::cookies() ) {
			$targets['admin'] = admin_url( 'index.php' );
		}
		if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			$targets['shop'] = add_query_arg( 'rafoo-health', wp_rand(), get_permalink( wc_get_page_id( 'shop' ) ) );
		}
		return (array) apply_filters( 'rafoo_health_targets', $targets );
	}

	public static function probe() {
		$results = array();
		$cookies = self::cookies();
		foreach ( self::targets() as $id => $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
					'cookies'     => 'admin' === $id ? $cookies : array(),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
					'rafoo_probe' => true,
				)
			);
			$results[ $id ] = self::judge( $response );
		}
		return $results;
	}

	public static function judge( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'state' => 'unknown',
				'code'  => 0,
				'why'   => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 503 === $code && false !== strpos( $body, self::MAINTENANCE ) ) {
			return array(
				'state' => 'unknown',
				'code'  => $code,
				'why'   => 'maintenance',
			);
		}
		if ( $code >= 500 || preg_match( self::FATAL, $body ) ) {
			return array(
				'state' => 'broken',
				'code'  => $code,
				'why'   => self::reason( $body, $code ),
			);
		}
		return array(
			'state' => 'ok',
			'code'  => $code,
			'why'   => '',
		);
	}

	public static function healthy( array $results ) {
		foreach ( $results as $result ) {
			if ( 'broken' === $result['state'] ) {
				return false;
			}
		}
		return true;
	}

	public static function summary( array $results ) {
		foreach ( $results as $id => $result ) {
			if ( 'broken' === $result['state'] ) {
				return $id . ': ' . $result['why'];
			}
		}
		return '';
	}

	private static function reason( $body, $code ) {
		if ( preg_match( '#(?:Fatal|Parse) error\s*(?:</b>)?\s*:\s*(.{0,300}?)(?:<br|\n|$)#is', $body, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return 'HTTP ' . $code;
	}

	private static function cookies() {
		return \Rafoo\Assets\Loopback::cookies();
	}
}
