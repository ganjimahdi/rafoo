<?php

namespace Rafoo\Shield;

use Rafoo\Admin\Page;
use Rafoo\Admin\Rest;

defined( 'ABSPATH' ) || exit;

final class Widget {

	const ACTION = 'rafoo_shield_mode';

	public static function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'rafoo_shield', __( 'Rafoo Shield', 'rafoo' ), array( __CLASS__, 'render' ) );
	}

	public static function names() {
		return array(
			'off'      => __( 'Off', 'rafoo' ),
			'smart'    => __( 'Smart', 'rafoo' ),
			'strict'   => __( 'Strict', 'rafoo' ),
			'fortress' => __( 'Fortress', 'rafoo' ),
		);
	}

	public static function render() {
		$card  = Api::card();
		$names = self::names();
		$mode  = $card['mode'];
		$url   = Page::url() . '#/shield';
		echo '<style>.rafoo-shield-widget{display:grid;gap:12px}.rafoo-shield-widget .rsw-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px}.rafoo-shield-widget .rsw-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px}.rafoo-shield-widget .rsw-stat{padding:10px 12px;border:1px solid #dcdcde;border-radius:6px}.rafoo-shield-widget .rsw-num{display:block;font-size:20px;font-weight:600;line-height:1.4}.rafoo-shield-widget .rsw-label{color:#50575e}.rafoo-shield-widget .rsw-mode{font-weight:600}.rafoo-shield-widget .rsw-dot{display:inline-block;width:8px;height:8px;margin-inline-end:6px;border-radius:50%;background:#00a32a;vertical-align:middle}.rafoo-shield-widget .rsw-off{background:#b32d2e}.rafoo-shield-widget .rsw-fort{background:#dba617}.rafoo-shield-widget form{margin:0}</style>';
		echo '<div class="rafoo-shield-widget">';
		echo '<div class="rsw-row"><span class="rsw-mode"><span class="' . esc_attr( 'rsw-dot' . ( 'off' === $mode ? ' rsw-off' : ( 'fortress' === $mode ? ' rsw-fort' : '' ) ) ) . '" aria-hidden="true"></span>';
		/* translators: %s: shield mode name, for example "Smart" */
		echo esc_html( sprintf( __( 'Mode: %s', 'rafoo' ), isset( $names[ $mode ] ) ? $names[ $mode ] : $mode ) ) . '</span>';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the shield', 'rafoo' ) . '</a></div>';
		echo '<div class="rsw-stats"><div class="rsw-stat"><span class="rsw-num">' . esc_html( number_format_i18n( $card['today'] ) ) . '</span><span class="rsw-label">' . esc_html__( 'Attacks stopped today', 'rafoo' ) . '</span></div>';
		echo '<div class="rsw-stat"><span class="rsw-num">' . esc_html( number_format_i18n( $card['week'] ) ) . '</span><span class="rsw-label">' . esc_html__( 'In the last 7 days', 'rafoo' ) . '</span></div></div>';
		if ( 'off' === $mode ) {
			echo '<p>' . esc_html__( 'The shield is off. Turn it on to stop attacks before they reach WordPress.', 'rafoo' ) . '</p>';
		}
		$next  = 'fortress' === $mode ? 'smart' : 'fortress';
		$label = 'fortress' === $mode ? __( 'Back to smart mode', 'rafoo' ) : __( 'Switch to fortress mode', 'rafoo' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="mode" value="' . esc_attr( $next ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<p class="description">' . esc_html( 'fortress' === $mode ? __( 'Fortress mode asks every new visitor to pass a short browser check. Go back when the attack is over.', 'rafoo' ) : __( 'Under a heavy attack, fortress mode asks every new visitor to pass a short browser check.', 'rafoo' ) ) . '</p>';
		echo '<p><button type="submit" class="button' . ( 'fortress' === $mode ? '' : ' button-primary' ) . '">' . esc_html( $label ) . '</button></p></form>';
		echo '</div>';
	}

	public static function submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change this.', 'rafoo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( in_array( $mode, Modes::ALL, true ) ) {
			Rest::apply( array( 'shield' => $mode ) );
		}
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}
}
