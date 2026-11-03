<?php

namespace Rafoo\Peers;

defined( 'ABSPATH' ) || exit;

final class Book {

	const OPTION    = 'rafoo_peer_log';
	const KEEP      = 172800;
	const FLUSH     = 900;
	const SENTINEL  = 600;
	const MIN_TICK  = 30;
	const MAX_BATCH = 600;

	public static function load() {
		$log = get_option( self::OPTION, array() );
		return self::shape( is_array( $log ) ? $log : array() );
	}

	public static function store( array $log ) {
		update_option( self::OPTION, self::shape( $log ), false );
	}

	public static function shape( array $log ) {
		return array(
			'b'        => isset( $log['b'] ) && is_array( $log['b'] ) ? $log['b'] : array(),
			'last'     => isset( $log['last'] ) && is_array( $log['last'] ) ? $log['last'] : array(),
			'flush_at' => isset( $log['flush_at'] ) ? (int) $log['flush_at'] : 0,
			'dirty'    => ! empty( $log['dirty'] ),
			'sentinel' => isset( $log['sentinel'] ) ? (int) $log['sentinel'] : 0,
			'turn'     => isset( $log['turn'] ) ? (int) $log['turn'] : 0,
		);
	}

	public static function due( array $targets, array $log, $now, $offline, $scope ) {
		$log  = self::shape( $log );
		$list = array();
		foreach ( $targets as $t ) {
			$id = (int) $t['id'];
			if ( 'ir' === $scope && $offline && 'intl' === $t['scope'] ) {
				continue;
			}
			$every = 60 === (int) $t['interval'] ? 60 : 300;
			$last  = isset( $log['last'][ $id ]['at'] ) ? (int) $log['last'][ $id ]['at'] : 0;
			if ( $now - $last >= $every - 5 ) {
				$list[] = $id;
			}
		}
		if ( 'intl' === $scope && $log['sentinel'] > 0 ) {
			$iran = array();
			foreach ( $targets as $t ) {
				if ( 'ir' === $t['scope'] ) {
					$iran[] = (int) $t['id'];
				}
			}
			$list = array_values( array_diff( $list, $iran ) );
			if ( $iran && $now - $log['sentinel'] >= self::SENTINEL ) {
				$list[] = $iran[ $log['turn'] % count( $iran ) ];
			}
		}
		return $list;
	}

	public static function record( array $log, array $target, $epoch, $now, $ok, $ms, $code ) {
		$log  = self::shape( $log );
		$id   = (int) $target['id'];
		$hour = (int) ( floor( $now / 3600 ) * 3600 );
		$key  = $id . ':' . $hour . ':' . (int) $epoch;
		$b    = isset( $log['b'][ $key ] ) ? $log['b'][ $key ] : array(
			't'  => $id,
			'e'  => (int) $epoch,
			'g'  => (string) $target['grant'],
			'h'  => $hour,
			'n'  => 0,
			'ok' => 0,
			'ms' => 0,
		);
		++$b['n'];
		if ( $ok ) {
			++$b['ok'];
			$b['ms'] += max( 0, (int) $ms );
		}
		$b['at']           = (int) $now;
		$b['last']         = (bool) $ok;
		$b['code']         = (int) $code;
		$log['b'][ $key ]  = $b;
		$before            = isset( $log['last'][ $id ] ) ? (bool) $log['last'][ $id ]['ok'] : null;
		$log['last'][ $id ] = array(
			'at'   => (int) $now,
			'ok'   => (bool) $ok,
			'ms'   => (int) $ms,
			'code' => (int) $code,
		);
		if ( null !== $before && $before !== (bool) $ok ) {
			$log['dirty'] = true;
		}
		return $log;
	}

