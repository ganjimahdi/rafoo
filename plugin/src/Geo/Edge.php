<?php

namespace Rafoo\Geo;

use Rafoo\Backup\Job;
use Rafoo\Backup\Schedule;
use Rafoo\Mail\Queue;

defined( 'ABSPATH' ) || exit;

final class Edge {

	const LOCAL = 'HTTP_X_RAFOO_GEO_LOCAL';

	private static $local = false;

	public static function boot() {
		if ( ! Store::active() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$host = self::request_host();
		$path = self::request_path();
		if ( isset( $_SERVER['HTTP_X_RAFOO_RELAY'] ) ) {
			Proxy::accept();
			return;
		}
		$self  = Store::self_node();
		$ready = $self && 'ready' === $self['status'];
		if ( isset( $_SERVER[ self::LOCAL ] ) && Link::valid_token( sanitize_text_field( wp_unslash( $_SERVER[ self::LOCAL ] ) ), $host ) ) {
			self::$local = true;
			add_filter( 'redirect_canonical', '__return_false' );
			if ( Store::is_copy() ) {
				self::guards();
			}
			return;
		}
		if ( Relay::is_challenge( $path ) ) {
			Relay::serve( $path );
		}
		if ( self::technical( $host ) ) {
			if ( self::internal( $path ) || ( Store::is_copy() && ! $ready ) ) {
				return;
			}
			self::redirect_main();
		}
		if ( Store::is_copy() && $ready ) {
			if ( ! wp_doing_cron() && Proxy::should( $path ) ) {
				Proxy::forward();
			}
			self::guards();
		}
	}

	public static function local() {
		return self::$local;
	}

	public static function request_host() {
		return isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	}

	public static function request_uri() {
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The raw path is forwarded as-is to the main host.
	}

	public static function request_path() {
		$path = wp_parse_url( self::request_uri(), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	public static function technical( $host ) {
		foreach ( Store::nodes() as $node ) {
			if ( Store::node_host( $node ) === $host ) {
				return true;
			}
		}
		return false;
	}

	private static function internal( $path ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes Rafoo's own signed endpoints; each endpoint verifies its signature.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( preg_match( '#/wp-admin/admin-ajax\.php$#', $path ) && 0 === strpos( $action, 'rafoo_' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed reachability ping, validated by Peers\Engine.
		return isset( $_GET[ \Rafoo\Peers\Engine::QUERY ] );
	}

	private static function redirect_main() {
		$target = Store::main_url( self::request_uri() );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Cache-Control: public, max-age=3600', true );
		header( 'Location: ' . esc_url_raw( $target ), true, 301 );
		exit;
	}

	public static function guards() {
		add_filter( 'pre_get_ready_cron_jobs', array( __CLASS__, 'cron' ), 99 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'mail' ), PHP_INT_MAX );
		add_filter( 'automatic_updater_disabled', '__return_true' );
		add_filter( 'auto_update_plugin', '__return_false' );
		add_filter( 'auto_update_theme', '__return_false' );
		add_filter( 'auto_update_core', '__return_false' );
		add_filter( 'allow_major_auto_core_updates', '__return_false' );
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		add_action( 'template_redirect', array( Seo::class, 'stale' ), 0 );
	}

	public static function local_base() {
		if ( Store::active() ) {
			$self = Store::self_node();
			return $self ? untrailingslashit( (string) $self['url'] ) : '';
		}
		$join = Store::get( 'join' );
		return 'joining' === Store::role() && is_array( $join ) && ! empty( $join['url'] ) ? untrailingslashit( (string) $join['url'] ) : '';
	}

	public static function cron_request( $request ) {
		$base = self::local_base();
		if ( '' !== $base && isset( $request['url'] ) ) {
			$request['url'] = $base . '/wp-cron.php?' . (string) wp_parse_url( $request['url'], PHP_URL_QUERY );
			if ( Store::active() ) {
				$request['args']['headers']['X-Rafoo-Geo-Local'] = Link::token( Store::node_host( Store::self_node() ) );
			}
		}
		return $request;
	}

	public static function loopback( $url ) {
		$base = self::local_base();
		return '' !== $base ? $base . '/wp-admin/admin-ajax.php' : $url;
	}

	public static function cron( $pre ) {
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$ready = array();
		$now   = microtime( true );
		$block = array( Job::HOOK, Schedule::HOOK, Queue::HOOK );
		foreach ( (array) $crons as $time => $hooks ) {
			if ( $time > $now ) {
				break;
			}
			foreach ( (array) $hooks as $hook => $events ) {
				if ( 0 === strpos( (string) $hook, 'rafoo_' ) && ! in_array( $hook, $block, true ) ) {
					$ready[ $time ][ $hook ] = $events;
				}
			}
		}
		return $ready;
	}

	public static function mail( $pre ) {
		return true;
	}

	public static function local_probes() {
		$self = Store::self_node();
		if ( ! $self ) {
			return null;
		}
		$host    = Store::node_host( $self );
		$targets = static function ( $list ) use ( $self ) {
			$out = array();
			foreach ( (array) $list as $id => $url ) {
				if ( 'admin' === $id ) {
					continue;
				}
				$path       = (string) wp_parse_url( $url, PHP_URL_PATH );
				$query      = (string) wp_parse_url( $url, PHP_URL_QUERY );
				$out[ $id ] = untrailingslashit( $self['url'] ) . ( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );
			}
			return $out;
		};
		$headers = static function ( $args, $url ) use ( $host ) {
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) . ( wp_parse_url( $url, PHP_URL_PORT ) ? ':' . wp_parse_url( $url, PHP_URL_PORT ) : '' ) ) === $host ) {
				$args['headers']['X-Rafoo-Geo-Local'] = Link::token( $host );
			}
			return $args;
		};
		add_filter( 'rafoo_health_targets', $targets, 99 );
		add_filter( 'http_request_args', $headers, 99, 2 );
		return static function () use ( $targets, $headers ) {
			remove_filter( 'rafoo_health_targets', $targets, 99 );
			remove_filter( 'http_request_args', $headers, 99 );
		};
	}

	public static function local_get( array $node, $path, $timeout = 15 ) {
		$host = Store::node_host( $node );
		return wp_remote_get(
			untrailingslashit( $node['url'] ) . $path,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'rafoo_geo_sslverify', true ),
				'headers'     => array( 'X-Rafoo-Geo-Local' => Link::token( $host ) ),
				'rafoo_probe' => true,
			)
		);
	}
}
