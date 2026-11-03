<?php

namespace Rafoo\Admin;

defined( 'ABSPATH' ) || exit;

final class Page {

	const SLUG = 'rafoo';

	const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAxLjVhOC41IDguNSAwIDEgMCAwIDE3IDguNSA4LjUgMCAwIDAgMC0xN1ptMCAxLjdhNi44IDYuOCAwIDEgMSAwIDEzLjYgNi44IDYuOCAwIDAgMSAwLTEzLjZaIi8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik00LjYgOS4xSDd2MS44SDQuNnptNC4yIDBoMi40djEuOEg4Ljh6bTQuMiAwaDIuNHYxLjhIMTN6Ii8+PC9zdmc+';

	private static $hook = '';

	public static function url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu() {
		self::$hook = (string) add_menu_page(
			__( 'Rafoo', 'rafoo' ),
			__( 'Rafoo', 'rafoo' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::ICON,
			81
		);
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'rafoo' ) . '</a>' );
		return $links;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1 class="screen-reader-text">' . esc_html__( 'Rafoo', 'rafoo' ) . '</h1><div id="rafoo-app"><p>' . esc_html__( 'Loading…', 'rafoo' ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'The Rafoo dashboard needs JavaScript.', 'rafoo' ) . '</p></noscript></div></div>';
	}

	public static function assets( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		$base = plugin_dir_url( RAFOO_FILE ) . 'assets/';
		wp_enqueue_style( 'rafoo-admin', $base . 'admin.css', array(), RAFOO_VERSION );
		wp_add_inline_style( 'rafoo-admin', self::fonts( $base . 'fonts/' ) );
		wp_enqueue_script( 'rafoo-admin', $base . 'admin.js', array(), RAFOO_VERSION, true );
		wp_add_inline_script( 'rafoo-admin', 'window.rafooBoot = ' . wp_json_encode( self::boot() ) . ';', 'before' );
	}

	public static function fonts( $base ) {
		$css = '';
		foreach ( array( 400 => 'Regular', 700 => 'Bold' ) as $weight => $name ) {
			$css .= sprintf(
				"@font-face{font-family:'Rafoo Vazirmatn';font-style:normal;font-weight:%d;font-display:swap;src:url('%s') format('woff2')}",
				$weight,
				esc_url( $base . 'Vazirmatn-' . $name . '.woff2?ver=33.0.3' )
			);
		}
		return $css;
	}

	public static function boot() {
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		return array(
			'root'        => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'edition'     => RAFOO_EDITION,
			'version'     => RAFOO_VERSION,
			'rtl'         => is_rtl(),
			'locale'      => get_user_locale(),
			'timezone'    => wp_timezone_string(),
			'strings'     => Strings::all(),
			'overview'    => Rest::overview(),
			'settings'    => Rest::public_settings(),
			'privacy_url' => current_user_can( 'manage_privacy_options' ) ? admin_url( 'options-privacy.php?tab=policyguide' ) : ( $privacy ? (string) get_permalink( $privacy ) : '' ),
		);
	}
}
