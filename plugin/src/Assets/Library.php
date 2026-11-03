<?php

namespace Rafoo\Assets;

use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Library {

	const MAP = 'rafoo_map';

	const RECORDS = 'rafoo_assets';

	const MAX = 1000;

	const SYNC_HOOK = 'rafoo_sync';

	private static $map = null;

	private static $notes = array();

	private static $flushing = false;

	public static function map() {
		if ( null === self::$map ) {
			$stored    = get_option( self::MAP, array() );
			self::$map = is_array( $stored ) ? $stored : array();
		}
		return self::$map;
	}

	public static function known( $key ) {
		return isset( self::map()[ $key ] );
	}

	public static function local_url( $key ) {
		$map = self::map();
		if ( empty( $map[ $key ]['p'] ) ) {
			return '';
		}
		$mode = Settings::get( 'replace' );
		if ( 'off' === $mode ) {
			return '';
		}
		$offline = State::offline();
		if ( ( 'outage' === $mode || empty( $map[ $key ]['v'] ) ) && ! $offline ) {
			return '';
		}
		return Store::url( $map[ $key ]['p'] );
	}

	public static function note( array $asset, $integrity = '', $kind = 'asset' ) {
		$key = $asset['key'];
		if ( self::known( $key ) ) {
			$entry = self::map()[ $key ];
			if ( '' === $integrity || ! empty( $entry['p'] ) || ! empty( $entry['s'] ) ) {
				return;
			}
		}
		$integrity = Sri::normalize( $integrity );
		if ( isset( self::$notes[ $key ] ) ) {
			if ( '' !== $integrity ) {
				self::$notes[ $key ]['sri'] = $integrity;
			}
			return;
		}
		if ( count( self::$notes ) >= 50 ) {
			return;
		}
		self::$notes[ $key ] = array(
			'asset' => $asset,
			'sri'   => $integrity,
			'kind'  => $kind,
		);
		if ( ! self::$flushing ) {
			self::$flushing = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_notes' ), 5 );
		}
	}

	public static function flush_notes() {
		$notes          = self::$notes;
		self::$notes    = array();
		self::$flushing = false;
		if ( ! $notes ) {
			return;
		}
		$records = self::records();
		$changed = false;
		$now     = time();
		foreach ( $notes as $key => $note ) {
			if ( isset( $records[ $key ] ) ) {
				if ( '' !== $note['sri'] && $records[ $key ]['sri'] !== $note['sri'] && 'ready' !== $records[ $key ]['status'] ) {
					$records[ $key ]['sri']     = $note['sri'];
					$records[ $key ]['next_at'] = 0;
					$changed                    = true;
				}
				continue;
			}
			if ( count( $records ) >= self::MAX ) {
				break;
			}
			$records[ $key ] = self::blank( $note['asset'], $note['sri'], $note['kind'], $now );
			$changed         = true;
		}
		if ( $changed ) {
			self::save( $records );
			self::schedule_soon();
		}
	}

	public static function blank( array $asset, $integrity, $kind, $now ) {
		return array(
			'origin'     => $asset['origin'],
			'mirror'     => isset( $asset['mirror'] ) ? $asset['mirror'] : '',
			'local'      => $asset['local'],
			'type'       => $asset['type'],
			'kind'       => $kind,
			'cdn'        => isset( $asset['cdn'] ) ? $asset['cdn'] : '',
			'pkg'        => isset( $asset['pkg'] ) ? $asset['pkg'] : '',
			'ver'        => isset( $asset['ver'] ) ? $asset['ver'] : '',
			'sri'        => $integrity,
			'status'     => 'pending',
			'verified'   => '',
			'source'     => '',
			'sha256'     => '',
			'size'       => 0,
			'deps'       => array(),
			'attempts'   => 0,
			'next_at'    => 0,
			'error'      => '',
			'created_at' => $now,
			'updated_at' => $now,
		);
	}

	public static function records() {
		$stored = get_option( self::RECORDS, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function save( array $records ) {
		update_option( self::RECORDS, $records, false );
		self::rebuild( $records );
	}

	public static function rebuild( array $records ) {
		$excluded = Settings::get( 'exclude' );
		$map      = array();
		foreach ( $records as $key => $record ) {
			$active      = 'ready' === $record['status'] && ! in_array( $record['origin'], $excluded, true );
			$map[ $key ] = $active
				? array(
					'p' => $record['local'],
					'v' => '' !== $record['verified'] ? 1 : 0,
				)
				: array(
					'p' => '',
					's' => '' !== $record['sri'] ? 1 : 0,
				);
		}
		$before = self::map();
		if ( $before !== $map ) {
			update_option( self::MAP, $map, true );
			self::$map = $map;
			do_action( 'rafoo_map_changed' );
		}
	}

	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::SYNC_HOOK, array( 'soon' ) ) ) {
			wp_schedule_single_event( time() + 10, self::SYNC_HOOK, array( 'soon' ) );
		}
	}

	public static function remove( $key ) {
		$records = self::records();
		if ( ! isset( $records[ $key ] ) ) {
			return false;
		}
		Store::delete( $records[ $key ]['local'] );
		foreach ( $records[ $key ]['deps'] as $dep ) {
			if ( ! self::shared( $records, $key, $dep ) ) {
				Store::delete( $dep );
			}
		}
		unset( $records[ $key ] );
		self::save( $records );
		return true;
	}

	public static function retry( $key ) {
		$records = self::records();
		if ( ! isset( $records[ $key ] ) ) {
			return false;
		}
		$records[ $key ]['status']   = 'pending';
		$records[ $key ]['attempts'] = 0;
		$records[ $key ]['next_at']  = 0;
		$records[ $key ]['error']    = '';
		self::save( $records );
		self::schedule_soon();
		return true;
	}

	public static function summary() {
		$out = array(
			'ready'      => 0,
			'unverified' => 0,
			'pending'    => 0,
			'failed'     => 0,
			'bytes'      => 0,
		);
		foreach ( self::records() as $record ) {
			if ( 'ready' === $record['status'] ) {
				++$out['ready'];
				$out['bytes'] += (int) $record['size'];
				if ( '' === $record['verified'] ) {
					++$out['unverified'];
				}
			} elseif ( 'failed' === $record['status'] ) {
				++$out['failed'];
			} else {
				++$out['pending'];
			}
		}
		return $out;
	}

	public static function reset_cache() {
		self::$map = null;
	}

	private static function shared( array $records, $owner, $path ) {
		foreach ( $records as $key => $record ) {
			if ( $key !== $owner && ( $record['local'] === $path || in_array( $path, $record['deps'], true ) ) ) {
				return true;
			}
		}
		return false;
	}
}
