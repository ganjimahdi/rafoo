<?php

namespace Rafoo\Peers;

use Rafoo\Connectivity\Monitor;
use Rafoo\Signature;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Engine {

	const LOCK   = 'rafoo_peer_lock';
	const STATUS = 'rafoo_peer_status';
	const QUERY  = 'rafoo-ping';
	const WAKE   = 'rafoo-wake';
	const WAKES  = 'rafoo_peer_wakes';

	const WAKE_EVERY = 120;

	public static function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, signed reachability answer; the nonce is the caller's challenge, not a WordPress form.
		$nonce = isset( $_GET[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,48}$/', $nonce ) || '' === Keys::public_key() ) {
			return;
		}
		$time = time();
		$sig  = Keys::sign( 'rafoo-ping|' . $nonce . '|' . $time . '|' . Keys::host( home_url( '/' ) ) );
		if ( '' === $sig ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex' );
		echo wp_json_encode(
			array(
				'v' => 1,
				'n' => $nonce,
				't' => $time,
				's' => $sig,
			)
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the wake request is a signed envelope from rafoo.ir, checked in woken().
		$wake     = isset( $_GET[ self::WAKE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::WAKE ] ) ) : '';
		$wake     = preg_match( '/^[A-Za-z0-9_-]{40,8000}$/', $wake ) ? $wake : '';
		$detached = false;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			$detached = fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			$detached = litespeed_finish_request();
		}
		if ( Keys::member() && ( $detached || '' !== $wake ) ) {
			ignore_user_abort( true );
			if ( ! $detached ) {
				while ( ob_get_level() > 0 ) {
					ob_end_flush();
				}
				flush();
			}
			if ( '' !== $wake ) {
				self::woken( $wake );
			} else {
				self::tick();
			}
		}
		exit;
	}

	public static function woken( $raw ) {
		$state = Keys::data();
		$json  = base64_decode( strtr( $raw, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $raw ) % 4 ) % 4 ), true );
		$data  = false === $json ? null : Signature::open( $json );
		$now   = time();
		if ( ! is_array( $data ) || 'rafoo-peer-wake' !== ( isset( $data['kind'] ) ? $data['kind'] : '' ) || (int) $data['watcher'] !== (int) $state['member'] ) {
			return false;
		}
		if ( (int) $data['valid_until'] < $now || (int) $data['issued_at'] > $now + 60 || empty( $data['target'] ) || ! is_array( $data['target'] ) ) {
			return false;
		}
		$target = self::target( $data['target'] );
		if ( ! $target || ! self::fresh_wake( hash( 'sha256', $raw ), $target['id'], $now ) ) {
			return false;
		}
		if ( ! empty( $data['refresh'] ) && $now - ( isset( $state['tasks_try'] ) ? (int) $state['tasks_try'] : 0 ) >= 60 ) {
			self::refresh( true );
		}
		$results = Checker::run( array( $target ) );
		if ( ! isset( $results[ $target['id'] ] ) ) {
			return false;
		}
		$r   = $results[ $target['id'] ];
		$log = Book::load();
		$log = Book::record( $log, $target, isset( $data['epoch'] ) ? (int) $data['epoch'] : 0, $now, $r['ok'], $r['ms'], $r['code'] );
		$log = self::flush( $log, $now );
		Book::store( $log );
		Keys::update( array( 'checked' => $now ) );
		return true;
	}

	private static function fresh_wake( $hash, $target, $now ) {
		$seen = get_transient( self::WAKES );
		$seen = is_array( $seen ) ? $seen : array();
		foreach ( $seen as $key => $at ) {
			if ( $now - (int) $at > 300 ) {
				unset( $seen[ $key ] );
			}
		}
		if ( isset( $seen[ $hash ] ) || ( isset( $seen[ 't' . $target ] ) && $now - (int) $seen[ 't' . $target ] < 20 ) ) {
			return false;
		}
		$seen[ $hash ]          = $now;
		$seen[ 't' . $target ] = $now;
		set_transient( self::WAKES, array_slice( $seen, -100, null, true ), 600 );
		return true;
	}

	public static function target( array $t ) {
		if ( ! isset( $t['id'], $t['url'], $t['key'], $t['grant'] ) || ( ! wp_http_validate_url( $t['url'] ) && ! apply_filters( 'rafoo_peers_allow_local', false ) ) ) {
			return null;
		}
		return array(
			'id'       => (int) $t['id'],
			'url'      => esc_url_raw( $t['url'] ),
			'key'      => (string) $t['key'],
			'interval' => isset( $t['interval'] ) && 60 === (int) $t['interval'] ? 60 : 300,
			'scope'    => isset( $t['scope'] ) && 'intl' === $t['scope'] ? 'intl' : 'ir',
			'grant'    => (string) $t['grant'],
		);
	}

	public static function wake( $force = false ) {
		if ( ! Keys::member() ) {
			return false;
		}
		$state = Keys::data();
		if ( ! $force && time() - ( isset( $state['woke_at'] ) ? (int) $state['woke_at'] : 0 ) < self::WAKE_EVERY ) {
			return false;
		}
		Keys::update( array( 'woke_at' => time() ) );
		$result = Api::call( 'wake', array(), 8 );
		delete_transient( self::STATUS );
		return ! is_wp_error( $result );
	}

	public static function recheck( $ref ) {
		$result = Api::call( 'recheck', array( 'ref' => (string) $ref ), 8 );
		delete_transient( self::STATUS );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = json_decode( $result['body'], true );
		return is_array( $data ) ? $data : array();
	}

	public static function join( $interval ) {
		if ( ! Keys::ensure() ) {
			return new \WP_Error( 'rafoo_peer_sodium', __( 'This server cannot create a signing key (the sodium extension is missing).', 'rafoo' ) );
		}
		$interval = 60 === (int) $interval ? 60 : 300;
		$result   = Api::call(
			'join',
			array(
				'url'      => home_url( '/' ),
				'interval' => $interval,
				'edition'  => RAFOO_EDITION,
				'version'  => RAFOO_VERSION,
			),
			25
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return new \WP_Error( 'rafoo_peer_answer', __( 'rafoo.ir gave an answer that could not be read.', 'rafoo' ) );
		}
		Keys::update(
			array(
				'member'    => (int) $data['id'],
				'interval'  => $interval,
				'country'   => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '',
				'network'   => isset( $data['network'] ) ? sanitize_text_field( $data['network'] ) : '',
				'scope'     => isset( $data['scope'] ) && 'intl' === $data['scope'] ? 'intl' : 'ir',
				'joined_at' => time(),
				'tasks'     => array(),
				'next'      => 0,
			)
		);
		Book::store( array() );
		delete_transient( self::STATUS );
		State::event( 'peers_joined', array( 'interval' => $interval ) );
		self::refresh();
		self::wake( true );
		return true;
	}

	public static function leave() {
		if ( Keys::member() ) {
			Api::call( 'leave', array(), 8 );
		}
		Keys::forget();
		State::event( 'peers_left' );
		return true;
	}

	public static function set_interval( $interval ) {
		$interval = 60 === (int) $interval ? 60 : 300;
		Keys::update( array( 'interval' => $interval ) );
		delete_transient( self::STATUS );
		return self::refresh();
	}

	public static function refresh( $background = false ) {
		$state  = Keys::data();
		$result = Api::call(
			'tasks',
			array(
				'interval' => isset( $state['interval'] ) ? (int) $state['interval'] : 300,
				'version'  => RAFOO_VERSION,
			),
			8,
			$background
		);
		if ( is_wp_error( $result ) ) {
			if ( 'rafoo_peer_not_member' === $result->get_error_code() ) {
				Keys::update( array( 'member' => 0 ) );
			}
			Keys::update( array( 'tasks_try' => time() ) );
			return $result;
		}
		$tasks = Signature::open( $result['body'] );
		if ( ! is_array( $tasks ) || 'rafoo-peer-tasks' !== ( isset( $tasks['kind'] ) ? $tasks['kind'] : '' ) || (int) $tasks['member'] !== (int) $state['member'] ) {
			Keys::update( array( 'tasks_try' => time() ) );
			return new \WP_Error( 'rafoo_peer_tasks', __( 'The task list from rafoo.ir could not be verified.', 'rafoo' ) );
		}
		$targets = array();
		foreach ( isset( $tasks['targets'] ) && is_array( $tasks['targets'] ) ? $tasks['targets'] : array() as $t ) {
			$target = is_array( $t ) ? self::target( $t ) : null;
			if ( ! $target ) {
				continue;
			}
			$targets[] = $target;
			if ( count( $targets ) >= 20 ) {
				break;
			}
		}
		Keys::update(
			array(
				'tasks'     => $targets,
				'epoch'     => (int) $tasks['epoch'],
				'issued_at' => (int) $tasks['issued_at'],
				'valid'     => (int) $tasks['valid_until'],
				'refresh'   => max( 600, (int) $tasks['refresh'] ),
				'intl'      => isset( $tasks['intl'] ) && 'down' === $tasks['intl'] ? 'down' : 'up',
				'scope'     => isset( $tasks['scope'] ) && 'intl' === $tasks['scope'] ? 'intl' : 'ir',
				'tasks_try' => time(),
				'next'      => 0,
			)
		);
		return true;
	}

	public static function tick( $force = false ) {
		if ( ! Keys::member() ) {
			return;
		}
		$now   = time();
		$state = Keys::data();
		if ( ! $force && ! empty( $state['next'] ) && $now < (int) $state['next'] ) {
			return;
		}
		if ( get_transient( self::LOCK ) && ! $force ) {
			return;
		}
		set_transient( self::LOCK, $now, 60 );
		$refresh = isset( $state['refresh'] ) ? (int) $state['refresh'] : 21600;
		$tried   = isset( $state['tasks_try'] ) ? (int) $state['tasks_try'] : 0;
		$stale   = empty( $state['issued_at'] ) || $now - (int) $state['issued_at'] >= $refresh;
		if ( ( $stale && $now - $tried >= 300 ) || ( $force && $now - $tried >= 60 ) ) {
			self::refresh( ! $force );
			$state = Keys::data();
		}
		$targets = isset( $state['tasks'] ) && is_array( $state['tasks'] ) ? $state['tasks'] : array();
		if ( ! empty( $state['valid'] ) && $now > (int) $state['valid'] + 172800 ) {
			$targets = array();
		}
		$scope   = isset( $state['scope'] ) ? $state['scope'] : 'ir';
		$offline = State::offline();
		$log     = Book::load();
		$ids     = Book::due( $targets, $log, $now, $offline, $scope );
		$batch   = array();
		foreach ( $targets as $t ) {
			if ( in_array( (int) $t['id'], $ids, true ) ) {
				$batch[ (int) $t['id'] ] = $t;
			}
		}
		$results = Checker::run( array_values( $batch ) );
		$epoch   = isset( $state['epoch'] ) ? (int) $state['epoch'] : 0;
		foreach ( $results as $id => $r ) {
			$log = Book::record( $log, $batch[ $id ], $epoch, $now, $r['ok'], $r['ms'], $r['code'] );
		}
		$log = Book::sentinel( $log, $targets, $results, $now, $scope );
		$log = Book::prune( $log, $targets, $now );
		if ( Book::should_flush( $log, $now ) ) {
			$log = self::flush( $log, $now );
		}
		Book::store( $log );
		Keys::update(
			array(
				'next'     => Book::next( $targets, $log, $now, $offline, $scope ) + Monitor::jitter( 20 ),
				'ticked'   => $now,
				'checked'  => $results ? $now : ( isset( $state['checked'] ) ? (int) $state['checked'] : 0 ),
			)
		);
		delete_transient( self::LOCK );
	}

	private static function flush( array $log, $now ) {
		$items = Book::items( $log );
		if ( ! $items ) {
			return $log;
		}
		$result = Api::call( 'receipts', array( 'items' => $items ), 10, true );
		if ( is_wp_error( $result ) ) {
			if ( 'rafoo_peer_not_member' === $result->get_error_code() ) {
				Keys::update( array( 'member' => 0 ) );
			}
			$log['flush_at'] = $now;
			return $log;
		}
		delete_transient( self::STATUS );
		return Book::sent( $log, $items, $now );
	}

	public static function status( $fresh = false ) {
		$state  = Keys::data();
		$log    = Book::load();
		$local  = array(
			'member'    => Keys::member(),
			'ready'     => Keys::ready(),
			'interval'  => isset( $state['interval'] ) ? (int) $state['interval'] : 300,
			'joined_at' => isset( $state['joined_at'] ) ? (int) $state['joined_at'] : 0,
			'country'   => isset( $state['country'] ) ? $state['country'] : '',
			'network'   => isset( $state['network'] ) ? $state['network'] : '',
			'scope'     => isset( $state['scope'] ) ? $state['scope'] : 'ir',
			'watching'  => isset( $state['tasks'] ) && is_array( $state['tasks'] ) ? count( $state['tasks'] ) : 0,
			'checked'   => isset( $state['checked'] ) ? (int) $state['checked'] : 0,
			'pending'   => count( $log['b'] ),
			'sentinel'  => $log['sentinel'] > 0,
			'offline'   => State::offline(),
			'detached'  => function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ),
			'results'   => array_values(
				array_map(
					static function ( $id, $l ) {
						return array(
							'id' => (int) $id,
							'at' => (int) $l['at'],
							'ok' => (bool) $l['ok'],
							'ms' => (int) $l['ms'],
						);
					},
					array_keys( $log['last'] ),
					$log['last']
				)
			),
			'remote'    => null,
			'error'     => '',
			'now'       => time(),
		);
		if ( ! $local['member'] ) {
			return $local;
		}
		$remote = $fresh ? false : get_transient( self::STATUS );
		if ( false === $remote ) {
			$result = Api::call( 'status', array(), 8 );
			if ( is_wp_error( $result ) ) {
				$local['error'] = $result->get_error_message();
				if ( 'rafoo_peer_not_member' === $result->get_error_code() ) {
					Keys::update( array( 'member' => 0 ) );
					$local['member'] = false;
				}
				return $local;
			}
			$remote = json_decode( $result['body'], true );
			if ( is_array( $remote ) ) {
				set_transient( self::STATUS, $remote, 60 );
			}
		}
		$local['remote'] = is_array( $remote ) ? $remote : null;
		return $local;
	}
}
