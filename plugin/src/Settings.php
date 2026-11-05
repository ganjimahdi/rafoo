<?php

namespace Rafoo;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'rafoo_settings';

	const NPM_MIRRORS = array( 'https://mirror.wbweb.ir' );

	const WP_MIRRORS = array( 'https://wordpress.wbweb.ir' );

	const PRESETS = array( 'recommended', 'outage', 'maximum' );

	const EDITABLE = array( 'replace', 'html_scan', 'fonts', 'avatars', 'embeds', 'updates', 'telemetry', 'allow_hosts', 'core_assets', 'analytics', 'social_login', 'mail_queue', 'request_log', 'captcha', 'safe_updates', 'translations', 'backup', 'backup_hours', 'backup_hour', 'backup_mode', 'backup_local', 'backup_exclude', 'backup_part', 'browser_guard', 'dedupe', 'heartbeat', 'block_hosts', 'scan_as_user' );

	private static $cache = null;

	public static function defaults() {
		return array(
			'replace'      => 'always',
			'html_scan'    => true,
			'fonts'        => true,
			'avatars'      => 'outage',
			'embeds'       => true,
			'updates'      => 'outage',
			'telemetry'    => false,
			'consent_at'   => 0,
			'allow_hosts'  => array(),
			'exclude'      => array(),
			'npm_mirrors'  => self::NPM_MIRRORS,
			'wp_mirrors'   => self::WP_MIRRORS,
			'core_assets'  => true,
			'analytics'    => 'outage',
			'social_login' => true,
			'mail_queue'   => true,
			'request_log'  => true,
			'captcha'      => 'outage',
			'safe_updates' => true,
			'translations' => true,
			'backup'       => false,
			'backup_hours' => 24,
			'backup_hour'  => 3,
			'backup_mode'  => 'incremental',
			'backup_local' => true,
			'backup_exclude' => array(),
			'backup_part'  => 256,
			'browser_guard' => true,
			'dedupe'       => true,
			'heartbeat'    => 'smart',
			'block_hosts'  => array(),
			'scan_as_user' => true,
			'paused'       => false,
			'setup'        => '',
			'preset'       => '',
		);
	}

	public static function preset( $name ) {
		$presets = array(
			'recommended' => array(
				'replace'      => 'always',
				'html_scan'    => true,
				'fonts'        => true,
				'avatars'      => 'outage',
				'embeds'       => true,
				'updates'      => 'outage',
				'core_assets'  => true,
				'analytics'    => 'outage',
				'social_login' => true,
				'mail_queue'   => true,
				'request_log'  => true,
				'captcha'      => 'outage',
				'safe_updates' => true,
				'translations' => true,
				'backup'       => true,
				'browser_guard' => true,
				'dedupe'       => true,
				'heartbeat'    => 'smart',
				'scan_as_user' => true,
			),
			'outage'      => array(
				'replace'      => 'outage',
				'html_scan'    => true,
				'fonts'        => true,
				'avatars'      => 'outage',
				'embeds'       => true,
				'updates'      => 'outage',
				'core_assets'  => true,
				'analytics'    => 'outage',
				'social_login' => true,
				'mail_queue'   => true,
				'request_log'  => false,
				'captcha'      => 'outage',
				'safe_updates' => true,
				'translations' => true,
				'backup'       => false,
				'browser_guard' => true,
				'dedupe'       => false,
				'heartbeat'    => 'default',
				'scan_as_user' => true,
			),
			'maximum'     => array(
				'replace'      => 'always',
				'html_scan'    => true,
				'fonts'        => true,
				'avatars'      => 'always',
				'embeds'       => true,
				'updates'      => 'always',
				'core_assets'  => true,
				'analytics'    => 'outage',
				'social_login' => true,
				'mail_queue'   => true,
				'request_log'  => true,
				'captcha'      => 'always',
				'safe_updates' => true,
				'translations' => true,
				'backup'       => true,
				'browser_guard' => true,
				'dedupe'       => true,
				'heartbeat'    => 'smart',
				'scan_as_user' => true,
			),
		);
		return isset( $presets[ $name ] ) ? $presets[ $name ] : array();
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored  = get_option( self::OPTION, array() );
			$stored  = is_array( $stored ) ? $stored : array();
			$network = Network::settings();
			if ( $network && ( Network::enforced() || ! $stored ) ) {
				$stored = array_merge( $stored, $network );
			}
			self::$cache = self::normalize( $stored );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function on( $key ) {
		$value = self::get( $key );
		return true === $value || 'always' === $value || ( 'outage' === $value && State::offline() );
	}

	public static function update( array $changes ) {
		if ( Network::enforced() ) {
			$changes = array_diff_key( $changes, array_flip( Network::keys() ) );
		}
		$next = self::normalize( array_merge( self::all(), $changes ) );
		if ( $next['telemetry'] && ! self::all()['telemetry'] ) {
			$next['consent_at'] = time();
		}
		if ( ! $next['telemetry'] ) {
			$next['consent_at'] = 0;
		}
		update_option( self::OPTION, $next, true );
		self::$cache = $next;
		return $next;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function normalize( array $in ) {
		$defaults = self::defaults();
		$out      = array();
		$choices  = array(
			'replace'  => array( 'always', 'outage', 'off' ),
			'avatars'  => array( 'off', 'outage', 'always' ),
			'updates'  => array( 'off', 'outage', 'always' ),
			'analytics' => array( 'off', 'outage', 'always' ),
			'captcha'  => array( 'off', 'outage', 'always' ),
			'backup_mode' => array( 'incremental', 'full' ),
			'heartbeat' => array( 'smart', 'default' ),
			'setup'    => array( '', 'done', 'skipped' ),
			'preset'   => array( '', 'recommended', 'outage', 'maximum', 'custom' ),
		);
		foreach ( $choices as $key => $allowed ) {
			$out[ $key ] = self::choice( $in, $key, $allowed, $defaults[ $key ] );
		}
		foreach ( array( 'html_scan', 'fonts', 'embeds', 'telemetry', 'core_assets', 'social_login', 'mail_queue', 'request_log', 'safe_updates', 'translations', 'backup', 'backup_local', 'browser_guard', 'dedupe', 'scan_as_user', 'paused' ) as $key ) {
			$out[ $key ] = self::flag( $in, $key, $defaults[ $key ] );
		}
		$hours               = isset( $in['backup_hours'] ) ? (int) $in['backup_hours'] : $defaults['backup_hours'];
		$out['backup_hours'] = in_array( $hours, array( 6, 12, 24, 48, 168 ), true ) ? $hours : $defaults['backup_hours'];
		$hour                = isset( $in['backup_hour'] ) ? (int) $in['backup_hour'] : $defaults['backup_hour'];
		$out['backup_hour']  = $hour >= 0 && $hour <= 23 ? $hour : $defaults['backup_hour'];
		$part                = isset( $in['backup_part'] ) ? (int) $in['backup_part'] : $defaults['backup_part'];
		$out['backup_part']  = in_array( $part, array( 128, 256, 512, 1024, 2048 ), true ) ? $part : $defaults['backup_part'];
		$out['backup_exclude'] = self::paths( isset( $in['backup_exclude'] ) ? $in['backup_exclude'] : array() );
		$out['consent_at']  = isset( $in['consent_at'] ) ? max( 0, (int) $in['consent_at'] ) : 0;
		$out['allow_hosts'] = self::hosts( isset( $in['allow_hosts'] ) ? $in['allow_hosts'] : array() );
		$out['block_hosts'] = self::blocks( isset( $in['block_hosts'] ) ? $in['block_hosts'] : array() );
		$out['exclude']     = self::urls( isset( $in['exclude'] ) ? $in['exclude'] : array() );
		$out['npm_mirrors'] = self::mirrors( isset( $in['npm_mirrors'] ) ? $in['npm_mirrors'] : array(), self::NPM_MIRRORS );
		$out['wp_mirrors']  = self::mirrors( isset( $in['wp_mirrors'] ) ? $in['wp_mirrors'] : array(), self::WP_MIRRORS );

		return $out;
	}

	private static function choice( array $in, $key, array $allowed, $fallback ) {
		return isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ? $in[ $key ] : $fallback;
	}

	private static function flag( array $in, $key, $fallback ) {
		if ( ! array_key_exists( $key, $in ) ) {
			return $fallback;
		}
		return filter_var( $in[ $key ], FILTER_VALIDATE_BOOLEAN );
	}

	private static function lines( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		return is_array( $value ) ? array_filter( array_map( 'trim', array_map( 'strval', $value ) ) ) : array();
	}

	private static function hosts( $value ) {
		$out = array();
		foreach ( self::lines( $value ) as $host ) {
			$host = strtolower( preg_replace( '#^[a-z]+://#i', '', $host ) );
			$host = explode( '/', $host )[0];
			if ( preg_match( '/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) ) {
				$out[] = $host;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 200 );
	}

	public static function paused() {
		return (bool) self::get( 'paused' );
	}

	private static function blocks( $value ) {
		$out = array();
		foreach ( self::lines( $value ) as $rule ) {
			if ( preg_match( '#^https?://#i', $rule ) ) {
				$url  = esc_url_raw( $rule, array( 'http', 'https' ) );
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( '' !== $url && preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) ) {
					$out[] = preg_replace( '#^(https?://)([^/]+)#i', '$1' . $host, $url );
				}
				continue;
			}
			if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $rule ) ) {
				continue;
			}
			$out = array_merge( $out, self::hosts( array( $rule ) ) );
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 200 );
	}

	private static function paths( $value ) {
		$out = array();
		foreach ( is_string( $value ) ? preg_split( '/[\r\n]+/', $value ) : (array) $value as $rule ) {
			$rule = trim( str_replace( '\\', '/', (string) $rule ) );
			$rule = trim( (string) preg_replace( '#/+#', '/', $rule ), '/' );
			if ( '' === $rule || strlen( $rule ) > 200 || false !== strpos( $rule, '..' ) || preg_match( '/[\x00-\x1f]/', $rule ) ) {
				continue;
			}
			$out[] = $rule;
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 100 );
	}

	private static function urls( $value ) {
		$out = array();
		foreach ( self::lines( $value ) as $url ) {
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 500 );
	}

	private static function mirrors( $value, array $fallback ) {
		$out = array();
		foreach ( self::lines( $value ) as $url ) {
			$url = untrailingslashit( esc_url_raw( $url, array( 'https' ) ) );
			if ( '' !== $url && null === wp_parse_url( $url, PHP_URL_PATH ) ) {
				$out[] = $url;
			}
		}
		$out = array_values( array_unique( $out ) );
		return $out ? array_slice( $out, 0, 5 ) : $fallback;
	}
}
