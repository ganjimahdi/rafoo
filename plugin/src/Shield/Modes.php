<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Modes {

	const ALL = array( 'off', 'smart', 'strict', 'fortress' );

	public static function get( $mode ) {
		$modes = array(
			'smart'    => array(
				'block'      => 10,
				'suspect'    => 4,
				'reputation' => 20,
				'ban'        => 3600,
				'challenge'  => 300,
				'limit'      => 900,
				'missing'    => 40,
				'logins'     => 10,
				'lock'       => 1800,
				'xmlrpc'     => 'multicall',
				'authors'    => 'redirect',
				'feed'       => 'mixed',
				'everyone'   => false,
				'pass'       => 6,
				'work'       => 40000,
			),
			'strict'   => array(
				'block'      => 6,
				'suspect'    => 3,
				'reputation' => 12,
				'ban'        => 21600,
				'challenge'  => 150,
				'limit'      => 400,
				'missing'    => 20,
				'logins'     => 5,
				'lock'       => 7200,
				'xmlrpc'     => 'auth',
				'authors'    => 'block',
				'feed'       => 'block',
				'everyone'   => false,
				'pass'       => 6,
				'work'       => 80000,
			),
			'fortress' => array(
				'block'      => 6,
				'suspect'    => 2,
				'reputation' => 8,
				'ban'        => 86400,
				'challenge'  => 0,
				'limit'      => 240,
				'missing'    => 10,
				'logins'     => 3,
				'lock'       => 86400,
				'xmlrpc'     => 'all',
				'authors'    => 'block',
				'feed'       => 'block',
				'everyone'   => true,
				'pass'       => 1,
				'work'       => 150000,
			),
		);
		return isset( $modes[ $mode ] ) ? $modes[ $mode ] : $modes['smart'];
	}
}
