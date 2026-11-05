<?php

namespace Rafoo;

use Rafoo\Assets\Library;
use Rafoo\Cache\Purge;

defined( 'ABSPATH' ) || exit;

final class Network {

	const OPTION = 'rafoo_network';

	const REVISION = 'rafoo_network_rev';

	const POLICIES = array( 'defaults', 'enforced' );

	const LOCAL = array( 'telemetry', 'backup', 'backup_hours', 'backup_hour', 'backup_mode', 'backup_local', 'backup_exclude', 'backup_part' );

	private static $cache = null;

	public static function keys() {
		return array_values( array_diff( Settings::EDITABLE, self::LOCAL ) );
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = is_multisite() ? get_site_option( self::OPTION, array() ) : array();
			self::$cache = self::normalize( is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	public static function normalize( array $in ) {
		$policy   = isset( $in['policy'] ) && in_array( $in['policy'], self::POLICIES, true ) ? $in['policy'] : 'defaults';
		$settings = isset( $in['settings'] ) && is_array( $in['settings'] ) ? $in['settings'] : array();
		return array(
			'policy'   => $policy,
			'settings' => $settings ? array_intersect_key( Settings::normalize( $settings ), array_flip( self::keys() ) ) : array(),
			'revision' => isset( $in['revision'] ) ? max( 0, (int) $in['revision'] ) : 0,
		);
	}

	public static function enforced() {
		$all = self::all();
		return 'enforced' === $all['policy'] && ! empty( $all['settings'] );
	}

	public static function settings() {
		return self::all()['settings'];
	}

	public static function view() {
		$all = self::all();
		return array(
			'policy'   => $all['policy'],
			'settings' => array_merge( array_intersect_key( Settings::defaults(), array_flip( self::keys() ) ), $all['settings'] ),
			'keys'     => self::keys(),
			'sites'    => is_multisite() ? (int) get_blog_count() : 1,
		);
	}

	public static function save( $policy, array $settings ) {
		$before = self::all();
		$next   = self::normalize(
			array(
				'policy'   => $policy,
				'settings' => array_merge( $before['settings'], array_intersect_key( $settings, array_flip( self::keys() ) ) ),
			)
		);
		if ( $next['policy'] !== $before['policy'] || $next['settings'] !== $before['settings'] ) {
			$next['revision'] = $before['revision'] + 1;
			update_site_option( self::OPTION, $next );
			State::event( 'network_settings', array( 'policy' => $next['policy'] ) );
		} else {
			$next['revision'] = $before['revision'];
		}
		self::$cache = $next;
		Settings::flush();
		self::sync();
		return self::view();
	}

	public static function sync() {
		if ( ! is_multisite() ) {
			return;
		}
		$revision = self::all()['revision'];
		if ( 0 === $revision || (int) get_option( self::REVISION, 0 ) === $revision ) {
			return;
		}
		update_option( self::REVISION, $revision, true );
		Settings::flush();
		Library::rebuild( Library::records() );
		Purge::later();
	}

	public static function flush() {
		self::$cache = null;
	}
}
