<?php

namespace Rafoo\Peers;

use Rafoo\Http\Client;

defined( 'ABSPATH' ) || exit;

final class Api {

	const ENDPOINT = 'https://rafoo.ir/api/v1/peers/';
	const PATH     = '/api/v1/peers/';

	public static function call( $action, array $body = array(), $timeout = 8, $background = false, $scope = 'peers' ) {
		$scope = 'shield' === $scope ? 'shield' : 'peers';
		$path  = '/api/v1/' . $scope . '/';
		$state = Keys::data();
		if ( $background && ! empty( $state['backoff'] ) && time() < (int) $state['backoff'] ) {
			return new \WP_Error( 'rafoo_peer_backoff', __( 'rafoo.ir asked to wait a little. Rafoo will try again soon.', 'rafoo' ) );
		}
		$raw = wp_json_encode( $body ? $body : new \stdClass() );
		if ( false === $raw ) {
			return new \WP_Error( 'rafoo_peer_json', __( 'The request could not be prepared.', 'rafoo' ) );
		}
		$time  = (string) time();
		$nonce = Keys::nonce();
		$sig   = Keys::sign( 'rafoo-peer|' . $path . $action . '|' . $time . '|' . $nonce . '|' . hash( 'sha256', $raw ) );
		if ( '' === $sig ) {
			return new \WP_Error( 'rafoo_peer_key', __( 'This site has no key for the network. Join again.', 'rafoo' ) );
		}
		$response = wp_remote_post(
			str_replace( self::PATH, $path, (string) apply_filters( 'rafoo_peers_endpoint', self::ENDPOINT ) ) . $action,
			Client::args(
				array(
					'timeout'     => $timeout,
					'redirection' => 0,
					'body'        => $raw,
					'rafoo_probe' => true,
					'headers'     => array(
						'Content-Type'  => 'application/json',
						'X-Rafoo-Key'   => Keys::public_key(),
						'X-Rafoo-Time'  => $time,
						'X-Rafoo-Nonce' => $nonce,
						'X-Rafoo-Sig'   => $sig,
					),
				)
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$text = (string) wp_remote_retrieve_body( $response );
		if ( 429 === $code || 503 === $code ) {
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			Keys::update( array( 'backoff' => time() + min( 3600, max( 60, $wait ) ) + wp_rand( 0, 30 ) ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$data    = json_decode( $text, true );
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'rafoo.ir did not accept the request.', 'rafoo' );
			$kind    = is_array( $data ) && ! empty( $data['error'] ) ? sanitize_key( $data['error'] ) : 'http_' . $code;
			return new \WP_Error( 'rafoo_peer_' . $kind, $message, array( 'status' => $code ) );
		}
		return array(
			'code' => $code,
			'body' => $text,
		);
	}
}
