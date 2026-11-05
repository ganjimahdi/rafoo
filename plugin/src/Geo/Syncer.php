<?php

namespace Rafoo\Geo;

use Rafoo\Backup\Ledger;
use Rafoo\Cache\Purge;

defined( 'ABSPATH' ) || exit;

final class Syncer {

	const NAME = 'geo-sync';

	const HOOK = 'rafoo_geo_sync';

	const FULL_EVERY = 21600;

	public static function state() {
		$state = Ledger::read( self::NAME );
		return array_merge(
			array(
				'gen'       => 0,
				'announced' => 0,
				'list_at'   => 0,
				'target'    => 0,
				'queue'     => null,
				'table'     => null,
				'files'     => null,
				'full_at'   => 0,
				'ok_at'     => 0,
				'started'   => 0,
				'error'     => '',
				'rows'      => 0,
				'tables'    => 0,
				'fetched'   => 0,
				'removed'   => 0,
				'lag'       => 0,
				'primary_at' => 0,
			),
			is_array( $state ) ? $state : array()
		);
	}

	private static function save( array $state ) {
		$stored             = Ledger::read( self::NAME );
		$state['announced'] = max( (int) $state['announced'], is_array( $stored ) && isset( $stored['announced'] ) ? (int) $stored['announced'] : 0 );
		Ledger::write( self::NAME, $state );
		return $state;
	}

	public static function announce( $gen ) {
		$state = self::state();
		if ( (int) $gen > (int) $state['announced'] ) {
			$state['announced'] = (int) $gen;
			self::save( $state );
		}
	}

	public static function seeded( $gen ) {
		$state             = self::state();
		$state['gen']      = (int) $gen;
		$state['full_at']  = time();
		$state['ok_at']    = time();
		$state['queue']    = null;
		$state['table']    = null;
		$state['files']    = null;
		$state['list_at']  = 0;
		self::save( $state );
		foreach ( array( 'applied.list', 'primary.part', 'todo.list', 'gone.list' ) as $name ) {
			wp_delete_file( Lister::path( $name ) );
		}
	}

	public static function behind() {
		$state = self::state();
		return (int) $state['announced'] > (int) $state['gen'] || ( null !== $state['queue'] && time() - (int) $state['ok_at'] > 120 );
	}

	public static function run( $seconds = 20 ) {
		$self = Store::self_node();
		if ( ! Store::is_copy() || ! $self || 'ready' !== $self['status'] ) {
			return self::state();
		}
		$primary = Store::primary_node();
		if ( ! $primary || ! Ledger::lock( self::NAME ) ) {
			return self::state();
		}
		$deadline       = microtime( true ) + $seconds;
		$state          = self::state();
		$state['again'] = false;
		try {
			$state = self::work( $state, $primary, $deadline );
		} catch ( \Throwable $e ) {
			$state['error'] = substr( $e->getMessage(), 0, 300 );
		}
		$state = self::save( $state );
		Ledger::unlock( self::NAME );
		if ( '' === $state['error'] && ( ! empty( $state['again'] ) || null !== $state['queue'] || (int) $state['announced'] > (int) $state['gen'] ) ) {
			Link::send( $self, 'poke', array( 'gen' => (int) $state['announced'] ) );
		}
		return $state;
	}

