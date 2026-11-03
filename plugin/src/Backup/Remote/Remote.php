<?php

namespace Rafoo\Backup\Remote;

use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

abstract class Remote {

	const CHUNK = 8388608;

	protected $config;

	protected $error = '';

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function make( array $config ) {
		switch ( isset( $config['type'] ) ? $config['type'] : '' ) {
			case 'sftp':
				return new Sftp( $config );
			case 'ftps-implicit':
				return self::curl() ? new Curl( $config ) : null;
			case 'ftp':
			case 'ftps':
				if ( Ftp::available() ) {
					return new Ftp( $config );
				}
				return self::curl() ? new Curl( $config ) : null;
		}
		return null;
	}

	public static function curl() {
		return is_readable( __DIR__ . '/Curl.php' ) && Curl::available();
	}

	public function error() {
		return $this->error;
	}

	protected function secret( $field ) {
		if ( isset( $this->config[ $field . '_plain' ] ) ) {
			return (string) $this->config[ $field . '_plain' ];
		}
		return isset( $this->config[ $field ] ) ? Vault::open( $this->config[ $field ] ) : '';
	}

	protected function folder() {
		$path = isset( $this->config['path'] ) ? trim( (string) $this->config['path'] ) : '';
		return '' === $path ? '' : '/' . trim( $path, '/' );
	}

	protected function remote( $name ) {
		$folder = $this->folder();
		return ( '' === $folder ? '' : $folder . '/' ) . basename( $name );
	}

	protected function fail( $message ) {
		$this->error = (string) $message;
		return false;
	}

	abstract public function connect( $timeout = 15 );

	abstract public function close();

	abstract public function size( $name );

	abstract public function put( $local, $name, $offset, $deadline );

	abstract public function get( $name, $local, $offset, $deadline );

	abstract public function delete( $name );

	abstract public function names();

	abstract public function mkdir( $path );

	public function prepare_folder() {
		$folder = $this->folder();
		if ( '' === $folder ) {
			return true;
		}
		return $this->mkdir( $folder );
	}

	public function write_test() {
		$name    = 'rafoo-test-' . strtolower( wp_generate_password( 8, false ) ) . '.txt';
		$local   = \Rafoo\Backup\Ledger::temp( $name );
		$payload = str_repeat( 'rafoo', 104858 );
		file_put_contents( $local, $payload );
		$started = microtime( true );
		$sent    = $this->put( $local, $name, 0, microtime( true ) + 60 );
		$took    = max( 0.001, microtime( true ) - $started );
		wp_delete_file( $local );
		if ( false === $sent || strlen( $payload ) !== (int) $this->size( $name ) ) {
			$this->delete( $name );
			return $this->fail( '' !== $this->error ? $this->error : 'write failed' );
		}
		$back = \Rafoo\Backup\Ledger::temp( $name );
		$got  = $this->get( $name, $back, 0, microtime( true ) + 60 );
		$same = false !== $got && hash_file( 'sha256', $back ) === hash( 'sha256', $payload );
		wp_delete_file( $back );
		$this->delete( $name );
		if ( ! $same ) {
			return $this->fail( 'read back failed' );
		}
		return array( 'speed' => (int) round( strlen( $payload ) / $took ) );
	}
}
