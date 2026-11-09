<?php

namespace Rafoo\Drill;

use Rafoo\Admin\Page;
use Rafoo\Assets\Store;
use Rafoo\Http\Hosts;
use Rafoo\Http\Log;

defined( 'ABSPATH' ) || exit;

final class Session {

	const COOKIE = 'rafoo_drill';

	const OPTION = 'rafoo_drill';

	const QUERY = 'rafoo-drill';

	const BEACON = 'rafoo-drill-beacon';

	const BASE = 'rafoo-drill-base';

	const TTL = 900;

	const MAX_FILES = 400;

	const MAX_REQUESTS = 80;

	const MAX_ASSETS = 120;

	const STARTED = 'rafoo_drill_started';

	private static $session = null;

	private static $page = 0;

	private static $requests = array();

	private static $assets = array();

	private static $fatal = null;

	private static $written = false;

	private static $base = false;

	public static function boot() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		$session = self::verify( $token );
		if ( ! $session ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing value checked against the drill session.
		$base = isset( $_GET[ self::BASE ] ) ? self::parse_page( sanitize_text_field( wp_unslash( $_GET[ self::BASE ] ) ), $session ) : null;
		$page = null === $base ? self::page_of( $session ) : $base;
		if ( null === $page ) {
			return;
		}
		self::$session = $session;
		self::$page    = $page;
		self::$base    = null !== $base;
		if ( self::guest_page() ) {
			add_action( 'plugins_loaded', array( __CLASS__, 'forget_login' ), PHP_INT_MIN );
			add_filter( 'determine_current_user', array( __CLASS__, 'guest' ), PHP_INT_MAX );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant that page cache plugins read.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'X-Accel-Expires: 0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( self::$base ) {
			return;
		}
		add_filter( 'rafoo_offline', '__return_true', PHP_INT_MAX );
		add_filter( 'http_request_args', array( __CLASS__, 'stamp' ), 1, 1 );
		add_filter( 'wp_php_error_message', array( __CLASS__, 'fatal' ), 1, 2 );
		add_action( 'shutdown', array( __CLASS__, 'write' ), 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the drill cookie authenticates the beacon; the body is sanitized in Beacon::clean.
		if ( isset( $_GET[ self::BEACON ] ) ) {
			add_action( 'init', array( __CLASS__, 'beacon' ), 0 );
		}
	}

	public static function watching() {
		return null !== self::$session;
	}

	public static function active() {
		return null !== self::$session && ! self::$base;
	}

	public static function page() {
		return self::$page;
	}

	public static function run() {
		return self::active() ? self::$session['run'] : '';
	}

	public static function current() {
		$session = self::stored();
		if ( ! $session || (int) $session['expires'] < time() ) {
			return null;
		}
		return $session;
	}

	public static function stored() {
		$session = get_option( self::OPTION, array() );
		return is_array( $session ) && ! empty( $session['run'] ) ? $session : null;
	}

	public static function verify( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return null;
		}
		$session = self::current();
		if ( ! $session || ! hash_equals( (string) $session['hash'], hash( 'sha256', $token ) ) ) {
			return null;
		}
		return $session;
	}

