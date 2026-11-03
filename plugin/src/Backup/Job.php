<?php

namespace Rafoo\Backup;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Job {

	const NAME = 'job';

	const HOOK = 'rafoo_backup_tick';

	const ACTION = 'rafoo_backup_tick';

	const STALE = 900;

	public static function current() {
		return Ledger::read( self::NAME );
	}

	public static function running() {
		$job = self::current();
		return $job && 'running' === $job['status'] && time() - (int) $job['updated'] < self::STALE ? $job : null;
	}

	public static function start( $kind, array $data, $step ) {
		if ( self::running() ) {
			return new \WP_Error( 'rafoo_busy', __( 'Another backup task is running. Wait until it finishes.', 'rafoo' ) );
		}
		if ( '' === Ledger::dir() ) {
			return new \WP_Error( 'rafoo_storage', __( 'The uploads folder is not writable, so Rafoo cannot keep backups.', 'rafoo' ) );
		}
		$job = array(
			'id'       => strtolower( wp_generate_password( 10, false ) ),
			'kind'     => $kind,
			'step'     => $step,
			'status'   => 'running',
			'error'    => '',
			'started'  => time(),
			'updated'  => time(),
			'token'    => wp_generate_password( 32, false ),
			'progress' => array(
				'done'  => 0,
				'total' => 0,
			),
			'data'     => $data,
		);
		Ledger::write( self::NAME, $job );
		self::kick( $job );
		return $job;
	}

	public static function tick( $seconds = 0 ) {
		if ( ! Ledger::lock( self::NAME ) ) {
			return self::current();
		}
		$job = self::current();
		if ( ! $job || 'running' !== $job['status'] ) {
			Ledger::unlock( self::NAME );
			return $job;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( Ledger::budget() + 30 );
		}
		ignore_user_abort( true );
		$crashes = isset( $job['crashes'] ) ? (int) $job['crashes'] : 0;
		if ( $crashes >= 3 ) {
			$job = self::fail( $job, 'crashed' );
			$job['updated'] = time();
			Ledger::write( self::NAME, $job );
			Ledger::unlock( self::NAME );
			self::handler( $job['kind'] )->cleanup( $job );
			self::finished( $job );
			return $job;
		}
		$job['crashes'] = $crashes + 1;
		Ledger::write( self::NAME, $job );
		$job['crashes'] = 0;
		$deadline       = microtime( true ) + ( $seconds > 0 ? $seconds : Ledger::budget() );
		try {
			while ( 'running' === $job['status'] && microtime( true ) < $deadline ) {
				$before = $job['step'];
				$job    = self::handler( $job['kind'] )->step( $job, $deadline );
				if ( 'wait' === $job['step'] || ( $before === $job['step'] && 'running' === $job['status'] && ! empty( $job['yield'] ) ) ) {
					unset( $job['yield'] );
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$job['status'] = 'failed';
			$job['error']  = substr( $e->getMessage(), 0, 300 );
		}
		$job['updated'] = time();
		Ledger::write( self::NAME, $job );
		Ledger::unlock( self::NAME );
		if ( 'running' === $job['status'] ) {
			self::kick( $job, 'wait' === $job['step'] ? 10 : 1 );
		} else {
			wp_clear_scheduled_hook( self::HOOK );
			self::finished( $job );
		}
		return $job;
	}

	public static function fail( array $job, $message ) {
		$job['status'] = 'failed';
		$job['error']  = (string) $message;
		return $job;
	}

	public static function cancel() {
		$job = self::current();
		if ( $job && 'running' === $job['status'] ) {
			$job['status'] = 'failed';
			$job['error']  = 'cancelled';
			self::handler( $job['kind'] )->cleanup( $job );
			Ledger::write( self::NAME, $job );
			wp_clear_scheduled_hook( self::HOOK );
			State::event( 'backup_cancelled', array() );
		}
		return $job;
	}

	public static function kick( array $job, $delay = 1 ) {
		if ( ! apply_filters( 'rafoo_backup_background', true ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HOOK );
		}
		if ( $delay > 2 ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'     => 0.01,
				'blocking'    => false,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'body'        => array(
					'action' => self::ACTION,
					'job'    => $job['id'],
					'token'  => hash_hmac( 'sha256', $job['id'], $job['token'] ),
				),
				'rafoo_probe' => true,
			)
		);
	}

	public static function endpoint() {
		$job   = self::current();
		$id    = (string) filter_input( INPUT_POST, 'job' );
		$token = (string) filter_input( INPUT_POST, 'token' );
		if ( ! $job || $id !== $job['id'] || ! hash_equals( hash_hmac( 'sha256', $job['id'], $job['token'] ), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::tick();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	public static function cron() {
		self::tick();
	}

	public static function view( $job = null ) {
		$job = null === $job ? self::current() : $job;
		if ( ! $job ) {
			return null;
		}
		return array(
			'id'       => $job['id'],
			'kind'     => $job['kind'],
			'step'     => $job['step'],
			'status'   => 'running' === $job['status'] && time() - (int) $job['updated'] >= self::STALE ? 'stalled' : $job['status'],
			'error'    => $job['error'],
			'started'  => (int) $job['started'],
			'updated'  => (int) $job['updated'],
			'progress' => $job['progress'],
			'role'     => isset( $job['data']['role'] ) ? $job['data']['role'] : '',
			'backup'   => isset( $job['data']['backup'] ) ? $job['data']['backup'] : '',
			'notice'   => isset( $job['data']['notice'] ) ? $job['data']['notice'] : '',
		);
	}

	private static function handler( $kind ) {
		switch ( $kind ) {
			case 'restore':
			case 'receive':
				return new Restorer();
			case 'send':
				return new Sender();
			case 'move':
				return new Mover();
		}
		return new Maker();
	}

	private static function finished( array $job ) {
		self::handler( $job['kind'] )->finished( $job );
	}
}