	private static function work( array $state, array $primary, $deadline ) {
		global $wpdb;
		if ( null === $state['queue'] ) {
			$full    = time() - (int) $state['full_at'] > self::FULL_EVERY;
			$changes = Link::call(
				$primary,
				'changes',
				array(
					'gen'    => (int) $state['gen'],
					'full'   => $full,
					'report' => Health::report(),
				),
				false,
				30
			);
			if ( is_wp_error( $changes ) ) {
				$state['error'] = $changes->get_error_message();
				return $state;
			}
			$state['primary_at'] = time();
			Store::save_state( array( 'contact' => time() ) );
			$state['target'] = (int) $changes['gen'];
			$state['prefix'] = (string) $changes['prefix'];
			$state['queue']  = array_values( array_map( 'strval', (array) $changes['tables'] ) );
			$state['full']   = $full;
			$state['rows']   = 0;
			$state['started'] = time();
			$list            = (array) $changes['list'];
			$state['files']  = ( ! empty( $changes['files'] ) || (int) $list['at'] > (int) $state['list_at'] ) && (int) $list['size'] > 0 ? FileSync::fresh( $list ) : null;
			if ( $full ) {
				foreach ( Db::tables() as $local ) {
					if ( ! in_array( $local, (array) $changes['all'], true ) && ! Changes::skipped_table( $wpdb->base_prefix . $local ) ) {
						Db::drop_local( $local );
					}
				}
			}
		}
		while ( ( $state['queue'] || null !== $state['table'] ) && microtime( true ) < $deadline ) {
			if ( null === $state['table'] ) {
				$state['table'] = array(
					'name'  => array_shift( $state['queue'] ),
					'phase' => 'start',
				);
			}
			$state['table'] = self::table( $state['table'], $primary, (string) $state['prefix'], $deadline );
			if ( ! empty( $state['table']['error'] ) ) {
				$state['error'] = 'table ' . $state['table']['name'] . ': ' . $state['table']['error'];
				$state['table']['error'] = '';
				return $state;
			}
			if ( 'done' === $state['table']['phase'] ) {
				$state['rows']  += (int) $state['table']['rows'];
				++$state['tables'];
				$state['table'] = null;
				wp_cache_flush();
			}
		}
		if ( $state['queue'] || null !== $state['table'] ) {
			return $state;
		}
		if ( null !== $state['files'] ) {
			$state['files'] = FileSync::step( $state['files'], $primary, $deadline );
			if ( ! empty( $state['files']['error'] ) ) {
				$state['error']          = 'files: ' . $state['files']['error'];
				$state['files']['error'] = '';
				return $state;
			}
			if ( 'restart' === $state['files']['phase'] ) {
				$state['files'] = null;
				$state['queue'] = null;
				$state['again'] = true;
				return $state;
			}
			if ( 'done' !== $state['files']['phase'] ) {
				return $state;
			}
			$state['list_at']  = (int) $state['files']['at'];
			$state['fetched'] += (int) $state['files']['fetched'];
			$state['removed'] += (int) $state['files']['removed'];
			$state['files']    = null;
		}
		$changed        = $state['rows'] > 0 || $state['tables'] > 0;
		$state['gen']   = max( (int) $state['gen'], (int) $state['target'] );
		$state['queue'] = null;
		$state['error'] = '';
		$state['ok_at'] = time();
		$state['lag']   = max( 0, time() - (int) $state['started'] );
		if ( ! empty( $state['full'] ) ) {
			$state['full_at'] = time();
		}
		wp_cache_flush();
		if ( $changed || $state['fetched'] || $state['removed'] ) {
			Purge::all();
		}
		$state['tables']  = 0;
		$state['fetched'] = 0;
		$state['removed'] = 0;
		return $state;
	}

