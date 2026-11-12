<?php

namespace Rafoo\Backup;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Mover {

	public static function start( $destination, $folder, $url ) {
		$config = Destinations::get( $destination );
		if ( ! $config || Remote\FilesIr::TYPE === $config['type'] ) {
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
				'files'       => array(),
				'index'       => 0,
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
				$d['inner']      = ( new Maker() )->step(
					$d['inner'],
					$deadline,
					static function ( array $inner ) use ( &$job ) {
						$job['data']['inner'] = $inner;
						$job['mark']          = isset( $inner['mark'] ) ? $inner['mark'] : null;
						Job::save( $job );
					}
				);
				$job['progress'] = $d['inner']['progress'];
				$job['phase']    = $d['inner']['step'];
				$job['info']     = isset( $d['inner']['info'] ) ? $d['inner']['info'] : array();
				$job['mark']     = isset( $d['inner']['mark'] ) ? $d['inner']['mark'] : null;
				if ( 'failed' === $d['inner']['status'] ) {
					return Job::fail( $job, $d['inner']['error'], isset( $d['inner']['detail'] ) ? (array) $d['inner']['detail'] : array() );
				}
				if ( 'done' === $d['inner']['status'] ) {
					$inner        = $d['inner']['data'];
					$d['archive'] = array(
						'id'       => $inner['backup'],
						'parts'    => $inner['pack']['parts'],
						'manifest' => $inner['manifest'],
					);
					$d['files']   = array_merge( array_column( $inner['pack']['parts'], 'name' ), array( $inner['manifest'] ) );
					$d['inner']   = null;
					$d['index']   = 0;
					$d['sent']    = 0;
					$job['step']  = 'upload';
				}
				return $job;
			case 'upload':
				$config = Destinations::get( $d['destination'] );
				if ( ! $config ) {
					return Job::fail( $job, 'unreachable', array( 'server' => $d['destination'] ) );
				}
				$config['path'] = $d['folder'];
				$remote         = Remote\Remote::make( $config );
				if ( ! $remote || ! $remote->connect() || ! $remote->prepare_folder() ) {
					return Job::fail( $job, 'upload_failed', array( 'server' => Destinations::label( $d['destination'] ), 'error' => $remote ? $remote->error() : '' ) );
				}
				while ( $d['index'] < count( $d['files'] ) && microtime( true ) < $deadline ) {
					$name = $d['files'][ $d['index'] ];
					$file = Ledger::path( $name );
					$size = (int) filesize( $file );
					$sent = $remote->put( $file, $name, (int) $d['sent'], $deadline );
					if ( false === $sent ) {
						$error = $remote->error();
						$remote->close();
						return Job::fail( $job, 'upload_failed', array( 'server' => Destinations::label( $d['destination'] ), 'error' => $error ) );
					}
					$d['sent']       = (int) $sent;
					$job['progress'] = array(
						'done'  => $d['sent'],
						'total' => max( 1, $size ),
					);
					$job['info']     = array(
						'server' => Destinations::label( $d['destination'] ),
						'index'  => $d['index'] + 1,
						'parts'  => count( $d['files'] ),
					);
					$job['mark']     = 'm' . $d['index'] . '-' . $d['sent'];
					if ( $d['sent'] < $size ) {
						break;
					}
					if ( $remote->size( $name ) !== $size ) {
						$remote->close();
						return Job::fail( $job, 'upload_failed', array( 'server' => Destinations::label( $d['destination'] ), 'error' => 'size mismatch: ' . $name ) );
					}
					wp_delete_file( $file );
					++$d['index'];
					$d['sent'] = 0;
				}
				if ( $d['index'] >= count( $d['files'] ) ) {
					$script = Ledger::temp( Installer::NAME );
					file_put_contents( $script, Installer::source() );
					$ok = false !== $remote->put( $script, Installer::NAME, 0, microtime( true ) + 60 );
					wp_delete_file( $script );
					if ( ! $ok ) {
						$error = $remote->error();
						$remote->close();
						return Job::fail( $job, 'upload_failed', array( 'server' => Destinations::label( $d['destination'] ), 'error' => $error ) );
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
		return Job::fail( $job, 'internal', array( 'message' => 'unknown step ' . $job['step'] ) );
	}

	public function cleanup( array $job ) {
		$d = $job['data'];
		if ( ! empty( $d['inner']['data'] ) ) {
			( new Maker() )->cleanup( $d['inner'] );
		}
		if ( ! empty( $d['archive'] ) ) {
			foreach ( (array) $d['archive']['parts'] as $part ) {
				wp_delete_file( Ledger::path( $part['name'] ) );
			}
			wp_delete_file( Ledger::path( $d['archive']['manifest'] ) );
			wp_delete_file( Ledger::path( $d['archive']['id'] . '.list' ) );
			Catalog::forget( $d['archive']['id'] );
		}
	}

	public function finished( array $job ) {
		$this->cleanup( $job );
		State::event( 'done' === $job['status'] ? 'move_ready' : 'move_failed', array( 'name' => 'done' === $job['status'] ? $job['data']['url'] : $job['error'] ) );
	}
}
