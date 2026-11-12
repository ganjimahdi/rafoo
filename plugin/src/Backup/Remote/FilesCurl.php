<?php

namespace Rafoo\Backup\Remote;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- the WordPress HTTP API cannot stream a request body from a file; backup parts of several hundred MB are streamed from disk instead of being loaded into memory. Not shipped in the wordpress.org build.

final class FilesCurl {

	private static $error = '';

	public static function available() {
		return function_exists( 'curl_init' ) && class_exists( 'CURLFile' ) && defined( 'CURLPROTO_HTTPS' );
	}

	public static function error() {
		return self::$error;
	}

	public static function upload( $method, $url, array $headers, $local, $offset, $bytes, $wait ) {
		$in = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streams one backup part from Rafoo's private folder without loading it into memory.
		if ( ! $in || 0 !== fseek( $in, (int) $offset ) ) {
			self::$error = 'local file unreadable';
			return false;
		}
		$handle = self::handle( $url, $headers, $wait );
		curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
		curl_setopt( $handle, CURLOPT_UPLOAD, true );
		curl_setopt( $handle, CURLOPT_INFILE, $in );
		curl_setopt( $handle, CURLOPT_INFILESIZE, (int) $bytes );
		$out = self::run( $handle );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen above.
		return $out;
	}

	public static function multipart( $url, array $headers, $local, $name, $wait ) {
		$handle = self::handle( $url, $headers, $wait );
		curl_setopt( $handle, CURLOPT_POST, true );
		curl_setopt( $handle, CURLOPT_POSTFIELDS, array( 'file' => new \CURLFile( $local, 'application/octet-stream', basename( $name ) ) ) );
		return self::run( $handle );
	}

	private static function handle( $url, array $headers, $wait ) {
		$lines = array( 'Expect:' );
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$handle = curl_init( $url );
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_RETURNTRANSFER  => true,
				CURLOPT_HTTPHEADER      => $lines,
				CURLOPT_CONNECTTIMEOUT  => 30,
				CURLOPT_TIMEOUT         => max( 60, (int) $wait ),
				CURLOPT_LOW_SPEED_LIMIT => 1024,
				CURLOPT_LOW_SPEED_TIME  => 120,
				CURLOPT_FOLLOWLOCATION  => false,
				CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS | CURLPROTO_HTTP,
				CURLOPT_SSL_VERIFYPEER  => true,
				CURLOPT_SSL_VERIFYHOST  => 2,
				CURLOPT_CAINFO          => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			)
		);
		return $handle;
	}

	private static function run( $handle ) {
		$headers = array();
		curl_setopt(
			$handle,
			CURLOPT_HEADERFUNCTION,
			static function ( $curl, $line ) use ( &$headers ) {
				$parts = explode( ':', trim( $line ), 2 );
				if ( 2 === count( $parts ) ) {
					$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
				return strlen( $line );
			}
		);
		$body = curl_exec( $handle );
		$code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		if ( false === $body ) {
			self::$error = (string) curl_error( $handle );
			curl_close( $handle );
			return false;
		}
		curl_close( $handle );
		self::$error = '';
		return array(
			'code'    => $code,
			'headers' => $headers,
			'body'    => (string) $body,
		);
	}
}