	private static function table( array $job, array $primary, $from, $deadline ) {
		if ( 'start' === $job['phase'] ) {
			$reply = Link::call( $primary, 'describe', array( 'name' => $job['name'] ), false, 30 );
			if ( is_wp_error( $reply ) ) {
				$job['error'] = $reply->get_error_message();
				return $job;
			}
			$job['rows'] = 0;
			if ( empty( $reply['info'] ) ) {
				Db::drop_local( $job['name'] );
				$job['phase'] = 'done';
				return $job;
			}
			$job['info'] = (array) $reply['info'];
			$local       = Db::describe( $job['name'] );
			$job['full'] = ! $local || $local['schema'] !== $job['info']['schema'];
			if ( $job['full'] ) {
				$temp = Db::create_temp( $job['name'], (string) $reply['create'] );
				if ( is_wp_error( $temp ) ) {
					$job['error'] = $temp->get_error_message();
					return $job;
				}
				$job['target'] = $temp;
			}
			$sums = Link::call( $primary, 'sums', array( 'name' => $job['name'] ), false, 120 );
			if ( is_wp_error( $sums ) || ! isset( $sums['sums'] ) ) {
				$job['error'] = is_wp_error( $sums ) ? $sums->get_error_message() : 'no sums';
				return $job;
			}
			$remote = (array) $sums['sums'];
			if ( $job['full'] ) {
				$todo = array_keys( $remote );
			} else {
				$mine = Db::sums( array_merge( $job['info'], array( 'name' => $job['name'] ) ) );
				$mine = null === $mine ? array() : $mine;
				$todo = array();
				foreach ( array_unique( array_merge( array_keys( $remote ), array_keys( $mine ) ) ) as $bucket ) {
					if ( ! isset( $remote[ $bucket ], $mine[ $bucket ] ) || $remote[ $bucket ] !== $mine[ $bucket ] ) {
						$todo[] = $bucket;
					}
				}
			}
			$job['todo']   = array_values( array_map( 'intval', $todo ) );
			$job['remote'] = array_map( 'intval', array_keys( $remote ) );
			$job['phase']  = 'rows';
		}
		while ( 'rows' === $job['phase'] && microtime( true ) < $deadline ) {
			if ( ! $job['todo'] ) {
				if ( ! empty( $job['full'] ) ) {
					$error = Db::swap_temp( $job['name'] );
					if ( '' !== $error ) {
						$job['error'] = $error;
						return $job;
					}
				}
				$job['phase'] = 'done';
				return $job;
			}
			$wanted = array_values( array_intersect( array_slice( $job['todo'], 0, 200 ), $job['remote'] ) );
			$got    = array( 'rows' => array(), 'done' => array() );
			if ( $wanted ) {
				$raw = Link::call( $primary, 'rows', array( 'name' => $job['name'], 'buckets' => $wanted ), true, 120 );
				if ( is_wp_error( $raw ) ) {
					$job['error'] = $raw->get_error_message();
					return $job;
				}
				$got = json_decode( (string) gzdecode( $raw ), true );
				if ( ! is_array( $got ) || ! isset( $got['done'] ) ) {
					$job['error'] = 'bad rows';
					return $job;
				}
			}
			$handled = array();
			foreach ( array_slice( $job['todo'], 0, 200 ) as $bucket ) {
				$remote_has = in_array( $bucket, $job['remote'], true );
				if ( $remote_has && ! in_array( $bucket, (array) $got['done'], true ) ) {
					continue;
				}
				$rows  = $remote_has && isset( $got['rows'][ (string) $bucket ] ) ? (array) $got['rows'][ (string) $bucket ] : array();
				$error = Db::apply( array_merge( $job['info'], array( 'name' => $job['name'] ) ), $bucket, $rows, $from, ! empty( $job['full'] ) ? (string) $job['target'] : '' );
				if ( '' !== $error ) {
					$job['error'] = $error;
					return $job;
				}
				$job['rows'] += count( $rows );
				$handled[]    = $bucket;
			}
			if ( ! $handled ) {
				$job['error'] = 'no progress';
				return $job;
			}
			$job['todo'] = array_values( array_diff( $job['todo'], $handled ) );
		}
		return $job;
	}

	public static function view() {
		$state = self::state();
		return array(
			'gen'       => (int) $state['gen'],
			'announced' => (int) $state['announced'],
			'ok_at'     => (int) $state['ok_at'],
			'busy'      => null !== $state['queue'],
			'error'     => (string) $state['error'],
			'lag'       => (int) $state['lag'],
			'table'     => is_array( $state['table'] ) ? (string) $state['table']['name'] : '',
			'files'     => is_array( $state['files'] ) ? (string) $state['files']['phase'] : '',
			'contact'   => (int) $state['primary_at'],
		);
	}
}
