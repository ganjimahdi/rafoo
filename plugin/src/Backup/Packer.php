<?php

namespace Rafoo\Backup;

use Rafoo\Backup\Archive\Sink;
use Rafoo\Backup\Archive\Tar;
use Rafoo\Settings;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Packer {

	const CHUNK = 1048576;

	const CHECKPOINT = 33554432;

	const LEVEL = 3;

	const BIG = 67108864;

	const RESERVE = 67108864;

	private $d;

	private $save;

	private $sink = null;

	private $list = null;

	private $prev = null;

	private $deleted = null;

	private $since = 0;

	private $cipher = null;

	public static function fresh( $previous ) {
		return array(
			'stage'     => 'db',
			'list'      => strlen( Files::HEADER ),
			'prev'      => '' !== $previous ? strlen( Files::HEADER ) : null,
			'part'      => 0,
			'sink'      => null,
			'entries'   => 0,
			'entry'     => null,
			'parts'     => array(),
			'files'     => 0,
			'bytes'     => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'removed'   => 0,
			'gone'      => 0,
			'raw'       => 0,
			'done'      => false,
		);
	}

	public static function target() {
		return (int) max( 1048576, (int) apply_filters( 'rafoo_backup_part_bytes', (int) Settings::get( 'backup_part' ) * 1048576 ) );
	}

	public static function extension( $encrypted ) {
		return $encrypted ? '.rfa' : ( Sink::gzip() ? '.tar.gz' : '.tar' );
	}

	public static function level( $name, $size ) {
		if ( Files::stored( $name ) ) {
			return 0;
		}
		return $size > self::BIG ? 1 : self::LEVEL;
	}

	public function run( array $d, $deadline, $save, $meta ) {
		$this->d    = $d;
		$this->save = $save;
		$p          = &$this->d['pack'];
		$this->open_lists();
		$signal = 'continue';
		try {
			while ( microtime( true ) < $deadline && 'continue' === $signal ) {
				if ( null !== $p['entry'] ) {
					if ( ! $this->stream( $deadline ) ) {
						break;
					}
					continue;
				}
				if ( 'db' === $p['stage'] ) {
					$this->begin_blob( $this->d['sql'], 'rafoo/database.sql.gz' );
					$p['stage'] = 'files';
					continue;
				}
				if ( 'files' === $p['stage'] ) {
					$next = $this->next_path();
					if ( null === $next ) {
						$p['stage'] = 'tail';
						continue;
					}
					if ( $this->full() ) {
						$this->close_part();
						if ( $this->d['targets'] ) {
							$signal = 'part';
						}
					}
					$this->begin_file( $next );
					continue;
				}
				if ( 'tail' === $p['stage'] ) {
					$this->deleted = null;
					$this->begin_blob( $this->d['work'] . '/deleted.txt', 'rafoo/deleted.txt' );
					$p['stage'] = 'meta';
					continue;
				}
				if ( 'meta' === $p['stage'] ) {
					$this->begin_inline( (string) wp_json_encode( call_user_func( $meta, $this->d ) ), 'rafoo/backup.json' );
					$p['stage'] = 'close';
					continue;
				}
				if ( 'close' === $p['stage'] ) {
					$this->close_part();
					$p['stage'] = 'done';
					$p['done']  = true;
					$signal     = 'done';
				}
			}
			$this->commit( false );
		} catch ( \Throwable $e ) {
			$this->sink = null;
			throw $e;
		}
		return array( $this->d, $signal );
	}

	private function open_lists() {
		$p          = &$this->d['pack'];
		$this->list = Ledger::open( $this->d['list'], 'rb' );
		if ( ! $this->list ) {
			throw new Problem( 'not_writable', array( 'path' => Ledger::show( $this->d['list'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		if ( null !== $p['prev'] ) {
			$this->prev = Ledger::open( $this->d['previous'], 'rb' );
		}
		$file = $this->d['work'] . '/deleted.txt';
		if ( in_array( $p['stage'], array( 'db', 'files' ), true ) ) {
			$this->deleted = Ledger::open( $file, is_file( $file ) ? 'c+b' : 'wb' );
			if ( ! $this->deleted ) {
				throw new Problem( 'not_writable', array( 'path' => Ledger::show( $file ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
			}
			$this->deleted->ftruncate( (int) $p['gone'] );
			$this->deleted->fseek( (int) $p['gone'] );
		}
		if ( null !== $p['sink'] ) {
			$this->sink  = Sink::open( $p['sink'] );
			$this->since = $this->sink->raw();
		}
	}

	private function next_path() {
		$p = &$this->d['pack'];
		while ( true ) {
			$current = $this->peek( $this->list, $p['list'] );
			if ( null === $this->prev ) {
				if ( null === $current ) {
					return null;
				}
				$p['list'] += $current[3];
				return $current;
			}
			$old = $this->peek( $this->prev, $p['prev'] );
			if ( null === $old ) {
				if ( null === $current ) {
					return null;
				}
				$p['list'] += $current[3];
				return $current;
			}
			$order = null === $current ? 1 : Files::compare( $current[0], $old[0] );
			if ( $order > 0 ) {
				$line = $old[0] . "\n";
				if ( strlen( $line ) !== $this->deleted->fwrite( $line ) ) {
					throw new Problem( 'disk_full', array( 'free' => Ledger::free_space() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
				}
				$p['gone']    += strlen( $line );
				$p['prev']    += $old[3];
				++$p['removed'];
				continue;
			}
			$p['list'] += $current[3];
			if ( 0 === $order ) {
				$p['prev'] += $old[3];
				if ( $current[1] === $old[1] && $current[2] === $old[2] ) {
					++$p['unchanged'];
					$p['skipped'] += $current[1];
					continue;
				}
			}
			return $current;
		}
	}

	private function peek( $file, &$offset ) {
		while ( true ) {
			$file->fseek( $offset );
			$line = $file->eof() ? '' : (string) $file->fgets();
			if ( '' === $line || "\n" !== substr( $line, -1 ) ) {
				return null;
			}
			$parts = explode( "\t", substr( $line, 0, -1 ) );
			if ( 3 === count( $parts ) && '' !== $parts[0] ) {
				return array( $parts[0], (int) $parts[1], (int) $parts[2], strlen( $line ) );
			}
			$offset += strlen( $line );
		}
	}

	private function full() {
		$p = $this->d['pack'];
		return null !== $this->sink && $p['entries'] > 0 && $this->sink->bytes() >= self::target();
	}

	private function begin_file( array $next ) {
		$full = Files::root() . '/' . $next[0];
		clearstatcache( true, $full );
		if ( ! is_file( $full ) ) {
			$this->warn( 'vanished', $next[0] );
			return;
		}
		if ( ! is_readable( $full ) ) {
			$this->warn( 'unreadable', $next[0] );
			return;
		}
		$size  = (int) @filesize( $full );
		$mtime = (int) @filemtime( $full );
		$this->begin( 'file', 'files/' . $next[0], $full, $size, $mtime, self::level( $next[0], $size ), '' );
	}

	private function begin_blob( $file, $name ) {
		clearstatcache( true, $file );
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		$this->begin( 'blob', $name, $file, $size, time(), 'rafoo/deleted.txt' === $name ? self::LEVEL : 0, '' );
	}

	private function begin_inline( $data, $name ) {
		$this->begin( 'inline', $name, '', strlen( $data ), time(), self::LEVEL, $data );
	}

	private function begin( $kind, $name, $source, $size, $mtime, $level, $data ) {
		$p = &$this->d['pack'];
		$this->ensure_part();
		$this->sink->write( Tar::header( $name, $size, $mtime ), $level );
		$p['entry'] = array(
			'kind'  => $kind,
			'name'  => base64_encode( $name ),
			'src'   => base64_encode( $source ),
			'data'  => base64_encode( $data ),
			'size'  => $size,
			'mtime' => $mtime,
			'level' => $level,
			'done'  => 0,
			'short' => false,
		);
		++$p['entries'];
	}

	private function stream( $deadline ) {
		$p      = &$this->d['pack'];
		$entry  = &$p['entry'];
		$source = (string) base64_decode( $entry['src'] );
		$data   = 'inline' === $entry['kind'] ? (string) base64_decode( $entry['data'] ) : null;
		$in     = null;
		if ( null === $data ) {
			$in = is_readable( $source ) ? Ledger::open( $source, 'rb' ) : null;
			if ( $in ) {
				$in->fseek( $entry['done'] );
			}
		}
		while ( $entry['done'] < $entry['size'] ) {
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			$want = (int) min( self::CHUNK, $entry['size'] - $entry['done'] );
			if ( null !== $data ) {
				$chunk = (string) substr( $data, $entry['done'], $want );
			} else {
				$chunk = $in ? (string) $in->fread( $want ) : '';
			}
			if ( '' === $chunk ) {
				$chunk = str_repeat( "\0", $want );
				if ( ! $entry['short'] ) {
					$entry['short'] = true;
					$this->warn( 'changed', substr( (string) base64_decode( $entry['name'] ), 6 ) );
				}
			}
			$this->sink->write( $chunk, $entry['level'] );
			$entry['done'] += strlen( $chunk );
			$p['raw']      += strlen( $chunk );
			if ( $this->sink->raw() - $this->since >= self::CHECKPOINT ) {
				$this->commit( true );
			}
		}
		$this->sink->write( Tar::padding( $entry['size'] ), $entry['level'] );
		if ( 'file' === $entry['kind'] ) {
			clearstatcache( true, $source );
			if ( ! $entry['short'] && ( (int) @filemtime( $source ) !== $entry['mtime'] || (int) @filesize( $source ) !== $entry['size'] ) ) {
				$this->warn( 'changed', substr( (string) base64_decode( $entry['name'] ), 6 ) );
			}
			++$p['files'];
			$p['bytes'] += $entry['size'];
		}
		$entry      = null;
		$p['entry'] = null;
		return true;
	}

	private function ensure_part() {
		$p = &$this->d['pack'];
		if ( null !== $this->sink ) {
			return;
		}
		$free = Ledger::free_space();
		if ( $free >= 0 && $free < self::RESERVE ) {
			throw new Problem( 'disk_full', array( 'free' => $free ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		++$p['part'];
		$p['entries'] = 0;
		$name         = $this->d['base'] . '-p' . sprintf( '%03d', $p['part'] ) . self::extension( '' !== $this->d['mode'] );
		$this->sink   = Sink::create( Ledger::path( $name ), $this->cipher() );
		$this->since  = 0;
		$p['sink']    = $this->sink->checkpoint();
		$p['current'] = $name;
		Journal::add( 'writing part ' . $p['part'] . ' (' . $name . ')' );
	}

	private function close_part() {
		$p = &$this->d['pack'];
		$this->ensure_part();
		$this->sink->write( Tar::end(), self::LEVEL );
		$state      = $this->sink->close();
		$this->sink = null;
		$file       = $state['file'];
		clearstatcache( true, $file );
		$record       = array(
			'name'    => $p['current'],
			'size'    => (int) filesize( $file ),
			'sha256'  => (string) hash_file( 'sha256', $file ),
			'raw'     => (int) $state['raw'],
			'entries' => (int) $p['entries'],
		);
		$p['parts'][] = $record;
		$p['sink']    = null;
		$p['current'] = '';
		if ( $this->d['targets'] ) {
			$this->d['queue'][] = $record['name'];
		}
		Journal::add( 'part ' . count( $p['parts'] ) . ' finished: ' . size_format( $record['size'], 1 ) . ', ' . $record['entries'] . ' entries' );
		call_user_func( $this->save, $this->d );
	}

	private function commit( $save ) {
		$p = &$this->d['pack'];
		if ( null !== $this->sink ) {
			$p['sink']   = $this->sink->checkpoint();
			$this->since = $this->sink->raw();
		}
		if ( null !== $this->deleted ) {
			$this->deleted->fflush();
		}
		$free = Ledger::free_space();
		if ( $free >= 0 && $free < self::RESERVE / 2 ) {
			throw new Problem( 'disk_full', array( 'free' => $free ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		if ( $save ) {
			call_user_func( $this->save, $this->d );
		}
	}

	private function warn( $code, $path ) {
		$this->d['warnings'] = Warnings::add( isset( $this->d['warnings'] ) ? $this->d['warnings'] : array(), $code, $path );
	}

	private function cipher() {
		if ( '' === $this->d['mode'] ) {
			return null;
		}
		if ( null === $this->cipher ) {
			$this->cipher = array(
				'key'  => hex2bin( Vault::open( $this->d['cipher']['key'] ) ),
				'mode' => $this->d['mode'],
				'salt' => hex2bin( $this->d['cipher']['salt'] ),
			);
		}
		return $this->cipher;
	}
}
