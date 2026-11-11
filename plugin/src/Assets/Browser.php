<?php

namespace Rafoo\Assets;

use Rafoo\Data;
use Rafoo\Drill\Session;
use Rafoo\Http\Breaker;
use Rafoo\Http\Hosts;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Browser {

	const ID = 'rafoo-guard';

	const TIMEOUT = 4000;

	private static $buffered = false;

	private static $printed = false;

	private static $source = array();

	public static function active() {
		if ( Settings::paused() ) {
			return false;
		}
		if ( Session::watching() ) {
			return true;
		}
		$rules = Settings::get( 'browser_guard' ) && ( State::offline() || Settings::get( 'block_hosts' ) );
		return $rules || (bool) Settings::get( 'dedupe' );
	}

	public static function buffering() {
		self::$buffered = true;
	}

	public static function config() {
		$offline = Settings::get( 'browser_guard' ) && State::offline();
		$block   = array();
		if ( Settings::get( 'browser_guard' ) ) {
			foreach ( Settings::get( 'block_hosts' ) as $rule ) {
				$block[] = strtolower( preg_replace( '#^https?://#i', '', $rule ) );
			}
		}
		$config = array(
			'o' => $offline,
			'b' => $block,
			'f' => array(),
			'a' => array(),
			'd' => (bool) Settings::get( 'dedupe' ),
			't' => $offline ? self::TIMEOUT : 0,
		);
		if ( $offline ) {
			$lists       = Data::get( 'hosts' );
			$analytics   = isset( $lists['analytics'] ) ? self::hosts_of( (array) $lists['analytics'] ) : array();
			$open        = array_map(
				static function ( $entry ) {
					return $entry['host'];
				},
				Breaker::snapshot()
			);
			$config['f'] = self::unique( array_merge( Hosts::rules( 'foreign' ), $analytics, $open ) );
			$mirrors     = array();
			foreach ( array_merge( Settings::get( 'npm_mirrors' ), Settings::get( 'wp_mirrors' ) ) as $mirror ) {
				$mirrors[] = Hosts::of( $mirror );
			}
			$config['a'] = self::unique( array_merge( Hosts::own(), Hosts::SERVICE, $mirrors, Hosts::rules( 'domestic' ), Settings::get( 'allow_hosts' ), Breaker::reachable_hosts() ) );
		}
		if ( Session::watching() ) {
			$config['x'] = Session::guard();
		} elseif ( is_user_logged_in() && current_user_can( 'manage_options' ) && ( $offline || $block ) ) {
			$config['r'] = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'rafoo/v1/requests/browser' ) );
		}
		return (array) apply_filters( 'rafoo_browser_guard', $config );
	}

	public static function light( array $config ) {
		return ! empty( $config['d'] ) && empty( $config['o'] ) && empty( $config['b'] ) && empty( $config['x'] ) && empty( $config['r'] );
	}

	public static function tag() {
		$config = self::config();
		$light  = self::light( $config );
		$source = self::source( $light );
		if ( '' === $source ) {
			return '';
		}
		$script = 'window.rafooGuard=' . wp_json_encode( $light ? array( 'd' => true ) : $config ) . ';' . $source;
		return wp_get_inline_script_tag(
			$script,
			array(
				'id'                       => self::ID,
				'data-no-optimize'         => '1',
				'data-no-defer'            => '1',
				'data-no-minify'           => '1',
				'data-cfasync'             => 'false',
				'data-pagespeed-no-defer'  => '',
				'nowprocket'               => '',
			)
		);
	}

	public static function inject( $html ) {
		if ( false !== strpos( $html, 'id="' . self::ID . '"' ) || ! preg_match( '#<head\b[^>]*>#i', $html, $head, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$tag = self::tag();
		if ( '' === $tag ) {
			return $html;
		}
		$at = $head[0][1] + strlen( $head[0][0] );
		return substr( $html, 0, $at ) . $tag . substr( $html, $at );
	}

	public static function head() {
		if ( self::$buffered ) {
			return;
		}
		self::output();
	}

	public static function output() {
		if ( self::$printed || ! self::active() ) {
			return;
		}
		self::$printed = true;
		echo self::tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag builds the tag; the config is JSON from sanitized settings.
	}

	public static function source( $light = false ) {
		$name = $light ? 'dedupe' : 'guard';
		if ( ! isset( self::$source[ $name ] ) ) {
			$file                  = dirname( __DIR__, 2 ) . '/assets/' . $name . '.js';
			$source                = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file shipped with the plugin.
			self::$source[ $name ] = trim( $source );
		}
		return self::$source[ $name ];
	}

	private static function hosts_of( array $needles ) {
		$out = array();
		foreach ( $needles as $needle ) {
			$host = strtolower( explode( '/', (string) $needle )[0] );
			if ( '' !== $host ) {
				$out[] = $host;
				$out[] = '*.' . $host;
			}
		}
		return $out;
	}

	private static function unique( array $list ) {
		return array_values( array_unique( array_filter( array_map( 'strval', $list ) ) ) );
	}
}
