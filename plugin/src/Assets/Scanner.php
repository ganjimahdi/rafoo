<?php

namespace Rafoo\Assets;

use Rafoo\Http\Hosts;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	const HOOK = 'rafoo_scan';

	const LIMIT = 6;

	public static function pages() {
		$urls = array( home_url( '/' ) );
		foreach ( array( 'post', 'page', 'product' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 'page' === $type ? 2 : 1,
					'fields'           => 'ids',
					'suppress_filters' => false,
					'no_found_rows'    => true,
				)
			);
			foreach ( $ids as $id ) {
				$urls[] = get_permalink( $id );
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = wc_get_page_permalink( 'shop' );
		}
		$urls[] = wp_login_url();
		return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, self::LIMIT );
	}

	const AS_USER = 3;

	public static function run( array $cookies = array() ) {
		$seen  = 0;
		$pages = self::pages();
		$jobs  = array();
		foreach ( $pages as $url ) {
			$jobs[] = array( $url, array() );
		}
		if ( $cookies ) {
			foreach ( array_slice( $pages, 0, self::AS_USER ) as $url ) {
				if ( in_array( Hosts::of( $url ), Hosts::own(), true ) ) {
					$jobs[] = array( $url, $cookies );
				}
			}
		}
		foreach ( $jobs as $job ) {
			$response = wp_remote_get(
				add_query_arg( 'rafoo-scan', wp_generate_password( 6, false ), $job[0] ),
				array(
					'timeout'     => 15,
					'redirection' => $job[1] ? 0 : 2,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
					'cookies'     => $job[1],
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				++$seen;
			}
		}
		Library::reset_cache();
		return $seen;
	}
}
