<?php

namespace Rafoo\Backup;

use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Sender {

	const WAIT_LIMIT = 21600;

	public static function start( $code, $final = true ) {
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
		if ( empty( $hello['zip'] ) ) {
			return new \WP_Error( 'rafoo_peer_zip', __( 'The other server has no ZipArchive extension, so it cannot receive the copy.', 'rafoo' ) );
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
						return Job::fail( $job, $data->get_error_code() );
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
				$d['inner'] = $maker->step( $d['inner'], $deadline );
				unset( $d['inner']['yield'] );
				$job['progress'] = $d['inner']['progress'];
				$job['phase']    = $d['inner']['step'];
				if ( 'failed' === $d['inner']['status'] ) {
					return Job::fail( $job, $d['inner']['error'] );
				}
				if ( 'done' === $d['inner']['status'] ) {
					$inner        = $d['inner']['data'];
					$d['archive'] = array(
						'name'   => $inner['file'],
						'size'   => $inner['size'],
						'sha256' => $inner['sha256'],
						'id'     => $inner['backup'],
					);
					$d['lists'][] = $inner['list'];
					$outgoing     = get_option( Transfer::OUTGOING );
					if ( is_array( $outgoing ) ) {
						$outgoing['files'] = array( $inner['file'] );
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
						'name'   => $d['archive']['name'],
						'size'   => $d['archive']['size'],
						'sha256' => $d['archive']['sha256'],
						'source' => Transfer::endpoint_url(),
						'pass'   => $d['pass'],
					)
				);
				if ( is_wp_error( $reply ) || empty( $reply['ok'] ) ) {
					return Job::fail( $job, is_wp_error( $reply ) ? 'unreachable' : ( isset( $reply['error'] ) ? $reply['error'] : 'refused' ) );
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
					return Job::fail( $job, 'remote: ' . $peer['error'] );
				}
				if ( time() - (int) $d['since'] > self::WAIT_LIMIT ) {
					return Job::fail( $job, 'timeout' );
				}
				$job['step'] = 'wait';
				return $job;
		}
		return Job::fail( $job, 'unknown step' );
	}

	private function peer_url() {
		$outgoing = get_option( Transfer::OUTGOING );
		return is_array( $outgoing ) ? $outgoing['url'] : '';
	}

	private function forget_archive( array $d ) {
		if ( ! empty( $d['archive'] ) ) {
			wp_delete_file( Ledger::path( $d['archive']['name'] ) );
			Catalog::forget( $d['archive']['id'] );
		}
	}

	public function cleanup( array $job ) {
		Transfer::freeze( false );
		$d = $job['data'];
		if ( ! empty( $d['inner']['data']['work'] ) ) {
			Ledger::remove_dir( $d['inner']['data']['work'] );
		}
		$this->forget_archive( $d );
		foreach ( (array) $d['lists'] as $list ) {
			wp_delete_file( $list );
		}
	}

	public function finished( array $job ) {
		$this->cleanup( $job );
		delete_option( Transfer::OUTGOING );
		State::event( 'done' === $job['status'] ? 'transfer_sent' : 'transfer_failed', array( 'name' => 'done' === $job['status'] ? $job['data']['peer'] : $job['error'] ) );
	}
}
