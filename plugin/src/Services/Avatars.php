<?php

namespace Rafoo\Services;

use Rafoo\Assets\Store;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Avatars {

	const COLORS = array( '#3b5bdb', '#0b7285', '#2b8a3e', '#5f3dc4', '#a61e4d', '#c92a2a', '#495057', '#1864ab' );

	public static function active() {
		$mode = Settings::get( 'avatars' );
		return 'always' === $mode || ( 'outage' === $mode && State::offline() );
	}

	public static function data( $args, $id_or_email ) {
		if ( ! is_array( $args ) || ! empty( $args['url'] ) || ! self::active() ) {
			return $args;
		}
		$url = self::url( self::name( $id_or_email ) );
		if ( '' === $url ) {
			return $args;
		}
		$args['url']          = $url;
		$args['found_avatar'] = true;
		return $args;
	}

	public static function url( $name ) {
		$letter = self::initial( $name );
		$color  = abs( crc32( $name ) ) % count( self::COLORS );
		$file   = 'avatars/' . $color . '-' . bin2hex( $letter ) . '.svg';
		if ( ! Store::exists( $file ) && ! Store::write( $file, self::svg( $letter, self::COLORS[ $color ] ) ) ) {
			return '';
		}
		return Store::url( $file );
	}

	public static function svg( $letter, $color ) {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
			. '<rect width="64" height="64" fill="' . $color . '"/>'
			. '<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="system-ui, sans-serif" font-size="28" fill="#fff">'
			. htmlspecialchars( $letter, ENT_QUOTES | ENT_XML1, 'UTF-8' )
			. '</text></svg>';
	}

	public static function initial( $name ) {
		$name   = trim( wp_strip_all_tags( (string) $name ) );
		$letter = '' === $name ? '?' : mb_substr( $name, 0, 1, 'UTF-8' );
		if ( ! preg_match( '/^[\p{L}\p{N}]$/u', $letter ) ) {
			return '?';
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letter, 'UTF-8' ) : strtoupper( $letter );
	}

	public static function name( $subject ) {
		if ( $subject instanceof \WP_Comment ) {
			if ( $subject->user_id ) {
				$user = get_userdata( (int) $subject->user_id );
				if ( $user ) {
					return $user->display_name;
				}
			}
			return $subject->comment_author;
		}
		if ( $subject instanceof \WP_User ) {
			return $subject->display_name;
		}
		if ( $subject instanceof \WP_Post ) {
			$user = get_userdata( (int) $subject->post_author );
			return $user ? $user->display_name : '';
		}
		if ( is_numeric( $subject ) ) {
			$user = get_userdata( (int) $subject );
			return $user ? $user->display_name : '';
		}
		if ( is_string( $subject ) && is_email( $subject ) ) {
			$user = get_user_by( 'email', $subject );
			return $user ? $user->display_name : strstr( $subject, '@', true );
		}
		return '';
	}
}
