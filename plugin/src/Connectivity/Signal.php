<?php

namespace Rafoo\Connectivity;

use Rafoo\Plugin;
use Rafoo\SelfUpdate\Release;
use Rafoo\Signature;

defined( 'ABSPATH' ) || exit;

final class Signal {

	const URL = 'https://rafoo.ir/api/v1/status';

	public static function parse( $body ) {
		$data = Signature::open( $body );
		if ( ! is_array( $data ) || ! isset( $data['state'], $data['issued_at'] ) ) {
			return array();
		}
		if ( ! in_array( $data['state'], array( 'up', 'down' ), true ) ) {
			return array();
		}
		return array(
			'state'     => $data['state'],
			'issued_at' => (int) $data['issued_at'],
		);
	}

	public static function releases( $body ) {
		$data = Signature::open( $body );
		return is_array( $data ) && isset( $data['releases'] ) && is_array( $data['releases'] ) ? $data['releases'] : array();
	}

	public static function announce( $body ) {
		if ( ! Plugin::self_updates() ) {
			return false;
		}
		$releases = self::releases( $body );
		return $releases ? Release::announce( $releases ) : false;
	}
}
