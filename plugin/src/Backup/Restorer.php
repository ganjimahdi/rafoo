<?php

namespace Rafoo\Backup;

use Rafoo\Safety\Health;
use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Restorer {

	const PRESERVE = array( 'rafoo_private', 'rafoo_backups', 'rafoo_backup_destinations', 'rafoo_backup_secret', 'rafoo_transfer_invite', 'rafoo_transfer_outgoing', 'rafoo_geo', 'rafoo_geo_secrets', 'rafoo_geo_state' );

	const HARD = 8;

	public static function start( $id, $password = '' ) {
		return self::begin( 'restore', Catalog::chain( $id ), $password );
	}

	public static function check( $id, $password = '' ) {
		$entry = Catalog::get( $id );
		return self::begin( 'check', $entry ? array( $entry ) : array(), $password );
	}

	private static function begin( $role, array $chain, $password ) {
		if ( ! $chain ) {
			return new \WP_Error( 'rafoo_not_found', __( 'This backup is no longer in the list.', 'rafoo' ) );
		}
		$items = array();
		foreach ( $chain as $entry ) {
			if ( ! empty( $entry['encrypted'] ) && Crypt::PASSWORD === $entry['mode'] && '' === $password && '' === Maker::password() ) {
				return new \WP_Error( 'rafoo_password', __( 'This backup is encrypted. Enter its password.', 'rafoo' ) );
			}
			if ( Catalog::format( $entry ) < 2 && 'check' === $role ) {
				return new \WP_Error( 'rafoo_old_format', __( 'Backups made before Rafoo 1.4 cannot be checked. They can still be restored.', 'rafoo' ) );
			}
			if ( Catalog::format( $entry ) < 2 && ! class_exists( 'ZipArchive' ) ) {
				return new \WP_Error( 'rafoo_old_format', __( 'This backup was made before Rafoo 1.4 and needs the PHP ZipArchive extension to be restored. Ask your host to enable it.', 'rafoo' ) );
			}
			$items[] = self::item( $entry );
		}
		$data = self::data( $role, $items, '' !== $password ? Vault::seal( $password ) : '', '' );
		return Job::start( $role, $data, Catalog::format( $chain[0] ) >= 2 ? 'part' : 'fetch' );
	}

	public static function item( array $entry ) {
		return array(
			'id'     => $entry['id'],
			'format' => Catalog::format( $entry ),
			'file'   => (string) $entry['file'],
			'parts'  => isset( $entry['parts'] ) ? array_values( (array) $entry['parts'] ) : array(),
			'size'   => (int) $entry['size'],
			'sha256' => isset( $entry['sha256'] ) ? (string) $entry['sha256'] : '',
			'mode'   => isset( $entry['mode'] ) ? $entry['mode'] : '',
			'places' => array_keys( array_filter( (array) $entry['places'] ) ),
			'kind'   => $entry['kind'],
		);
	}

	public static function data( $role, array $items, $password, $key ) {
		$work = Ledger::path( 'restore-' . strtolower( wp_generate_password( 6, false ) ) );
		wp_mkdir_p( $work );
		$total = 0;
		foreach ( $items as $item ) {
			$total += (int) $item['size'];
		}
		return array(
			'role'     => $role,
			'chain'    => $items,
			'index'    => 0,
			'part'     => 0,
			'unpack'   => null,
			'verified' => false,
			'fetched'  => array(),
			'retries'  => array(),
			'keys'     => array(),
			'done'     => 0,
			'total'    => $total,
			'stats'    => array(
				'restored' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'removed'  => 0,
				'entries'  => 0,
				'bytes'    => 0,
				'samples'  => array(),
			),
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
			case 'part':
				return $this->part( $job, $deadline );
			case 'unpack':
				return $this->unpack( $job, $deadline );
			case 'apply':
				if ( empty( $d['apply'] ) ) {
					$d['apply'] = array(
						'offset'  => 0,
						'removed' => 0,
					);
				}
				$d['apply'] = Files::remove_listed( $d['work'] . '/deleted.txt', $d['apply'], $deadline );
				if ( empty( $d['apply']['done'] ) ) {
					return $job;
				}
				$d['stats']['removed'] += $d['apply']['removed'];
				$d['apply']             = null;
				wp_delete_file( $d['work'] . '/deleted.txt' );
				if ( $d['index'] < count( $d['chain'] ) - 1 ) {
					++$d['index'];
					$d['part']   = 0;
					$job['step'] = 'part';
					return $job;
				}
				$job['step'] = 'database';
				return $job;
			case 'database':
				global $wpdb;
				if ( ! is_array( $d['meta'] ) || ! is_file( $d['work'] . '/database.sql.gz' ) ) {
					return Job::fail( $job, 'damaged', array( 'part' => 'rafoo/database.sql.gz' ) );
				}
				Dump::drop_prefixed( Dump::TEMP );
				$d['import'] = array(
					'source'     => null,
					'from'       => (string) $d['meta']['prefix'],
					'to'         => ! empty( $d['geo'] ) ? (string) $d['meta']['prefix'] : $wpdb->base_prefix,
					'created'    => array(),
					'errors'     => array(),
					'duplicates' => 0,
					'raw'        => 0,
				);
				Journal::add( 'files restored: ' . $d['stats']['restored'] . ' written, ' . $d['stats']['skipped'] . ' already the same, ' . $d['stats']['removed'] . ' removed, ' . $d['stats']['failed'] . ' could not be written' );
				$job['step'] = 'load';
				return $job;
			case 'load':
				$import          = Dump::load( $d['work'] . '/database.sql.gz', $d['import'], $deadline, $deadline + self::HARD );
				$d['import']     = $import;
				$job['progress'] = array(
					'done'  => (int) $import['raw'],
					'total' => max( 1, isset( $d['meta']['sql_bytes'] ) ? (int) $d['meta']['sql_bytes'] : (int) $import['raw'] ),
				);
				$job['mark']     = 'l' . (int) $import['raw'];
				if ( ! empty( $import['done'] ) ) {
					if ( ! in_array( Dump::TEMP . $d['import']['to'] . 'options', $import['created'], true ) ) {
						Dump::drop_prefixed( Dump::TEMP );
						return Job::fail( $job, 'damaged', array( 'part' => 'rafoo/database.sql.gz' ) );
					}
					foreach ( $import['errors'] as $failed ) {
						$d['warnings'] = Warnings::add( isset( $d['warnings'] ) ? $d['warnings'] : array(), 'db_errors', $failed );
					}
					Journal::add( 'database restored into ' . count( $import['created'] ) . ' tables' . ( $import['errors'] ? ', ' . count( $import['errors'] ) . ' statements failed, first: ' . $import['errors'][0] : '' ) );
					Dump::fix_prefix( $d['import']['from'], $d['import']['to'] );
					$job['step'] = 'replace';
				}
				return $job;
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
					return Job::fail( $job, 'db_import', array( 'error' => isset( $d['import']['errors'][0] ) ? $d['import']['errors'][0] : '' ) );
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
				if ( ! empty( $d['geo'] ) ) {
					$job['step'] = 'swap';
					return $job;
				}
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
					global $wpdb;
					return Job::fail( $job, 'db_swap', array( 'error' => Journal::clean( $wpdb->last_error ) ) );
				}
				$d['live'] = $live;
				self::put_back( $keep, $d['import']['to'] . 'options' );
				if ( ! empty( $d['geo'] ) ) {
					$old = \Rafoo\Geo\Config::set_prefix( $d['import']['to'] );
					if ( is_wp_error( $old ) ) {
						Dump::undo( $d['live'] );
						return Job::fail( $job, 'not_writable', array( 'path' => 'wp-config.php' ) );
					}
					$d['geo_prefix'] = $old;
				}
				wp_cache_flush();
				$job['step'] = 'check';
				return $job;
			case 'check':
				$local   = ! empty( $d['geo'] ) ? \Rafoo\Geo\Edge::local_probes() : null;
				$results = Health::probe();
				if ( $local ) {
					call_user_func( $local );
				}
				if ( ! Health::healthy( $results ) ) {
					$d['reason'] = Health::summary( $results );
					if ( ! empty( $d['geo_prefix'] ) ) {
						\Rafoo\Geo\Config::set_prefix( $d['geo_prefix'] );
					}
					Dump::undo( $d['live'] );
					wp_cache_flush();
					return Job::fail( $job, 'site_broken', array( 'reason' => Journal::clean( $d['reason'] ) ) );
				}
				Dump::drop_prefixed( Dump::OLD );
				Ledger::remove_dir( $d['work'] );
				$d['notice']   = 'login_again';
				$job['status'] = 'done';
				$job['step']   = 'done';
				return $job;
		}
		return Job::fail( $job, 'internal', array( 'message' => 'unknown step ' . $job['step'] ) );
	}

	private function part( array $job, $deadline ) {
		$d     = &$job['data'];
		$item  = $d['chain'][ $d['index'] ];
		$part  = $item['parts'][ $d['part'] ];
		$local = self::local_path( $d, $part['name'] );
		clearstatcache( true, $local );
		$have = is_file( $local ) ? (int) filesize( $local ) : 0;
		if ( $have < (int) $part['size'] ) {
			if ( 0 === $have ) {
				$free = Ledger::free_space();
				if ( $free >= 0 && $free < (int) $part['size'] + Packer::RESERVE ) {
					return Job::fail(
						$job,
						'disk_full',
						array(
							'free'   => $free,
							'needed' => (int) $part['size'] + Packer::RESERVE,
						)
					);
				}
			}
			$d['fetched'][ $part['name'] ] = true;
			return 'receive' === $d['role'] ? Transfer::pull_part( $job, $deadline, $part, $local ) : $this->fetch_part( $job, $deadline, $item, $part, $local );
		}
		if ( ! $d['verified'] ) {
			$job['info'] = array(
				'part'  => $d['part'] + 1,
				'parts' => count( $item['parts'] ),
			);
			if ( $have !== (int) $part['size'] || ! hash_equals( (string) $part['sha256'], (string) hash_file( 'sha256', $local ) ) ) {
				$tries = isset( $d['retries'][ $part['name'] ] ) ? (int) $d['retries'][ $part['name'] ] : 0;
				if ( ! empty( $d['fetched'][ $part['name'] ] ) && $tries < 2 ) {
					$d['retries'][ $part['name'] ] = $tries + 1;
					wp_delete_file( $local );
					Journal::add( 'part ' . $part['name'] . ' arrived damaged, downloading it again' );
					return $job;
				}
				return Job::fail( $job, 'damaged', array( 'part' => $part['name'] ) );
			}
			$d['verified'] = true;
		}
		$d['unpack'] = Archive\Unpack::begin( $local, $this->key( $d, $item, $local ) );
		$job['step'] = 'unpack';
		return $job;
	}

	private function unpack( array $job, $deadline ) {
		$d       = &$job['data'];
		$item    = $d['chain'][ $d['index'] ];
		$part    = $item['parts'][ $d['part'] ];
		$last    = $d['index'] === count( $d['chain'] ) - 1;
		$handler = new Extract( $d['work'], $last, 'check' === $d['role'] );
		$reader  = new Archive\Unpack( $d['unpack'], $handler );
		$result  = $reader->run( $deadline, $deadline + self::HARD );
		$offset  = $result['done'] ? (int) $part['size'] : (int) $result['state']['source']['offset'];
		$job['progress'] = array(
			'done'  => $d['done'] + $offset,
			'total' => max( 1, $d['total'] ),
		);
		$job['info']     = array(
			'part'     => $d['part'] + 1,
			'parts'    => count( $item['parts'] ),
			'item'     => $d['index'] + 1,
			'items'    => count( $d['chain'] ),
			'restored' => $d['stats']['restored'] + (int) $handler->state()['restored'],
		);
		$job['mark']     = 'r' . $d['index'] . '-' . $d['part'] . '-' . $offset;
		$job['yield']    = true;
		if ( ! $result['done'] ) {
			$d['unpack'] = $result['state'];
			return $job;
		}
		$stats = $handler->state();
		foreach ( array( 'restored', 'skipped', 'failed', 'entries', 'bytes' ) as $key ) {
			$d['stats'][ $key ] += (int) $stats[ $key ];
		}
		$d['stats']['samples'] = array_slice( array_merge( $d['stats']['samples'], (array) $stats['samples'] ), 0, 5 );
		if ( $stats['failed'] > 0 ) {
			foreach ( (array) $stats['samples'] as $sample ) {
				$d['warnings'] = Warnings::add( isset( $d['warnings'] ) ? $d['warnings'] : array(), 'not_restored', (string) base64_decode( $sample ) );
			}
		}
		$meta = $handler->meta();
		if ( $meta && $last ) {
			$d['meta'] = $meta;
		}
		$d['done']    += (int) $part['size'];
		$d['unpack']   = null;
		$d['verified'] = false;
		if ( ! empty( $d['fetched'][ $part['name'] ] ) || 'receive' === $d['role'] ) {
			wp_delete_file( self::local_path( $d, $part['name'] ) );
		}
		++$d['part'];
		if ( $d['part'] < count( $item['parts'] ) ) {
			$job['step'] = 'part';
			return $job;
		}
		if ( 'check' === $d['role'] ) {
			Journal::add( 'check finished: ' . $d['stats']['entries'] . ' entries, ' . size_format( $d['stats']['bytes'], 1 ) . ', every part opened without errors' );
			$d['notice']   = 'checked';
			$job['status'] = 'done';
			$job['step']   = 'done';
			return $job;
		}
		$job['step'] = 'apply';
		return $job;
	}

	private function fetch_part( array $job, $deadline, array $item, array $part, $local ) {
		$d = &$job['data'];
		foreach ( $item['places'] as $target ) {
			$remote = Destinations::remote( $target );
			if ( ! $remote || ! $remote->connect() ) {
				continue;
			}
			clearstatcache( true, $local );
			$offset = is_file( $local ) ? (int) filesize( $local ) : 0;
			$got    = $remote->get( $part['name'], $local, $offset, $deadline );
			$remote->close();
			if ( false === $got ) {
				continue;
			}
			$job['progress'] = array(
				'done'  => $d['done'] + (int) $got,
				'total' => max( 1, $d['total'] ),
			);
			$job['info']     = array(
				'server' => Destinations::label( $target ),
				'part'   => $d['part'] + 1,
				'parts'  => count( $item['parts'] ),
			);
			$job['mark']     = 'f' . $d['index'] . '-' . $d['part'] . '-' . (int) $got;
			return $job;
		}
		return Job::fail( $job, 'unreachable', array( 'part' => $part['name'] ) );
	}

	private function key( array &$d, array $item, $local ) {
		if ( '' === (string) $item['mode'] ) {
			return null;
		}
		$head = Archive\Source::head( $local );
		if ( ! $head['encrypted'] ) {
			throw new Problem( 'damaged', array( 'part' => basename( $local ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$salt = bin2hex( $head['salt'] );
		if ( isset( $d['keys'][ $salt ] ) ) {
			return hex2bin( Vault::open( $d['keys'][ $salt ] ) );
		}
		if ( Crypt::KEY === $head['mode'] ) {
			$secret = hex2bin( $d['key'] );
		} else {
			$secret = '' !== $d['password'] ? Vault::open( $d['password'] ) : Maker::password();
		}
		$key                 = Crypt::derive( $secret, $head['mode'], $head['salt'] );
		$d['keys'][ $salt ] = Vault::seal( bin2hex( $key ) );
		return $key;
	}

	public static function local_path( array $d, $name ) {
		return Ledger::path( ( 'receive' === $d['role'] ? 'incoming-' : '' ) . $name );
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

	public static function put_back( array $values, $table = '' ) {
		global $wpdb;
		$table = '' !== $table ? $table : $wpdb->options;
		foreach ( self::PRESERVE as $name ) {
			if ( isset( $values[ $name ] ) ) {
				$wpdb->replace(
					$table,
					array(
						'option_name'  => $name,
						'option_value' => $values[ $name ],
						'autoload'     => 'no',
					)
				);
			} else {
				$wpdb->delete( $table, array( 'option_name' => $name ) );
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
		if ( 'check' === $d['role'] ) {
			foreach ( array_keys( (array) $d['fetched'] ) as $name ) {
				wp_delete_file( self::local_path( $d, $name ) );
			}
			Ledger::remove_dir( $d['work'] );
			return;
		}
		if ( 'done' !== $job['status'] ) {
			$this->cleanup( $job );
			foreach ( array_keys( (array) $d['fetched'] ) as $name ) {
				wp_delete_file( self::local_path( $d, $name ) );
			}
		}
		if ( 'receive' === $d['role'] ) {
			Transfer::received( $job );
			return;
		}
		State::event( 'done' === $job['status'] ? 'restore_done' : 'restore_failed', array( 'name' => $job['error'] ) );
	}
}
