<?php

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Rafoo\\' ) ) {
			return;
		}
		$file = 0 === strpos( $class_name, 'Rafoo\\Vendor\\' )
			? dirname( __DIR__ ) . '/vendor/' . str_replace( '\\', '/', substr( $class_name, 13 ) ) . '.php'
			: __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, 6 ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
