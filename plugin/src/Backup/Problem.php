<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Problem extends \RuntimeException {

	private $reason;

	private $detail;

	public function __construct( $reason, array $detail = array() ) {
		parent::__construct( (string) $reason );
		$this->reason = (string) $reason;
		$this->detail = $detail;
	}

	public function reason() {
		return $this->reason;
	}

	public function detail() {
		return $this->detail;
	}

	public static function from( \Throwable $error ) {
		if ( $error instanceof self ) {
			return $error;
		}
		$message = $error->getMessage();
		if ( in_array( $message, array( 'damaged', 'wrong_password', 'unreadable' ), true ) ) {
			return new self( 'unreadable' === $message ? 'damaged' : $message );
		}
		return new self( 'internal', array( 'message' => Journal::clean( $message ) ) );
	}
}
