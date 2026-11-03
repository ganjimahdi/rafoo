<?php

namespace Rafoo\Assets;

use Rafoo\Http\Client;
use Rafoo\Mirror\Index;
use Rafoo\Mirror\Mirror;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Sync {

	const LOCK = 'rafoo_sync_lock';

	const BUDGET = 20;

	const BACKOFF = array( 300, 1800, 7200, 21600, 86400 );

	const RECHECK = 3600;

	private static $selftest = array();

	public static function run( $budget = self::BUDGET ) {
		if ( get_transient( self::LOCK ) ) {
			return array( 'skipped' => true );
		}
		set_transient( self::LOCK, 1, $budget + 60 );

		$deadline  = microtime( true ) + $budget;
		$records   = Library::records();
		$now       = time();
		$online    = ! State::offline();
		$processed = 0;

		uasort(
			$records,
			static function ( $a, $b ) {
				return $a['created_at'] - $b['created_at'];
			}
		);

		foreach ( $records as $key => $record ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			if ( ! self::due( $record, $now, $online ) ) {
				continue;
			}
			$record               = 'font' === $record['kind'] ? Fonts::localize( $record, $deadline ) : self::localize( $record, $deadline, $online );
			$record['updated_at'] = time();
			$records[ $key ]      = $record;
			++$processed;
		}

		$records = self::merge_fresh( $records );
		Library::save( $records );
		delete_transient( self::LOCK );

		$remaining = 0;
		foreach ( $records as $record ) {
			if ( self::due( $record, time(), $online ) ) {
				++$remaining;
			}
		}

		return array(
			'skipped'   => false,
			'processed' => $processed,
			'remaining' => $remaining,
		);
	}

	public static function due( array $record, $now, $online ) {
		if ( 'excluded' === $record['status'] || $record['next_at'] > $now ) {
			return false;
		}
		if ( 'font' === $record['kind'] && ! $online ) {
			return false;
		}
		if ( 'ready' === $record['status'] ) {
			return '' === $record['verified'] && $online;
		}
		return true;
	}

	public static function localize( array $record, $deadline, $online ) {
		$mirror = '' !== $record['mirror'] ? Mirror::get( $record['mirror'] ) : null;
		$origin = $online ? self::origin( $record['origin'] ) : null;
		$choice = self::choose( $record, $mirror, $origin );

		if ( is_string( $choice ) ) {
			return self::failed( $record, $choice );
		}
		if ( ! Store::write( $record['local'], $choice['bytes'] ) ) {
			return self::failed( $record, 'write_failed' );
		}

		$complete = true;
		if ( 'css' === $record['type'] ) {
			list( $record['deps'], $complete ) = self::dependencies( $record, $choice['bytes'], $choice['source'], $online, $deadline );
		}
		if ( ! $complete ) {
			$record['status']  = 'pending';
			$record['next_at'] = 0;
			return $record;
		}
		if ( ! self::serves( $record ) ) {
			return self::failed( $record, 'selftest_failed' );
		}

		$record['status']   = 'ready';
		$record['verified'] = $choice['verified'];
		$record['source']   = $choice['source'];
		$record['sha256']   = hash( 'sha256', $choice['bytes'] );
		$record['size']     = strlen( $choice['bytes'] );
		$record['attempts'] = 0;
		$record['error']    = '';
		$record['next_at']  = '' === $choice['verified'] ? time() + self::RECHECK : 0;
		return $record;
	}

	public static function choose( array $record, $mirror, $origin ) {
		$candidates = array_filter(
			array(
				'mirror' => $mirror,
				'origin' => $origin,
			),
			'is_string'
		);
		if ( ! $candidates ) {
			return '' === $record['mirror'] ? 'needs_internet' : 'unavailable';
		}
		if ( '' !== $record['sri'] ) {
			foreach ( $candidates as $source => $bytes ) {
				if ( Sri::matches( $bytes, $record['sri'] ) ) {
					return self::pick( $bytes, $source, 'sri' );
				}
			}
			return 'integrity_mismatch';
		}
		if ( isset( $candidates['origin'] ) ) {
			$source = isset( $candidates['mirror'] ) && hash_equals( $candidates['mirror'], $candidates['origin'] ) ? 'mirror' : 'origin';
			return self::pick( $candidates['origin'], $source, 'origin' );
		}
		$expected = self::index_hash( $record );
		if ( null !== $expected ) {
			return Sri::matches( $candidates['mirror'], $expected ) ? self::pick( $candidates['mirror'], 'mirror', 'index' ) : 'integrity_mismatch';
		}
		return self::pick( $candidates['mirror'], 'mirror', '' );
	}

	public static function verify() {
		$records = Library::records();
		$changed = false;
		foreach ( $records as $key => $record ) {
			if ( 'ready' !== $record['status'] ) {
				continue;
			}
			$intact = Store::hash( $record['local'] ) === $record['sha256'];
			foreach ( $record['deps'] as $dep ) {
				$intact = $intact && Store::exists( $dep );
			}
			if ( ! $intact ) {
				$records[ $key ]['status']  = 'pending';
				$records[ $key ]['next_at'] = 0;
				$records[ $key ]['error']   = 'file_changed';
				$changed                    = true;
			}
		}
		if ( $changed ) {
			Library::save( $records );
			Library::schedule_soon();
		}
		return $changed;
	}

	private static function pick( $bytes, $source, $verified ) {
		return array(
			'bytes'    => $bytes,
			'source'   => $source,
			'verified' => $verified,
		);
	}

	private static function index_hash( array $record ) {
		if ( '' === $record['pkg'] || 0 !== strpos( $record['mirror'], 'npm/' ) ) {
			return null;
		}
		$prefix = 'npm/' . $record['pkg'] . '@' . $record['ver'] . '/';
		if ( 0 !== strpos( $record['mirror'], $prefix ) ) {
			return null;
		}
		$files = Index::npm_files( $record['pkg'], $record['ver'] );
		$file  = substr( $record['mirror'], strlen( $prefix ) );
		return is_array( $files ) && isset( $files[ $file ] ) ? (string) $files[ $file ] : null;
	}

	private static function dependencies( array $record, $css, $source, $online, $deadline ) {
		$root     = CssLinks::root( $record );
		$deps     = array();
		$complete = true;
		$origin   = wp_parse_url( $record['origin'] );
		foreach ( CssLinks::references( $css ) as $reference ) {
			$local = Recognizer::resolve( $record['local'], $reference );
			if ( ! CssLinks::within( $local, $root ) ) {
				continue;
			}
			if ( Store::exists( $local ) ) {
				$deps[] = $local;
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				$complete = false;
				break;
			}
			$bytes = null;
			if ( 'mirror' === $source && '' !== $record['mirror'] ) {
				$mirror_path = Recognizer::resolve( $record['mirror'], $reference );
				$bytes       = '' !== $mirror_path ? Mirror::get( $mirror_path ) : null;
			}
			if ( null === $bytes && $online ) {
				$origin_path = Recognizer::resolve( ltrim( $origin['path'], '/' ), $reference );
				$bytes       = '' !== $origin_path ? self::origin( 'https://' . $origin['host'] . '/' . $origin_path ) : null;
			}
			if ( is_string( $bytes ) && Store::write( $local, $bytes ) ) {
				$deps[] = $local;
			}
		}
		return array( array_values( array_unique( $deps ) ), $complete );
	}

	private static function origin( $url ) {
		$body = Client::body( $url, array( 'timeout' => 10 ), Store::MAX_BYTES );
		return is_string( $body ) ? $body : null;
	}

	private static function serves( array $record ) {
		$type = in_array( $record['type'], array( 'js', 'mjs' ), true ) ? 'js' : $record['type'];
		if ( ! in_array( $type, array( 'js', 'css' ), true ) ) {
			return true;
		}
		if ( ! isset( self::$selftest[ $type ] ) ) {
			self::$selftest[ $type ] = Loopback::serves( Store::url( $record['local'] ), $type );
		}
		return self::$selftest[ $type ];
	}

	public static function failed( array $record, $reason ) {
		$attempt              = min( (int) $record['attempts'], count( self::BACKOFF ) - 1 );
		$record['attempts']   = (int) $record['attempts'] + 1;
		$record['error']      = $reason;
		$record['next_at']    = time() + self::BACKOFF[ $attempt ];
		$record['status']     = 'ready' === $record['status'] && 'unavailable' === $reason ? 'ready' : 'failed';
		return $record;
	}

	private static function merge_fresh( array $records ) {
		foreach ( Library::records() as $key => $record ) {
			if ( ! isset( $records[ $key ] ) ) {
				$records[ $key ] = $record;
			}
		}
		return $records;
	}
}
