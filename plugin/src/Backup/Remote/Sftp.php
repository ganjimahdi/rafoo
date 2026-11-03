<?php

namespace Rafoo\Backup\Remote;

use Rafoo\Vendor\phpseclib3\Crypt\PublicKeyLoader;
use Rafoo\Vendor\phpseclib3\Net\SFTP as Client;

defined( 'ABSPATH' ) || exit;

final class Sftp extends Remote {

	private $client = null;

	public function connect( $timeout = 15 ) {
		$port = ! empty( $this->config['port'] ) ? (int) $this->config['port'] : 22;
		try {
			$client = new Client( $this->config['host'], $port, $timeout );
			$key    = $this->secret( 'key' );
			$auth   = '' !== $key ? PublicKeyLoader::load( $key, '' !== $this->secret( 'pass' ) ? $this->secret( 'pass' ) : false ) : $this->secret( 'pass' );
			if ( ! $client->login( (string) $this->config['user'], $auth ) ) {
				return $this->fail( 'login failed' );
			}
			$client->setTimeout( max( 30, $timeout ) );
			$this->client = $client;
			if ( ! empty( $this->config['fingerprint'] ) && ! hash_equals( (string) $this->config['fingerprint'], $this->fingerprint() ) ) {
				$this->close();
				return $this->fail( 'server key changed' );
			}
			return true;
		} catch ( \Exception $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function fingerprint() {
		$key = $this->client ? $this->client->getServerPublicHostKey() : '';
		return is_string( $key ) && '' !== $key ? hash( 'sha256', $key ) : '';
	}

	public function close() {
		if ( $this->client ) {
			$this->client->disconnect();
			$this->client = null;
		}
	}

	public function size( $name ) {
		$size = $this->client->filesize( $this->remote( $name ) );
		return false === $size ? -1 : (int) $size;
	}

	public function put( $local, $name, $offset, $deadline ) {
		$in = \Rafoo\Backup\Ledger::open( $local, 'rb' );
		if ( ! $in ) {
			return $this->fail( 'local file missing' );
		}
		$total  = (int) filesize( $local );
		$remote = $this->remote( $name );
		if ( 0 === $offset && ! $this->client->put( $remote, '' ) ) {
			return $this->fail( 'upload failed' );
		}
		$in->fseek( $offset );
		while ( $offset < $total && microtime( true ) < $deadline ) {
			$chunk = (string) $in->fread( self::CHUNK );
			if ( ! $this->client->put( $remote, $chunk, Client::SOURCE_STRING, $offset ) ) {
				return $this->fail( 'upload failed' );
			}
			$offset += strlen( $chunk );
		}
		return $offset;
	}

	public function get( $name, $local, $offset, $deadline ) {
		$total = $this->size( $name );
		$out   = \Rafoo\Backup\Ledger::open( $local, $offset > 0 ? 'ab' : 'wb' );
		if ( ! $out || $total < 0 ) {
			return $this->fail( 'download failed' );
		}
		while ( $offset < $total && microtime( true ) < $deadline ) {
			$chunk = $this->client->get( $this->remote( $name ), false, $offset, self::CHUNK );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				return $this->fail( 'download failed' );
			}
			$out->fwrite( $chunk );
			$offset += strlen( $chunk );
		}
		return $offset;
	}

	public function delete( $name ) {
		return (bool) $this->client->delete( $this->remote( $name ), false );
	}

	public function names() {
		$list = $this->client->nlist( '' === $this->folder() ? '.' : $this->folder() );
		return is_array( $list ) ? array_values( array_diff( $list, array( '.', '..' ) ) ) : array();
	}

	public function mkdir( $path ) {
		return $this->client->is_dir( $path ) || $this->client->mkdir( $path, -1, true );
	}

	public function home() {
		$home = $this->client->pwd();
		return is_string( $home ) ? $home : '/';
	}

	public function free_space() {
		$stat = method_exists( $this->client, 'statvfs' ) ? $this->client->statvfs( '' === $this->folder() ? '.' : $this->folder() ) : false;
		return is_array( $stat ) && isset( $stat['bavail'], $stat['frsize'] ) ? (int) $stat['bavail'] * (int) $stat['frsize'] : -1;
	}
}
