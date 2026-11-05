<?php

namespace Rafoo\Backup\Archive;

defined( 'ABSPATH' ) || exit;

final class Lines {

	private $source;

	private $buffer = '';

	private $mark;

	private $pending = null;

	private $at = true;

	private $end = false;

	public function __construct( array $state ) {
		$this->source = new Source( $state );
		$this->mark   = $this->source->mark();
	}

	public function next() {
		while ( false === ( $newline = strpos( $this->buffer, "\n" ) ) ) {
			if ( $this->end ) {
				if ( '' === $this->buffer ) {
					return null;
				}
				$line         = $this->buffer;
				$this->buffer = '';
				$this->at     = false;
				return $line;
			}
			list( $data, $boundary, $end ) = $this->source->read();
			$this->buffer .= $data;
			$this->end     = $end;
			$this->pending = $boundary && ! $end ? $this->source->mark() : null;
		}
		$line         = substr( $this->buffer, 0, $newline );
		$this->buffer = (string) substr( $this->buffer, $newline + 1 );
		$this->at     = false;
		if ( '' === $this->buffer && null !== $this->pending ) {
			$this->mark    = $this->pending;
			$this->pending = null;
			$this->at      = true;
		}
		return $line;
	}

	public function at_mark() {
		return $this->at;
	}

	public function mark() {
		return $this->mark;
	}

	public function raw() {
		return $this->source->raw() - strlen( $this->buffer );
	}
}
