<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	const OPTION = 'rafoo_backups';

	const DAILY = 7;

	const WEEKLY = 4;

	const MONTHLY = 6;

	const RECENT = 4;

	const PATTERN = '/^rafoo-.+-\d{8}-\d{6}-[a-z0-9]{4}(\.(zip|rfb|json)|-p\d{3}\.(tar\.gz|tar|rfa))$/';

	public static function format( array $entry ) {
		return isset( $entry['format'] ) ? (int) $entry['format'] : 1;
	}

	public static function files( array $entry ) {
		if ( self::format( $entry ) < 2 ) {
			return '' !== (string) $entry['file'] ? array( (string) $entry['file'] ) : array();
		}
		$names = array_column( isset( $entry['parts'] ) ? (array) $entry['parts'] : array(), 'name' );
		if ( '' !== (string) $entry['file'] ) {
			$names[] = (string) $entry['file'];
		}
		return $names;
	}

	public static function local( array $entry ) {
		if ( self::format( $entry ) < 2 ) {
			return '' !== (string) $entry['file'] && is_file( Ledger::path( $entry['file'] ) );
		}
		$parts = isset( $entry['parts'] ) ? (array) $entry['parts'] : array();
		foreach ( $parts as $part ) {
			if ( ! is_file( Ledger::path( $part['name'] ) ) ) {
				return false;
			}
		}
		return (bool) $parts;
	}

	public static function all() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		uasort(
			$stored,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		return $stored;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function put( array $entry ) {
		$all                 = self::all();
		$all[ $entry['id'] ] = $entry;
		update_option( self::OPTION, $all, false );
	}

	public static function forget( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	public static function latest( $usable = true ) {
		foreach ( self::all() as $entry ) {
			if ( empty( $entry['transfer'] ) && ( ! $usable || ! empty( $entry['complete'] ) ) ) {
				return $entry;
			}
		}
		return null;
	}

	public static function chain( $id ) {
		$all   = self::all();
		$chain = array();
		$guard = 0;
		while ( isset( $all[ $id ] ) && $guard++ < 50 ) {
			array_unshift( $chain, $all[ $id ] );
			if ( 'full' === $all[ $id ]['kind'] ) {
				return $chain;
			}
			$id = $all[ $id ]['parent'];
		}
		return array();
	}

	public static function keep( array $entries, $now = null ) {
		$now     = null === $now ? time() : $now;
		$keep    = array();
		$days    = array();
		$weeks   = array();
		$months  = array();
		$ordered = $entries;
		usort(
			$ordered,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		$recent  = 0;
		foreach ( $ordered as $entry ) {
			if ( ! empty( $entry['transfer'] ) ) {
				continue;
			}
			if ( ! empty( $entry['complete'] ) && $recent < self::RECENT ) {
				++$recent;
				$keep[ $entry['id'] ] = true;
			}
			if ( empty( $entry['complete'] ) ) {
				if ( $now - $entry['created'] < DAY_IN_SECONDS ) {
					$keep[ $entry['id'] ] = true;
				}
				continue;
			}
			$day   = gmdate( 'Y-m-d', $entry['created'] );
			$week  = gmdate( 'o-W', $entry['created'] );
			$month = gmdate( 'Y-m', $entry['created'] );
			if ( count( $days ) < self::DAILY && ! isset( $days[ $day ] ) ) {
				$days[ $day ]         = true;
				$keep[ $entry['id'] ] = true;
			}
			if ( count( $weeks ) < self::WEEKLY && ! isset( $weeks[ $week ] ) ) {
				$weeks[ $week ]       = true;
				$keep[ $entry['id'] ] = true;
			}
			if ( count( $months ) < self::MONTHLY && ! isset( $months[ $month ] ) ) {
				$months[ $month ]     = true;
				$keep[ $entry['id'] ] = true;
			}
		}
		$by_id = array();
		foreach ( $entries as $entry ) {
			$by_id[ $entry['id'] ] = $entry;
		}
		foreach ( array_keys( $keep ) as $id ) {
			$guard = 0;
			while ( isset( $by_id[ $id ] ) && 'full' !== $by_id[ $id ]['kind'] && $guard++ < 50 ) {
				$id          = $by_id[ $id ]['parent'];
				$keep[ $id ] = true;
			}
		}
		return array_keys( $keep );
	}

	public static function view() {
		$out = array();
		foreach ( self::all() as $entry ) {
			if ( ! empty( $entry['transfer'] ) ) {
				continue;
			}
			$out[] = array(
				'id'        => $entry['id'],
				'created'   => (int) $entry['created'],
				'kind'      => $entry['kind'],
				'size'      => (int) $entry['size'],
				'files'     => (int) $entry['files'],
				'tables'    => (int) $entry['tables'],
				'encrypted' => ! empty( $entry['encrypted'] ),
				'complete'  => ! empty( $entry['complete'] ),
				'local'     => self::local( $entry ),
				'places'    => array_keys( array_filter( (array) $entry['places'] ) ),
				'wp'        => (string) $entry['wp'],
				'format'    => self::format( $entry ),
				'parts'     => self::format( $entry ) < 2 ? 1 : count( (array) $entry['parts'] ),
				'warnings'  => isset( $entry['warnings'] ) ? Warnings::view( (array) $entry['warnings'] ) : array(),
			);
		}
		return $out;
	}
}
