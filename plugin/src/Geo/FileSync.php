<?php

namespace Rafoo\Geo;

use Rafoo\Assets\Store as Assets;
use Rafoo\Backup\Archive\Tar;
use Rafoo\Backup\Archive\Unpack;
use Rafoo\Backup\Extract;
use Rafoo\Backup\Files;
use Rafoo\Backup\Ledger;

defined( 'ABSPATH' ) || exit;

final class FileSync {

	const CHUNK = 4194304;

	const SMALL = 1048576;

	const BATCH = 400;

	const CHANGED = 'RAFOO-LIST-CHANGED';

	public static function fresh( array $meta ) {
		return array(
			'phase'   => is_file( Lister::path( 'applied.list' ) ) ? 'list' : 'local',
			'scan'    => null,
			'size'    => (int) $meta['size'],
			'at'      => (int) $meta['at'],
			'got'     => 0,
			'offset'  => 0,
			'big'     => null,
			'fetched' => 0,
			'bytes'   => 0,
			'removed' => 0,
			'failed'  => 0,
		);
	}

	public static function step( array $job, array $primary, $deadline ) {
		switch ( $job['phase'] ) {
			case 'local':
				return self::local( $job, $deadline );
			case 'list':
				return self::download( $job, $primary, $deadline );
			case 'diff':
				return self::diff( $job );
			case 'fetch':
				return self::fetch( $job, $primary, $deadline );
			case 'gone':
				return self::gone( $job, $deadline );
		}
		return $job;
	}

	private static function local( array $job, $deadline ) {
		$next     = Lister::path( 'applied.next' );
		$warnings = array();
		if ( null === $job['scan'] ) {
			$job['scan'] = Files::start_scan( $next );
		}
		$job['scan'] = Files::scan( $next, $job['scan'], $deadline, $warnings, Lister::rules() );
		if ( ! empty( $job['scan']['done'] ) ) {
			Ledger::move( $next, Lister::path( 'applied.list' ) );
			$job['scan']  = null;
			$job['phase'] = 'list';
		}
		return $job;
	}

	private static function download( array $job, array $primary, $deadline ) {
		$part = Lister::path( 'primary.part' );
		if ( 0 === (int) $job['got'] ) {
			file_put_contents( $part, '' );
		}
		while ( $job['got'] < $job['size'] && microtime( true ) < $deadline ) {
			$data = Link::call(
				$primary,
				'list',
				array(
					'offset' => $job['got'],
					'length' => self::CHUNK,
					'at'     => $job['at'],
				),
				true,
				60
			);
			if ( self::CHANGED === $data ) {
				$job['phase'] = 'restart';
				return $job;
			}
			if ( is_wp_error( $data ) || '' === $data ) {
				$job['error'] = is_wp_error( $data ) ? $data->get_error_message() : 'empty list';
				return $job;
			}
			file_put_contents( $part, $data, FILE_APPEND );
			$job['got'] += strlen( $data );
		}
		if ( $job['got'] >= $job['size'] ) {
			$head = (string) file_get_contents( $part, false, null, 0, strlen( Files::HEADER ) );
			if ( Files::HEADER !== $head ) {
				$job['got'] = 0;
				$job['error'] = 'bad list';
				return $job;
			}
			$job['phase'] = 'diff';
		}
		return $job;
	}

	private static function read_line( $file ) {
		while ( ! $file->eof() ) {
			$line = (string) $file->fgets();
			if ( '' === $line || "\n" !== substr( $line, -1 ) ) {
				return null;
			}
			$parts = explode( "\t", substr( $line, 0, -1 ) );
			if ( 3 === count( $parts ) ) {
				return array( $parts[0], (int) $parts[1], (int) $parts[2] );
			}
		}
		return null;
	}

