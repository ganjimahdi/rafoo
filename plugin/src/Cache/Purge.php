<?php

namespace Rafoo\Cache;

defined( 'ABSPATH' ) || exit;

final class Purge {

	private static $pending = false;

	public static function later() {
		if ( ! self::$pending ) {
			self::$pending = true;
			add_action( 'shutdown', array( __CLASS__, 'all' ), 1 );
		}
	}

	public static function all() {
		self::$pending = false;
		$purged        = array();

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'wp-rocket';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$purged[] = 'litespeed';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'w3-total-cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'wp-super-cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$purged[] = 'siteground';
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
			$purged[] = 'wp-fastest-cache';
		}
		if ( class_exists( '\autoptimizeCache' ) && method_exists( '\autoptimizeCache', 'clearall' ) ) {
			\autoptimizeCache::clearall();
			$purged[] = 'autoptimize';
		}
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$purged[] = 'breeze';
		}
		do_action( 'rafoo_purged', $purged );
		return $purged;
	}
}
