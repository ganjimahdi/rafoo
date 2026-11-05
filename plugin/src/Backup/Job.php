<?php

namespace Rafoo\Backup;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Job {

	const NAME = 'job';

	const FATAL = 'job-fatal';

	const HOOK = 'rafoo_backup_tick';

	const ACTION = 'rafoo_backup_tick';

	const STALE = 900;

	const STRIKES = 4;

	private static $active = false;

	private static $watching = false;

	private static $reserve = null;

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
			'format'   => Maker::FORMAT,
			'step'     => $step,
			'status'   => 'running',
			'error'    => '',
			'detail'   => array(),
			'started'  => time(),
			'updated'  => time(),
			'token'    => wp_generate_password( 32, false ),
			'crashes'  => 0,
			'strikes'  => 0,
			'tune'     => array(),
			'progress' => array(
				'done'  => 0,
				'total' => 0,
			),
			'data'     => $data,
		);
		$old = self::current();
		if ( $old && 'running' === $old['status'] ) {
			self::handler( $old['kind'] )->cleanup( $old );
		}
		Ledger::write( self::FATAL, null );
		Journal::start( 'Rafoo ' . $kind . ' started' );
		Maker::sweep();
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
		$job = self::recover( $job );
		if ( 'running' === $job['status'] && empty( $job['format'] ) && in_array( $job['kind'], array( 'backup', 'send', 'move' ), true ) ) {
			$job = self::fail( $job, 'outdated' );
		}
		if ( 'running' !== $job['status'] ) {
			$job['updated'] = time();
			Ledger::write( self::NAME, $job );
			Ledger::unlock( self::NAME );
			self::handler( $job['kind'] )->cleanup( $job );
			self::finished( $job );
			return $job;
		}
		$budget = Ledger::budget( self::tune( $job, 'budget' ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 40 );
		}
		ignore_user_abort( true );
		$job['crashes'] = 1;
		$job['updated'] = time();
		Ledger::write( self::NAME, $job );
		self::watch();
		$deadline = microtime( true ) + ( $seconds > 0 ? min( $seconds, $budget ) : $budget );
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
			$problem = Problem::from( $e );
			$job     = self::fail( $job, $problem->reason(), $problem->detail() );
		}
		self::$active   = false;
		$job['crashes'] = 0;
		$job['strikes'] = 0;
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

	public static function save( array $job ) {
		$job['updated'] = time();
		Ledger::write( self::NAME, $job );
	}

	public static function tune( array $job, $name ) {
		return isset( $job['tune'][ $name ] ) ? (float) $job['tune'][ $name ] : 1.0;
	}

	public static function fail( array $job, $code, array $detail = array() ) {
		$job['status'] = 'failed';
		$job['error']  = (string) $code;
		$job['detail'] = $detail;
		Journal::add( 'stopped: ' . $code . ( $detail ? ' ' . wp_json_encode( $detail ) : '' ) );
		return $job;
	}

	public static function cancel() {
		$job = self::current();
		if ( $job && 'running' === $job['status'] ) {
			$job['status'] = 'failed';
			$job['error']  = 'cancelled';
			Journal::add( 'stopped by hand' );
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
			(string) apply_filters( 'rafoo_loopback_url', admin_url( 'admin-ajax.php' ) ),
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
		$d = isset( $job['data'] ) ? $job['data'] : array();
		return array(
			'id'       => $job['id'],
			'kind'     => $job['kind'],
			'step'     => $job['step'],
			'phase'    => isset( $job['phase'] ) ? (string) $job['phase'] : '',
			'status'   => 'running' === $job['status'] && time() - (int) $job['updated'] >= self::STALE ? 'stalled' : $job['status'],
			'error'    => $job['error'],
			'detail'   => isset( $job['detail'] ) ? (array) $job['detail'] : array(),
			'started'  => (int) $job['started'],
			'updated'  => (int) $job['updated'],
			'progress' => $job['progress'],
			'info'     => isset( $job['info'] ) ? (array) $job['info'] : array(),
			'warnings' => isset( $d['warnings'] ) ? Warnings::view( $d['warnings'] ) : array(),
			'retried'  => isset( $job['last_crash'] ) ? $job['last_crash']['kind'] : '',
			'role'     => isset( $d['role'] ) ? $d['role'] : '',
			'backup'   => isset( $d['backup'] ) ? $d['backup'] : '',
			'notice'   => isset( $d['notice'] ) ? $d['notice'] : '',
		);
	}

	public static function shutdown() {
		self::$reserve = null;
		if ( ! self::$active ) {
			return;
		}
		$error = error_get_last();
		if ( $error && in_array( (int) $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE ), true ) ) {
			Ledger::write(
				self::FATAL,
				array(
					'message' => Journal::clean( $error['message'] ),
					'at'      => time(),
				)
			);
		}
	}

	public static function classify( $message ) {
		$message = (string) $message;
		if ( false !== stripos( $message, 'Allowed memory size' ) || false !== stripos( $message, 'Out of memory' ) ) {
			return 'php_memory';
		}
		if ( false !== stripos( $message, 'Maximum execution time' ) ) {
			return 'php_time';
		}
		return '' === $message ? 'php_killed' : 'php_error';
	}

	private static function watch() {
		self::$active  = true;
		self::$reserve = str_repeat( ' ', 1048576 );
		if ( ! self::$watching ) {
			self::$watching = true;
			register_shutdown_function( array( __CLASS__, 'shutdown' ) );
		}
	}

	private static function recover( array $job ) {
		if ( empty( $job['crashes'] ) ) {
			return $job;
		}
		$fatal = Ledger::read( self::FATAL );
		Ledger::write( self::FATAL, null );
		$message = is_array( $fatal ) && isset( $fatal['message'] ) ? (string) $fatal['message'] : '';
		$kind    = self::classify( $message );
		$mark    = isset( $job['mark'] ) ? $job['mark'] : null;
		$same    = array_key_exists( 'crash_mark', $job ) && $job['crash_mark'] === $mark;
		$strikes = $same ? (int) $job['strikes'] + 1 : 1;
		$tune    = isset( $job['tune'] ) ? (array) $job['tune'] : array();
		if ( 'php_memory' === $kind ) {
			$tune['memory'] = max( 0.125, self::tune( $job, 'memory' ) / 2 );
		} else {
			$tune['budget'] = max( 0.15, self::tune( $job, 'budget' ) / 2 );
		}
		$job['tune']       = $tune;
		$job['crashes']    = 0;
		$job['strikes']    = $strikes;
		$job['crash_mark'] = $mark;
		$job['last_crash'] = array(
			'kind'    => $kind,
			'message' => $message,
			'step'    => $job['step'],
		);
		Journal::add( 'PHP stopped during "' . $job['step'] . '"' . ( '' !== $message ? ': ' . $message : ' with no error message, so the host ended the request' ) . '. Trying again with smaller steps (' . $strikes . ' of ' . self::STRIKES . ').' );
		if ( $strikes < self::STRIKES ) {
			return $job;
		}
		$details = array(
			'php_memory' => array( 'limit' => (string) ini_get( 'memory_limit' ) ),
			'php_time'   => array( 'limit' => (int) ini_get( 'max_execution_time' ) ),
			'php_error'  => array( 'message' => $message ),
			'php_killed' => array( 'seconds' => Ledger::budget( self::tune( $job, 'budget' ) ) ),
		);
		return self::fail( $job, $kind, $details[ $kind ] );
	}

	private static function handler( $kind ) {
		switch ( $kind ) {
			case 'restore':
			case 'receive':
			case 'check':
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
