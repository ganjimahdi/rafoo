<?php

namespace Rafoo\Backup\Remote;

defined( 'ABSPATH' ) || exit;

final class Curl extends Remote {

	private $timeout = 15;

	public static function available( $scheme = 'ftps' ) {
		return function_exists( 'curl_init' ) && defined( 'CURLPROTO_FTPS' ) && in_array( $scheme, (array) curl_version()['protocols'], true );
	}

	public function connect( $timeout = 15 ) {
		if ( ! self::available( 'ftp' === $this->config['type'] ? 'ftp' : 'ftps' ) ) {
			return $this->fail( 'curl ftp missing' );
		}
		$this->timeout = $timeout;
		return false !== $this->call( '/', array( CURLOPT_NOBODY => true, CURLOPT_DIRLISTONLY => true ) ) || $this->fail( '' !== $this->error ? $this->error : 'connection failed' );
	}

	public function close() {
	}

	public function size( $name ) {
		$handle = $this->handle( $this->remote( $name ) );
		curl_setopt( $handle, CURLOPT_NOBODY, true );
		curl_setopt( $handle, CURLOPT_FILETIME, true );
		$ok   = curl_exec( $handle );
		$size = curl_getinfo( $handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD );
		curl_close( $handle );
		return false !== $ok && $size >= 0 ? (int) $size : -1;
	}

	public function put( $local, $name, $offset, $deadline ) {
		$in = fopen( $local, 'rb' );
		if ( ! $in ) {
			return $this->fail( 'local file missing' );
		}
		$total = filesize( $local );
		fseek( $in, $offset );
		$handle = $this->handle( $this->remote( $name ) );
		curl_setopt( $handle, CURLOPT_UPLOAD, true );
		curl_setopt( $handle, CURLOPT_INFILE, $in );
		curl_setopt( $handle, CURLOPT_INFILESIZE, $total - $offset );
		curl_setopt( $handle, CURLOPT_APPEND, $offset > 0 );
		curl_setopt( $handle, CURLOPT_TIMEOUT, max( 5, (int) ( $deadline - microtime( true ) ) ) );
		$ok = curl_exec( $handle );
		curl_close( $handle );
		fclose( $in );
		$size = $this->size( $name );
		if ( false === $ok && $size <= $offset ) {
			return $this->fail( 'upload failed' );
		}
		return $size;
	}

	public function get( $name, $local, $offset, $deadline ) {
		$out = fopen( $local, $offset > 0 ? 'ab' : 'wb' );
		if ( ! $out ) {
			return $this->fail( 'local file not writable' );
		}
		$handle = $this->handle( $this->remote( $name ) );
		curl_setopt( $handle, CURLOPT_FILE, $out );
		curl_setopt( $handle, CURLOPT_RESUME_FROM, $offset );
		curl_setopt( $handle, CURLOPT_TIMEOUT, max( 5, (int) ( $deadline - microtime( true ) ) ) );
		curl_exec( $handle );
		curl_close( $handle );
		fclose( $out );
		clearstatcache( true, $local );
		return (int) filesize( $local );
	}

	public function delete( $name ) {
		return false !== $this->call( '/', array( CURLOPT_QUOTE => array( 'DELE ' . $this->remote( $name ) ), CURLOPT_NOBODY => true ) );
	}

	public function names() {
		$list = $this->call( ( '' === $this->folder() ? '' : $this->folder() ) . '/', array( CURLOPT_DIRLISTONLY => true ) );
		return false === $list ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $list ) ) ) );
	}

	public function mkdir( $path ) {
		$handle = $this->handle( rtrim( $path, '/' ) . '/' );
		curl_setopt( $handle, CURLOPT_FTP_CREATE_MISSING_DIRS, CURLFTP_CREATE_DIR );
		curl_setopt( $handle, CURLOPT_NOBODY, true );
		$ok = curl_exec( $handle );
		curl_close( $handle );
		return false !== $ok;
	}

	private function call( $path, array $options ) {
		$handle = $this->handle( $path );
		curl_setopt_array( $handle, $options + array( CURLOPT_RETURNTRANSFER => true ) );
		$result = curl_exec( $handle );
		if ( false === $result ) {
			$this->error = curl_error( $handle );
		}
		curl_close( $handle );
		return $result;
	}

	private function handle( $path ) {
		$implicit = 'ftps-implicit' === $this->config['type'];
		$port     = ! empty( $this->config['port'] ) ? (int) $this->config['port'] : ( $implicit ? 990 : 21 );
		$handle   = curl_init( ( $implicit ? 'ftps://' : 'ftp://' ) . $this->config['host'] . ':' . $port . '/' . ltrim( $path, '/' ) );
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_USERPWD           => $this->config['user'] . ':' . $this->secret( 'pass' ),
				CURLOPT_CONNECTTIMEOUT    => $this->timeout,
				CURLOPT_FTP_SSL           => 'ftp' === $this->config['type'] ? CURLFTPSSL_NONE : CURLFTPSSL_ALL,
				CURLOPT_FTPSSLAUTH        => CURLFTPAUTH_DEFAULT,
				CURLOPT_SSL_VERIFYPEER    => ! empty( $this->config['verify'] ),
				CURLOPT_SSL_VERIFYHOST    => empty( $this->config['verify'] ) ? 0 : 2,
				CURLOPT_FTP_USE_EPSV      => true,
				CURLOPT_PROTOCOLS         => $implicit ? CURLPROTO_FTPS : CURLPROTO_FTP,
				CURLOPT_RETURNTRANSFER    => true,
			)
		);
		return $handle;
	}
}
