<?php

namespace Rafoo\Backup\Archive;

defined( 'ABSPATH' ) || exit;

final class Source {

	const MAGIC = 'RAFOOBK2';

	const HEAD = 50;

	const READ = 1048576;

	const FRAME_MAX = 1048640;

	const FEED = 4096;

	const OUT = 524288;

	private $at = 0;

	private $in;

	private $state;

	private $crypt = null;

	private $inflate = null;

	private $taken = 0;

	private $input = '';

	private $pos = 0;

	public static function head( $file ) {
		$head = (string) self::file( $file )->fread( self::HEAD );
		if ( self::HEAD === strlen( $head ) && 0 === strpos( $head, self::MAGIC ) ) {
			return array(
				'encrypted' => true,
				'mode'      => $head[8],
				'gzip'      => 1 === ( ord( $head[9] ) & 1 ),
				'salt'      => substr( $head, 10, 16 ),
				'header'    => substr( $head, 26, 24 ),
				'offset'    => self::HEAD,
			);
		}
		return array(
			'encrypted' => false,
			'mode'      => '',
			'gzip'      => "\x1f\x8b" === substr( $head, 0, 2 ),
			'salt'      => '',
			'header'    => '',
			'offset'    => 0,
		);
	}

	public static function start( $file, $key = null ) {
		$head  = self::head( $file );
		$state = array(
			'file'   => $file,
			'offset' => $head['offset'],
			'raw'    => 0,
			'gzip'   => $head['gzip'],
			'crypt'  => null,
			'final'  => false,
		);
		if ( $head['encrypted'] ) {
			if ( null === $key || '' === $key || ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_pull' ) ) {
				throw new \RuntimeException( 'wrong_password' );
			}
			try {
				$state['crypt'] = bin2hex( sodium_crypto_secretstream_xchacha20poly1305_init_pull( $head['header'], $key ) );
			} catch ( \Throwable $e ) {
				throw new \RuntimeException( 'wrong_password' );
			}
		}
		return $state;
	}

	public function __construct( array $state ) {
		$this->state = $state;
		$this->in    = self::file( $state['file'] );
		$this->pos   = (int) $state['offset'];
		$this->in->fseek( $this->pos );
		$this->crypt = null === $state['crypt'] ? null : hex2bin( $state['crypt'] );
	}

	public function read() {
		$out = '';
		while ( true ) {
			if ( $this->at >= strlen( $this->input ) ) {
				if ( '' !== $out ) {
					return array( $out, false, false );
				}
				$this->input = '';
				$this->at    = 0;
				if ( ! $this->fill() ) {
					if ( null !== $this->inflate || ( null !== $this->crypt && empty( $this->state['final'] ) ) ) {
						throw new \RuntimeException( 'damaged' );
					}
					return array( '', true, true );
				}
				if ( ! $this->state['gzip'] ) {
					$data                   = $this->input;
					$this->input            = '';
					$this->state['raw']    += strlen( $data );
					$this->state['offset']  = $this->pos;
					return array( $data, true, false );
				}
			}
			if ( null === $this->inflate ) {
				$this->inflate = inflate_init( ZLIB_ENCODING_GZIP );
				$this->taken   = 0;
			}
			$piece = @inflate_add( $this->inflate, (string) substr( $this->input, $this->at, self::FEED ), ZLIB_SYNC_FLUSH );
			if ( false === $piece ) {
				throw new \RuntimeException( 'damaged' );
			}
			$total               = (int) inflate_get_read_len( $this->inflate );
			$this->at           += $total - $this->taken;
			$this->taken         = $total;
			$this->state['raw'] += strlen( $piece );
			$out                .= $piece;
			$left                = strlen( $this->input ) - $this->at;
			if ( ZLIB_STREAM_END === inflate_get_status( $this->inflate ) ) {
				$this->inflate = null;
				if ( null === $this->crypt ) {
					$this->state['offset'] = $this->pos - $left;
					return array( $out, true, false );
				}
				if ( 0 === $left ) {
					$this->state['offset'] = $this->pos;
					return array( $out, true, false );
				}
				return array( $out, false, false );
			}
			if ( strlen( $out ) >= self::OUT ) {
				return array( $out, false, false );
			}
		}
	}

	public function mark() {
		$state          = $this->state;
		$state['crypt'] = null === $this->crypt ? null : bin2hex( $this->crypt );
		return $state;
	}

	public function raw() {
		return (int) $this->state['raw'];
	}

	private function fill() {
		if ( null === $this->crypt ) {
			$chunk = (string) $this->in->fread( self::READ );
			if ( '' === $chunk ) {
				return false;
			}
			$this->pos  += strlen( $chunk );
			$this->input = $chunk;
			return true;
		}
		while ( empty( $this->state['final'] ) ) {
			$length = (string) $this->in->fread( 4 );
			if ( '' === $length ) {
				return false;
			}
			$size = 4 === strlen( $length ) ? (int) unpack( 'N', $length )[1] : 0;
			if ( $size < 17 || $size > self::FRAME_MAX ) {
				throw new \RuntimeException( 'damaged' );
			}
			$cipher = (string) $this->in->fread( $size );
			if ( strlen( $cipher ) !== $size ) {
				throw new \RuntimeException( 'damaged' );
			}
			$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $this->crypt, $cipher );
			if ( false === $result ) {
				throw new \RuntimeException( self::HEAD === $this->pos ? 'wrong_password' : 'damaged' );
			}
			$this->pos += 4 + $size;
			if ( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $result[1] ) {
				$this->state['final'] = true;
			}
			if ( '' !== $result[0] ) {
				$this->input = $result[0];
				return true;
			}
		}
		return false;
	}

	private static function file( $file ) {
		try {
			return new \SplFileObject( $file, 'rb' );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'unreadable' );
		}
	}
}
