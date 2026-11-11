<?php

namespace Rafoo\Shield;

use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Shield {

	const HOOK = 'rafoo_shield_tick';

	public static function active() {
		return 'off' !== Config::mode();
	}

	public static function hooks() {
		return array(
			array( 'wp_login_failed', array( __CLASS__, 'failed' ), 10, 1 ),
			array( 'wp_login', array( __CLASS__, 'logged_in' ), 10, 2 ),
			array( 'clear_auth_cookie', array( __CLASS__, 'logged_out' ), 10, 0 ),
			array( 'init', array( __CLASS__, 'refresh_trust' ), 1, 0 ),
			array( 'authenticate', array( __CLASS__, 'authenticate' ), 1, 1 ),
			array( 'xmlrpc_methods', array( __CLASS__, 'xmlrpc_methods' ), 99 ),
			array( 'xmlrpc_enabled', array( __CLASS__, 'xmlrpc_enabled' ), 99 ),
			array( 'rest_pre_dispatch', array( __CLASS__, 'rest_users' ), 5, 3 ),
			array( 'template_redirect', array( __CLASS__, 'missing' ), 0, 0 ),
			array( 'rafoo_settings_changed', array( __CLASS__, 'changed' ), 10, 2 ),
			array( 'rafoo_shield_tick', array( __CLASS__, 'tick' ), 10, 0 ),
			array( 'upgrader_process_complete', array( __CLASS__, 'recompile' ), 99, 0 ),
			array( 'activated_plugin', array( __CLASS__, 'plugin_changed' ), 99, 1 ),
			array( 'deactivated_plugin', array( __CLASS__, 'plugin_changed' ), 99, 1 ),
			array( 'activated_plugin', array( __CLASS__, 'first' ), 99, 0 ),
			array( 'rafoo_rest_routes', array( Api::class, 'routes' ) ),
			array( 'wp_dashboard_setup', array( Widget::class, 'register' ) ),
		);
	}

	public static function mode() {
		return Modes::get( Config::mode() );
	}

	public static function ip() {
		$r = Gate::request();
		if ( $r ) {
			return $r->ip;
		}
		return Ip::resolve( $_SERVER, array( 'proxies' => (array) Local::get( 'proxies' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only address headers are read and each value is validated as an IP.
	}

	public static function store() {
		if ( ! Gate::request() ) {
			Store::init( Config::dir(), substr( md5( home_url( '/' ) ), 0, 8 ) );
		}
	}

	public static function failed( $username = '' ) {
		if ( ! self::active() ) {
			return;
		}
		$ip = self::ip();
		if ( '' === $ip || isset( Local::get( 'admins' )[ $ip ] ) || Ip::any( $ip, (array) Local::get( 'allow' ) ) ) {
			return;
		}
		self::store();
		$mode = self::mode();
		$key  = 'ip:' . Ip::key( $ip );
		$rec  = Store::get( $key );
		$now  = time();
		if ( empty( $rec['lt'] ) || $now - (int) $rec['lt'] > 900 ) {
			$rec['lt'] = $now;
			$rec['lf'] = 0;
		}
		$rec['lf'] = (int) $rec['lf'] + 1;
		if ( $rec['lf'] >= (int) $mode['logins'] ) {
			$rec['l']  = $now + (int) $mode['lock'];
			$rec['lf'] = 0;
			$rec['r']  = ( isset( $rec['r'] ) ? (int) $rec['r'] : 0 ) + 4;
			$rec['rt'] = $now;
			$r         = Gate::request();
			if ( $r ) {
				Log::write( Config::dir(), $r, 'lock', Rules::verdict( array( Rules::hit( 'w_login', 'login', 0, substr( sanitize_user( (string) $username ), 0, 40 ) ) ) ), Log::reference() );
			}
		}
		Store::put( $key, $rec, 86400 * 2 );
	}

	public static function authenticate( $user ) {
		if ( ! self::active() ) {
			return $user;
		}
		$ip = self::ip();
		if ( '' === $ip ) {
			return $user;
		}
		self::store();
		$rec = Store::get( 'ip:' . Ip::key( $ip ) );
		if ( ! empty( $rec['l'] ) && (int) $rec['l'] > time() && ! isset( Local::get( 'admins' )[ $ip ] ) ) {
			return new \WP_Error( 'rafoo_shield_locked', __( 'There were too many failed sign-in attempts from your connection. Please try again later.', 'rafoo' ) );
		}
		return $user;
	}

	public static function logged_in( $login, $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$ip = self::ip();
		if ( '' !== $ip ) {
			self::store();
			$key = 'ip:' . Ip::key( $ip );
			$rec = Store::get( $key );
			unset( $rec['lf'], $rec['lt'], $rec['l'] );
			Store::put( $key, $rec, 86400 );
		}
		self::give_trust( $user );
		if ( user_can( $user, 'manage_options' ) && '' !== $ip ) {
			Local::remember_admin( $ip );
		}
	}

	public static function level( \WP_User $user ) {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'unfiltered_html' ) && user_can( $user, 'activate_plugins' ) ) {
			return Trust::ADMIN;
		}
		if ( user_can( $user, 'edit_others_posts' ) || user_can( $user, 'unfiltered_html' ) ) {
			return Trust::EDITOR;
		}
		return Trust::USER;
	}

	public static function give_trust( \WP_User $user ) {
		if ( headers_sent() ) {
			return;
		}
		$until = time() + 12 * HOUR_IN_SECONDS;
		$value = Trust::make( Local::secret(), $user->ID, self::level( $user ), $until );
		setcookie( Trust::COOKIE, $value, Gate::cookie_options( $until ) );
		$_COOKIE[ Trust::COOKIE ] = $value;
	}

	public static function logged_out() {
		if ( ! headers_sent() && isset( $_COOKIE[ Trust::COOKIE ] ) ) {
			setcookie( Trust::COOKIE, '', Gate::cookie_options( time() - 3600 ) );
		}
		unset( $_COOKIE[ Trust::COOKIE ] );
	}

	public static function refresh_trust() {
		if ( ! self::active() || ! is_user_logged_in() ) {
			return;
		}
		$current = isset( $_COOKIE[ Trust::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Trust::COOKIE ] ) ) : '';
		$parts   = explode( '.', $current );
		$user    = wp_get_current_user();
		$valid   = 4 === count( $parts ) && Trust::read( Local::secret(), $current ) === self::level( $user ) && (int) $parts[0] === (int) $user->ID && (int) $parts[2] - time() > HOUR_IN_SECONDS;
		if ( ! $valid ) {
			self::give_trust( $user );
		}
		if ( current_user_can( 'manage_options' ) ) {
			Local::remember_admin( self::ip() );
		}
	}

	public static function xmlrpc_methods( $methods ) {
		if ( ! self::active() ) {
			return $methods;
		}
		$policy = self::mode()['xmlrpc'];
		unset( $methods['system.multicall'] );
		if ( 'multicall' === $policy ) {
			return $methods;
		}
		foreach ( array_keys( (array) $methods ) as $name ) {
			if ( 0 !== strpos( (string) $name, 'pingback.' ) && 0 !== strpos( (string) $name, 'system.' ) && 'demo.sayHello' !== $name ) {
				unset( $methods[ $name ] );
			}
		}
		return $methods;
	}

	public static function xmlrpc_enabled( $enabled ) {
		return self::active() && 'all' === self::mode()['xmlrpc'] ? false : $enabled;
	}

	public static function rest_users( $result, $server, $request ) {
		if ( null !== $result || ! self::active() || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( preg_match( '#^/wp/v2/users(/|$)#', (string) $request->get_route() ) && ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_user_cannot_view', __( 'Sorry, you are not allowed to list users.', 'rafoo' ), array( 'status' => 401 ) );
		}
		return $result;
	}

	public static function missing() {
		if ( ! is_404() || ! self::active() || is_user_logged_in() ) {
			return;
		}
		$ip = self::ip();
		if ( '' === $ip || isset( Local::get( 'admins' )[ $ip ] ) || Ip::any( $ip, (array) Local::get( 'allow' ) ) ) {
			return;
		}
		$r = Gate::request();
		if ( $r && Gate::bot( $r ) ) {
			return;
		}
		self::store();
		$mode   = self::mode();
		$key    = 'ip:' . Ip::key( $ip );
		$rec    = Store::get( $key );
		$now    = time();
		$minute = intdiv( $now, 60 );
		if ( ! isset( $rec['fm'] ) || (int) $rec['fm'] !== $minute ) {
			$rec['fm'] = $minute;
			$rec['f']  = 0;
		}
		$rec['f'] = (int) $rec['f'] + 1;
		if ( $rec['f'] > (int) $mode['missing'] ) {
			$rec['b']  = $now + min( 3600, (int) $mode['ban'] );
			$rec['br'] = 's_missing';
			$rec['bk'] = 'block';
			$rec['f']  = 0;
			if ( $r ) {
				Log::write( Config::dir(), $r, 'block', Rules::verdict( array( Rules::hit( 's_missing', 'scanner', 0, '404' ) ) ), Log::reference() );
			}
		}
		Store::put( $key, $rec, 86400 );
	}

	public static function changed( $after, $before ) {
		$keys = array( 'shield', 'shield_network', 'paused' );
		foreach ( $keys as $key ) {
			if ( ( isset( $after[ $key ] ) ? $after[ $key ] : null ) !== ( isset( $before[ $key ] ) ? $before[ $key ] : null ) ) {
				self::recompile();
				if ( 'shield' === $key ) {
					\Rafoo\State::event( 'shield_mode', array( 'name' => (string) $after['shield'] ) );
				}
				if ( 'shield_network' === $key && ! empty( $after['shield_network'] ) ) {
					wp_schedule_single_event( time() + 5, self::HOOK );
				}
				if ( 'shield_network' === $key && empty( $after['shield_network'] ) ) {
					Intel::leave();
				}
				break;
			}
		}
	}

	public static function plugin_changed( $plugin ) {
		if ( defined( 'RAFOO_FILE' ) && plugin_basename( RAFOO_FILE ) === $plugin ) {
			return;
		}
		self::recompile();
	}

	public static function recompile() {
		if ( self::active() ) {
			Config::compile();
			Prepend::refresh();
			self::first();
			return;
		}
		Config::compile();
	}

	public static function first() {
		$file    = defined( 'RAFOO_FILE' ) ? plugin_basename( RAFOO_FILE ) : '';
		$plugins = (array) get_option( 'active_plugins', array() );
		$index   = array_search( $file, $plugins, true );
		if ( '' === $file || false === $index || 0 === $index || ! self::active() ) {
			return;
		}
		unset( $plugins[ $index ] );
		array_unshift( $plugins, $file );
		update_option( 'active_plugins', array_values( $plugins ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'rafoo_minute', self::HOOK );
		}
	}

	public static function tick() {
		$day = wp_date( 'Y-m-d' );
		if ( get_option( 'rafoo_shield_day' ) !== $day ) {
			update_option( 'rafoo_shield_day', $day, false );
			Stats::rotate();
		} else {
			Stats::compact();
		}
		if ( Settings::get( 'shield_network' ) && self::active() ) {
			Intel::tick();
		}
		Edge::tick();
		if ( 0 === wp_rand( 0, 59 ) ) {
			self::store();
			Store::sweep();
		}
	}
}
