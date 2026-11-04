<?php

namespace Rafoo\Http;

use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Log {

	const OPTION = 'rafoo_requests';

	const MAX = 300;

	const STARTED = 'rafoo_started';

	const ENTRY_POINTS = array( 'wp_remote_request', 'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_safe_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head', 'download_url' );

	private static $pending = array();

	private static $scheduled = false;

	public static function enabled() {
		return (bool) Settings::get( 'request_log' );
	}

	public static function stamp( $args, $url ) {
		if ( is_array( $args ) && self::enabled() && empty( $args['rafoo_probe'] ) ) {
			$args[ self::STARTED ] = microtime( true );
		}
		return $args;
	}

	public static function record( $host, $outcome, $args = array(), $origin = '' ) {
		if ( '' === $host || ! self::enabled() || ! in_array( $outcome, array( 'ok', 'failed', 'refused' ), true ) ) {
			return;
		}
		$origin = '' !== $origin ? $origin : self::origin();
		$key    = $origin . '|' . $host;
		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = self::blank( $origin, $host );
		}
		$entry = &self::$pending[ $key ];
		++$entry['count'];
		++$entry[ $outcome ];
		if ( is_array( $args ) && ! empty( $args[ self::STARTED ] ) ) {
			$entry['ms'] += (int) round( ( microtime( true ) - (float) $args[ self::STARTED ] ) * 1000 );
			++$entry['timed'];
		}
		$entry['last'] = time();
		unset( $entry );
		if ( ! self::$scheduled ) {
			self::$scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), PHP_INT_MAX );
		}
	}

	public static function flush() {
		self::$scheduled = false;
		if ( ! self::$pending ) {
			return;
		}
		$entries = self::entries();
		foreach ( self::$pending as $key => $add ) {
			$entry = isset( $entries[ $key ] ) ? array_merge( self::blank( $add['origin'], $add['host'] ), $entries[ $key ] ) : self::blank( $add['origin'], $add['host'] );
			foreach ( array( 'count', 'ok', 'failed', 'refused', 'ms', 'timed' ) as $field ) {
				$entry[ $field ] += $add[ $field ];
			}
			$entry['last']   = max( $entry['last'], $add['last'] );
			$entries[ $key ] = $entry;
		}
		self::$pending = array();
		if ( count( $entries ) > self::MAX ) {
			uasort(
				$entries,
				static function ( $a, $b ) {
					return $b['last'] - $a['last'];
				}
			);
			$entries = array_slice( $entries, 0, self::MAX, true );
		}
		update_option( self::OPTION, $entries, false );
	}

	public static function entries() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function clear() {
		self::$pending = array();
		delete_option( self::OPTION );
	}

	public static function report() {
		$names = self::names();
		$out   = array();
		foreach ( self::entries() as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['host'] ) ) {
				continue;
			}
			$origin = (string) $entry['origin'];
			$out[]  = array(
				'origin'  => $origin,
				'name'    => isset( $names[ $origin ] ) ? $names[ $origin ] : self::fallback_name( $origin ),
				'host'    => (string) $entry['host'],
				'foreign' => ! Hosts::domestic( (string) $entry['host'] ),
				'blocked' => Hosts::blocked( 'https://' . $entry['host'] . '/' ),
				'count'   => (int) $entry['count'],
				'ok'      => (int) $entry['ok'],
				'failed'  => (int) $entry['failed'],
				'refused' => (int) $entry['refused'],
				'average' => $entry['timed'] > 0 ? (int) round( $entry['ms'] / $entry['timed'] ) : 0,
				'last'    => (int) $entry['last'],
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] - $a['count'];
				}
				return strcmp( $a['host'], $b['host'] );
			}
		);
		return $out;
	}

	public static function origin( $trace = null ) {
		$trace   = null === $trace ? ( new \Exception() )->getTrace() : $trace;
		$started = false;
		foreach ( $trace as $frame ) {
			$function = isset( $frame['function'] ) ? $frame['function'] : '';
			$class    = isset( $frame['class'] ) ? $frame['class'] : '';
			if ( ! $started ) {
				$started = in_array( $function, self::ENTRY_POINTS, true ) || ( 'WP_Http' === $class && 'request' === $function );
				if ( ! $started ) {
					continue;
				}
			}
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$origin = self::owner( $frame['file'] );
			if ( '' !== $origin ) {
				return $origin;
			}
		}
		return 'core';
	}

	public static function owner( $file ) {
		$file  = wp_normalize_path( $file );
		$roots = array(
			'plugin' => wp_normalize_path( WP_PLUGIN_DIR ),
			'mu'     => wp_normalize_path( WPMU_PLUGIN_DIR ),
			'theme'  => wp_normalize_path( get_theme_root() ),
		);
		foreach ( $roots as $type => $root ) {
			$root = trailingslashit( $root );
			if ( 0 === strpos( $file, $root ) ) {
				$slug = strtok( substr( $file, strlen( $root ) ), '/' );
				if ( 'mu' === $type ) {
					$slug = preg_replace( '/\.php$/', '', $slug );
				}
				return $type . ':' . sanitize_key( $slug );
			}
		}
		return '';
	}

	private static function blank( $origin, $host ) {
		return array(
			'origin'  => $origin,
			'host'    => $host,
			'count'   => 0,
			'ok'      => 0,
			'failed'  => 0,
			'refused' => 0,
			'ms'      => 0,
			'timed'   => 0,
			'last'    => 0,
		);
	}

	private static function names() {
		$names = array(
			'core'    => 'WordPress',
			'browser' => __( 'Browser', 'rafoo' ),
		);
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $plugin ) {
			$slug = sanitize_key( false !== strpos( $file, '/' ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file ) );
			$names[ 'plugin:' . $slug ] = $plugin['Name'];
		}
		foreach ( get_mu_plugins() as $file => $plugin ) {
			$names[ 'mu:' . sanitize_key( preg_replace( '/\.php$/', '', $file ) ) ] = $plugin['Name'];
		}
		foreach ( wp_get_themes() as $slug => $theme ) {
			$names[ 'theme:' . sanitize_key( $slug ) ] = $theme->get( 'Name' );
		}
		return $names;
	}

	private static function fallback_name( $origin ) {
		$parts = explode( ':', $origin, 2 );
		return isset( $parts[1] ) ? $parts[1] : $origin;
	}
}