	private static function diff( array $job ) {
		$next    = Ledger::open( Lister::path( 'primary.part' ), 'rb' );
		$applied = is_file( Lister::path( 'applied.list' ) ) ? Ledger::open( Lister::path( 'applied.list' ), 'rb' ) : null;
		$todo    = Ledger::open( Lister::path( 'todo.list' ), 'wb' );
		$gone    = Ledger::open( Lister::path( 'gone.list' ), 'wb' );
		if ( ! $next || ! $todo || ! $gone ) {
			$job['error'] = 'not writable';
			return $job;
		}
		$root  = Files::root();
		$new   = self::read_line( $next );
		$old   = $applied ? self::read_line( $applied ) : null;
		$count = 0;
		while ( null !== $new || null !== $old ) {
			$order = null === $new ? 1 : ( null === $old ? -1 : Files::compare( $new[0], $old[0] ) );
			if ( $order > 0 ) {
				if ( ! Lister::skipped( $old[0] ) ) {
					$gone->fwrite( $old[0] . "\n" );
				}
				$old = self::read_line( $applied );
				continue;
			}
			$changed = true;
			if ( 0 === $order ) {
				$changed = $new[1] !== $old[1] || $new[2] !== $old[2];
				$old     = self::read_line( $applied );
			}
			if ( $changed && ! Lister::skipped( $new[0] ) ) {
				$full = $root . '/' . $new[0];
				if ( ! ( is_file( $full ) && (int) @filesize( $full ) === $new[1] && (int) @filemtime( $full ) === $new[2] ) ) {
					$todo->fwrite( $new[0] . "\t" . $new[1] . "\t" . $new[2] . "\n" );
					++$count;
				}
			}
			$new = self::read_line( $next );
		}
		$gone = null;
		$job['gone'] = self::count_lines( Lister::path( 'gone.list' ) );
		$job['seen'] = self::count_lines( Lister::path( 'applied.list' ) );
		if ( $job['gone'] > max( 200, (int) ( $job['seen'] * 0.25 ) ) ) {
			file_put_contents( Lister::path( 'gone.list' ), '' );
			$job['held'] = $job['gone'];
		}
		$job['todo']   = $count;
		$job['offset'] = 0;
		$job['phase']  = 'fetch';
		return $job;
	}

	private static function count_lines( $file ) {
		if ( ! is_file( $file ) ) {
			return 0;
		}
		$count = 0;
		$in    = Ledger::open( $file, 'rb' );
		while ( $in && ! $in->eof() ) {
			if ( '' !== (string) $in->fgets() ) {
				++$count;
			}
		}
		return $count;
	}

	private static function fetch( array $job, array $primary, $deadline ) {
		$todo = Ledger::open( Lister::path( 'todo.list' ), 'rb' );
		if ( ! $todo ) {
			$job['phase'] = 'gone';
			return $job;
		}
		while ( microtime( true ) < $deadline ) {
			if ( null !== $job['big'] ) {
				$job = self::big( $job, $primary, $deadline );
				if ( null !== $job['big'] || ! empty( $job['error'] ) ) {
					return $job;
				}
				continue;
			}
			$todo->fseek( (int) $job['offset'] );
			$batch = array();
			$size  = 0;
			$end   = (int) $job['offset'];
			while ( count( $batch ) < self::BATCH && $size < self::CHUNK ) {
				$line = $todo->eof() ? '' : (string) $todo->fgets();
				if ( '' === $line ) {
					break;
				}
				$parts = explode( "\t", rtrim( $line, "\n" ) );
				if ( 3 !== count( $parts ) ) {
					$end += strlen( $line );
					continue;
				}
				if ( (int) $parts[1] >= self::SMALL ) {
					if ( ! $batch ) {
						$end       += strlen( $line );
						$job['big'] = array(
							'path'  => $parts[0],
							'size'  => (int) $parts[1],
							'mtime' => (int) $parts[2],
							'got'   => 0,
							'next'  => $end,
						);
					}
					break;
				}
				$batch[] = $parts[0];
				$size   += (int) $parts[1];
				$end    += strlen( $line );
			}
			if ( null !== $job['big'] ) {
				continue;
			}
			if ( ! $batch ) {
				$job['phase'] = 'gone';
				$job['offset'] = 0;
				return $job;
			}
			$data = Link::call( $primary, 'pack', array( 'paths' => $batch ), true, 120 );
			if ( is_wp_error( $data ) ) {
				$job['error'] = $data->get_error_message();
				return $job;
			}
			$done = self::unpack( $data );
			if ( is_wp_error( $done ) ) {
				$job['error'] = $done->get_error_message();
				return $job;
			}
			$job['fetched'] += $done['restored'];
			$job['failed']  += $done['failed'];
			$job['bytes']   += strlen( $data );
			$job['offset']   = $end;
		}
		return $job;
	}

	private static function unpack( $data ) {
		$temp = Lister::path( 'batch.tar.gz' );
		if ( false === file_put_contents( $temp, $data ) ) {
			return new \WP_Error( 'rafoo_geo_disk', 'disk full' );
		}
		$work = Lister::path( 'work' );
		wp_mkdir_p( $work );
		try {
			$handler = new Extract( $work, false, false );
			$reader  = new Unpack( Unpack::begin( $temp ), $handler );
			$reader->run( INF, INF );
			$stats = $handler->state();
		} catch ( \Throwable $e ) {
			wp_delete_file( $temp );
			return new \WP_Error( 'rafoo_geo_pack', $e->getMessage() );
		}
		wp_delete_file( $temp );
		return array(
			'restored' => (int) $stats['restored'],
			'failed'   => (int) $stats['failed'],
		);
	}

