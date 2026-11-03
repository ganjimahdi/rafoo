<?php

namespace Rafoo\Backup;

use Rafoo\Safety\Health;
use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Restorer {

	const PRESERVE = array( 'rafoo_private', 'rafoo_backups', 'rafoo_backup_destinations', 'rafoo_backup_secret', 'rafoo_transfer_invite', 'rafoo_transfer_outgoing' );

	public static function start( $id, $password = '' ) {
		$chain = Catalog::chain( $id );
		if ( ! $chain ) {
			return new \WP_Error( 'rafoo_not_found', __( 'This backup is no longer in the list.', 'rafoo' ) );
		}
		$items = array();
		foreach ( $chain as $entry ) {
			if ( ! empty( $entry['encrypted'] ) && Crypt::PASSWORD === $entry['mode'] && '' === $password && '' === Maker::password() ) {
				return new \WP_Error( 'rafoo_password', __( 'This backup is encrypted. Enter its password.', 'rafoo' ) );
			}
			$items[] = array(
				'id'     => $entry['id'],
				'file'   => $entry['file'],
				'size'   => (int) $entry['size'],
				'sha256' => $entry['sha256'],
				'mode'   => isset( $entry['mode'] ) ? $entry['mode'] : '',
				'places' => array_keys( array_filter( (array) $entry['places'] ) ),
				'kind'   => $entry['kind'],
			);
		}
		return Job::start(
			'restore',
			self::data( 'restore', $items, '' !== $password ? Vault::seal( $password ) : '', '' ),
			'fetch'
		);
	}

	public static function data( $role, array $items, $password, $key ) {
		$work = Ledger::path( 'restore-' . strtolower( wp_generate_password( 6, false ) ) );
		wp_mkdir_p( $work );
		return array(
			'role'     => $role,
			'chain'    => $items,
			'index'    => 0,
			'password' => $password,
			'key'      => $key,
			'work'     => $work,
			'offset'   => 0,
			'notice'   => '',
		);
	}

	public function step( array $job, $deadline ) {
		$d    = &$job['data'];
		$item = isset( $d['chain'][ $d['index'] ] ) ? $d['chain'][ $d['index'] ] : null;
		switch ( $job['step'] ) {
			case 'pull':
				return Transfer::pull( $job, $deadline );
			case 'fetch':
				$local = Ledger::path( $item['file'] );
				if ( is_file( $local ) && filesize( $local ) === $item['size'] ) {
					$job['step'] = 'verify';
					return $job;
				}
				return $this->fetch( $job, $deadline );
			case 'verify':
				$local = Ledger::path( $item['file'] );
				if ( ! hash_equals( $item['sha256'], (string) hash_file( 'sha256', $local ) ) ) {
					return Job::fail( $job, 'damaged' );
				}
				$job['step'] = '' !== $item['mode'] ? 'decrypt' : 'files';
				$d['zip']    = '' !== $item['mode'] ? $d['work'] . '/' . $d['index'] . '.zip' : $local;
				return $job;
			case 'decrypt':
				$local = Ledger::path( $item['file'] );
				if ( empty( $d['crypt'] ) ) {
					$secret     = Crypt::KEY === $item['mode'] ? hex2bin( $d['key'] ) : ( '' !== $d['password'] ? Vault::open( $d['password'] ) : Maker::password() );
					$d['crypt'] = Crypt::open( $local, $secret );
					if ( ! $d['crypt'] ) {
						return Job::fail( $job, 'wrong_password' );
					}
					$d['crypt']['written'] = 0;
				}
				$crypt = Crypt::pull( $local, $d['zip'], $d['crypt'], $deadline );
				if ( false === $crypt ) {
					return Job::fail( $job, 'wrong_password' );
				}
				$d['crypt']      = $crypt;
				$job['progress'] = array(
					'done'  => $crypt['offset'],
					'total' => $item['size'],
				);
				if ( ! empty( $crypt['done'] ) ) {
					unset( $d['crypt'] );
					$job['step'] = 'files';
				}
				return $job;
			case 'files':
				if ( empty( $d['extract'] ) ) {
					$d['extract'] = array(
						'index'    => 0,
						'restored' => 0,
						'errors'   => array(),
					);
				}
				$extract = Files::extract( $d['zip'], $d['extract'], $deadline );
				if ( false === $extract ) {
					return Job::fail( $job, 'damaged' );
				}
				$d['extract']    = $extract;
				$job['progress'] = array(
					'done'  => $extract['index'],
					'total' => 0,
				);
				$job['yield']    = true;
				if ( empty( $extract['done'] ) ) {
					return $job;
				}
				$zip = new \ZipArchive();
				if ( true === $zip->open( $d['zip'] ) ) {
					$deleted = (string) $zip->getFromName( 'deleted.txt' );
					$zip->close();
					Files::remove( array_filter( explode( "\n", $deleted ) ) );
				}
				unset( $d['extract'] );
				if ( $d['index'] < count( $d['chain'] ) - 1 ) {
					if ( $d['zip'] !== Ledger::path( $item['file'] ) ) {
						wp_delete_file( $d['zip'] );
					}
					++$d['index'];
					$job['step'] = 'restore' === $d['role'] ? 'fetch' : 'verify';
					return $job;
				}
				$job['step'] = 'sql';
				return $job;
			case 'sql':
				$zip = new \ZipArchive();
				if ( true !== $zip->open( $d['zip'] ) ) {
					return Job::fail( $job, 'damaged' );
				}
				$meta = json_decode( (string) $zip->getFromName( 'rafoo.json' ), true );
				$zip->close();
				if ( ! is_array( $meta ) || ! Files::copy_out( $d['zip'], 'database.sql', $d['work'] . '/database.sql' ) ) {
					return Job::fail( $job, 'damaged' );
				}
				global $wpdb;
				Dump::drop_prefixed( Dump::TEMP );
				$d['meta']   = array_intersect_key( $meta, array_flip( array( 'home', 'siteurl', 'prefix', 'wp', 'multisite' ) ) );
				$d['import'] = array(
					'offset'  => 0,
					'from'    => (string) $meta['prefix'],
					'to'      => $wpdb->base_prefix,
					'created' => array(),
					'errors'  => array(),
				);
				$job['step'] = 'import';
				return $job;
			case 'import':
				$import = Dump::import( $d['work'] . '/database.sql', $d['import'], $deadline );
				if ( false === $import ) {
					Dump::drop_prefixed( Dump::TEMP );
					return Job::fail( $job, 'database import failed' );
				}
				$d['import']     = $import;
				$job['progress'] = array(
					'done'  => $import['offset'],
					'total' => (int) filesize( $d['work'] . '/database.sql' ),
				);
				if ( ! empty( $import['done'] ) ) {
					if ( ! in_array( Dump::TEMP . $d['import']['to'] . 'options', $import['created'], true ) ) {
						Dump::drop_prefixed( Dump::TEMP );
						return Job::fail( $job, 'damaged' );
					}
					Dump::fix_prefix( $d['import']['from'], $d['import']['to'] );
					$job['step'] = 'replace';
				}
				return $job;
			case 'replace':
				$pairs = array_merge( Replace::pairs( $d['meta']['home'], home_url() ), Replace::pairs( $d['meta']['siteurl'], site_url() ) );
				if ( ! $pairs ) {
					$job['step'] = 'swap';
					return $job;
				}
				if ( empty( $d['replace'] ) ) {
					$d['replace'] = Replace::start( array_unique( $d['import']['created'] ) );
				}
				$d['replace'] = Replace::run( $d['replace'], $pairs, $deadline );
				if ( ! empty( $d['replace']['done'] ) ) {
					$job['step'] = 'swap';
				}
				return $job;
			case 'swap':
				$keep = self::preserved();
				$live = Dump::swap( $d['import']['created'], $d['import']['to'] );
				if ( false === $live ) {
					Dump::drop_prefixed( Dump::TEMP );
					return Job::fail( $job, 'database swap failed' );
				}
				$d['live'] = $live;
				self::put_back( $keep );
				wp_cache_flush();
				$job['step'] = 'check';
				return $job;
			case 'check':
				$results = Health::probe();
				if ( ! Health::healthy( $results ) ) {
					$d['reason'] = Health::summary( $results );
					Dump::undo( $d['live'] );
					wp_cache_flush();
					return Job::fail( $job, 'site_broken' );
				}
				Dump::drop_prefixed( Dump::OLD );
				Ledger::remove_dir( $d['work'] );
				$d['notice']   = 'login_again';
				$job['status'] = 'done';
				$job['step']   = 'done';
				return $job;
		}
		return Job::fail( $job, 'unknown step' );
	}

	private function fetch( array $job, $deadline ) {
		$d     = &$job['data'];
		$item  = $d['chain'][ $d['index'] ];
		$local = Ledger::path( $item['file'] );
		foreach ( $item['places'] as $target ) {
			$remote = Destinations::remote( $target );
			if ( ! $remote || ! $remote->connect() ) {
				continue;
			}
			$offset = is_file( $local ) ? (int) filesize( $local ) : 0;
			$got    = $remote->get( $item['file'], $local, $offset, $deadline );
			$remote->close();
			if ( false === $got ) {
				continue;
			}
			$job['progress'] = array(
				'done'  => $got,
				'total' => $item['size'],
			);
			if ( $got >= $item['size'] ) {
				$job['step'] = 'verify';
			}
			return $job;
		}
		return Job::fail( $job, 'unreachable' );
	}

	public static function preserved() {
		global $wpdb;
		$out = array();
		foreach ( self::PRESERVE as $name ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	public static function put_back( array $values ) {
		global $wpdb;
		foreach ( self::PRESERVE as $name ) {
			if ( isset( $values[ $name ] ) ) {
				$wpdb->replace(
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => $values[ $name ],
						'autoload'     => 'no',
					)
				);
			} else {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
			}
		}
	}

	public function cleanup( array $job ) {
		Dump::drop_prefixed( Dump::TEMP );
		if ( ! empty( $job['data']['work'] ) ) {
			Ledger::remove_dir( $job['data']['work'] );
		}
	}

	public function finished( array $job ) {
		$d = $job['data'];
		if ( 'done' !== $job['status'] ) {
			$this->cleanup( $job );
		}
		if ( 'receive' === $d['role'] ) {
			Transfer::received( $job );
			return;
		}
		State::event( 'done' === $job['status'] ? 'restore_done' : 'restore_failed', array( 'name' => $job['error'] ) );
	}
}
