<?php

namespace Rafoo\Backup\Remote;

defined( 'ABSPATH' ) || exit;

final class Detect {

	const FOLDER = 'rafoo-backups';

	public static function run( array $input ) {
		$host = strtolower( trim( (string) ( isset( $input['host'] ) ? $input['host'] : '' ) ) );
		$host = preg_replace( '#^(?:s?ftps?|ssh)://#', '', $host );
		$host = explode( '/', $host )[0];
		if ( ! preg_match( '/^[a-z0-9.\-]+(?::\d{1,5})?$/', $host ) ) {
			return array( 'ok' => false, 'error' => 'bad_host', 'tried' => array() );
		}
		$port = 0;
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port ) = explode( ':', $host );
			$port                = (int) $port;
		}
		if ( ! empty( $input['port'] ) ) {
			$port = (int) $input['port'];
		}
		$base = array(
			'host'       => $host,
			'user'       => (string) ( isset( $input['user'] ) ? $input['user'] : '' ),
			'pass_plain' => (string) ( isset( $input['pass'] ) ? $input['pass'] : '' ),
			'key_plain'  => (string) ( isset( $input['key'] ) ? $input['key'] : '' ),
			'path'       => '',
		);
		$tries = array();
		$order = array(
			array( 'sftp', 22 ),
			array( 'ftps', 21 ),
			array( 'ftps-implicit', 990 ),
			array( 'ftp', 21 ),
		);
		if ( ! empty( $input['type'] ) ) {
			$order = array_values(
				array_filter(
					$order,
					static function ( $item ) use ( $input ) {
						return $item[0] === $input['type'];
					}
				)
			);
		}
		$failures = array();
		foreach ( $order as $item ) {
			if ( '' !== $base['key_plain'] && 'sftp' !== $item[0] ) {
				continue;
			}
			$config = $base + array(
				'type' => $item[0],
				'port' => $port > 0 ? $port : $item[1],
			);
			if ( $port > 0 && 'sftp' !== $item[0] && 22 === $port ) {
				continue;
			}
			if ( $port > 0 && 'sftp' === $item[0] && in_array( $port, array( 21, 990 ), true ) ) {
				continue;
			}
			$remote = Remote::make( $config );
			$tries[] = $item[0] . ':' . $config['port'];
			if ( ! $remote || ! $remote->connect( 8 ) ) {
				$failures[ $item[0] ] = $remote ? $remote->error() : 'unsupported';
				continue;
			}
			$config['path'] = self::folder( $remote, $config, isset( $input['path'] ) ? (string) $input['path'] : '' );
			$check          = Remote::make( $config );
			if ( '' === $config['path'] || ! $check->connect( 8 ) ) {
				$remote->close();
				$failures[ $item[0] ] = 'no writable folder';
				continue;
			}
			$test = $check->write_test();
			$free = method_exists( $check, 'free_space' ) ? $check->free_space() : -1;
			$fingerprint = $check instanceof Sftp ? $check->fingerprint() : '';
			$check->close();
			$remote->close();
			if ( false === $test ) {
				$failures[ $item[0] ] = $check->error();
				continue;
			}
			return array(
				'ok'          => true,
				'type'        => $item[0],
				'port'        => $config['port'],
				'path'        => $config['path'],
				'speed'       => $test['speed'],
				'free'        => $free,
				'fingerprint' => $fingerprint,
				'plain'       => 'ftp' === $item[0],
				'tried'       => $tries,
			);
		}
		$login = in_array( 'login failed', $failures, true );
		return array(
			'ok'       => false,
			'error'    => $login ? 'login_failed' : 'unreachable',
			'tried'    => $tries,
			'failures' => $failures,
		);
	}

	private static function folder( Remote $remote, array $config, $wanted ) {
		$wanted = trim( $wanted );
		$home   = method_exists( $remote, 'home' ) ? rtrim( $remote->home(), '/' ) : '';
		$tries  = '' !== $wanted
			? array( 0 === strpos( $wanted, '/' ) ? $wanted : $home . '/' . $wanted )
			: array( $home . '/' . self::FOLDER, '/' . self::FOLDER );
		foreach ( array_unique( $tries ) as $path ) {
			if ( $remote->mkdir( $path ) ) {
				return $path;
			}
		}
		return '';
	}
}
