<?php

namespace Rafoo\Http;

defined( 'ABSPATH' ) || exit;

final class Breaker {

	const OPTION = 'rafoo_breaker';

	const WINDOW = 120;

	const FAILURES = 2;

	const COOLDOWN = 180;

	const OUTAGE_MEMORY = 1800;

	const MAX_HOSTS = 200;

	private static $cache = null;

	public static function open( $host, $now = null ) {
		$now     = null === $now ? time() : $now;
		$entries = self::entries();
		return isset( $entries[ $host ]['until'] ) && $entries[ $host ]['until'] > $now;
	}

	public static function reachable( $host, $now = null ) {
		$now     = null === $now ? time() : $now;
		$entries = self::entries();
		return isset( $entries[ $host ]['ok'] ) && $entries[ $host ]['ok'] > $now;
	}

	public static function failed( $host, $offline = false, $now = null ) {
		$now     = null === $now ? time() : $now;
		$entries = self::prune( self::entries(), $now );
		$entry   = self::entry( $entries, $host );

		if ( $offline ) {
			$entry['until'] = $now + self::OUTAGE_MEMORY;
			$entry['ok']    = 0;
			$entry['fails'] = array();
		} else {
			$entry['fails']   = self::recent( $entry['fails'], $now );
			$entry['fails'][] = $now;
			if ( count( $entry['fails'] ) >= self::FAILURES ) {
				$entry['until'] = $now + self::COOLDOWN;
				$entry['fails'] = array();
			}
		}
		$entries[ $host ] = $entry;
		self::store( $entries );
	}

	public static function succeeded( $host, $offline = false, $now = null ) {
		$now     = null === $now ? time() : $now;
		$entries = self::entries();
		if ( $offline ) {
			if ( isset( $entries[ $host ]['ok'] ) && $entries[ $host ]['ok'] > $now + 600 ) {
				return;
			}
			$entries          = self::prune( $entries, $now );
			$entry            = self::entry( $entries, $host );
			$entry['ok']      = $now + self::OUTAGE_MEMORY;
			$entry['until']   = 0;
			$entry['fails']   = array();
			$entries[ $host ] = $entry;
			self::store( $entries );
			return;
		}
		if ( isset( $entries[ $host ] ) && ( ! empty( $entries[ $host ]['fails'] ) || ! empty( $entries[ $host ]['until'] ) ) ) {
			unset( $entries[ $host ] );
			self::store( $entries );
		}
	}

	public static function snapshot( $now = null ) {
		$now = null === $now ? time() : $now;
		$out = array();
		foreach ( self::entries() as $host => $entry ) {
			if ( ! empty( $entry['until'] ) && $entry['until'] > $now ) {
				$out[] = array(
					'host'  => (string) $host,
					'until' => (int) $entry['until'],
				);
			}
		}
		return $out;
	}

	public static function reset() {
		self::store( array() );
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function transient_failure( $error ) {
		if ( ! is_wp_error( $error ) || 'http_request_failed' !== $error->get_error_code() ) {
			return false;
		}
		$message = strtolower( $error->get_error_message() );
		if ( preg_match( '/curl error (6|7|28|35|52|56)\b/', $message ) ) {
			return true;
		}
		foreach ( array( 'timed out', 'could not resolve', 'failed to connect', 'connection refused', 'connection reset' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function entry( array $entries, $host ) {
		return array_merge(
			array(
				'fails' => array(),
				'until' => 0,
				'ok'    => 0,
			),
			isset( $entries[ $host ] ) ? $entries[ $host ] : array()
		);
	}

	private static function recent( array $fails, $now ) {
		return array_values(
			array_filter(
				$fails,
				static function ( $at ) use ( $now ) {
					return $now - $at < self::WINDOW;
				}
			)
		);
	}

	private static function entries() {
		if ( null === self::$cache ) {
			$stored      = get_site_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache;
	}

	private static function prune( array $entries, $now ) {
		foreach ( $entries as $host => $entry ) {
			$entry = self::entry( $entries, $host );
			if ( $entry['until'] <= $now && $entry['ok'] <= $now && ! self::recent( $entry['fails'], $now ) ) {
				unset( $entries[ $host ] );
			}
		}
		if ( count( $entries ) > self::MAX_HOSTS ) {
			$entries = array_slice( $entries, -self::MAX_HOSTS, null, true );
		}
		return $entries;
	}

	private static function store( array $entries ) {
		self::$cache = $entries;
		update_site_option( self::OPTION, $entries );
	}
}
