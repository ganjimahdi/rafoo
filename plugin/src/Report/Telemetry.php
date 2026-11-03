<?php

namespace Rafoo\Report;

use Rafoo\Assets\Library;
use Rafoo\Http\Client;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Telemetry {

	const ENDPOINT = 'https://rafoo.ir/api/v1/';

	const OPTION = 'rafoo_telemetry';

	const INTERVAL = 604800;

	public static function enabled() {
		return (bool) Settings::get( 'telemetry' );
	}

	public static function maybe_report() {
		if ( ! self::enabled() ) {
			return false;
		}
		$meta = self::meta();
		if ( time() - (int) $meta['last_report'] < self::INTERVAL ) {
			return false;
		}
		return self::report();
	}

	public static function report() {
		if ( ! self::enabled() ) {
			return false;
		}
		$sent = self::send( 'report', self::payload() );
		if ( $sent ) {
			$meta                = self::meta();
			$meta['last_report'] = time();
			update_option( self::OPTION, $meta, false );
		}
		return $sent;
	}

	public static function ping( $event ) {
		if ( ! self::enabled() ) {
			return false;
		}
		return self::send( 'install', array_merge( self::environment(), array( 'event' => sanitize_key( $event ) ) ), false );
	}

	public static function feedback( $message, $contact ) {
		$payload = array_merge(
			self::environment(),
			array(
				'message' => $message,
				'contact' => $contact,
			)
		);
		if ( ! self::enabled() ) {
			unset( $payload['site'] );
		}
		return self::send( 'feedback', $payload );
	}

	public static function payload() {
		$missing = array();
		foreach ( Library::records() as $record ) {
			if ( 'failed' === $record['status'] && count( $missing ) < 200 ) {
				$missing[] = $record['origin'];
			}
		}
		$summary = Library::summary();
		return array_merge(
			self::environment(),
			array(
				'assets'  => array(
					'ready'  => $summary['ready'],
					'failed' => $summary['failed'],
				),
				'missing' => $missing,
				'plugins' => self::plugins(),
				'themes'  => self::themes(),
			)
		);
	}

	public static function environment() {
		global $wp_version;
		return array(
			'site'      => self::site_id(),
			'edition'   => RAFOO_EDITION,
			'version'   => RAFOO_VERSION,
			'wp'        => $wp_version,
			'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'    => get_locale(),
			'multisite' => is_multisite(),
		);
	}

	public static function site_id() {
		$meta = self::meta();
		if ( '' === $meta['site_id'] ) {
			$meta['site_id'] = wp_generate_uuid4();
			update_option( self::OPTION, $meta, false );
		}
		return $meta['site_id'];
	}

	public static function forget() {
		delete_option( self::OPTION );
	}

	private static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$out[ sanitize_key( $slug ) ] = (string) $data['Version'];
		}
		return $out;
	}

	private static function themes() {
		$out = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[ sanitize_key( $slug ) ] = (string) $theme->get( 'Version' );
		}
		return $out;
	}

	private static function meta() {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'site_id'     => '',
				'last_report' => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	private static function send( $path, array $payload, $blocking = true ) {
		$response = wp_safe_remote_post(
			self::ENDPOINT . $path,
			Client::args(
				array(
					'timeout'  => $blocking ? 8 : 2,
					'blocking' => $blocking,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( $payload ),
				)
			)
		);
		if ( ! $blocking ) {
			return true;
		}
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
