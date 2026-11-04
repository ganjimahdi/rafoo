<?php

namespace Rafoo\Services;

use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Heartbeat {

	const EDITOR = 60;

	const OTHER = 120;

	const EDITOR_SCREENS = array( 'post.php', 'post-new.php', 'site-editor.php', 'customize.php' );

	public static function settings( $settings ) {
		if ( 'smart' !== Settings::get( 'heartbeat' ) || ! is_array( $settings ) ) {
			return $settings;
		}
		$settings['interval'] = self::editor() ? self::EDITOR : self::OTHER;
		return $settings;
	}

	public static function editor() {
		if ( is_admin() ) {
			$screen = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
			return in_array( $screen, self::EDITOR_SCREENS, true );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only detection of a page builder's front-end editor.
		foreach ( array( 'elementor-preview', 'et_fb', 'fl_builder', 'bricks', 'ct_builder', 'tve', 'vc_editable' ) as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return function_exists( 'is_customize_preview' ) && is_customize_preview();
	}
}
