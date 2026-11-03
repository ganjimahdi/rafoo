<?php

namespace Rafoo\Services;

use Rafoo\Data;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class SocialLogin {

	const HANDLE = 'rafoo-social-login';

	public static function active() {
		return Settings::get( 'social_login' ) && State::offline();
	}

	public static function selectors() {
		$data      = Data::get( 'social' );
		$selectors = (array) apply_filters( 'rafoo_social_login_selectors', isset( $data['selectors'] ) ? $data['selectors'] : array() );
		return array_values(
			array_filter(
				array_map( 'strval', $selectors ),
				static function ( $selector ) {
					return '' !== $selector && ! preg_match( '/[{}<>;@\\\\]/', $selector );
				}
			)
		);
	}

	public static function css() {
		$selectors = self::selectors();
		return $selectors ? implode( ',', $selectors ) . '{display:none!important}' : '';
	}

	public static function style() {
		if ( ! self::active() ) {
			return;
		}
		$css = self::css();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( self::HANDLE, false, array(), RAFOO_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, $css );
	}
}