	public static function sentinel( array $log, array $targets, array $results, $now, $scope ) {
		$log = self::shape( $log );
		if ( 'intl' !== $scope ) {
			$log['sentinel'] = 0;
			return $log;
		}
		$iran = array();
		foreach ( $targets as $t ) {
			if ( 'ir' === $t['scope'] ) {
				$iran[ (int) $t['id'] ] = true;
			}
		}
		$tried  = 0;
		$failed = 0;
		foreach ( $results as $id => $r ) {
			if ( isset( $iran[ (int) $id ] ) ) {
				++$tried;
				if ( empty( $r['ok'] ) ) {
					++$failed;
				}
			}
		}
		if ( 0 === $tried ) {
			return $log;
		}
		if ( $log['sentinel'] > 0 ) {
			if ( $failed < $tried ) {
				$log['sentinel'] = 0;
				$log['dirty']    = true;
			} else {
				$log['sentinel'] = (int) $now;
				++$log['turn'];
			}
			return $log;
		}
		if ( count( $iran ) >= 2 && $tried >= 2 && $failed * 10 >= $tried * 8 ) {
			$log['sentinel'] = (int) $now;
			$log['dirty']    = true;
		}
		return $log;
	}

	public static function items( array $log ) {
		$log   = self::shape( $log );
		$items = array();
		foreach ( $log['b'] as $b ) {
			$items[] = array(
				't'    => (int) $b['t'],
				'e'    => (int) $b['e'],
				'g'    => (string) $b['g'],
				'h'    => (int) $b['h'],
				'n'    => (int) $b['n'],
				'ok'   => (int) $b['ok'],
				'ms'   => $b['ok'] > 0 ? (int) round( $b['ms'] / $b['ok'] ) : 0,
				'at'   => (int) $b['at'],
				'last' => (bool) $b['last'],
				'code' => (int) $b['code'],
			);
			if ( count( $items ) >= self::MAX_BATCH ) {
				break;
			}
		}
		return $items;
	}

	public static function sent( array $log, array $items, $now ) {
		$log = self::shape( $log );
		foreach ( $items as $it ) {
			unset( $log['b'][ $it['t'] . ':' . $it['h'] . ':' . $it['e'] ] );
		}
		$log['flush_at'] = (int) $now;
		$log['dirty']    = false;
		return $log;
	}

	public static function should_flush( array $log, $now ) {
		$log = self::shape( $log );
		if ( empty( $log['b'] ) ) {
			return false;
		}
		return $log['dirty'] || $now - $log['flush_at'] >= self::FLUSH;
	}

	public static function prune( array $log, array $targets, $now ) {
		$log  = self::shape( $log );
		$keep = array();
		foreach ( $targets as $t ) {
			$keep[ (int) $t['id'] ] = true;
		}
		foreach ( $log['b'] as $key => $b ) {
			if ( $now - (int) $b['at'] > self::KEEP ) {
				unset( $log['b'][ $key ] );
			}
		}
		foreach ( $log['last'] as $id => $l ) {
			if ( ! isset( $keep[ (int) $id ] ) && $now - (int) $l['at'] > self::KEEP ) {
				unset( $log['last'][ $id ] );
			}
		}
		return $log;
	}

	public static function next( array $targets, array $log, $now, $offline, $scope ) {
		$log  = self::shape( $log );
		$soon = $now + self::FLUSH;
		foreach ( $targets as $t ) {
			$id = (int) $t['id'];
			if ( 'ir' === $scope && $offline && 'intl' === $t['scope'] ) {
				continue;
			}
			if ( 'intl' === $scope && $log['sentinel'] > 0 && 'ir' === $t['scope'] ) {
				$soon = min( $soon, $log['sentinel'] + self::SENTINEL );
				continue;
			}
			$every = 60 === (int) $t['interval'] ? 60 : 300;
			$last  = isset( $log['last'][ $id ]['at'] ) ? (int) $log['last'][ $id ]['at'] : 0;
			$soon  = min( $soon, $last + $every - 5 );
		}
		return max( $now + self::MIN_TICK, $soon );
	}
}
