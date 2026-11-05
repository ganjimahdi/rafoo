<?php

namespace Rafoo\Geo;

defined( 'ABSPATH' ) || exit;

final class Changes {

	const OPTION = 'rafoo_geo_changes';

	const POKE = 'rafoo_geo_poke';

	const POKE_GAP = 5;

	const SKIP_TABLES = array( 'wc_sessions', 'woocommerce_sessions', 'actionscheduler_logs', 'wc_admin_notes', 'wc_rate_limits', 'wfblockediplog', 'wfcrawlers', 'wfhits', 'wflogins', 'wflivetraffic', 'wfstatus', 'wsal_occurrences', 'wsal_metadata', 'redirection_logs', 'redirection_404', 'yoast_seo_links', 'litespeed_url', 'litespeed_url_file' );

	private static $dirty = array();

	private static $files = false;

	private static $quiet = false;

	private static $watching = false;

	public static function start() {
		if ( self::$watching ) {
			return;
		}
		self::$watching = true;
		add_filter( 'query', array( __CLASS__, 'observe' ), PHP_INT_MAX );
		foreach ( array( 'add_attachment', 'delete_attachment', 'wp_update_attachment_metadata', 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'customize_save_after', 'wp_handle_upload' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'files_changed' ), 99 );
		}
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
	}

	public static function data( $fresh = false ) {
		global $wpdb;
		$data = $fresh ? maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ) ) : get_option( self::OPTION, array() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must read past the request cache while holding the lock.
		return array_merge(
			array(
				'gen'    => 0,
				'tables' => array(),
				'ddl'    => array(),
				'files'  => 0,
				'poked'  => 0,
			),
			is_array( $data ) ? $data : array()
		);
	}

	public static function generation() {
		return (int) self::data()['gen'];
	}

	public static function skipped_table( $table ) {
		global $wpdb;
		$prefix = $wpdb->base_prefix;
		if ( 0 !== strpos( $table, $prefix ) ) {
			return true;
		}
		$name = substr( $table, strlen( $prefix ) );
		if ( 0 === strpos( $name, 'rafoo_' ) || 0 === strpos( $name, 'rfrn_' ) || 0 === strpos( $name, 'rfro_' ) || 0 === strpos( $name, 'rfgs_' ) ) {
			return true;
		}
		return in_array( $name, (array) apply_filters( 'rafoo_geo_skip_tables', self::SKIP_TABLES ), true );
	}

	public static function skipped_option( $name ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' ) ) {
			return true;
		}
		if ( 0 === strpos( $name, 'rafoo_' ) && 'rafoo_settings' !== $name ) {
			return true;
		}
		return in_array( $name, Store::local_options(), true );
	}

	public static function observe( $query ) {
		if ( self::$quiet || ! is_string( $query ) ) {
			return $query;
		}
		$head = strtolower( substr( ltrim( $query ), 0, 8 ) );
		if ( ! preg_match( '/^(insert|update|delete|replace|alter|create|drop|truncat|rename)/', $head, $verb ) ) {
			return $query;
		}
		if ( ! preg_match( '/^\s*(?:INSERT|REPLACE|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|RENAME)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+|INTO\s+|FROM\s+|TABLE\s+|TEMPORARY\s+|IF\s+(?:NOT\s+)?EXISTS\s+)*`?([A-Za-z0-9_$]+)`?/i', $query, $match ) ) {
			return $query;
		}
		$table = $match[1];
		if ( self::skipped_table( $table ) ) {
			return $query;
		}
		global $wpdb;
		if ( $table === $wpdb->options && in_array( $verb[1], array( 'insert', 'update', 'delete', 'replace' ), true ) && self::only_local( $query ) ) {
			return $query;
		}
		$ddl                   = in_array( $verb[1], array( 'alter', 'create', 'drop', 'truncat', 'rename' ), true );
		self::$dirty[ $table ] = ( isset( self::$dirty[ $table ] ) && self::$dirty[ $table ] ) || $ddl;
		return $query;
	}

	private static function only_local( $query ) {
		if ( ! preg_match_all( "/'((?:[^'\\\\]|\\\\.){1,191})'/", $query, $quoted ) ) {
			return false;
		}
		foreach ( $quoted[1] as $value ) {
			if ( preg_match( '/^[A-Za-z0-9_\-.:]+$/', $value ) && self::skipped_option( $value ) ) {
				return true;
			}
		}
		return false;
	}

	public static function files_changed( $value = null ) {
		self::$files = true;
		return $value;
	}

	public static function flush() {
		if ( ! self::$dirty && ! self::$files ) {
			return;
		}
		self::$quiet = true;
		$locked      = self::lock();
		$data        = self::data( true );
		$gen         = (int) $data['gen'] + 1;
		foreach ( self::$dirty as $table => $ddl ) {
			$data['tables'][ $table ] = $gen;
			if ( $ddl ) {
				$data['ddl'][ $table ] = $gen;
			}
		}
		if ( self::$files ) {
			$data['files'] = $gen;
			Lister::soon();
		}
		$data['gen'] = $gen;
		$poke        = time() - (int) $data['poked'] >= self::POKE_GAP;
		if ( $poke ) {
			$data['poked'] = time();
		}
		self::store( $data );
		if ( $locked ) {
			self::unlock();
		}
		self::$dirty = array();
		self::$files = false;
		self::$quiet = false;
		if ( $poke ) {
			self::poke( $gen );
		} elseif ( ! wp_next_scheduled( self::POKE ) ) {
			wp_schedule_single_event( time() + self::POKE_GAP, self::POKE );
		}
	}

	private static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( "SELECT GET_LOCK('rafoo_geo_changes', 3)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock keeps concurrent requests from losing each other's changes.
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->get_var( "SELECT RELEASE_LOCK('rafoo_geo_changes')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
	}

	private static function store( array $data ) {
		wp_cache_delete( self::OPTION, 'options' );
		update_option( self::OPTION, $data, false );
		wp_cache_delete( self::OPTION, 'options' );
	}

	public static function poke( $gen = null ) {
		$gen = null === $gen ? self::generation() : (int) $gen;
		foreach ( Store::others() as $node ) {
			if ( 'ready' === $node['status'] ) {
				Link::send( $node, 'poke', array( 'gen' => $gen ) );
			}
		}
	}

	public static function scheduled_poke() {
		self::$quiet   = true;
		$locked        = self::lock();
		$data          = self::data( true );
		$data['poked'] = time();
		self::store( $data );
		if ( $locked ) {
			self::unlock();
		}
		self::$quiet = false;
		self::poke( (int) $data['gen'] );
	}

	public static function quietly( callable $task ) {
		$was         = self::$quiet;
		self::$quiet = true;
		try {
			return call_user_func( $task );
		} finally {
			self::$quiet = $was;
		}
	}

	public static function since( $gen ) {
		$data   = self::data( true );
		$tables = array();
		foreach ( (array) $data['tables'] as $table => $at ) {
			if ( (int) $at > (int) $gen ) {
				$tables[ $table ] = isset( $data['ddl'][ $table ] ) && (int) $data['ddl'][ $table ] > (int) $gen;
			}
		}
		return array(
			'gen'    => (int) $data['gen'],
			'tables' => $tables,
			'files'  => (int) $data['files'] > (int) $gen,
		);
	}
}
