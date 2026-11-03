<?php

namespace Rafoo\Backup;

use Rafoo\Settings;
use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Maker {

	const SECRET = 'rafoo_backup_secret';

	const FULL_EVERY = 7;

	public static function prepare( array $args = array() ) {
		$id       = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		$parent   = null;
		$previous = '';
		if ( ! empty( $args['base_list'] ) ) {
			$previous = $args['base_list'];
		} elseif ( empty( $args['full'] ) && empty( $args['transfer'] ) && 'incremental' === Settings::get( 'backup_mode' ) ) {
			$last  = Catalog::latest();
			$chain = $last ? Catalog::chain( $last['id'] ) : array();
			if ( $chain && count( $chain ) < self::FULL_EVERY && time() - $chain[0]['created'] < self::FULL_EVERY * DAY_IN_SECONDS && is_file( Ledger::path( $last['id'] . '.list' ) ) ) {
				$parent   = $last['id'];
				$previous = Ledger::path( $last['id'] . '.list' );
			}
		}
		$work = Ledger::path( 'work-' . $id );
		if ( ! wp_mkdir_p( $work ) ) {
			return new \WP_Error( 'rafoo_storage', __( 'The uploads folder is not writable, so Rafoo cannot keep backups.', 'rafoo' ) );
		}
		$mode   = '';
		$secret = '';
		if ( ! empty( $args['key'] ) ) {
			$mode   = Crypt::KEY;
			$secret = bin2hex( $args['key'] );
		} elseif ( '' !== self::password() && Crypt::available() ) {
			$mode = Crypt::PASSWORD;
		}
		$targets = isset( $args['targets'] ) ? (array) $args['targets'] : array_keys( Destinations::all() );
		$data    = array(
			'role'      => 'backup',
			'backup'    => $id,
			'kind'      => '' !== $previous ? 'incremental' : 'full',
			'parent'    => $parent,
			'work'      => $work,
			'zip'       => $work . '/archive.zip',
			'sql'       => $work . '/database.sql',
			'list'      => Ledger::path( $id . '.list' ),
			'previous'  => $previous,
			'mode'      => $mode,
			'secret'    => $secret,
			'targets'   => array_values( array_filter( $targets, array( Destinations::class, 'get' ) ) ),
			'uploads'   => array(),
			'transfer'  => ! empty( $args['transfer'] ),
			'local'     => ! empty( $args['transfer'] ) || Settings::get( 'backup_local' ) || ! $targets,
			'created'   => time(),
			'manual'    => ! empty( $args['manual'] ),
		);
		return $data;
	}

	public static function start( array $args = array() ) {
		$data = self::prepare( $args );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$job = Job::start( 'backup', $data, 'db' );
		if ( is_wp_error( $job ) ) {
			Ledger::remove_dir( $data['work'] );
		}
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

	public function step( array $job, $deadline ) {
		$d = &$job['data'];
		switch ( $job['step'] ) {
			case 'db':
				if ( empty( $d['dump'] ) ) {
					$d['dump'] = Dump::start( $d['sql'] );
					if ( ! $d['dump'] ) {
						return Job::fail( $job, 'database file not writable' );
					}
				}
				$dump = Dump::export( $d['sql'], $d['dump'], $deadline );
				if ( false === $dump ) {
					return Job::fail( $job, 'database export failed' );
				}
				$d['dump']       = $dump;
				$job['progress'] = array(
					'done'  => $dump['table'],
					'total' => count( $dump['tables'] ),
				);
				if ( ! empty( $dump['done'] ) ) {
					$job['step'] = 'scan';
				}
				return $job;
			case 'scan':
				if ( empty( $d['scan'] ) ) {
					$d['scan'] = Files::start_scan( $d['list'] );
				}
				$scan = Files::scan( $d['list'], $d['scan'], $deadline );
				if ( false === $scan ) {
					return Job::fail( $job, 'file list not writable' );
				}
				$d['scan']       = $scan;
				$job['progress'] = array(
					'done'  => $scan['files'],
					'total' => 0,
				);
				if ( ! empty( $scan['done'] ) ) {
					$free = Ledger::free_space();
					if ( $free >= 0 && $free < $scan['bytes'] * 1.1 ) {
						return Job::fail( $job, 'disk_full' );
					}
					$job['step'] = 'pack';
					$d['pack']   = array(
						'offset' => 0,
						'packed' => 0,
					);
				}
				return $job;
			case 'pack':
				$previous = '' !== $d['previous'] ? Files::manifest( $d['previous'] ) : array();
				$pack     = Files::pack( $d['zip'], $d['list'], $d['pack'], $deadline, $previous );
				if ( false === $pack ) {
					return Job::fail( $job, 'archive could not be written' );
				}
				$d['pack']       = $pack;
				$job['progress'] = array(
					'done'  => $pack['packed'],
					'total' => $d['scan']['files'],
				);
				$job['yield']    = true;
				if ( ! empty( $pack['done'] ) ) {
					$job['step'] = 'finish';
				}
				return $job;
			case 'finish':
				$zip = new \ZipArchive();
				if ( true !== $zip->open( $d['zip'], \ZipArchive::CREATE ) ) {
					return Job::fail( $job, 'archive could not be written' );
				}
				$deleted = '' !== $d['previous'] ? Files::deleted( Files::manifest( $d['previous'] ), Files::manifest( $d['list'] ) ) : array();
				$zip->addFile( $d['sql'], 'database.sql' );
				$zip->addFromString( 'deleted.txt', implode( "\n", $deleted ) );
				$zip->addFromString( 'rafoo.json', wp_json_encode( self::meta( $job ) ) );
				if ( ! $zip->close() ) {
					return Job::fail( $job, 'archive could not be written' );
				}
				wp_delete_file( $d['sql'] );
				$d['archive'] = $d['zip'];
				$job['step']  = '' !== $d['mode'] ? 'encrypt' : 'hash';
				return $job;
			case 'encrypt':
				$target = $d['work'] . '/archive.rfb';
				if ( empty( $d['crypt'] ) ) {
					$d['crypt'] = Crypt::begin( $target, Crypt::KEY === $d['mode'] ? hex2bin( $d['secret'] ) : self::password(), $d['mode'] );
				}
				$crypt = $d['crypt'] ? Crypt::push( $d['zip'], $target, $d['crypt'], $deadline ) : false;
				if ( false === $crypt ) {
					return Job::fail( $job, 'encryption failed' );
				}
				$d['crypt']      = $crypt;
				$job['progress'] = array(
					'done'  => $crypt['offset'],
					'total' => filesize( $d['zip'] ),
				);
				if ( ! empty( $crypt['done'] ) ) {
					wp_delete_file( $d['zip'] );
					$d['archive'] = $target;
					$job['step']  = 'hash';
				}
				return $job;
			case 'hash':
				$name = 'rafoo-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . $d['backup'] . ( '' !== $d['mode'] ? '.rfb' : '.zip' );
				$file = Ledger::path( $name );
				if ( ! Ledger::move( $d['archive'], $file ) ) {
					return Job::fail( $job, 'archive could not be moved' );
				}
				$d['file']   = $name;
				$d['size']   = (int) filesize( $file );
				$d['sha256'] = hash_file( 'sha256', $file );
				Catalog::put( self::entry( $job, false ) );
				$job['step'] = $d['targets'] ? 'upload' : 'retain';
				return $job;
			case 'upload':
				return $this->upload( $job, $deadline );
			case 'retain':
				$entry             = self::entry( $job, true );
				$entry['complete'] = true;
				Catalog::put( $entry );
				if ( ! $d['local'] && array_filter( $entry['places'] ) ) {
					wp_delete_file( Ledger::path( $d['file'] ) );
					$entry['local'] = false;
					Catalog::put( $entry );
				}
				if ( ! $d['transfer'] ) {
					self::retain();
				}
				Ledger::remove_dir( $d['work'] );
				$job['status'] = 'done';
				$job['step']   = 'done';
				return $job;
		}
		return Job::fail( $job, 'unknown step' );
	}

	private function upload( array $job, $deadline ) {
		$d    = &$job['data'];
		$file = Ledger::path( $d['file'] );
		foreach ( $d['targets'] as $target ) {
			$offset = isset( $d['uploads'][ $target ] ) ? $d['uploads'][ $target ] : 0;
			if ( true === $offset || 'failed' === $offset ) {
				continue;
			}
			$remote = Destinations::remote( $target );
			if ( ! $remote || ! $remote->connect() || ! $remote->prepare_folder() ) {
				$d['uploads'][ $target ] = 'failed';
				Destinations::update( $target, array( 'last_error' => $remote ? $remote->error() : 'missing' ) );
				continue;
			}
			$sent = $remote->put( $file, $d['file'], (int) $offset, $deadline );
			if ( false === $sent ) {
				$d['uploads'][ $target ] = 'failed';
				Destinations::update( $target, array( 'last_error' => $remote->error() ) );
				$remote->close();
				continue;
			}
			if ( $sent >= $d['size'] ) {
				$ok                      = $remote->size( $d['file'] ) === $d['size'];
				$d['uploads'][ $target ] = $ok ? true : 'failed';
				Destinations::update( $target, $ok ? array( 'last_ok' => time(), 'last_error' => '' ) : array( 'last_error' => 'size mismatch' ) );
				if ( $ok ) {
					self::prune_remote( $remote, $target );
				}
			} else {
				$d['uploads'][ $target ] = (int) $sent;
			}
			$remote->close();
			$job['progress'] = array(
				'done'  => is_int( $d['uploads'][ $target ] ) ? $d['uploads'][ $target ] : $d['size'],
				'total' => $d['size'],
			);
			if ( microtime( true ) >= $deadline ) {
				return $job;
			}
		}
		$pending = array_filter(
			$d['targets'],
			static function ( $target ) use ( $d ) {
				$state = isset( $d['uploads'][ $target ] ) ? $d['uploads'][ $target ] : 0;
				return true !== $state && 'failed' !== $state;
			}
		);
		if ( ! $pending ) {
			$job['step'] = 'retain';
		}
		return $job;
	}

	public static function entry( array $job, $complete ) {
		$d      = $job['data'];
		$places = array();
		foreach ( $d['targets'] as $target ) {
			$places[ $target ] = isset( $d['uploads'][ $target ] ) && true === $d['uploads'][ $target ];
		}
		return array(
			'id'        => $d['backup'],
			'created'   => $d['created'],
			'kind'      => $d['kind'],
			'parent'    => $d['parent'],
			'file'      => $d['file'],
			'size'      => $d['size'],
			'sha256'    => $d['sha256'],
			'encrypted' => '' !== $d['mode'],
			'mode'      => $d['mode'],
			'local'     => true,
			'places'    => $places,
			'files'     => (int) $d['scan']['files'],
			'tables'    => count( $d['dump']['tables'] ),
			'wp'        => get_bloginfo( 'version' ),
			'site'      => home_url(),
			'transfer'  => $d['transfer'],
			'complete'  => $complete,
		);
	}

	public static function meta( array $job ) {
		global $wpdb;
		$d = $job['data'];
		return array(
			'format'    => 1,
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
			wp_delete_file( Ledger::path( $entry['file'] ) );
			wp_delete_file( Ledger::path( $id . '.list' ) );
			foreach ( array_keys( array_filter( (array) $entry['places'] ) ) as $target ) {
				$remote = Destinations::remote( $target );
				if ( $remote && $remote->connect() ) {
					$remote->delete( $entry['file'] );
					$remote->close();
				}
			}
			Catalog::forget( $id );
		}
	}

	private static function prune_remote( $remote, $target ) {
		$known = array();
		foreach ( Catalog::all() as $entry ) {
			$known[ $entry['file'] ] = true;
		}
		foreach ( $remote->names() as $name ) {
			if ( preg_match( '/^rafoo-.+-\d{8}-\d{6}-[a-z0-9]{4}\.(zip|rfb)$/', $name ) && ! isset( $known[ $name ] ) && false !== strpos( $name, sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ) {
				$remote->delete( $name );
			}
		}
	}

	public function cleanup( array $job ) {
		if ( ! empty( $job['data']['work'] ) ) {
			Ledger::remove_dir( $job['data']['work'] );
		}
	}

	public function finished( array $job ) {
		$d = $job['data'];
		if ( 'done' === $job['status'] ) {
			if ( empty( $d['transfer'] ) ) {
				State::event(
					'backup_done',
					array(
						'name' => size_format( isset( $d['size'] ) ? $d['size'] : 0 ),
						'to'   => $d['kind'],
					)
				);
			}
			Schedule::plan();
			return;
		}
		$this->cleanup( $job );
		$entry = ! empty( $d['backup'] ) ? Catalog::get( $d['backup'] ) : null;
		if ( $entry && empty( $entry['complete'] ) ) {
			wp_delete_file( Ledger::path( $entry['file'] ) );
			Catalog::forget( $d['backup'] );
		}
		State::event( 'backup_failed', array( 'name' => $job['error'] ) );
		Schedule::plan( HOUR_IN_SECONDS );
	}
}
