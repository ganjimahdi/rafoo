<?php

namespace Rafoo\Connectivity;

use Rafoo\Data;
use Rafoo\Http\Client;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Monitor {

	const HOOK = 'rafoo_check';

	const LOCK = 'rafoo_check_lock';

	const TIMEOUT = 4;

	public static function external_targets() {
		$probes = Data::get( 'probes' );
		return (array) apply_filters( 'rafoo_external_targets', isset( $probes['external'] ) ? $probes['external'] : array() );
	}

	public static function domestic_targets() {
		$targets = array(
			'rafoo' => array(
				'url'    => Signal::URL,
				'method' => 'GET',
			),
		);
		$bases   = array_unique( array_merge( Settings::get( 'npm_mirrors' ), Settings::get( 'wp_mirrors' ) ) );
		foreach ( $bases as $base ) {
			$host = wp_parse_url( $base, PHP_URL_HOST );
			if ( $host ) {
				$targets[ $host ] = array(
					'url'    => $base . '/',
					'method' => 'HEAD',
				);
			}
		}
		return (array) apply_filters( 'rafoo_domestic_targets', $targets );
	}

	public static function run( $force = false ) {
		if ( ! $force && get_site_transient( self::LOCK ) ) {
			return State::all();
		}
		set_site_transient( self::LOCK, 1, 45 );

		$external = self::external_targets();
		$domestic = self::domestic_targets();
		$requests = array();
		foreach ( $external as $id => $target ) {
			$requests[ 'x:' . $id ] = $target;
		}
		foreach ( $domestic as $id => $target ) {
			$requests[ 'd:' . $id ] = $target;
		}

		$started   = microtime( true );
		$responses = Client::multi( $requests, self::TIMEOUT );
		$elapsed   = (int) round( ( microtime( true ) - $started ) * 1000 );

		$external_up = 0;
		$domestic_ok = false;
		$results     = array();
		$hint        = array();
		foreach ( $requests as $id => $target ) {
			$code      = isset( $responses[ $id ] ) ? $responses[ $id ]['code'] : 0;
			$ok        = $code > 0;
			$results[] = array(
				'id'    => substr( $id, 2 ),
				'group' => 'x' === $id[0] ? 'external' : 'domestic',
				'ok'    => $ok,
				'code'  => $code,
			);
			if ( ! $ok ) {
				continue;
			}
			if ( 'x' === $id[0] ) {
				++$external_up;
			} else {
				$domestic_ok = true;
			}
			if ( 'd:rafoo' === $id && 200 === $code ) {
				$hint = Signal::parse( $responses[ $id ]['body'] );
			}
		}

		$before     = State::offline();
		$was_local  = ! empty( State::all()['local_down'] );
		$next       = Hysteresis::next( State::all(), $external_up, $domestic_ok, $hint, time() );
		$next       = array_merge(
			$next,
			array(
				'targets' => $results,
				'hint'    => $hint,
				'took_ms' => $elapsed,
			)
		);
		$previously = State::all()['detected'];
		State::save( $next );

		if ( $previously !== $next['detected'] && 'unknown' !== $previously ) {
			State::event( 'offline' === $next['detected'] ? 'outage_started' : 'outage_ended' );
		}
		if ( $next['local_down'] && ! $was_local ) {
			State::event( 'server_network_down' );
		}
		if ( $before !== State::offline() ) {
			do_action( 'rafoo_mode_changed', State::offline() );
		}

		return State::all();
	}
}
