<?php

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'RAFOO_SHIELD_RAN' ) ) {
	define( 'RAFOO_SHIELD_RAN', true );
	foreach ( array( 'Ip', 'Request', 'Rules', 'Store', 'Feed', 'Challenge', 'Reply', 'Log', 'Modes', 'Trust', 'Patches', 'Crawler', 'Gate' ) as $rafoo_shield_class ) {
		if ( ! class_exists( 'Rafoo\\Shield\\' . $rafoo_shield_class, false ) ) {
			require_once __DIR__ . '/' . $rafoo_shield_class . '.php';
		}
	}
	$rafoo_shield_file = defined( 'RAFOO_SHIELD' ) && is_string( RAFOO_SHIELD ) ? RAFOO_SHIELD : '';
	if ( '' === $rafoo_shield_file && function_exists( 'get_option' ) ) {
		$rafoo_shield_boot = get_option( 'rafoo_shield_boot', array() );
		$rafoo_shield_file = is_array( $rafoo_shield_boot ) && ! empty( $rafoo_shield_boot['file'] ) ? (string) $rafoo_shield_boot['file'] : '';
	}
	if ( '' !== $rafoo_shield_file && is_file( $rafoo_shield_file ) ) {
		$rafoo_shield_config = include $rafoo_shield_file;
		if ( is_array( $rafoo_shield_config ) ) {
			try {
				Rafoo\Shield\Gate::run( $rafoo_shield_config );
			} catch ( \Throwable $rafoo_shield_error ) {
				unset( $rafoo_shield_error );
			}
		}
	}
	unset( $rafoo_shield_file, $rafoo_shield_boot, $rafoo_shield_config, $rafoo_shield_class );
}
