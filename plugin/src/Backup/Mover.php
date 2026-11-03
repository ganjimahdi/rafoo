<?php

namespace Rafoo\Backup;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Mover {

	public static function start( $destination, $folder, $url ) {
		$config = Destinations::get( $destination );
		if ( ! $config ) {
			return new \WP_Error( 'rafoo_not_found', __( 'This server is no longer in the list.', 'rafoo' ) );
		}
		$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return new \WP_Error( 'rafoo_invalid', __( 'Enter the address the site will have on the new host.', 'rafoo' ) );
		}
		$key = random_bytes( 32 );
		return Job::start(
			'move',
			array(
				'role'        => 'move',
				'destination' => $destination,
				'folder'      => '/' . trim( (string) $folder, '/' ),
				'url'         => untrailingslashit( $url ),
				'key'         => bin2hex( $key ),
				'inner'       => null,
				'sent'        => 0,
				'notice'      => '',
			),
			'backup'
		);
	}

	public function step( array $job, $deadline ) {
		$d = &$job['data'];
		switch ( $job['step'] ) {
			case 'backup':
				if ( null === $d['inner'] ) {
					$data = Maker::prepare(
						array(
							'transfer' => true,
							'targets'  => array(),
							'key'      => hex2bin( $d['key'] ),
							'full'     => true,
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
				$d['inner']      = ( new Maker() )->step( $d['inner'], $deadline );
				$job['progress'] = $d['inner']['progress'];
				$job['phase']    = $d['inner']['step'];
				if ( 'failed' === $d['inner']['status'] ) {
					return Job::fail( $job, $d['inner']['error'] );
				}
				if ( 'done' === $d['inner']['status'] ) {
					$d['archive'] = array(
						'name' => $d['inner']['data']['file'],
						'size' => $d['inner']['data']['size'],
						'id'   => $d['inner']['data']['backup'],
					);
					$d['inner']   = null;
					$job['step']  = 'upload';
				}
				return $job;
			case 'upload':
				$config         = Destinations::get( $d['destination'] );
				$config['path'] = $d['folder'];
				$remote         = $config ? Remote\Remote::make( $config ) : null;
				if ( ! $remote || ! $remote->connect() || ! $remote->prepare_folder() ) {
					return Job::fail( $job, 'unreachable' );
				}
				$sent = $remote->put( Ledger::path( $d['archive']['name'] ), $d['archive']['name'], (int) $d['sent'], $deadline );
				if ( false === $sent ) {
					$remote->close();
					return Job::fail( $job, $remote->error() );
				}
				$d['sent']       = (int) $sent;
				$job['progress'] = array(
					'done'  => $d['sent'],
					'total' => $d['archive']['size'],
				);
				if ( $d['sent'] >= $d['archive']['size'] ) {
					$script = \Rafoo\Backup\Ledger::temp( Installer::NAME );
					file_put_contents( $script, Installer::source() );
					$ok = false !== $remote->put( $script, Installer::NAME, 0, microtime( true ) + 60 );
					wp_delete_file( $script );
					if ( ! $ok || $remote->size( $d['archive']['name'] ) !== $d['archive']['size'] ) {
						$remote->close();
						return Job::fail( $job, 'upload failed' );
					}
					$d['notice']   = array(
						'url' => $d['url'] . '/' . Installer::NAME,
						'key' => Transfer::encode( hex2bin( $d['key'] ) ),
					);
					$job['status'] = 'done';
					$job['step']   = 'done';
				}
				$remote->close();
				return $job;
		}
		return Job::fail( $job, 'unknown step' );
	}

	public function cleanup( array $job ) {
		$d = $job['data'];
		if ( ! empty( $d['inner']['data']['work'] ) ) {
			Ledger::remove_dir( $d['inner']['data']['work'] );
		}
		if ( ! empty( $d['archive'] ) ) {
			wp_delete_file( Ledger::path( $d['archive']['name'] ) );
			Catalog::forget( $d['archive']['id'] );
		}
	}

	public function finished( array $job ) {
		$this->cleanup( $job );
		State::event( 'done' === $job['status'] ? 'move_ready' : 'move_failed', array( 'name' => 'done' === $job['status'] ? $job['data']['url'] : $job['error'] ) );
	}
}
