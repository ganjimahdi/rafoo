<?php

namespace Rafoo\Cli;

use Rafoo\Assets\Library;
use Rafoo\Assets\Scanner;
use Rafoo\Assets\Sync;
use Rafoo\Cache\Purge;
use Rafoo\Connectivity\Monitor;
use Rafoo\State;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register() {
		$format = array(
			'type'     => 'assoc',
			'name'     => 'format',
			'optional' => true,
			'default'  => 'table',
			'options'  => array( 'table', 'json', 'csv', 'yaml' ),
		);
		$commands = array(
			'status' => array( 'Shows the connection state and asset counts.', array( $format ) ),
			'mode'   => array(
				'Sets the mode by hand.',
				array(
					array(
						'type'    => 'positional',
						'name'    => 'mode',
						'options' => array( 'auto', 'offline', 'online' ),
					),
				),
			),
			'check'  => array( 'Checks the connection now.', array() ),
			'scan'   => array( 'Visits a few pages of the site to find libraries loaded from public CDNs.', array() ),
			'sync'   => array(
				'Downloads pending assets.',
				array(
					array(
						'type'     => 'assoc',
						'name'     => 'budget',
						'optional' => true,
						'default'  => 120,
					),
				),
			),
			'assets' => array( 'Lists assets.', array( $format ) ),
			'verify' => array( 'Checks local files against their recorded hashes.', array() ),
			'purge'  => array( 'Clears the page cache of supported caching plugins.', array() ),
		);
		foreach ( $commands as $name => $definition ) {
			WP_CLI::add_command(
				'rafoo ' . $name,
				array( __CLASS__, $name ),
				array(
					'shortdesc' => $definition[0],
					'synopsis'  => $definition[1],
				)
			);
		}
	}

	public static function status( $args, $assoc ) {
		$state   = State::all();
		$summary = Library::summary();
		$rows    = array(
			self::row( 'mode', State::offline() ? 'offline' : 'online' ),
			self::row( 'detected', $state['detected'] ),
			self::row( 'override', $state['override'] ),
			self::row( 'checked', $state['checked_at'] ? gmdate( 'Y-m-d H:i:s', $state['checked_at'] ) . ' UTC' : '-' ),
			self::row( 'assets ready', $summary['ready'] ),
			self::row( 'assets unverified', $summary['unverified'] ),
			self::row( 'assets pending', $summary['pending'] ),
			self::row( 'assets failed', $summary['failed'] ),
			self::row( 'edition', RAFOO_EDITION ),
		);
		WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'field', 'value' ) );
	}

	public static function mode( $args ) {
		State::set_override( $args[0] );
		Purge::all();
		WP_CLI::success( sprintf( 'Mode set to %s. Rafoo is now %s.', $args[0], State::offline() ? 'offline' : 'online' ) );
	}

	public static function check() {
		$state = Monitor::run( true );
		foreach ( $state['targets'] as $target ) {
			WP_CLI::log( sprintf( '%-9s %-24s %s', $target['group'], $target['id'], $target['ok'] ? 'ok (' . $target['code'] . ')' : 'unreachable' ) );
		}
		WP_CLI::success( sprintf( 'Detected: %s.', $state['detected'] ) );
	}

	public static function scan() {
		$pages = Scanner::run();
		WP_CLI::success( sprintf( '%d pages scanned, %d assets known.', $pages, count( Library::records() ) ) );
	}

	public static function sync( $args, $assoc ) {
		delete_transient( Sync::LOCK );
		$result = Sync::run( max( 5, (int) $assoc['budget'] ) );
		WP_CLI::success( sprintf( '%d processed, %d remaining.', $result['processed'], $result['remaining'] ) );
	}

	public static function assets( $args, $assoc ) {
		$rows = array();
		foreach ( Library::records() as $record ) {
			$rows[] = array(
				'origin'   => $record['origin'],
				'status'   => $record['status'],
				'verified' => '' === $record['verified'] ? '-' : $record['verified'],
				'source'   => '' === $record['source'] ? '-' : $record['source'],
				'error'    => '' === $record['error'] ? '-' : $record['error'],
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'], $rows, array( 'origin', 'status', 'verified', 'source', 'error' ) );
	}

	public static function verify() {
		WP_CLI::success( Sync::verify() ? 'Damaged files queued for download.' : 'All files intact.' );
	}

	public static function purge() {
		$purged = Purge::all();
		WP_CLI::success( $purged ? 'Purged: ' . implode( ', ', $purged ) : 'No supported cache plugin found.' );
	}

	private static function row( $field, $value ) {
		return array(
			'field' => $field,
			'value' => $value,
		);
	}
}
