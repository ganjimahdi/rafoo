<?php

namespace Rafoo\Backup\Archive;

defined( 'ABSPATH' ) || exit;

final class Unpack {

	const META_MAX = 1048576;

	private $source;

	private $tar;

	private $handler;

	private $saved;

	public static function fresh() {
		return array(
			'mode'    => 'header',
			'need'    => 0,
			'buffer'  => '',
			'entry'   => null,
			'pax'     => array(),
			'entries' => 0,
			'ended'   => false,
		);
	}

	public static function begin( $file, $key = null ) {
		return array(
			'source'  => Source::start( $file, $key ),
			'tar'     => self::encode( self::fresh() ),
			'handler' => null,
		);
	}

	public function __construct( array $state, $handler ) {
		$this->source  = new Source( $state['source'] );
		$this->tar     = self::decode( $state['tar'] );
		$this->handler = $handler;
		$this->handler->restore( null === $state['handler'] ? array() : self::decode( $state['handler'] ) );
		if ( null !== $this->tar['entry'] && 'data' === $this->tar['mode'] && 'file' === $this->tar['entry']['kind'] ) {
			$this->tar['entry'] = $this->handler->resume( $this->tar['entry'] );
		}
		$this->saved = $state;
	}

	public function run( $soft, $hard ) {
		while ( true ) {
			list( $data, $boundary, $end ) = $this->source->read();
			if ( '' !== $data ) {
				$this->feed( $data );
			}
			if ( $end ) {
				if ( ! $this->tar['ended'] ) {
					throw new \RuntimeException( 'damaged' );
				}
				$this->handler->sync();
				return array(
					'done'  => true,
					'state' => null,
				);
			}
			if ( $boundary ) {
				$this->handler->sync();
				$this->saved = array(
					'source'  => $this->source->mark(),
					'tar'     => self::encode( $this->tar ),
					'handler' => self::encode( $this->handler->state() ),
				);
				if ( microtime( true ) >= $soft ) {
					return array(
						'done'  => false,
						'state' => $this->saved,
					);
				}
			}
			if ( microtime( true ) >= $hard ) {
				$this->handler->abandon();
				return array(
					'done'  => false,
					'state' => $this->saved,
				);
			}
		}
	}

	public function raw() {
		return $this->source->raw();
	}

	public static function encode( array $value ) {
		return base64_encode( serialize( $value ) );
	}

	public static function decode( $value ) {
		$data = unserialize( (string) base64_decode( (string) $value ), array( 'allowed_classes' => false ) );
		return is_array( $data ) ? $data : array();
	}

	private function feed( $data ) {
		$at     = 0;
		$length = strlen( $data );
		while ( $at < $length && ! $this->tar['ended'] ) {
			if ( 'header' === $this->tar['mode'] ) {
				$take                  = min( Tar::BLOCK - strlen( $this->tar['buffer'] ), $length - $at );
				$this->tar['buffer'] .= substr( $data, $at, $take );
				$at                  += $take;
				if ( Tar::BLOCK > strlen( $this->tar['buffer'] ) ) {
					continue;
				}
				$entry               = Tar::parse( $this->tar['buffer'], $this->tar['pax'] );
				$this->tar['buffer'] = '';
				if ( null === $entry ) {
					throw new \RuntimeException( 'damaged' );
				}
				if ( 'end' === $entry['type'] ) {
					$this->tar['ended'] = true;
					continue;
				}
				$this->tar['pax'] = array();
				$this->open( $entry );
				continue;
			}
			$take               = (int) min( $this->tar['need'], $length - $at );
			$chunk              = substr( $data, $at, $take );
			$at                += $take;
			$this->tar['need'] -= $take;
			if ( 'data' === $this->tar['mode'] ) {
				$this->take( $chunk );
				if ( 0 === $this->tar['need'] ) {
					$this->complete();
				}
			} elseif ( 0 === $this->tar['need'] ) {
				$this->tar['mode'] = 'header';
			}
		}
	}

	private function open( array $entry ) {
		$kinds         = array(
			'0' => 'file',
			'7' => 'file',
			'x' => 'pax',
			'L' => 'long',
		);
		$entry['kind'] = isset( $kinds[ $entry['type'] ] ) ? $kinds[ $entry['type'] ] : 'skip';
		$entry['done'] = 0;
		if ( 'file' === $entry['kind'] ) {
			$entry = $this->handler->open( $entry );
		} elseif ( 'skip' !== $entry['kind'] ) {
			if ( $entry['size'] > self::META_MAX ) {
				throw new \RuntimeException( 'damaged' );
			}
			$entry['meta'] = '';
		}
		$this->tar['entry'] = $entry;
		if ( $entry['size'] > 0 ) {
			$this->tar['mode'] = 'data';
			$this->tar['need'] = $entry['size'];
			return;
		}
		$this->complete();
	}

	private function take( $chunk ) {
		$entry = $this->tar['entry'];
		if ( 'file' === $entry['kind'] ) {
			$entry = $this->handler->write( $entry, $chunk );
		} elseif ( 'skip' !== $entry['kind'] ) {
			$entry['meta'] .= $chunk;
		}
		$entry['done']     += strlen( $chunk );
		$this->tar['entry'] = $entry;
	}

	private function complete() {
		$entry = $this->tar['entry'];
		if ( 'file' === $entry['kind'] ) {
			$this->handler->close( $entry );
			++$this->tar['entries'];
		} elseif ( 'pax' === $entry['kind'] ) {
			$this->tar['pax'] = Tar::pax( $entry['meta'] );
		} elseif ( 'long' === $entry['kind'] ) {
			$this->tar['pax'] = array( 'path' => rtrim( $entry['meta'], "\0" ) );
		}
		$this->tar['entry'] = null;
		$pad                = ( Tar::BLOCK - $entry['size'] % Tar::BLOCK ) % Tar::BLOCK;
		$this->tar['mode']  = $pad > 0 ? 'pad' : 'header';
		$this->tar['need']  = $pad;
	}
}
