<?php

namespace Rafoo\Backup;

use Rafoo\Settings;
use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Maker {

	const SECRET = 'rafoo_backup_secret';

	const FULL_EVERY = 7;

	const FORMAT = 2;

	public static function prepare( array $args = array() ) {
		$id       = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		$rules    = self::rules();
		$parent   = null;
		$previous = '';
		if ( ! empty( $args['base_list'] ) && self::listed( $args['base_list'] ) ) {
			$previous = $args['base_list'];
		} elseif ( empty( $args['full'] ) && empty( $args['transfer'] ) && 'incremental' === Settings::get( 'backup_mode' ) ) {
			$last  = Catalog::latest();
			$chain = $last ? Catalog::chain( $last['id'] ) : array();
			$list  = $last ? Ledger::path( $last['id'] . '.list' ) : '';
			if ( $chain && count( $chain ) < self::FULL_EVERY && time() - $chain[0]['created'] < self::FULL_EVERY * DAY_IN_SECONDS && Catalog::format( $last ) >= self::FORMAT && isset( $last['rules'] ) && $last['rules'] === $rules && self::listed( $list ) ) {
				$parent   = $last['id'];
				$previous = $list;
			}
		}
		$work = Ledger::path( 'work-' . $id );
		if ( '' === $work || ! wp_mkdir_p( $work ) ) {
			return new \WP_Error( 'rafoo_storage', __( 'The uploads folder is not writable, so Rafoo cannot keep backups.', 'rafoo' ) );
		}
		$mode   = '';
		$secret = '';
		if ( ! empty( $args['key'] ) ) {
			$mode   = Crypt::KEY;
			$secret = $args['key'];
		} elseif ( '' !== self::password() && Crypt::available() ) {
			$mode   = Crypt::PASSWORD;
			$secret = self::password();
		}
		$cipher = null;
		if ( '' !== $mode ) {
			$salt   = random_bytes( 16 );
			$cipher = array(
				'key'  => Vault::seal( bin2hex( Crypt::derive( $secret, $mode, $salt ) ) ),
				'salt' => bin2hex( $salt ),
			);
		}
		$targets = isset( $args['targets'] ) ? (array) $args['targets'] : array_keys( Destinations::all() );
		$targets = array_values( array_filter( $targets, array( Destinations::class, 'get' ) ) );
		return array(
			'role'     => 'backup',
			'format'   => self::FORMAT,
			'backup'   => $id,
			'base'     => 'rafoo-' . self::host() . '-' . $id,
			'kind'     => '' !== $previous ? 'incremental' : 'full',
			'parent'   => $parent,
			'work'     => $work,
			'sql'      => $work . '/database.sql.gz',
			'list'     => Ledger::path( $id . '.list' ),
			'previous' => $previous,
			'rules'    => $rules,
			'mode'     => $mode,
			'cipher'   => $cipher,
			'targets'  => $targets,
			'uploads'  => array(),
			'failed'   => array(),
			'queue'    => array(),
			'resume'   => '',
			'manifest' => '',
			'transfer' => ! empty( $args['transfer'] ),
			'local'    => ! empty( $args['transfer'] ) || Settings::get( 'backup_local' ) || ! $targets,
			'created'  => time(),
			'manual'   => ! empty( $args['manual'] ),
			'warnings' => array(),
		);
	}

	public static function start( array $args = array() ) {
		$data = self::prepare( $args );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$job = Job::start( 'backup', $data, 'db' );
		if ( is_wp_error( $job ) ) {
			Ledger::remove_dir( $data['work'] );
			return $job;
		}
		Journal::add( $data['kind'] . ' backup ' . $data['backup'] . ( '' !== $data['mode'] ? ', encrypted' : '' ) . ', ' . count( $data['targets'] ) . ' servers, ' . ( $data['local'] ? 'kept on this host' : 'sent and removed from this host' ) . ', parts of ' . size_format( Packer::target() ) );
		return $job;
	}

	public static function password() {
		return Vault::open( (string) get_option( self::SECRET, '' ) );
	}

	public static function set_password( $password ) {
		if ( '' === (string) $password ) {
			delete_option( self::SECRET );
			return;
		}
		update_option( self::SECRET, Vault::seal( (string) $password ), false );
	}

	public static function host() {
		return sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	public static function rules() {
		return md5( wp_json_encode( array( Files::excluded(), (array) Settings::get( 'backup_exclude' ), Files::SKIP_DIRS, Files::SKIP_NAMES ) ) );
	}

	public static function listed( $list ) {
		if ( '' === (string) $list || ! is_file( $list ) ) {
			return false;
		}
		$in = Ledger::open( $list, 'rb' );
		return $in && Files::HEADER === (string) $in->fgets();
	}

	public function step( array $job, $deadline, $save = null ) {
		$d    = &$job['data'];
		$save = null !== $save ? $save : static function ( array $current ) {
			Job::save( $current );
		};
		$keep = static function ( array $data ) use ( &$job, $save ) {
			$job['data'] = $data;
			$job['mark'] = self::mark( $data );
			call_user_func( $save, $job );
		};
		switch ( $job['step'] ) {
			case 'db':
				if ( empty( $d['dump'] ) ) {
					$d['dump'] = Dump::begin( $d['sql'] );
					Journal::add( 'database: ' . count( $d['dump']['tables'] ) . ' tables, ' . size_format( array_sum( $d['dump']['sizes'] ), 1 ) );
				}
				$d['dump']       = Dump::dump(
					$d['dump'],
					$deadline,
					Job::tune( $job, 'memory' ),
					static function ( array $dump ) use ( &$d, $keep ) {
						$d['dump'] = $dump;
						call_user_func( $keep, $d );
					}
				);
				$dump            = $d['dump'];
				$job['progress'] = array(
					'done'  => Dump::done_bytes( $dump ),
					'total' => max( 1, array_sum( $dump['sizes'] ) ),
				);
				$job['info']     = array(
					'table'  => isset( $dump['tables'][ $dump['table'] ] ) ? $dump['tables'][ $dump['table'] ] : '',
					'tables' => count( $dump['tables'] ),
					'index'  => min( $dump['table'] + 1, count( $dump['tables'] ) ),
				);
				$job['mark']     = self::mark( $d );
				if ( ! empty( $dump['done'] ) ) {
					$d['sql_bytes'] = (int) $dump['sink']['raw'];
					Journal::add( 'database saved: ' . size_format( (int) filesize( $d['sql'] ), 1 ) . ' compressed from ' . size_format( $d['sql_bytes'], 1 ) );
					$job['step'] = 'scan';
				}
				return $job;
			case 'scan':
				if ( empty( $d['scan'] ) ) {
					$d['scan'] = Files::start_scan( $d['list'] );
				}
				$d['scan']       = Files::scan( $d['list'], $d['scan'], $deadline, $d['warnings'] );
				$job['progress'] = array(
					'done'  => $d['scan']['files'],
					'total' => 0,
				);
				$job['info']     = array(
					'files' => $d['scan']['files'],
					'bytes' => $d['scan']['bytes'],
				);
				$job['mark']     = self::mark( $d );
				if ( ! empty( $d['scan']['done'] ) ) {
					unset( $d['scan']['stack'] );
					Journal::add( 'files found: ' . $d['scan']['files'] . ', ' . size_format( $d['scan']['bytes'], 1 ) );
					$job['step'] = 'plan';
				}
				return $job;
			case 'plan':
				$needed = self::needed( $d );
				$free   = Ledger::free_space();
				Journal::add( 'space: about ' . size_format( $needed, 1 ) . ' needed on this host, ' . ( $free >= 0 ? size_format( $free, 1 ) : 'unknown' ) . ' free' );
				if ( $free >= 0 && $free < $needed ) {
					return Job::fail(
						$job,
						'disk_full',
						array(
							'free'   => $free,
							'needed' => $needed,
							'stream' => $d['local'] && $d['targets'] && ! $d['transfer'],
						)
					);
				}
				$d['pack']   = Packer::fresh( $d['previous'] );
				$job['step'] = 'pack';
				return $job;
			case 'pack':
				list( $data, $signal ) = ( new Packer() )->run( $d, $deadline, $keep, array( __CLASS__, 'meta' ) );
				$d                     = $data;
				$total                 = (int) $d['scan']['bytes'] + ( is_file( $d['sql'] ) ? (int) filesize( $d['sql'] ) : 0 );
				$job['progress']       = array(
					'done'  => min( $total, (int) $d['pack']['raw'] + (int) $d['pack']['skipped'] ),
					'total' => max( 1, $total ),
				);
				$job['info']           = array(
					'files'     => $d['pack']['files'],
					'total'     => $d['scan']['files'],
					'unchanged' => $d['pack']['unchanged'],
					'part'      => max( 1, $d['pack']['part'] ),
				);
				$job['mark']           = self::mark( $d );
				$job['yield']          = true;
				if ( 'part' === $signal && $d['queue'] ) {
					$d['resume'] = 'pack';
					$job['step'] = 'upload';
				} elseif ( 'done' === $signal ) {
					$job['step'] = 'manifest';
				}
				return $job;
			case 'manifest':
				$d['manifest'] = $d['base'] . '.json';
				$parts         = $d['pack']['parts'];
				$manifest      = array(
					'rafoo'     => RAFOO_VERSION,
					'format'    => self::FORMAT,
					'id'        => $d['backup'],
					'kind'      => $d['kind'],
					'parent'    => $d['parent'],
					'created'   => $d['created'],
					'encrypted' => '' !== $d['mode'],
					'mode'      => $d['mode'],
					'parts'     => array_map(
						static function ( $part ) {
							return array_intersect_key( $part, array_flip( array( 'name', 'size', 'sha256' ) ) );
						},
						$parts
					),
				);
				$file          = Ledger::path( $d['manifest'] );
				if ( false === file_put_contents( $file, wp_json_encode( $manifest ) ) ) {
					return Job::fail( $job, 'disk_full', array( 'free' => Ledger::free_space() ) );
				}
				$d['size'] = array_sum( array_column( $parts, 'size' ) );
				Catalog::put( self::entry( $job, false ) );
				Journal::add( 'backup written: ' . count( $parts ) . ' parts, ' . size_format( $d['size'], 1 ) . ', ' . $d['pack']['files'] . ' files' . ( $d['pack']['unchanged'] ? ', ' . $d['pack']['unchanged'] . ' unchanged' : '' ) . ( $d['warnings'] ? ', notes: ' . Warnings::summary( $d['warnings'] ) : '' ) );
				if ( $d['targets'] ) {
					$d['queue'][] = $d['manifest'];
					$d['resume']  = 'retain';
					$job['step']  = 'upload';
				} else {
					$job['step'] = 'retain';
				}
				return $job;
			case 'upload':
				return $this->upload( $job, $deadline );
			case 'retain':
				$entry             = self::entry( $job, true );
				$entry['complete'] = true;
				Catalog::put( $entry );
				foreach ( array_keys( array_filter( $entry['places'] ) ) as $target ) {
					$remote = Destinations::remote( $target );
					if ( $remote && $remote->connect() ) {
						self::prune_remote( $remote );
						$remote->close();
					}
				}
				if ( ! $d['transfer'] ) {
					self::retain();
				}
				Ledger::remove_dir( $d['work'] );
				Journal::add( 'done' );
				$job['status'] = 'done';
				$job['step']   = 'done';
				return $job;
		}
		return Job::fail( $job, 'internal', array( 'message' => 'unknown step ' . $job['step'] ) );
	}

	public static function mark( array $d ) {
		if ( isset( $d['pack'] ) ) {
			return 'p' . (int) $d['pack']['raw'] . '-' . (int) $d['pack']['files'] . '-' . count( $d['pack']['parts'] );
		}
		if ( isset( $d['scan'] ) ) {
			return 's' . (int) $d['scan']['files'];
		}
		if ( isset( $d['dump'] ) ) {
			return 'd' . (int) $d['dump']['table'] . '-' . (int) $d['dump']['rows'] . '-' . (int) $d['dump']['sink']['bytes'];
		}
		return 'start';
	}

	private static function needed( array $d ) {
		$sql      = is_file( $d['sql'] ) ? (int) filesize( $d['sql'] ) : 0;
		$scan     = $d['scan'];
		$estimate = $sql + (int) ( $scan['compressible'] * 0.45 ) + ( $scan['bytes'] - $scan['compressible'] );
		if ( 'incremental' === $d['kind'] ) {
			$estimate = $sql + (int) ( $estimate * 0.1 );
		}
		if ( ! $d['local'] && $d['targets'] ) {
			$estimate = min( $estimate, 2 * Packer::target() + $sql );
		}
		return $estimate + Packer::RESERVE;
	}

	private function upload( array $job, $deadline ) {
		$d = &$job['data'];
		while ( $d['queue'] && microtime( true ) < $deadline ) {
			$name = $d['queue'][0];
			$file = Ledger::path( $name );
			clearstatcache( true, $file );
			$size    = is_file( $file ) ? (int) filesize( $file ) : 0;
			$pending = false;
			foreach ( $d['targets'] as $target ) {
				if ( isset( $d['failed'][ $target ] ) ) {
					continue;
				}
				$state = isset( $d['uploads'][ $target ][ $name ] ) ? $d['uploads'][ $target ][ $name ] : 0;
				if ( true === $state ) {
					continue;
				}
				$remote = Destinations::remote( $target );
				if ( ! $remote || ! $remote->connect() || ! $remote->prepare_folder() ) {
					$this->drop_target( $d, $target, $remote ? $remote->error() : 'missing' );
					continue;
				}
				$sent = $remote->put( $file, $name, (int) $state, $deadline );
				if ( false === $sent ) {
					$this->drop_target( $d, $target, $remote->error() );
					$remote->close();
					continue;
				}
				if ( $sent >= $size ) {
					if ( $remote->size( $name ) !== $size ) {
						$this->drop_target( $d, $target, 'size mismatch' );
						$remote->close();
						continue;
					}
					$d['uploads'][ $target ][ $name ] = true;
				} else {
					$d['uploads'][ $target ][ $name ] = (int) $sent;
					$pending                         = true;
				}
				$remote->close();
				$job['progress'] = array(
					'done'  => true === $d['uploads'][ $target ][ $name ] ? $size : (int) $d['uploads'][ $target ][ $name ],
					'total' => max( 1, $size ),
				);
				$job['info']     = array(
					'server' => Destinations::label( $target ),
					'file'   => $name,
					'index'  => count( $d['pack']['parts'] ) - count( $d['queue'] ) + 1,
					'parts'  => count( $d['pack']['parts'] ),
				);
				if ( microtime( true ) >= $deadline ) {
					return $job;
				}
			}
			if ( $pending ) {
				return $job;
			}
			$sent_any = false;
			foreach ( $d['targets'] as $target ) {
				$sent_any = $sent_any || ( isset( $d['uploads'][ $target ][ $name ] ) && true === $d['uploads'][ $target ][ $name ] );
			}
			if ( ! $d['local'] && $sent_any && $name !== $d['manifest'] ) {
				wp_delete_file( $file );
			}
			array_shift( $d['queue'] );
			$job['mark'] = 'u' . count( $d['queue'] ) . '-' . $name;
		}
		if ( ! $d['queue'] ) {
			$job['step'] = '' !== $d['resume'] ? $d['resume'] : 'retain';
		}
		return $job;
	}

	private function drop_target( array &$d, $target, $error ) {
		$d['failed'][ $target ] = (string) $error;
		$d['warnings']          = Warnings::add( $d['warnings'], 'upload_failed', Destinations::label( $target ) . ': ' . $error );
		Destinations::update( $target, array( 'last_error' => (string) $error ) );
		Journal::add( 'server ' . Destinations::label( $target ) . ' failed: ' . $error );
	}

	public static function entry( array $job, $complete ) {
		$d      = $job['data'];
		$parts  = isset( $d['pack']['parts'] ) ? $d['pack']['parts'] : array();
		$names  = array_column( $parts, 'name' );
		$places = array();
		foreach ( $d['targets'] as $target ) {
			$ok = ! isset( $d['failed'][ $target ] ) && $names;
			foreach ( array_merge( $names, array( $d['manifest'] ) ) as $name ) {
				$ok = $ok && isset( $d['uploads'][ $target ][ $name ] ) && true === $d['uploads'][ $target ][ $name ];
			}
			$places[ $target ] = (bool) $ok;
			if ( $ok ) {
				Destinations::update( $target, array( 'last_ok' => time(), 'last_error' => '' ) );
			}
		}
		$local = (bool) $names;
		foreach ( $names as $name ) {
			$local = $local && is_file( Ledger::path( $name ) );
		}
		return array(
			'id'        => $d['backup'],
			'format'    => self::FORMAT,
			'created'   => $d['created'],
			'kind'      => $d['kind'],
			'parent'    => $d['parent'],
			'file'      => $d['manifest'],
			'parts'     => array_map(
				static function ( $part ) {
					return array_intersect_key( $part, array_flip( array( 'name', 'size', 'sha256' ) ) );
				},
				$parts
			),
			'size'      => isset( $d['size'] ) ? (int) $d['size'] : 0,
			'sha256'    => '',
			'encrypted' => '' !== $d['mode'],
			'mode'      => $d['mode'],
			'local'     => $local,
			'places'    => $places,
			'files'     => isset( $d['pack']['files'] ) ? (int) $d['pack']['files'] : 0,
			'tables'    => isset( $d['dump']['tables'] ) ? count( $d['dump']['tables'] ) : 0,
			'wp'        => get_bloginfo( 'version' ),
			'site'      => home_url(),
			'rules'     => $d['rules'],
			'warnings'  => $d['warnings'],
			'transfer'  => $d['transfer'],
			'complete'  => $complete,
		);
	}

	public static function meta( array $d ) {
		global $wpdb;
		return array(
			'format'    => self::FORMAT,
			'id'        => $d['backup'],
			'kind'      => $d['kind'],
			'parent'    => $d['parent'],
			'created'   => $d['created'],
			'home'      => home_url(),
			'siteurl'   => site_url(),
			'prefix'    => $wpdb->base_prefix,
			'wp'        => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'multisite' => is_multisite(),
			'tables'    => $d['dump']['tables'],
			'files'     => $d['scan']['files'],
			'sql_bytes' => isset( $d['sql_bytes'] ) ? (int) $d['sql_bytes'] : 0,
			'rafoo'     => RAFOO_VERSION,
		);
	}

	public static function retain() {
		$all  = Catalog::all();
		$keep = array_flip( Catalog::keep( array_values( $all ) ) );
		foreach ( $all as $id => $entry ) {
			if ( isset( $keep[ $id ] ) || ! empty( $entry['transfer'] ) ) {
				continue;
			}
			self::discard( $entry );
		}
	}

	public static function sweep( $age = HOUR_IN_SECONDS ) {
		$dir = Ledger::dir();
		if ( '' === $dir ) {
			return 0;
		}
		$known = array();
		foreach ( Catalog::all() as $entry ) {
			foreach ( Catalog::files( $entry ) as $name ) {
				$known[ $name ] = true;
			}
			$known[ $entry['id'] . '.list' ] = true;
		}
		$removed = 0;
		foreach ( (array) scandir( $dir, SCANDIR_SORT_NONE ) as $name ) {
			$name = (string) $name;
			$path = $dir . '/' . $name;
			if ( isset( $known[ $name ] ) || time() - (int) @filemtime( $path ) < $age ) {
				continue;
			}
			if ( is_dir( $path ) && preg_match( '/^(work|restore)-[A-Za-z0-9-]+$/', $name ) ) {
				Ledger::remove_dir( $path );
				++$removed;
			} elseif ( is_file( $path ) && ( preg_match( Catalog::PATTERN, $name ) || preg_match( '/^incoming-/', $name ) || preg_match( '/^\d{8}-\d{6}-[a-z0-9]{4}\.list$/', $name ) || preg_match( '/\.rafoo-part$|\.tmp$/', $name ) ) ) {
				wp_delete_file( $path );
				++$removed;
			}
		}
		if ( $removed ) {
			Journal::add( 'removed ' . $removed . ' files left by earlier interrupted tasks' );
		}
		return $removed;
	}

	public static function discard( array $entry ) {
		foreach ( Catalog::files( $entry ) as $name ) {
			wp_delete_file( Ledger::path( $name ) );
		}
		wp_delete_file( Ledger::path( $entry['id'] . '.list' ) );
		foreach ( array_keys( array_filter( (array) $entry['places'] ) ) as $target ) {
			$remote = Destinations::remote( $target );
			if ( $remote && $remote->connect() ) {
				foreach ( Catalog::files( $entry ) as $name ) {
					$remote->delete( $name );
				}
				$remote->close();
			}
		}
		Catalog::forget( $entry['id'] );
	}

	private static function prune_remote( $remote ) {
		$known = array();
		foreach ( Catalog::all() as $entry ) {
			foreach ( Catalog::files( $entry ) as $name ) {
				$known[ $name ] = true;
			}
		}
		$host = self::host();
		foreach ( $remote->names() as $name ) {
			if ( preg_match( Catalog::PATTERN, $name ) && ! isset( $known[ $name ] ) && 0 === strpos( $name, 'rafoo-' . $host . '-' ) ) {
				$remote->delete( $name );
			}
		}
	}

	public function cleanup( array $job ) {
		$d = $job['data'];
		if ( ! empty( $d['work'] ) ) {
			Ledger::remove_dir( $d['work'] );
		}
		$entry = ! empty( $d['backup'] ) ? Catalog::get( $d['backup'] ) : null;
		if ( $entry && ! empty( $entry['complete'] ) ) {
			return;
		}
		foreach ( isset( $d['pack']['parts'] ) ? $d['pack']['parts'] : array() as $part ) {
			wp_delete_file( Ledger::path( $part['name'] ) );
		}
		if ( ! empty( $d['pack']['current'] ) ) {
			wp_delete_file( Ledger::path( $d['pack']['current'] ) );
		}
		if ( ! empty( $d['manifest'] ) ) {
			wp_delete_file( Ledger::path( $d['manifest'] ) );
		}
		if ( ! empty( $d['list'] ) && empty( $d['transfer'] ) ) {
			wp_delete_file( $d['list'] );
		}
		if ( $entry ) {
			Catalog::forget( $entry['id'] );
		}
	}

	public function finished( array $job ) {
		$d = $job['data'];
		if ( 'done' === $job['status'] ) {
			if ( empty( $d['transfer'] ) ) {
				State::event(
					'backup_done',
					array(
						'name'  => size_format( isset( $d['size'] ) ? $d['size'] : 0 ),
						'bytes' => isset( $d['size'] ) ? (int) $d['size'] : 0,
						'to'    => $d['kind'],
					)
				);
			}
			Schedule::plan();
			return;
		}
		$this->cleanup( $job );
		State::event( 'backup_failed', array( 'name' => $job['error'] ) );
		Schedule::plan( HOUR_IN_SECONDS );
	}
}