	public static function page_of( array $session ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing value checked against the drill session.
		$own = isset( $_GET[ self::QUERY ] ) ? self::parse_page( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ), $session ) : null;
		if ( null !== $own ) {
			return $own;
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$query   = (string) wp_parse_url( $referer, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $vars );
			if ( isset( $vars[ self::QUERY ] ) && is_string( $vars[ self::QUERY ] ) ) {
				$from = self::parse_page( $vars[ self::QUERY ], $session );
				if ( null !== $from ) {
					return $from;
				}
			}
		}
		if ( empty( $session['manual'] ) || self::own_request() ) {
			return null;
		}
		return 0;
	}

	public static function parse_page( $value, array $session ) {
		if ( ! preg_match( '/^([a-z0-9]{8})-(\d{1,2})$/', (string) $value, $m ) || $m[1] !== $session['run'] ) {
			return null;
		}
		$id = (int) $m[2];
		return isset( $session['pages'][ $id ] ) ? $id : null;
	}

	public static function guest_page() {
		$session = self::$session;
		if ( 0 === self::$page || ! isset( $session['pages'][ self::$page ] ) ) {
			return false;
		}
		$page = $session['pages'][ self::$page ];
		if ( 'login' === $page['kind'] || false !== strpos( (string) $page['url'], 'wp-login.php' ) ) {
			return true;
		}
		return ! empty( $session['guest'] ) && empty( $page['admin'] );
	}

	public static function forget_login() {
		foreach ( array( 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ) as $name ) {
			if ( defined( $name ) ) {
				unset( $_COOKIE[ constant( $name ) ] );
			}
		}
	}

	public static function guest( $user ) {
		return 0;
	}

	public static function stamp( $args ) {
		if ( is_array( $args ) ) {
			$args[ self::STARTED ] = microtime( true );
		}
		return $args;
	}

	public static function request( $host, $outcome, $args = array(), $origin = '' ) {
		if ( ! self::active() || '' === $host || Hosts::local( $host ) || count( self::$requests ) >= self::MAX_REQUESTS ) {
			return;
		}
		if ( 'refused' !== $outcome && ! Hosts::allowed( $host ) && ! Hosts::foreign( $host ) ) {
			$outcome = 'trial';
		} elseif ( 'refused' !== $outcome && Hosts::allowed( $host ) ) {
			return;
		}
		$ms = 0;
		if ( is_array( $args ) && ! empty( $args[ self::STARTED ] ) ) {
			$ms = (int) round( ( microtime( true ) - (float) $args[ self::STARTED ] ) * 1000 );
		}
		self::$requests[] = array(
			'h' => substr( $host, 0, 253 ),
			'o' => '' !== $origin ? $origin : Log::origin(),
			's' => $outcome,
			'm' => $ms,
		);
	}

	public static function asset( $outcome, $subject, $kind = 'asset' ) {
		if ( ! self::active() || count( self::$assets ) >= self::MAX_ASSETS || ! is_string( $subject ) || '' === $subject ) {
			return;
		}
		$id = $outcome . '|' . $subject;
		if ( isset( self::$assets[ $id ] ) ) {
			return;
		}
		self::$assets[ $id ] = array(
			's' => $outcome,
			'k' => substr( $subject, 0, 600 ),
			't' => $kind,
		);
	}

	public static function fatal( $message, $error = null ) {
		if ( self::active() && is_array( $error ) && null === self::$fatal ) {
			self::$fatal = self::describe_error( $error );
			self::write( 500 );
		}
		return $message;
	}

	public static function describe_error( array $error ) {
		$file    = isset( $error['file'] ) ? (string) $error['file'] : '';
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';
		$message = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $message ) );
		$message = preg_replace( '/\s+/', ' ', $message );
		return array(
			'o' => '' !== $file ? Log::owner( $file ) : '',
			'm' => substr( (string) strtok( (string) $message, "\n" ), 0, 300 ),
			'f' => substr( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) ), 0, 200 ),
			'l' => isset( $error['line'] ) ? (int) $error['line'] : 0,
		);
	}

	public static function write( $code = 0 ) {
		if ( ! self::active() || self::$written ) {
			return;
		}
		if ( null === self::$fatal ) {
			$error = error_get_last();
			if ( is_array( $error ) && in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				self::$fatal = self::describe_error( $error );
				$code        = 500;
			}
		}
		self::$written = true;
		$started       = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$record        = array(
			'p'  => self::$page,
			'u'  => self::path(),
			'k'  => self::kind(),
			'c'  => $code > 0 ? (int) $code : (int) http_response_code(),
			'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'mb' => round( memory_get_peak_usage() / 1048576, 1 ),
			'r'  => self::$requests,
			'a'  => array_values( self::$assets ),
			'f'  => self::$fatal,
			'at' => time(),
		);
		self::save( 's', $record );
	}

	public static function beacon() {
		$raw  = file_get_contents( 'php://input', false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the request body.
		$data = json_decode( (string) $raw, true );
		if ( is_array( $data ) && ! empty( self::$session['manual'] ) ) {
			$client = Beacon::clean( $data );
			if ( $client ) {
				self::save( 'c', $client );
			}
		}
		self::$written = true;
		status_header( 204 );
		exit;
	}

	public static function save( $prefix, array $record ) {
		$dir = self::dir( self::$session['run'] );
		if ( '' === $dir ) {
			return false;
		}
		$files = glob( $dir . '/*.json' );
		if ( is_array( $files ) && count( $files ) >= self::MAX_FILES ) {
			return false;
		}
		$name = $prefix . '-' . self::$page . '-' . str_replace( '.', '', sprintf( '%.4f', microtime( true ) ) ) . '-' . strtolower( wp_generate_password( 6, false ) ) . '.json';
		return Store::fs()->put_contents( $dir . '/' . $name, (string) wp_json_encode( $record ), Store::mode() );
	}

	public static function dir( $run, $create = true ) {
		if ( ! preg_match( '/^[a-z0-9]{8}$/', (string) $run ) ) {
			return '';
		}
		$base = Store::private_dir( 'drill' );
		if ( '' === $base ) {
			return '';
		}
		$dir = $base . '/' . $run;
		if ( $create && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		return is_dir( $dir ) ? $dir : '';
	}

	public static function captures( $run ) {
		$dir = self::dir( $run, false );
		$out = array(
			's' => array(),
			'c' => array(),
		);
		if ( '' === $dir ) {
			return $out;
		}
		$files = glob( $dir . '/*.json' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$type = substr( basename( $file ), 0, 1 );
			if ( ! isset( $out[ $type ] ) || filesize( $file ) > 262144 ) {
				continue;
			}
			$data = json_decode( (string) Store::fs()->get_contents( $file ), true );
			if ( is_array( $data ) ) {
				$out[ $type ][] = $data;
			}
		}
		return $out;
	}

	public static function wipe() {
		$base = Store::private_dir( 'drill' );
		if ( '' === $base ) {
			return;
		}
		$dirs = glob( $base . '/*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? $dirs : array() as $dir ) {
			Store::fs()->rmdir( $dir, true );
		}
	}

	public static function open( array $pages, $guest, $manual ) {
		$token   = wp_generate_password( 32, false );
		$run     = strtolower( wp_generate_password( 8, false ) );
		$session = array(
			'run'     => $run,
			'hash'    => hash( 'sha256', $token ),
			'user'    => get_current_user_id(),
			'guest'   => (bool) $guest,
			'manual'  => (bool) $manual,
			'started' => time(),
			'expires' => time() + self::TTL,
			'pages'   => $pages,
		);
		self::wipe();
		update_option( self::OPTION, $session, false );
		self::cookie( $token, $session['expires'] );
		return $session;
	}

	public static function close() {
		delete_option( self::OPTION );
		self::wipe();
		self::cookie( '', time() - YEAR_IN_SECONDS );
	}

	public static function guard() {
		$ready = did_action( 'init' );
		return array(
			'm' => $ready ? __( 'Outage rehearsal is on. Only you see the site as it would be during an international outage.', 'rafoo' ) : 'Outage rehearsal is on.',
			'e' => $ready ? __( 'Finish the rehearsal', 'rafoo' ) : 'Finish',
			'u' => Page::url() . '#/drill',
			'b' => self::active() && ! empty( self::$session['manual'] ) ? add_query_arg( self::BEACON, '1', home_url( '/' ) ) : '',
			'n' => self::$base,
		);
	}

	public static function link( array $session, $id, $url ) {
		return add_query_arg( self::QUERY, $session['run'] . '-' . (int) $id, $url );
	}

	private static function cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function own_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, 'rafoo/v1' ) || false !== strpos( $uri, 'rafoo%2Fv1' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check for the Rafoo admin screen.
		return isset( $_GET['page'] ) && 'rafoo' === $_GET['page'];
	}

	private static function path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = remove_query_arg( array( self::QUERY, self::BEACON, '_wpnonce' ), $uri );
		return substr( (string) $path, 0, 300 );
	}

	private static function kind() {
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || false !== strpos( self::path(), '/wp-json/' ) ) {
			return 'rest';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing value.
		return isset( $_GET[ self::QUERY ] ) || 0 === self::$page ? 'page' : 'sub';
	}
}
