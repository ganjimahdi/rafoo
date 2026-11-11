<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Stats {

	const DAYS = 30;

	const KEEP = 200;

	const ACTIONS = array( 'block', 'limit', 'locked', 'lock', 'challenge', 'watch' );

	public static function data() {
		$data = get_option( Local::STATS, array() );
		return array_merge(
			array(
				'offset' => 0,
				'days'   => array(),
			),
			is_array( $data ) ? $data : array()
		);
	}

	public static function events( $limit = 50 ) {
		$events = get_option( Local::EVENTS, array() );
		return is_array( $events ) ? array_slice( array_reverse( $events ), 0, max( 1, (int) $limit ) ) : array();
	}

	public static function compact() {
		$dir = Config::dir();
		return '' !== $dir ? self::compact_file( $dir . '/' . Log::FILE ) : 0;
	}

	public static function compact_file( $file ) {
		if ( '' === $file || ! is_file( $file ) ) {
			return 0;
		}
		clearstatcache( true, $file );
		$data   = self::data();
		$size   = (int) filesize( $file );
		$offset = (int) $data['offset'];
		if ( $offset > $size ) {
			$offset = 0;
		}
		if ( $offset === $size ) {
			return 0;
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads the firewall's own log in the private folder line by line.
		if ( ! $handle ) {
			return 0;
		}
		fseek( $handle, $offset );
		$events = get_option( Local::EVENTS, array() );
		$events = is_array( $events ) ? $events : array();
		$count   = 0;
		$network = \Rafoo\Settings::get( 'shield_network' ) && Intel::joined();
		$report  = array();
		while ( $count < 20000 && false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- standard line reader.
			if ( "\n" !== substr( $line, -1 ) ) {
				break;
			}
			$offset += strlen( $line );
			$row     = json_decode( $line, true );
			if ( ! is_array( $row ) || empty( $row['a'] ) ) {
				continue;
			}
			++$count;
			$data = self::add( $data, $row );
			if ( $network ) {
				$report[] = $row;
			}
			if ( 'watch' !== $row['a'] ) {
				$events[] = self::clean( $row );
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen above.
		if ( $report ) {
			Intel::queue( $report );
		}
		$data['offset'] = $offset;
		$data['days']   = array_slice( $data['days'], -self::DAYS, null, true );
		update_option( Local::STATS, $data, false );
		update_option( Local::EVENTS, array_slice( $events, -self::KEEP ), false );
		return $count;
	}

	public static function rotate( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$dir  = Config::dir();
		$file = '' !== $dir ? $dir . '/' . Log::FILE : '';
		if ( '' === $file ) {
			return;
		}
		self::compact();
		if ( is_file( $file ) && rename( $file, $file . '.done' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moves the firewall's own log aside so new lines go to a fresh file.
			self::compact_file( $file . '.done' );
			wp_delete_file( $file . '.done' );
		}
		$data           = self::data();
		$data['offset'] = 0;
		update_option( Local::STATS, $data, false );
		if ( is_file( $file . '.1' ) && $now - (int) filemtime( $file . '.1' ) > self::DAYS * DAY_IN_SECONDS ) {
			wp_delete_file( $file . '.1' );
		}
		$events = get_option( Local::EVENTS, array() );
		if ( is_array( $events ) ) {
			$events = array_values(
				array_filter(
					$events,
					static function ( $event ) use ( $now ) {
						return isset( $event['t'] ) && $now - (int) $event['t'] <= self::DAYS * DAY_IN_SECONDS;
					}
				)
			);
			update_option( Local::EVENTS, $events, false );
		}
	}

	public static function add( array $data, array $row ) {
		$day = wp_date( 'Y-m-d', (int) $row['t'] );
		if ( ! isset( $data['days'][ $day ] ) ) {
			$data['days'][ $day ] = array(
				'a' => array(),
				'g' => array(),
				'i' => array(),
				'p' => array(),
				'x' => array(),
			);
			ksort( $data['days'] );
		}
		$slot = &$data['days'][ $day ];
		$act  = in_array( $row['a'], self::ACTIONS, true ) ? $row['a'] : 'block';
		$slot['a'][ $act ] = ( isset( $slot['a'][ $act ] ) ? $slot['a'][ $act ] : 0 ) + 1;
		if ( 'watch' === $act ) {
			return $data;
		}
		$group = isset( $row['g'] ) ? sanitize_key( $row['g'] ) : '';
		if ( '' !== $group ) {
			$slot['g'][ $group ] = ( isset( $slot['g'][ $group ] ) ? $slot['g'][ $group ] : 0 ) + 1;
		}
		foreach ( array( 'i' => 'i', 'p' => 'p', 'x' => 'x' ) as $field => $source ) {
			$value = isset( $row[ $source ] ) ? substr( (string) $row[ $source ], 0, 120 ) : '';
			if ( '' === $value ) {
				continue;
			}
			$slot[ $field ][ $value ] = ( isset( $slot[ $field ][ $value ] ) ? $slot[ $field ][ $value ] : 0 ) + 1;
			if ( count( $slot[ $field ] ) > 300 ) {
				arsort( $slot[ $field ] );
				$slot[ $field ] = array_slice( $slot[ $field ], 0, 200, true );
			}
		}
		unset( $slot );
		return $data;
	}

	public static function clean( array $row ) {
		return array(
			't' => (int) $row['t'],
			'r' => isset( $row['r'] ) ? (string) $row['r'] : '',
			'i' => isset( $row['i'] ) ? (string) $row['i'] : '',
			'a' => (string) $row['a'],
			'g' => isset( $row['g'] ) ? (string) $row['g'] : '',
			'u' => isset( $row['u'] ) ? (string) $row['u'] : '',
			'w' => isset( $row['w'] ) ? (string) $row['w'] : '',
			'm' => isset( $row['m'] ) ? (string) $row['m'] : '',
			'p' => isset( $row['p'] ) ? (string) $row['p'] : '',
			'b' => isset( $row['b'] ) ? (string) $row['b'] : '',
			'x' => isset( $row['x'] ) ? (string) $row['x'] : '',
			'k' => empty( $row['k'] ) ? 0 : 1,
		);
	}

	public static function summary( $days = 7 ) {
		$data  = self::data();
		$out   = array(
			'today'  => array(),
			'week'   => array(),
			'groups' => array(),
			'ips'    => array(),
			'paths'  => array(),
			'series' => array(),
		);
		$today = wp_date( 'Y-m-d' );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day  = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$slot = isset( $data['days'][ $day ] ) ? $data['days'][ $day ] : array( 'a' => array(), 'g' => array(), 'i' => array(), 'p' => array() );
			$out['series'][] = array(
				'day'     => $day,
				'stopped' => self::stopped( $slot['a'] ),
			);
			foreach ( $slot['a'] as $act => $n ) {
				$out['week'][ $act ] = ( isset( $out['week'][ $act ] ) ? $out['week'][ $act ] : 0 ) + $n;
				if ( $day === $today ) {
					$out['today'][ $act ] = $n;
				}
			}
			foreach ( array( 'g' => 'groups', 'i' => 'ips', 'p' => 'paths' ) as $field => $name ) {
				foreach ( isset( $slot[ $field ] ) ? $slot[ $field ] : array() as $value => $n ) {
					$out[ $name ][ $value ] = ( isset( $out[ $name ][ $value ] ) ? $out[ $name ][ $value ] : 0 ) + $n;
				}
			}
		}
		foreach ( array( 'groups', 'ips', 'paths' ) as $name ) {
			arsort( $out[ $name ] );
			$rows = array();
			foreach ( array_slice( $out[ $name ], 0, 10, true ) as $value => $n ) {
				$rows[] = array(
					'name'  => (string) $value,
					'count' => (int) $n,
				);
			}
			$out[ $name ] = $rows;
		}
		$out['stopped_today'] = self::stopped( $out['today'] );
		$out['stopped_week']  = self::stopped( $out['week'] );
		return $out;
	}

	public static function stopped( array $actions ) {
		$sum = 0;
		foreach ( array( 'block', 'limit', 'locked', 'lock' ) as $act ) {
			$sum += isset( $actions[ $act ] ) ? (int) $actions[ $act ] : 0;
		}
		return $sum;
	}
}
