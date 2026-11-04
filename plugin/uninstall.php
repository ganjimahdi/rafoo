<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$rafoo_editions = array( 'rafoo/rafoo.php', 'rafoo-complete/rafoo-complete.php' );
$rafoo_leaving  = defined( 'WP_UNINSTALL_PLUGIN' ) ? WP_UNINSTALL_PLUGIN : '';

foreach ( $rafoo_editions as $rafoo_edition ) {
	if ( $rafoo_edition === $rafoo_leaving ) {
		continue;
	}
	if ( file_exists( WP_PLUGIN_DIR . '/' . $rafoo_edition ) ) {
		return;
	}
}

$rafoo_site_options = array( 'rafoo_settings', 'rafoo_map', 'rafoo_assets', 'rafoo_telemetry', 'rafoo_requests', 'rafoo_mail_queue', 'rafoo_mail_relay', 'rafoo_rollback', 'rafoo_backup_destinations', 'rafoo_backup_secret', 'rafoo_transfer_invite', 'rafoo_transfer_outgoing', 'rafoo_freeze_until', 'rafoo_peer', 'rafoo_peer_log', 'rafoo_network_rev' );
$rafoo_network      = array( 'rafoo_state', 'rafoo_events', 'rafoo_breaker', 'rafoo_network' );
$rafoo_hooks        = array( 'rafoo_check', 'rafoo_sync', 'rafoo_scan', 'rafoo_daily', 'rafoo_mail', 'rafoo_backup', 'rafoo_backup_tick' );

$rafoo_clean_site = static function () use ( $rafoo_site_options, $rafoo_hooks ) {
	foreach ( $rafoo_site_options as $rafoo_option ) {
		delete_option( $rafoo_option );
	}
	foreach ( $rafoo_hooks as $rafoo_hook ) {
		wp_unschedule_hook( $rafoo_hook );
	}
	delete_transient( 'rafoo_sync_lock' );
	delete_transient( 'rafoo_feedback' );
	delete_transient( 'rafoo_setup_redirect' );
	delete_transient( 'rafoo_mail_down' );
	delete_transient( 'rafoo_mail_lock' );
	global $wpdb;
	foreach ( array( '_transient_rafoo_', '_transient_timeout_rafoo_' ) as $rafoo_prefix ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $rafoo_prefix ) . '%' ) );
	}

	$rafoo_uploads = wp_upload_dir( null, false );
	$rafoo_dir     = trailingslashit( $rafoo_uploads['basedir'] ) . 'rafoo';
	if ( is_dir( $rafoo_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$rafoo_fs = new WP_Filesystem_Direct( null );
		foreach ( (array) scandir( $rafoo_dir ) as $rafoo_item ) {
			if ( '.' !== $rafoo_item && '..' !== $rafoo_item && 0 !== strpos( $rafoo_item, 'backups-' ) ) {
				$rafoo_fs->delete( $rafoo_dir . '/' . $rafoo_item, true );
			}
		}
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $rafoo_site ) {
		switch_to_blog( $rafoo_site );
		$rafoo_clean_site();
		restore_current_blog();
	}
} else {
	$rafoo_clean_site();
}

foreach ( $rafoo_network as $rafoo_option ) {
	delete_site_option( $rafoo_option );
}

global $wpdb;
$rafoo_meta_table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
$rafoo_meta_key   = is_multisite() ? 'meta_key' : 'option_name';
foreach ( array( '_site_transient_rafoo_', '_site_transient_timeout_rafoo_' ) as $rafoo_prefix ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i LIKE %s', $rafoo_meta_table, $rafoo_meta_key, $wpdb->esc_like( $rafoo_prefix ) . '%' ) );
}
