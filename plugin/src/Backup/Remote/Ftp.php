<?php

namespace Rafoo\Backup\Remote;

defined( 'ABSPATH' ) || exit;

final class Ftp extends Remote {

	private $link = null;

	public static function available() {
		return function_exists( 'ftp_connect' );
	}

	public function connect( $timeout = 15 ) {
		if ( ! self::available() ) {
			return $this->fail( 'ftp extension missing' );
		}
		$secure = 'ftps' === $this->config['type'];
		if ( $secure && ! function_exists( 'ftp_ssl_connect' ) ) {
			return $this->fail( 'ftps not supported' );
		}
		$port = ! empty( $this->config['port'] ) ? (int) $this->config['port'] : 21;
		$link = $secure ? @ftp_ssl_connect( $this->config['host'], $port, $timeout ) : @ftp_connect( $this->config['host'], $port, $timeout );
		if ( ! $link ) {
			return $this->fail( 'connection failed' );
		}
		if ( ! @ftp_login( $link, (string) $this->config['user'], $this->secret( 'pass' ) ) ) {
			@ftp_close( $link );
			return $this->fail( 'login failed' );
		}
		@ftp_set_option( $link, FTP_TIMEOUT_SEC, max( 30, $timeout ) );
		if ( defined( 'FTP_USEPASVADDRESS' ) ) {
			@ftp_set_option( $link, FTP_USEPASVADDRESS, false );
		}
		@ftp_pasv( $link, ! isset( $this->config['passive'] ) || $this->config['passive'] );
		$this->link = $link;
		return true;
	}

	public function close() {
		if ( $this->link ) {
			@ftp_close( $this->link );
			$this->link = null;
		}
	}

	public function size( $name ) {
		$size = @ftp_size( $this->link, $this->remote( $name ) );
		return $size >= 0 ? (int) $size : -1;
	}

	public function put( $local, $name, $offset, $deadline ) {
		if ( ! is_readable( $local ) ) {
			return $this->fail( 'local file missing' );
		}
		$total = (int) filesize( $local );
		$state = @ftp_nb_put( $this->link, $this->remote( $name ), $local, FTP_BINARY, $offset );
		while ( FTP_MOREDATA === $state && microtime( true ) < $deadline ) {
			$state = @ftp_nb_continue( $this->link );
		}
		$position = $offset;
		if ( FTP_FAILED === $state ) {
			return $this->fail( 'upload failed' );
		}
		if ( FTP_FINISHED === $state ) {
			return $total;
		}
		$this->close();
		$this->connect();
		$size = $this->size( $name );
		return $size >= 0 ? $size : (int) $position;
	}

	public function get( $name, $local, $offset, $deadline ) {
		if ( 0 === $offset && false === file_put_contents( $local, '' ) ) {
			return $this->fail( 'local file not writable' );
		}
		$state = @ftp_nb_get( $this->link, $local, $this->remote( $name ), FTP_BINARY, $offset );
		while ( FTP_MOREDATA === $state && microtime( true ) < $deadline ) {
			$state = @ftp_nb_continue( $this->link );
		}
		clearstatcache( true, $local );
		if ( FTP_FAILED === $state ) {
			return $this->fail( 'download failed' );
		}
		if ( FTP_MOREDATA === $state ) {
			$this->close();
			$this->connect();
		}
		return (int) filesize( $local );
	}

	public function delete( $name ) {
		return (bool) @ftp_delete( $this->link, $this->remote( $name ) );
	}

	public function names() {
		$list = @ftp_nlist( $this->link, '' === $this->folder() ? '.' : $this->folder() );
		return is_array( $list ) ? array_map( 'basename', $list ) : array();
	}

	public function mkdir( $path ) {
		if ( @ftp_chdir( $this->link, $path ) ) {
			@ftp_chdir( $this->link, '/' );
			return true;
		}
		$built = '';
		foreach ( array_filter( explode( '/', $path ) ) as $part ) {
			$built .= '/' . $part;
			if ( ! @ftp_chdir( $this->link, $built ) ) {
				@ftp_mkdir( $this->link, $built );
			}
		}
		$ok = @ftp_chdir( $this->link, $path );
		@ftp_chdir( $this->link, '/' );
		return $ok;
	}

	public function home() {
		$home = @ftp_pwd( $this->link );
		return is_string( $home ) ? $home : '/';
	}
}
