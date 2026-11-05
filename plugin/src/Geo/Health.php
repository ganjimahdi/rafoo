<?php

namespace Rafoo\Geo;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Health {

	const HOOK = 'rafoo_geo_tick';

	const GRACE = 3;

	const REPORT_AGE = 300;

	const TAKEOVER = 300;

	public static function tick() {
		if ( ! Store::active() ) {
			return;
		}
		$self = Store::self_node();
		if ( ! $self || 'ready' !== $self['status'] ) {
			return;
		}
		self::probe();
		Seo::verify_batch();
		if ( Store::is_primary() ) {
			if ( Lister::due() ) {
				Lister::run( 8 );
			}
			Planner::tick();
			return;
		}
		Syncer::run( 25 );
		if ( self::controller() ) {
			Planner::tick( true );
		}
	}

	public static function probe() {
		$state  = Store::state();
		$health = (array) $state['health'];
		foreach ( Store::others() as $id => $node ) {
			if ( 'ready' !== $node['status'] ) {
				continue;
			}
			$reply = Link::call( $node, 'ping', array(), false, 8 );
			$entry = isset( $health[ $id ] ) ? (array) $health[ $id ] : array( 'fails' => 0 );
			if ( ! is_wp_error( $reply ) && ! empty( $reply['ok'] ) ) {
				$health[ $id ] = array(
					'ok'      => true,
					'ms'      => (int) $reply['_ms'],
					'at'      => time(),
					'last_ok' => time(),
					'fails'   => 0,
					'gen'     => (int) $reply['gen'],
					'offline' => ! empty( $reply['offline'] ),
					'version' => sanitize_text_field( (string) $reply['version'] ),
					'error'   => '',
				);
				continue;
			}
			$health[ $id ] = array_merge(
				$entry,
				array(
					'ok'    => false,
					'at'    => time(),
					'fails' => (int) ( isset( $entry['fails'] ) ? $entry['fails'] : 0 ) + 1,
					'error' => is_wp_error( $reply ) ? substr( $reply->get_error_message(), 0, 200 ) : 'refused',
				)
			);
		}
		foreach ( array_keys( $health ) as $id ) {
			if ( ! Store::node( $id ) ) {
				unset( $health[ $id ] );
			}
		}
		Store::save_state( array( 'health' => $health ) );
		return $health;
	}

	public static function report() {
		$state = Store::state();
		$out   = array();
		foreach ( (array) $state['health'] as $id => $entry ) {
			$out[ $id ] = array_intersect_key( (array) $entry, array_flip( array( 'ok', 'ms', 'at', 'fails', 'last_ok' ) ) );
		}
		return array(
			'health'  => $out,
			'offline' => State::offline(),
			'sync'    => Store::is_copy() ? Syncer::view() : null,
			'crawl'   => Seo::summary(),
			'version' => RAFOO_VERSION,
			'php'     => PHP_VERSION,
			'at'      => time(),
		);
	}

	public static function remember( $from, array $report ) {
		if ( ! Store::node( $from ) ) {
			return;
		}
		$state            = Store::state();
		$reports          = (array) $state['reports'];
		$report['got']    = time();
		$reports[ $from ] = $report;
		Store::save_state( array( 'reports' => $reports ) );
	}

	public static function up( $id, $copy_view = false ) {
		$node = Store::node( $id );
		if ( ! $node ) {
			return false;
		}
		if ( ! empty( $node['primary'] ) ) {
			return true;
		}
		if ( 'ready' !== $node['status'] ) {
			return false;
		}
		if ( (string) Store::get( 'self' ) === $id ) {
			return true;
		}
		$state = Store::state();
		$own   = isset( $state['health'][ $id ] ) ? (array) $state['health'][ $id ] : null;
		if ( ! $own || ! empty( $own['ok'] ) || (int) $own['fails'] < self::GRACE ) {
			return true;
		}
		if ( ! $copy_view && State::offline() ) {
			return true;
		}
		foreach ( (array) $state['reports'] as $from => $report ) {
			if ( $from === $id || time() - (int) ( isset( $report['got'] ) ? $report['got'] : 0 ) > self::REPORT_AGE ) {
				continue;
			}
			$seen = isset( $report['health'][ $id ] ) ? (array) $report['health'][ $id ] : null;
			if ( $seen && ! empty( $seen['ok'] ) && time() - (int) $seen['at'] < self::REPORT_AGE ) {
				return true;
			}
		}
		return false;
	}

	public static function controller() {
		if ( ! Store::is_copy() || 'provider' !== Store::get( 'method' )['type'] ) {
			return false;
		}
		$contact = (int) Store::state()['contact'];
		if ( time() - $contact < self::TAKEOVER ) {
			return false;
		}
		$self = (string) Store::get( 'self' );
		foreach ( Store::nodes() as $id => $node ) {
			if ( empty( $node['primary'] ) && 'ready' === $node['status'] && strcmp( $id, $self ) < 0 && self::up( $id, true ) ) {
				return false;
			}
		}
		return true;
	}

	public static function view() {
		$state = Store::state();
		$out   = array();
		foreach ( Store::nodes() as $id => $node ) {
			$views = array();
			foreach ( (array) $state['reports'] as $from => $report ) {
				if ( isset( $report['health'][ $id ] ) ) {
					$views[ $from ] = (array) $report['health'][ $id ];
				}
			}
			$report     = isset( $state['reports'][ $id ] ) ? (array) $state['reports'][ $id ] : array();
			$out[ $id ] = array(
				'up'      => self::up( $id ),
				'self'    => isset( $state['health'][ $id ] ) ? (array) $state['health'][ $id ] : null,
				'views'   => $views,
				'sync'    => isset( $report['sync'] ) ? $report['sync'] : null,
				'crawl'   => (string) Store::get( 'self' ) === $id ? Seo::summary() : ( isset( $report['crawl'] ) ? $report['crawl'] : null ),
				'version' => (string) Store::get( 'self' ) === $id ? RAFOO_VERSION : ( isset( $report['version'] ) ? (string) $report['version'] : ( isset( $state['health'][ $id ]['version'] ) ? (string) $state['health'][ $id ]['version'] : '' ) ),
				'offline' => (string) Store::get( 'self' ) === $id ? State::offline() : ! empty( $report['offline'] ),
				'report'  => isset( $report['got'] ) ? (int) $report['got'] : 0,
			);
		}
		return $out;
	}
}
