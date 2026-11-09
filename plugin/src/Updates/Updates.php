<?php

namespace Rafoo\Updates;

use Rafoo\Admin\Rest;
use Rafoo\Mirror\Index;
use Rafoo\SelfUpdate\Downloader;
use Rafoo\Settings;
use Rafoo\State;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Updates {

	const API = 'api.wordpress.org';

	const DOWNLOADS = '#^https://downloads\.wordpress\.org/(plugin|theme)/([a-z0-9_\-]+)\.([0-9][0-9A-Za-z.\-]*)\.zip$#';

	const RELEASE = '#^https://downloads\.wordpress\.org/release/(?:([a-z]{2,3}_[A-Z]{2})/)?wordpress-([0-9][0-9.]*)(?:-([a-z]{2,3}_[A-Z]{2}))?\.zip$#';

	public static function register() {
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 1, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 2 );
		add_action( 'rafoo_rest_routes', array( __CLASS__, 'routes' ) );
	}

	public static function active() {
		$mode = Settings::get( 'updates' );
		return Index::available() && ( 'always' === $mode || ( 'outage' === $mode && State::offline() ) );
	}

	public static function intercept( $pre, $args, $url ) {
		if ( false !== $pre || 'off' === Settings::get( 'updates' ) || ! State::offline() || ! Index::available() ) {
			return $pre;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || self::API !== strtolower( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $pre;
		}
		$body = isset( $args['body'] ) ? $args['body'] : array();
		switch ( untrailingslashit( $parts['path'] ) ) {
			case '/plugins/update-check/1.1':
				return Source::plugins( $body );
			case '/themes/update-check/1.1':
				return Source::themes( $body );
			case '/core/version-check/1.7':
				$response = Source::core( $body );
				return null === $response ? $pre : $response;
		}
		return $pre;
	}

	public static function download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$signed = Downloader::parse( $package );
		if ( $signed ) {
			return Downloader::fetch( $signed );
		}
		if ( ! self::active() ) {
			return $reply;
		}
		$mirrored = self::mirrored( $package );
		if ( ! $mirrored ) {
			return $reply;
		}
		$file = Downloader::fetch( Downloader::parse( Source::package( $mirrored ) ) );
		if ( is_wp_error( $file ) && ! State::offline() ) {
			return $reply;
		}
		return $file;
	}

	public static function mirrored( $package ) {
		if ( preg_match( self::DOWNLOADS, $package, $m ) ) {
			$index = 'plugin' === $m[1] ? Index::plugin( $m[2] ) : Index::theme( $m[2] );
			return $index ? Source::find( $index, $m[3] ) : null;
		}
		if ( preg_match( self::RELEASE, $package, $m ) ) {
			$locale = '' !== $m[1] ? $m[1] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : 'en_US' );
			$index  = Index::core();
			return $index ? Source::find( $index, $m[2], $locale ) : null;
		}
		return null;
	}

	public static function routes( $namespace ) {
		register_rest_route(
			$namespace,
			'/updates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'report' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/updates/refresh',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'refresh' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
			)
		);
	}

	public static function refresh() {
		foreach ( self::installed() as $item ) {
			Index::forget( $item['type'], array( 'slug' => $item['slug'] ) );
		}
		return self::report();
	}

	public static function report() {
		if ( ! Index::available() ) {
			return array(
				'available'  => false,
				'items'      => array(),
				'checked_at' => 0,
			);
		}
		$items = array();
		foreach ( self::installed() as $item ) {
			$state  = 'missing';
			$latest = '';
			if ( ! empty( $item['own'] ) ) {
				$state = 'own';
			} elseif ( 'core' === $item['type'] ) {
				$index  = Index::core();
				$offer  = $index ? Source::core_offer( $index, get_locale(), $item['installed'] ) : null;
				$latest = $offer ? $offer['version'] : '';
			} else {
				$index  = 'plugin' === $item['type'] ? Index::plugin( $item['slug'] ) : Index::theme( $item['slug'] );
				$best   = $index ? Source::best( $index, '' ) : null;
				$latest = $best ? $best['v'] : '';
				if ( $index && ! Source::same( $item['name'], $index ) ) {
					$state  = 'own';
					$latest = '';
				}
			}
			if ( '' !== $latest ) {
				$compare = version_compare( $latest, $item['installed'] );
				$state   = $compare > 0 ? 'update' : ( $compare < 0 ? 'older' : 'current' );
			}
			unset( $item['own'] );
			$items[] = array_merge(
				$item,
				array(
					'latest'    => $latest,
					'in_mirror' => '' !== $latest,
					'state'     => $state,
				)
			);
		}
		return array(
			'available'  => true,
			'items'      => $items,
			'checked_at' => time(),
		);
	}

	private static function installed() {
		global $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$items = array(
			array(
				'type'      => 'core',
				'slug'      => 'wordpress',
				'name'      => 'WordPress',
				'installed' => $wp_version,
			),
		);
		foreach ( get_plugins() as $file => $data ) {
			$slug = Source::plugin_slug( $file );
			if ( '' === $slug ) {
				continue;
			}
			$items[] = array(
				'type'      => 'plugin',
				'slug'      => $slug,
				'name'      => wp_strip_all_tags( $data['Name'] ),
				'installed' => (string) $data['Version'],
				'own'       => Source::ours( $slug ) || ( ! empty( $data['UpdateURI'] ) && ! Source::wporg_uri( $data['UpdateURI'] ) ),
			);
		}
		foreach ( wp_get_themes() as $slug => $theme ) {
			$uri     = (string) $theme->get( 'UpdateURI' );
			$items[] = array(
				'type'      => 'theme',
				'slug'      => (string) $slug,
				'name'      => wp_strip_all_tags( $theme->get( 'Name' ) ),
				'installed' => (string) $theme->get( 'Version' ),
				'own'       => Source::ours( (string) $slug ) || ( '' !== $uri && ! Source::wporg_uri( $uri ) ) || ! preg_match( Source::SLUG, (string) $slug ),
			);
		}
		return $items;
	}
}
