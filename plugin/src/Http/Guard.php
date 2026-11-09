<?php

namespace Rafoo\Http;

use Rafoo\Drill\Session;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Guard {

	const QUIET_HOSTS = array( 'api.wordpress.org', 'downloads.wordpress.org' );

	const TRIAL_TIMEOUT = 3;

	public static function args( $args, $url ) {
		if ( ! is_array( $args ) || ! empty( $args['rafoo_probe'] ) || ! State::offline() ) {
			return $args;
		}
		$host = Hosts::of( $url );
		if ( '' === $host || Hosts::allowed( $host ) || Breaker::reachable( $host ) ) {
			return $args;
		}
		$args['timeout'] = min( (float) ( isset( $args['timeout'] ) ? $args['timeout'] : 5 ), self::TRIAL_TIMEOUT );
		return $args;
	}

	public static function filter( $pre, $args, $url ) {
		if ( false !== $pre || ! empty( $args['rafoo_probe'] ) ) {
			return $pre;
		}
		$host = Hosts::of( $url );
		if ( '' === $host || Hosts::local( $host ) ) {
			return $pre;
		}
		if ( Hosts::blocked( $url ) ) {
			Log::record( $host, 'refused' );
			return new \WP_Error( 'rafoo_blocked', self::message( 'rafoo_blocked', $host ) );
		}
		$offline = State::offline();
		if ( $offline && ! Hosts::allowed( $host ) && Hosts::foreign( $host ) ) {
			Log::record( $host, 'refused' );
			return self::refuse( $host, 'rafoo_offline' );
		}
		if ( Breaker::open( $host ) ) {
			Log::record( $host, 'refused' );
			return self::refuse( $host, $offline ? 'rafoo_offline' : 'rafoo_unreachable' );
		}
		return $pre;
	}

	public static function observe( $response, $context, $transport, $args, $url ) {
		if ( 'response' !== $context || ! empty( $args['rafoo_probe'] ) ) {
			return;
		}
		$host = Hosts::of( $url );
		if ( '' === $host || Hosts::local( $host ) ) {
			return;
		}
		if ( Session::active() ) {
			if ( ! self::refused( $response ) ) {
				Session::request( $host, is_wp_error( $response ) ? 'failed' : 'ok', $args );
			}
			return;
		}
		Log::record( $host, is_wp_error( $response ) ? 'failed' : 'ok', $args );
		$offline = State::offline() && ! Hosts::allowed( $host );
		if ( Breaker::transient_failure( $response ) ) {
			Breaker::failed( $host, $offline );
		} elseif ( ! is_wp_error( $response ) ) {
			Breaker::succeeded( $host, $offline );
		}
	}

	private static function refused( $response ) {
		if ( is_wp_error( $response ) ) {
			return in_array( $response->get_error_code(), array( 'rafoo_offline', 'rafoo_blocked', 'rafoo_unreachable' ), true );
		}
		return is_array( $response ) && ! empty( $response['headers']['x-rafoo'] );
	}

	private static function refuse( $host, $code ) {
		if ( in_array( $host, self::QUIET_HOSTS, true ) ) {
			return array(
				'headers'  => array( 'x-rafoo' => $code ),
				'body'     => '',
				'response' => array(
					'code'    => 503,
					'message' => 'Service Unavailable',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return new \WP_Error( $code, self::message( $code, $host ) );
	}

	private static function message( $code, $host ) {
		if ( 'rafoo_blocked' === $code ) {
			return did_action( 'init' )
				/* translators: %s: host name */
				? sprintf( __( 'Rafoo blocked this request to %s because it is on the block list.', 'rafoo' ), $host )
				: sprintf( 'Rafoo blocked this request to %s because it is on the block list.', $host );
		}
		if ( ! did_action( 'init' ) ) {
			return 'rafoo_offline' === $code
				? sprintf( 'The international internet is unavailable. Rafoo skipped this request to %s.', $host )
				: sprintf( 'Rafoo skipped this request because %s did not respond recently.', $host );
		}
		return 'rafoo_offline' === $code
			/* translators: %s: host name */
			? sprintf( __( 'The international internet is unavailable. Rafoo skipped this request to %s.', 'rafoo' ), $host )
			/* translators: %s: host name */
			: sprintf( __( 'Rafoo skipped this request because %s did not respond recently.', 'rafoo' ), $host );
	}
}
