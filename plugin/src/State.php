<?php

namespace Rafoo;

use Rafoo\Connectivity\Hysteresis;

defined( 'ABSPATH' ) || exit;

final class State {

	const OPTION = 'rafoo_state';

	const EVENTS = 'rafoo_events';

	const EVENTS_MAX = 100;

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_site_option( self::OPTION, array() );
			self::$cache = array_merge( Hysteresis::initial(), self::extras(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	public static function save( array $state ) {
		self::$cache = array_merge( self::all(), $state );
		update_site_option( self::OPTION, self::$cache );
		return self::$cache;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function offline() {
		return (bool) apply_filters( 'rafoo_offline', Hysteresis::offline( self::all() ) );
	}

	public static function set_override( $mode ) {
		if ( ! in_array( $mode, array( 'auto', 'offline', 'online' ), true ) ) {
			return self::all();
		}
		$before = self::offline();
		$state  = self::save( array( 'override' => $mode ) );
		self::event( 'override', array( 'mode' => $mode ) );
		if ( $before !== self::offline() ) {
			do_action( 'rafoo_mode_changed', self::offline() );
		}
		return $state;
	}

	public static function event( $type, array $data = array() ) {
		$events   = get_site_option( self::EVENTS, array() );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'type' => sanitize_key( $type ),
			'at'   => time(),
			'data' => $data,
		);
		if ( count( $events ) > self::EVENTS_MAX ) {
			$events = array_slice( $events, -self::EVENTS_MAX );
		}
		update_site_option( self::EVENTS, $events );
	}

	public static function events( $limit = 30 ) {
		$events = get_site_option( self::EVENTS, array() );
		return is_array( $events ) ? array_reverse( array_slice( $events, -absint( $limit ) ) ) : array();
	}

	private static function extras() {
		return array(
			'targets' => array(),
			'hint'    => array(),
		);
	}
}
