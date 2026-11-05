<?php

namespace Rafoo\Backup;

use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Sender {

	const WAIT_LIMIT = 21600;

	public static function start( $code, $final = true, array $extra = array() ) {
		$peer = Transfer::resolve( $code );
		if ( is_wp_error( $peer ) ) {
			return $peer;
		}
		$hello = Transfer::call( $peer['url'], $peer['key'], 'hello', array(), false, 15 );
		if ( is_wp_error( $hello ) || empty( $hello['ok'] ) ) {
			return new \WP_Error( 'rafoo_peer', __( 'The other site did not answer. Check that Rafoo is active there and its code is still valid.', 'rafoo' ) );
		}
		if ( ! empty( $hello['busy'] ) ) {
			return new \WP_Error( 'rafoo_peer_busy', __( 'The other site is busy with another backup task. Try again when it finishes.', 'rafoo' ) );
		}
		if ( empty( $hello['format'] ) || (int) $hello['format'] < Maker::FORMAT ) {
			return new \WP_Error( 'rafoo_peer_old', __( 'Rafoo on the other site is too old to receive this copy. Update Rafoo there to version 1.4 or newer and make a new code.', 'rafoo' ) );
		}
		update_option(
			Transfer::OUTGOING,
			array(
				'url'   => $peer['url'],
				'key'   => Vault::seal( bin2hex( $peer['key'] ) ),
				'files' => array(),
				'peer'  => isset( $hello['home'] ) ? esc_url_raw( $hello['home'] ) : '',
			),
			false
		);
		$job = Job::start(
			'send',
			array(
				'role'   => 'send',
				'pass'   => 1,
				'final'  => (bool) $final,
				'peer'   => isset( $hello['home'] ) ? esc_url_raw( $hello['home'] ) : '',
				'inner'  => null,
				'lists'  => array(),
				'notice' => '',
				'geo'    => isset( $extra['geo'] ) ? (string) $extra['geo'] : '',
				'gen'    => isset( $extra['gen'] ) ? (int) $extra['gen'] : 0,
			),
			'backup'
		);
		if ( is_wp_error( $job ) ) {
			delete_option( Transfer::OUTGOING );
		}
		return $job;
	}

	public function step( array $job, $deadline ) {
		$d = &$job['data'];
		switch ( $job['step'] ) {
			case 'backup':
				if ( null === $d['inner'] ) {
					if ( 2 === $d['pass'] ) {
						Transfer::freeze( true );
					}
					$data = Maker::prepare(
						array(
							'transfer'  => true,
							'targets'   => array(),
							'key'       => Transfer::outgoing_key(),
							'base_list' => 2 === $d['pass'] && $d['lists'] ? end( $d['lists'] ) : '',
						)
					);
					if ( is_wp_error( $data ) ) {
						return Job::fail( $job, 'not_writable', array( 'path' => 'uploads' ) );
					}
					$d['inner'] = array(
						'step'     => 'db',
						'status'   => 'running',
						'error'    => '',
						'progress' => array(),
						'data'     => $data,
					);
				}
				$maker      = new Maker();
				$d['inner'] = $maker->step(
					$d['inner'],
					$deadline,
					static function ( array $inner ) use ( &$job ) {
						$job['data']['inner'] = $inner;
						$job['mark']          = isset( $inner['mark'] ) ? $inner['mark'] : null;
						Job::save( $job );
					}
				);
				$job['mark'] = isset( $d['inner']['mark'] ) ? $d['inner']['mark'] : null;
				$job['info'] = isset( $d['inner']['info'] ) ? $d['inner']['info'] : array();
				unset( $d['inner']['yield'] );
				$job['progress'] = $d['inner']['progress'];
				$job['phase']    = $d['inner']['step'];
				if ( 'failed' === $d['inner']['status'] ) {
					return Job::fail( $job, $d['inner']['error'], isset( $d['inner']['detail'] ) ? (array) $d['inner']['detail'] : array() );
				}
				if ( 'done' === $d['inner']['status'] ) {
					$inner        = $d['inner']['data'];
					$parts        = array_map(
						static function ( $part ) {
							return array_intersect_key( $part, array_flip( array( 'name', 'size', 'sha256' ) ) );
						},
						$inner['pack']['parts']
					);
					$d['archive'] = array(
						'parts'    => $parts,
						'manifest' => $inner['manifest'],
						'size'     => $inner['size'],
						'id'       => $inner['backup'],
					);
					$d['lists'][] = $inner['list'];
					$outgoing     = get_option( Transfer::OUTGOING );
					if ( is_array( $outgoing ) ) {
						$outgoing['files'] = array_column( $parts, 'name' );
						update_option( Transfer::OUTGOING, $outgoing, false );
					}
					$d['inner']  = null;
					$job['step'] = 'notify';
				}
				return $job;
			case 'notify':
				$reply = Transfer::call(
					$this->peer_url(),
					Transfer::outgoing_key(),
					'start',
					array(
						'parts'  => $d['archive']['parts'],
						'source' => Transfer::endpoint_url(),
						'pass'   => $d['pass'],
						'geo'    => ! empty( $d['geo'] ),
						'gen'    => isset( $d['gen'] ) ? (int) $d['gen'] : 0,
					)
				);
				if ( is_wp_error( $reply ) || empty( $reply['ok'] ) ) {
					return Job::fail( $job, is_wp_error( $reply ) ? 'unreachable' : ( isset( $reply['error'] ) && 'disk_full' === $reply['error'] ? 'remote: disk_full' : 'refused' ) );
				}
				$d['since']  = time();
				$job['step'] = 'wait';
				return $job;
			case 'wait':
				$reply = Transfer::call( $this->peer_url(), Transfer::outgoing_key(), 'status', array(), false, 20 );
				$peer  = is_wp_error( $reply ) || empty( $reply['job'] ) ? null : $reply['job'];
				if ( $peer ) {
					$job['progress'] = $peer['progress'];
					$job['phase']    = 'remote-' . $peer['step'];
					$job['info']     = isset( $peer['info'] ) ? (array) $peer['info'] : array();
				}
				if ( $peer && 'done' === $peer['status'] && 'receive' === $peer['kind'] ) {
					$this->forget_archive( $d );
					if ( 1 === $d['pass'] && $d['final'] ) {
						$d['pass']   = 2;
						$job['step'] = 'backup';
						return $job;
					}
					Transfer::freeze( false );
					$job['status'] = 'done';
					$job['step']   = 'done';
					return $job;
				}
				if ( $peer && in_array( $peer['status'], array( 'failed', 'stalled' ), true ) && 'receive' === $peer['kind'] ) {
					return Job::fail( $job, 'remote: ' . $peer['error'], isset( $peer['detail'] ) ? (array) $peer['detail'] : array() );
				}
				if ( time() - (int) $d['since'] > self::WAIT_LIMIT ) {
					return Job::fail( $job, 'timeout' );
				}
				$job['step'] = 'wait';
				return $job;
		}
		return Job::fail( $job, 'internal', array( 'message' => 'unknown step ' . $job['step'] ) );
	}

	private function peer_url() {
		$outgoing = get_option( Transfer::OUTGOING );
		return is_array( $outgoing ) ? $outgoing['url'] : '';
	}

	private function forget_archive( array $d ) {
		if ( ! empty( $d['archive'] ) ) {
			foreach ( (array) $d['archive']['parts'] as $part ) {
				wp_delete_file( Ledger::path( $part['name'] ) );
			}
			wp_delete_file( Ledger::path( $d['archive']['manifest'] ) );
			Catalog::forget( $d['archive']['id'] );
		}
	}

	public function cleanup( array $job ) {
		Transfer::freeze( false );
		$d = $job['data'];
		if ( ! empty( $d['inner']['data'] ) ) {
			( new Maker() )->cleanup( $d['inner'] );
		}
		$this->forget_archive( $d );
		foreach ( (array) $d['lists'] as $list ) {
			wp_delete_file( $list );
		}
	}

	public function finished( array $job ) {
		$this->cleanup( $job );
		delete_option( Transfer::OUTGOING );
		\Rafoo\Geo\Nodes::seeded( $job );
		State::event( 'done' === $job['status'] ? 'transfer_sent' : 'transfer_failed', array( 'name' => 'done' === $job['status'] ? $job['data']['peer'] : $job['error'] ) );
	}
}
