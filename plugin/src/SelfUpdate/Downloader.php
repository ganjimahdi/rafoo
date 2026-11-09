<?php

namespace Rafoo\SelfUpdate;

use Rafoo\Http\Client;
use Rafoo\Http\Hosts;
use Rafoo\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Downloader {

	const TIMEOUT = 300;

	public static function package_url( $base, $file, $sha256, $size = 0 ) {
		$url = $base . '/' . ltrim( $file, '/' );
		return $url . '#sha256=' . $sha256 . ( $size > 0 ? '&size=' . (int) $size : '' );
	}

	public static function parse( $package ) {
		if ( ! is_string( $package ) || false === strpos( $package, '#sha256=' ) ) {
			return null;
		}
		list( $url, $fragment ) = explode( '#', $package, 2 );
		parse_str( $fragment, $meta );
		$sha256 = isset( $meta['sha256'] ) ? strtolower( (string) $meta['sha256'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) || 0 !== strpos( $url, 'https://' ) ) {
			return null;
		}
		$host = Hosts::of( $url );
		if ( ! in_array( $host, self::trusted_hosts(), true ) ) {
			return null;
		}
		return array(
			'url'    => $url,
			'sha256' => $sha256,
			'size'   => isset( $meta['size'] ) ? (int) $meta['size'] : 0,
		);
	}

	public static function fetch( array $package ) {
		$path  = (string) wp_parse_url( $package['url'], PHP_URL_PATH );
		$host  = Hosts::of( $package['url'] );
		$bases = array( 'https://' . $host );
		if ( in_array( 'https://' . $host, Settings::get( 'wp_mirrors' ), true ) ) {
			$bases = array_unique( array_merge( $bases, Settings::get( 'wp_mirrors' ) ) );
		}
		$error = new WP_Error( 'rafoo_download_failed', __( 'The package could not be downloaded from the domestic mirror.', 'rafoo' ) );
		foreach ( $bases as $base ) {
			$result = self::attempt( $base . $path, $package['sha256'], $package['size'] );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$error = $result;
		}
		return $error;
	}

	private static function attempt( $url, $sha256, $size ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file     = wp_tempnam( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$response = wp_safe_remote_get(
			$url,
			Client::args(
				array(
					'timeout'  => self::TIMEOUT,
					'stream'   => true,
					'filename' => $file,
				)
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $file );
			return is_wp_error( $response ) ? $response : new WP_Error( 'rafoo_download_failed', sprintf( 'HTTP %d', (int) wp_remote_retrieve_response_code( $response ) ) );
		}
		clearstatcache( true, $file );
		if ( $size > 0 && filesize( $file ) !== $size ) {
			wp_delete_file( $file );
			return new WP_Error( 'rafoo_size_mismatch', __( 'The downloaded package has the wrong size and was not installed.', 'rafoo' ) );
		}
		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'rafoo_hash_mismatch', __( 'The downloaded package did not match its signed hash and was not installed.', 'rafoo' ) );
		}
		return $file;
	}

	private static function trusted_hosts() {
		$hosts = array( 'rafoo.ir' );
		foreach ( Settings::get( 'wp_mirrors' ) as $base ) {
			$hosts[] = Hosts::of( $base );
		}
		return $hosts;
	}
}
