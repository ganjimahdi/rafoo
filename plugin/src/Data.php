<?php

namespace Rafoo;

defined( 'ABSPATH' ) || exit;

final class Data {

	private static $loaded = array();

	public static function get( $name ) {
		if ( ! isset( self::$loaded[ $name ] ) ) {
			$file                  = dirname( __DIR__ ) . '/data/' . basename( $name ) . '.json';
			$data                  = wp_json_file_decode( $file, array( 'associative' => true ) );
			self::$loaded[ $name ] = is_array( $data ) ? $data : array();
		}
		return self::$loaded[ $name ];
	}
}
