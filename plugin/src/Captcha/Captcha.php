<?php

namespace Rafoo\Captcha;

use Rafoo\Data;
use Rafoo\Settings;
use Rafoo\State;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Captcha {

	const HANDLE = 'rafoo-captcha';

	const FIELD = 'rafoo_captcha';

	const HEADER = 'x_rafoo_captcha';

	const ACTION = 'rafoo_captcha';

	const STORE_ROUTE = '#^/wc/store(?:/v\d+)?/checkout$#';

	const SCRIPT_TAG = '#<script\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1[^>]*>\s*</script\s*>#i';

	private static $verdict = null;

	private static $comment = false;

	private static $lists = null;

	public static function active() {
		return Settings::on( 'captcha' );
	}

	public static function replacing() {
		return 'off' !== Settings::get( 'captcha' ) && State::offline();
	}

	public static function endpoint() {
		nocache_headers();
		wp_send_json_success( Pow::create() );
	}

	public static function enqueue( $store = false ) {
		$deps = $store && wp_script_is( 'wp-api-fetch', 'registered' ) ? array( 'wp-api-fetch' ) : array();
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script(
				self::HANDLE,
				plugins_url( 'assets/captcha.js', RAFOO_FILE ),
				$deps,
				RAFOO_VERSION,
				array( 'in_footer' => true )
			);
			wp_add_inline_script( self::HANDLE, 'var rafooCaptcha = ' . wp_json_encode( self::config( $store ) ) . ';', 'before' );
		}
		wp_enqueue_script( self::HANDLE );
	}

	public static function config( $store = false ) {
		return array(
			'url'     => admin_url( 'admin-ajax.php?action=' . self::ACTION ),
			'outside' => self::replacing(),
			'store'   => (bool) $store,
			'working' => __( 'Checking that you are not a robot…', 'rafoo' ),
			'failed'  => __( 'The security check could not finish. Please reload the page and try again.', 'rafoo' ),
		);
	}

	public static function markup() {
		self::enqueue();
		return sprintf(
			'<div class="rafoo-captcha" data-rafoo-captcha><input type="hidden" name="%1$s" value=""><p class="rafoo-captcha-note" role="status" hidden style="%2$s"></p><noscript><p style="%2$s">%3$s</p></noscript></div>',
			esc_attr( self::FIELD ),
			esc_attr( 'margin:.5em 0;font-size:.9em' ),
			esc_html__( 'Please turn on JavaScript to send this form.', 'rafoo' )
		);
	}

	public static function render() {
		if ( self::active() ) {
			self::output();
		}
	}

	public static function render_comment() {
		if ( self::active() && ! is_user_logged_in() ) {
			self::output();
		}
	}

	private static function output() {
		self::enqueue();
		printf(
			'<div class="rafoo-captcha" data-rafoo-captcha><input type="hidden" name="%1$s" value=""><p class="rafoo-captcha-note" role="status" hidden style="%2$s"></p><noscript><p style="%2$s">%3$s</p></noscript></div>',
			esc_attr( self::FIELD ),
			esc_attr( 'margin:.5em 0;font-size:.9em' ),
			esc_html__( 'Please turn on JavaScript to send this form.', 'rafoo' )
		);
	}

	public static function cf7( $html ) {
		return self::active() && is_string( $html ) ? $html . self::markup() : $html;
	}

	public static function assets() {
		$store = self::active() && function_exists( 'is_checkout' ) && is_checkout() && has_block( 'woocommerce/checkout' );
		if ( $store || self::replacing() ) {
			self::enqueue( $store );
		}
	}

	public static function valid() {
		if ( null === self::$verdict ) {
			$token = filter_input( INPUT_POST, self::FIELD );
			if ( ! is_string( $token ) || '' === $token ) {
				$token = isset( $_SERVER['HTTP_X_RAFOO_CAPTCHA'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAFOO_CAPTCHA'] ) ) : '';
			}
			self::$verdict = Pow::verify( is_string( $token ) ? trim( $token ) : '' );
		}
		return self::$verdict;
	}

	public static function reset() {
		self::$verdict = null;
		self::$comment = false;
	}

	public static function message() {
		return __( 'The security check did not finish. Please wait a moment and send the form again.', 'rafoo' );
	}

	public static function authenticate( $user ) {
		if ( ! self::active() || ! self::on_login_page() || null === filter_input( INPUT_POST, 'log' ) ) {
			return $user;
		}
		return self::valid() ? $user : new WP_Error( 'rafoo_captcha', self::message() );
	}

	public static function registration( $errors ) {
		if ( self::active() && self::on_login_page() && is_wp_error( $errors ) && ! self::valid() ) {
			$errors->add( 'rafoo_captcha', self::message() );
		}
		return $errors;
	}

	public static function signup( $result ) {
		if ( ! self::active() || ! is_array( $result ) || ! isset( $result['errors'] ) || ! is_wp_error( $result['errors'] ) ) {
			return $result;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-signup.php' === $GLOBALS['pagenow'] && ! is_user_logged_in() && ! self::valid() ) {
			$result['errors']->add( 'generic', self::message() );
		}
		return $result;
	}

	public static function lost_password( $errors ) {
		if ( ! self::active() || ! is_wp_error( $errors ) || ( is_user_logged_in() && current_user_can( 'edit_users' ) ) ) {
			return;
		}
		if ( ( self::on_login_page() || null !== filter_input( INPUT_POST, 'wc_reset_password' ) ) && ! self::valid() ) {
			$errors->add( 'rafoo_captcha', self::message() );
		}
	}

	public static function comment_started() {
		self::$comment = true;
	}

	public static function comment( $approved ) {
		if ( ! self::$comment || ! self::active() || is_user_logged_in() || is_wp_error( $approved ) || 'spam' === $approved ) {
			return $approved;
		}
		return self::valid() ? $approved : new WP_Error( 'rafoo_captcha', self::message(), 403 );
	}

	public static function woo_errors( $errors ) {
		if ( self::active() && is_wp_error( $errors ) && ! self::valid() ) {
			$errors->add( 'rafoo_captcha', self::message() );
		}
		return $errors;
	}

	public static function woo_checkout( $data, $errors ) {
		self::woo_errors( $errors );
	}

	public static function store_api( $response, $handler, $request ) {
		if ( null !== $response || ! self::active() || ! is_object( $request ) || 'POST' !== $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( self::STORE_ROUTE, (string) $request->get_route() ) || ! apply_filters( 'rafoo_captcha_store_api', true, $request ) ) {
			return $response;
		}
		$token         = (string) $request->get_header( self::HEADER );
		self::$verdict = Pow::verify( trim( $token ) );
		return self::$verdict ? $response : new WP_Error( 'rafoo_captcha', self::message(), array( 'status' => 403 ) );
	}

	public static function cf7_spam( $spam, $submission = null ) {
		if ( $spam || ! self::active() ) {
			return $spam;
		}
		if ( self::valid() ) {
			return false;
		}
		if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log(
				array(
					'agent'  => 'rafoo',
					'reason' => __( 'The Rafoo security check was missing or wrong.', 'rafoo' ),
				)
			);
		}
		return true;
	}

	public static function wpforms( $entry, $form_data ) {
		if ( ! self::active() || self::valid() || ! function_exists( 'wpforms' ) || empty( $form_data['id'] ) ) {
			return;
		}
		$main    = wpforms();
		$process = method_exists( $main, 'obj' ) ? $main->obj( 'process' ) : ( method_exists( $main, 'get' ) ? $main->get( 'process' ) : null );
		if ( is_object( $process ) ) {
			$process->errors[ $form_data['id'] ]['header'] = self::message();
		}
	}

	public static function siteverify( $pre, $args, $url ) {
		if ( false !== $pre || ! self::replacing() || ! self::listed( (string) $url, 'captcha_verify' ) ) {
			return $pre;
		}
		$ok   = self::valid();
		$body = $ok
			? array(
				'success'         => true,
				'challenge_ts'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'hostname'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'score'           => 0.9,
				'tokenProperties' => array( 'valid' => true ),
				'riskAnalysis'    => array( 'score' => 0.9 ),
			)
			: array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			);
		return array(
			'headers'  => array(
				'content-type' => 'application/json',
				'x-rafoo'      => 'captcha',
			),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public static function script_tag( $tag, $handle = '', $src = '' ) {
		if ( ! is_string( $tag ) || ! self::listed( (string) $src, 'captcha_scripts' ) || ! self::replacing() ) {
			return $tag;
		}
		return self::stub( (string) $src );
	}

	public static function strip( $html ) {
		if ( ! is_string( $html ) || ! self::mentions( $html ) ) {
			return $html;
		}
		$out = preg_replace_callback(
			self::SCRIPT_TAG,
			static function ( $m ) {
				$src = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				return self::listed( $src, 'captcha_scripts' ) ? self::stub( $src ) : $m[0];
			},
			$html
		);
		return null === $out ? $html : $out;
	}

	public static function mentions( $html ) {
		foreach ( self::lists()['captcha_scripts'] as $needle ) {
			if ( false !== stripos( $html, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function stub( $src ) {
		$query    = (string) wp_parse_url( $src, PHP_URL_QUERY );
		$callback = '';
		if ( '' !== $query ) {
			parse_str( $query, $params );
			$callback = isset( $params['onload'] ) && is_string( $params['onload'] ) && preg_match( '/^[A-Za-z_$][\w$]{0,60}$/', $params['onload'] ) ? $params['onload'] : '';
		}
		$script = sprintf(
			'(function(w,d,c){function api(){return{ready:function(f){if(typeof f==="function"){f()}},execute:function(){return Promise.resolve("rafoo")},render:function(){return 0},getResponse:function(){return "rafoo"},reset:function(){},remove:function(){}}}if(!w.grecaptcha){w.grecaptcha=api();w.grecaptcha.enterprise=api()}if(!w.hcaptcha){w.hcaptcha=api()}if(!w.turnstile){w.turnstile=api()}if(c){var run=function(){if(typeof w[c]==="function"){w[c]()}};if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",run)}else{setTimeout(run,0)}}})(window,document,%s);',
			wp_json_encode( $callback )
		);
		return wp_get_inline_script_tag( $script, array( 'data-rafoo' => 'captcha' ) );
	}

	private static function listed( $url, $group ) {
		if ( '' === $url ) {
			return false;
		}
		$url = preg_replace( '#^(?:https?:)?//#i', '', strtolower( $url ) );
		foreach ( self::lists()[ $group ] as $needle ) {
			$needle = strtolower( $needle );
			if ( 0 === strpos( $url, $needle ) || false !== strpos( $url, '.' . $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function lists() {
		if ( null === self::$lists ) {
			$data        = Data::get( 'hosts' );
			self::$lists = array(
				'captcha_scripts' => (array) apply_filters( 'rafoo_captcha_scripts', isset( $data['captcha_scripts'] ) ? $data['captcha_scripts'] : array() ),
				'captcha_verify'  => (array) apply_filters( 'rafoo_captcha_verify', isset( $data['captcha_verify'] ) ? $data['captcha_verify'] : array() ),
			);
		}
		return self::$lists;
	}

	private static function on_login_page() {
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
	}
}
