<?php

namespace Rafoo\Backup\Archive;

use Rafoo\Backup\Ledger;
use Rafoo\Backup\Problem;

defined( 'ABSPATH' ) || exit;

final class Sink {

	const FRAME = 1048576;

	private $out;

	private $state;

	private $deflate = null;

	private $level = -1;

	private $pending = '';

	private $crypt = null;

	public static function gzip() {
		return function_exists( 'deflate_init' ) && function_exists( 'inflate_init' ) && function_exists( 'inflate_get_read_len' ) && function_exists( 'inflate_get_status' );
	}

	public static function create( $file, $cipher = null, $gzip = null ) {
		$out = Ledger::open( $file, 'wb' );
		if ( ! $out ) {
			throw new Problem( 'not_writable', array( 'path' => Ledger::show( $file ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$gzip = null === $gzip ? self::gzip() : (bool) $gzip;
		$sink = new self(
			$out,
			array(
				'file'  => $file,
				'bytes' => 0,
				'raw'   => 0,
				'gzip'  => $gzip,
				'crypt' => null,
			)
		);
		if ( $cipher ) {
			list( $crypt, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $cipher['key'] );
			$sink->crypt            = $crypt;
			$sink->put( Source::MAGIC . $cipher['mode'] . chr( $gzip ? 1 : 0 ) . $cipher['salt'] . $header );
		}
		return $sink;
	}

	public static function open( array $state ) {
		$out = is_file( $state['file'] ) ? Ledger::open( $state['file'], 'c+b' ) : null;
		if ( ! $out ) {
			throw new Problem( 'not_writable', array( 'path' => Ledger::show( $state['file'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		if ( ! $out->ftruncate( (int) $state['bytes'] ) ) {
			throw new Problem( 'not_writable', array( 'path' => Ledger::show( $state['file'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$out->fseek( (int) $state['bytes'] );
		$sink        = new self( $out, $state );
		$sink->crypt = empty( $state['crypt'] ) ? null : hex2bin( $state['crypt'] );
		return $sink;
	}

	private function __construct( $out, array $state ) {
		$this->out   = $out;
		$this->state = $state;
	}

	public function write( $data, $level = 3 ) {
		if ( '' === $data ) {
			return;
		}
		$this->state['raw'] += strlen( $data );
		if ( ! $this->state['gzip'] ) {
			$this->emit( $data );
			return;
		}
		if ( $level !== $this->level ) {
			$this->finish();
		}
		if ( null === $this->deflate ) {
			$this->deflate = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $level ) );
			$this->level   = $level;
		}
		$this->emit( deflate_add( $this->deflate, $data, ZLIB_NO_FLUSH ) );
	}

	public function checkpoint() {
		$this->finish();
		if ( null !== $this->crypt && '' !== $this->pending ) {
			$this->frame( $this->pending, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE );
			$this->pending = '';
		}
		$this->flush();
		return $this->state();
	}

	public function close() {
		$this->finish();
		if ( null !== $this->crypt ) {
			$this->frame( $this->pending, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL );
			$this->pending = '';
		}
		$this->flush();
		$state     = $this->state();
		$this->out = null;
		return $state;
	}

	public function bytes() {
		return (int) $this->state['bytes'] + strlen( $this->pending );
	}

	public function raw() {
		return (int) $this->state['raw'];
	}

	private function state() {
		$this->state['crypt'] = null === $this->crypt ? null : bin2hex( $this->crypt );
		return $this->state;
	}

	private function finish() {
		if ( null !== $this->deflate ) {
			$this->emit( deflate_add( $this->deflate, '', ZLIB_FINISH ) );
			$this->deflate = null;
			$this->level   = -1;
		}
	}

	private function emit( $bytes ) {
		if ( '' === $bytes ) {
			return;
		}
		if ( null === $this->crypt ) {
			$this->put( $bytes );
			return;
		}
		$this->pending .= $bytes;
		while ( strlen( $this->pending ) >= self::FRAME ) {
			$this->frame( substr( $this->pending, 0, self::FRAME ), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE );
			$this->pending = (string) substr( $this->pending, self::FRAME );
		}
	}

	private function frame( $plain, $tag ) {
		$cipher = sodium_crypto_secretstream_xchacha20poly1305_push( $this->crypt, $plain, '', $tag );
		$this->put( pack( 'N', strlen( $cipher ) ) . $cipher );
	}

	private function put( $bytes ) {
		$length = strlen( $bytes );
		if ( $this->out->fwrite( $bytes ) !== $length ) {
			throw new Problem( 'disk_full', array( 'free' => Ledger::free_space() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$this->state['bytes'] += $length;
	}

	private function flush() {
		if ( ! $this->out->fflush() ) {
			throw new Problem( 'disk_full', array( 'free' => Ledger::free_space() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
	}
}