	private static function big( array $job, array $primary, $deadline ) {
		$big    = $job['big'];
		$target = Files::root() . '/' . $big['path'];
		$temp   = $target . '.rafoo-part';
		if ( Lister::skipped( $big['path'] ) || ! wp_mkdir_p( dirname( $target ) ) ) {
			$job['offset'] = $big['next'];
			$job['big']    = null;
			++$job['failed'];
			return $job;
		}
		if ( 0 === (int) $big['got'] ) {
			file_put_contents( $temp, '' );
		}
		while ( $big['got'] < $big['size'] && microtime( true ) < $deadline ) {
			$data = Link::call(
				$primary,
				'file',
				array(
					'path'   => $big['path'],
					'offset' => $big['got'],
					'length' => self::CHUNK,
				),
				true,
				120
			);
			if ( is_wp_error( $data ) ) {
				$job['big']   = $big;
				$job['error'] = $data->get_error_message();
				return $job;
			}
			if ( '' === $data ) {
				break;
			}
			if ( false === file_put_contents( $temp, $data, FILE_APPEND ) ) {
				$job['big']   = $big;
				$job['error'] = 'disk full';
				return $job;
			}
			$big['got']   += strlen( $data );
			$job['bytes'] += strlen( $data );
		}
		clearstatcache( true, $temp );
		if ( $big['got'] < $big['size'] && microtime( true ) >= $deadline ) {
			$job['big'] = $big;
			return $job;
		}
		if ( (int) filesize( $temp ) === (int) $big['size'] && Ledger::move( $temp, $target ) ) {
			Assets::fs()->touch( $target, (int) $big['mtime'] );
			++$job['fetched'];
		} else {
			wp_delete_file( $temp );
			++$job['failed'];
		}
		$job['offset'] = $big['next'];
		$job['big']    = null;
		return $job;
	}

	private static function gone( array $job, $deadline ) {
		$gone = Ledger::open( Lister::path( 'gone.list' ), 'rb' );
		$root = Files::root();
		if ( $gone ) {
			$gone->fseek( (int) $job['offset'] );
			while ( microtime( true ) < $deadline ) {
				$line = $gone->eof() ? '' : (string) $gone->fgets();
				if ( '' === $line ) {
					break;
				}
				$job['offset'] += strlen( $line );
				$relative       = rtrim( $line, "\n" );
				if ( '' !== $relative && ! Lister::skipped( $relative ) && is_file( $root . '/' . $relative ) ) {
					wp_delete_file( $root . '/' . $relative );
					++$job['removed'];
				}
			}
			if ( ! $gone->eof() && microtime( true ) >= $deadline ) {
				return $job;
			}
		}
		Ledger::move( Lister::path( 'primary.part' ), Lister::path( 'applied.list' ) );
		foreach ( array( 'todo.list', 'gone.list' ) as $name ) {
			wp_delete_file( Lister::path( $name ) );
		}
		$job['phase'] = 'done';
		return $job;
	}

	public static function pack( array $paths ) {
		$root = Files::root();
		$tar  = '';
		foreach ( array_slice( $paths, 0, self::BATCH ) as $path ) {
			$path = ltrim( wp_normalize_path( (string) $path ), '/' );
			$full = $root . '/' . $path;
			if ( '' === $path || Lister::skipped( $path ) || ! is_file( $full ) || is_link( $full ) ) {
				continue;
			}
			clearstatcache( true, $full );
			$size = (int) filesize( $full );
			if ( $size > 2 * self::SMALL ) {
				continue;
			}
			$data = (string) file_get_contents( $full );
			$tar .= Tar::header( 'files/' . $path, strlen( $data ), (int) filemtime( $full ) ) . $data . Tar::padding( strlen( $data ) );
		}
		$tar .= Tar::end();
		return (string) gzencode( $tar, 1 );
	}

	public static function slice( $path, $offset, $length ) {
		$path = ltrim( wp_normalize_path( (string) $path ), '/' );
		$full = Files::root() . '/' . $path;
		if ( '' === $path || Lister::skipped( $path ) || ! is_file( $full ) || is_link( $full ) ) {
			return '';
		}
		return (string) file_get_contents( $full, false, null, max( 0, (int) $offset ), max( 1, min( self::CHUNK, (int) $length ) ) );
	}
}
