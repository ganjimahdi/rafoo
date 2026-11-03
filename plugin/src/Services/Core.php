<?php

namespace Rafoo\Services;

use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Core {

	const EMOJI = array(
		array( 'wp_head', 'print_emoji_detection_script', 7 ),
		array( 'admin_print_scripts', 'print_emoji_detection_script', 10 ),
		array( 'embed_head', 'print_emoji_detection_script', 10 ),
		array( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles', 10 ),
		array( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles', 10 ),
		array( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles', 10 ),
	);

	public static function active() {
		return Settings::get( 'core_assets' ) && State::offline();
	}

	public static function init() {
		if ( ! self::active() ) {
			return;
		}
		foreach ( self::EMOJI as $hook ) {
			remove_action( $hook[0], $hook[1], $hook[2] );
		}
		if ( class_exists( 'WP_Font_Library' ) && \WP_Font_Library::get_instance()->get_font_collection( 'google-fonts' ) ) {
			\WP_Font_Library::get_instance()->unregister_font_collection( 'google-fonts' );
		}
	}

	public static function patterns( $load ) {
		return self::active() ? false : $load;
	}

	public static function editor( $settings ) {
		if ( is_array( $settings ) && self::active() ) {
			$settings['enableOpenverseMediaCategory'] = false;
		}
		return $settings;
	}

	public static function dashboard() {
		if ( self::active() ) {
			remove_meta_box( 'dashboard_primary', is_network_admin() ? 'dashboard-network' : 'dashboard', 'side' );
		}
	}
}
