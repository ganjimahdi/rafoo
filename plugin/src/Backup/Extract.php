<?php

namespace Rafoo\Backup;

use Rafoo\Assets\Store;

defined( 'ABSPATH' ) || exit;

final class Extract {

	const META_MAX = 1048576;

	private $work;

	private $last;

	private $dry;

	private $root;

	private $out = null;

	private $stats = array();

	public function __construct( $work, $last, $dry = false ) {
		$this->work = $work;
		$this->last = (bool) $last;
		$this->dry  = (bool) $dry;
		$this->root = Files::root();
	}

	public function restore( array $state ) {
		$this->stats = array_merge(
			array(
				'restored' => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'entries'  => 0,
				'bytes'    => 0,
				'meta'     => '',
				'samples'  => array(),
			),
			$state
		);
	}

	public function state() {
		return $this->stats;
	}

	public function open( array $entry ) {
		++$this->stats['entries'];
		$this->stats['bytes'] += (int) $entry['size'];
		$entry['act']          = 'skip';
		$path                  = (string) $entry['path'];
		if ( 'rafoo/backup.json' === $path ) {
			$entry['act'] = 'meta';
			if ( $entry['size'] > self::META_MAX ) {
				throw new Problem( 'damaged' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
			}
			$this->stats['meta'] = '';
			return $entry;
		}
		if ( $this->dry ) {
			return $entry;
		}
		if ( 'rafoo/database.sql.gz' === $path ) {
			return $this->last ? $this->target( $entry, $this->work . '/database.sql.gz', false ) : $entry;
		}
		if ( 'rafoo/deleted.txt' === $path ) {
			return $this->target( $entry, $this->work . '/deleted.txt', false );
		}
		if ( 0 !== strpos( $path, 'files/' ) ) {
			return $entry;
		}
		$relative = substr( $path, 6 );
		if ( Files::protected_path( $relative ) ) {
			return $entry;
		}
		$target = $this->root . '/' . $relative;
		clearstatcache( true, $target );
		if ( is_file( $target ) && (int) filesize( $target ) === (int) $entry['size'] && (int) filemtime( $target ) === (int) $entry['mtime'] ) {
			++$this->stats['skipped'];
			return $entry;
		}
		return $this->target( $entry, $target, true );
	}

	public function write( array $entry, $chunk ) {
		if ( 'meta' === $entry['act'] ) {
			$this->stats['meta'] .= $chunk;
			return $entry;
		}
		if ( 'write' !== $entry['act'] || null === $this->out ) {
			return $entry;
		}
		if ( $this->out->fwrite( $chunk ) !== strlen( $chunk ) ) {
			throw new Problem( 'disk_full', array( 'free' => Ledger::free_space() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$entry['written'] += strlen( $chunk );
		return $entry;
	}

	public function close( array $entry ) {
		if ( 'write' !== $entry['act'] ) {
			return;
		}
		if ( null !== $this->out && ! $this->out->fflush() ) {
			throw new Problem( 'disk_full', array( 'free' => Ledger::free_space() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$this->out = null;
		$temp      = (string) base64_decode( $entry['temp'] );
		$target    = (string) base64_decode( $entry['target'] );
		if ( ! Ledger::move( $temp, $target ) ) {
			wp_delete_file( $temp );
			$this->fail( substr( (string) $entry['path'], 6 ) );
			return;
		}
		if ( ! empty( $entry['file'] ) ) {
			Store::fs()->touch( $target, (int) $entry['mtime'] );
			++$this->stats['restored'];
		}
	}

	public function resume( array $entry ) {
		if ( 'write' !== $entry['act'] ) {
			return $entry;
		}
		$temp      = (string) base64_decode( $entry['temp'] );
		$this->out = is_file( $temp ) ? Ledger::open( $temp, 'c+b' ) : Ledger::open( $temp, 'wb' );
		if ( ! $this->out ) {
			$this->fail( substr( (string) $entry['path'], 6 ) );
			$entry['act'] = 'skip';
			return $entry;
		}
		$this->out->ftruncate( (int) $entry['written'] );
		$this->out->fseek( (int) $entry['written'] );
		return $entry;
	}

	public function sync() {
		if ( null !== $this->out ) {
			$this->out->fflush();
		}
	}

	public function abandon() {
		$this->out = null;
	}

	public function meta() {
		$meta = json_decode( (string) $this->stats['meta'], true );
		return is_array( $meta ) ? $meta : null;
	}

	private function target( array $entry, $target, $file ) {
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			$this->fail( $file ? substr( (string) $entry['path'], 6 ) : basename( $target ) );
			return $entry;
		}
		$temp      = $target . '.rafoo-part';
		$this->out = Ledger::open( $temp, 'wb' );
		if ( ! $this->out ) {
			$this->fail( $file ? substr( (string) $entry['path'], 6 ) : basename( $target ) );
			return $entry;
		}
		$entry['act']     = 'write';
		$entry['file']    = $file;
		$entry['target']  = base64_encode( $target );
		$entry['temp']    = base64_encode( $temp );
		$entry['written'] = 0;
		return $entry;
	}

	private function fail( $path ) {
		++$this->stats['failed'];
		if ( count( $this->stats['samples'] ) < 5 ) {
			$this->stats['samples'][] = base64_encode( (string) $path );
		}
	}
}
