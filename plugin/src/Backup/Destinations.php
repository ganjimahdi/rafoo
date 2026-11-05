<?php

namespace Rafoo\Backup;

use Rafoo\Backup\Remote\Remote;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Destinations {

	const OPTION = 'rafoo_backup_destinations';

	const MAX = 5;

	public static function all() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function add( array $input, array $found ) {
		$all = self::all();
		if ( count( $all ) >= self::MAX ) {
			return null;
		}
		$id          = 'd' . strtolower( wp_generate_password( 8, false ) );
		$all[ $id ]  = array(
			'id'          => $id,
			'label'       => sanitize_text_field( isset( $input['label'] ) && '' !== trim( (string) $input['label'] ) ? $input['label'] : $input['host'] ),
			'type'        => $found['type'],
			'host'        => strtolower( preg_replace( '#^(?:s?ftps?|ssh)://#', '', explode( ':', trim( (string) $input['host'] ) )[0] ) ),
			'port'        => (int) $found['port'],
			'user'        => sanitize_text_field( (string) $input['user'] ),
			'pass'        => isset( $input['pass'] ) && '' !== $input['pass'] ? Vault::seal( (string) $input['pass'] ) : '',
			'key'         => isset( $input['key'] ) && '' !== $input['key'] ? Vault::seal( (string) $input['key'] ) : '',
			'path'        => (string) $found['path'],
			'fingerprint' => (string) $found['fingerprint'],
			'speed'       => (int) $found['speed'],
			'free'        => (int) $found['free'],
			'checked_at'  => time(),
			'last_ok'     => 0,
			'last_error'  => '',
		);
		update_option( self::OPTION, $all, false );
		return $all[ $id ];
	}

	public static function update( $id, array $changes ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ] = array_merge( $all[ $id ], array_intersect_key( $changes, array_flip( array( 'last_ok', 'last_error', 'checked_at', 'speed', 'free' ) ) ) );
		update_option( self::OPTION, $all, false );
	}

	public static function remove( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	public static function label( $id ) {
		$config = self::get( $id );
		if ( ! $config ) {
			return (string) $id;
		}
		return '' !== (string) $config['label'] ? (string) $config['label'] : (string) $config['host'];
	}

	public static function remote( $id ) {
		$config = self::get( $id );
		return $config ? Remote::make( $config ) : null;
	}

	public static function view() {
		$out = array();
		foreach ( self::all() as $item ) {
			$out[] = array(
				'id'         => $item['id'],
				'label'      => $item['label'],
				'type'       => $item['type'],
				'host'       => $item['host'],
				'port'       => $item['port'],
				'user'       => $item['user'],
				'path'       => $item['path'],
				'speed'      => $item['speed'],
				'free'       => $item['free'],
				'plain'      => 'ftp' === $item['type'],
				'last_ok'    => (int) $item['last_ok'],
				'last_error' => (string) $item['last_error'],
			);
		}
		return $out;
	}
}
