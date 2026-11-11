<?php

namespace Rafoo\Shield;

use Rafoo\Peers\Api as Remote;
use Rafoo\Peers\Keys;
use Rafoo\Settings;
use Rafoo\Signature;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Intel {

	const OUTBOX = 'rafoo_shield_outbox';

	const LIST_FILE = 'feed.json';

	const REPORT_EVERY = 600;

	const FEED_EVERY = 900;

	const MAX_REPORT = 500;

	const MAX_FEED = 20000;

	public static function needs_key( $needed ) {
		return $needed || ( Settings::get( 'shield_network' ) && ! empty( Local::network()['joined'] ) );
	}

	public static function joined() {
		return ! empty( Local::network()['joined'] );
	}

	public static function join() {
		if ( ! Keys::ensure() ) {
			return new \WP_Error( 'rafoo_shield_sodium', __( 'This server cannot create a signing key (the sodium extension is missing).', 'rafoo' ) );
		}
		$result = Remote::call(
			'join',
			array(
				'url'     => home_url( '/' ),
				'edition' => RAFOO_EDITION,
				'version' => RAFOO_VERSION,
				'mode'    => Config::mode(),
			),
			25,
			false,
			'shield'
		);
		if ( is_wp_error( $result ) ) {
			Local::save_network( array( 'error' => $result->get_error_message() ) );
			return $result;
		}
		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return new \WP_Error( 'rafoo_shield_answer', __( 'rafoo.ir gave an answer that could not be read.', 'rafoo' ) );
		}
		Local::save_network(
			array(
				'joined' => time(),
				'member' => (int) $data['id'],
				'error'  => '',
				'rev'    => 0,
			)
		);
		State::event( 'shield_network_joined' );
		self::pull( true );
		Config::compile();
		return true;
	}

	public static function leave() {
		if ( self::joined() ) {
			Remote::call( 'leave', array(), 8, false, 'shield' );
		}
		delete_option( Local::NETWORK );
		delete_option( self::OUTBOX );
		$dir = Config::dir();
		foreach ( array( self::LIST_FILE, Config::FEED ) as $name ) {
			if ( '' !== $dir && is_file( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
		Config::compile();
		return true;
	}

	public static function tick( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! self::joined() ) {
			if ( empty( Local::network()['error'] ) || $now - (int) Local::network()['checked'] > HOUR_IN_SECONDS ) {
				Local::save_network( array( 'checked' => $now ) );
				self::join();
			}
			return;
		}
		$net = Local::network();
		if ( $now - (int) $net['reported'] >= self::REPORT_EVERY ) {
			self::report( $now );
		}
		$behind = (int) $net['remote'] > (int) $net['rev'];
		if ( $behind || $now - (int) $net['checked'] >= self::FEED_EVERY ) {
			self::pull( false, $now );
		}
	}

	public static function signal( $body ) {
		$data = Signature::open( $body );
		if ( ! is_array( $data ) || ! isset( $data['shield'] ) || ! self::joined() ) {
			return false;
		}
		$rev = (int) $data['shield'];
		if ( $rev > (int) Local::network()['rev'] ) {
			Local::save_network( array( 'remote' => $rev ) );
			return true;
		}
		return false;
	}

	public static function queue( array $rows ) {
		$box    = get_option( self::OUTBOX, array() );
		$box    = is_array( $box ) ? $box : array();
		$admins = (array) Local::get( 'admins' );
		$allow  = (array) Local::get( 'allow' );
		foreach ( $rows as $row ) {
			$ip    = isset( $row['i'] ) ? (string) $row['i'] : '';
			$group = isset( $row['g'] ) ? sanitize_key( $row['g'] ) : '';
			if ( ! Ip::valid( $ip ) || Ip::local( $ip ) || isset( $admins[ $ip ] ) || Ip::any( $ip, $allow ) ) {
				continue;
			}
			if ( ! empty( $row['k'] ) || in_array( $group, array( 'network', 'list', 'reputation', 'challenge', 'fakebot', '' ), true ) || ! in_array( $row['a'], array( 'block', 'limit', 'lock' ), true ) ) {
				continue;
			}
			if ( ! isset( $box[ $ip ] ) && count( $box ) >= self::MAX_REPORT ) {
				continue;
			}
			$item                = isset( $box[ $ip ] ) ? $box[ $ip ] : array( 'n' => 0, 'g' => array() );
			$item['n']           = (int) $item['n'] + 1;
			$item['t']           = (int) $row['t'];
			$item['g'][ $group ] = 1;
			if ( ! empty( $row['x'] ) ) {
				$item['x'] = sanitize_key( $row['x'] );
			}
			$box[ $ip ] = $item;
		}
		update_option( self::OUTBOX, $box, false );
	}

	public static function report( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$box = get_option( self::OUTBOX, array() );
		Local::save_network( array( 'reported' => $now ) );
		if ( ! is_array( $box ) || ! $box ) {
			return 0;
		}
		$items = array();
		foreach ( array_slice( $box, 0, self::MAX_REPORT, true ) as $ip => $item ) {
			$items[] = array(
				'ip'     => (string) $ip,
				'groups' => array_slice( array_keys( (array) $item['g'] ), 0, 5 ),
				'count'  => min( 100000, (int) $item['n'] ),
				'at'     => (int) $item['t'],
				'plugin' => isset( $item['x'] ) ? (string) $item['x'] : '',
			);
		}
		$result = Remote::call( 'report', array( 'items' => $items ), 10, true, 'shield' );
		if ( is_wp_error( $result ) ) {
			if ( 'rafoo_peer_not_member' === $result->get_error_code() ) {
				Local::save_network( array( 'joined' => 0 ) );
			}
			return 0;
		}
		delete_option( self::OUTBOX );
		Local::save_network( array( 'sent' => (int) Local::network()['sent'] + count( $items ) ) );
		return count( $items );
	}

	public static function pull( $full = false, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$net  = Local::network();
		$list = $full ? array() : self::load();
		$since = $full || ! $list ? 0 : (int) $net['rev'];
		Local::save_network( array( 'checked' => $now ) );
		$result = Remote::call( 'feed', array( 'since' => $since ), 20, true, 'shield' );
		if ( is_wp_error( $result ) ) {
			if ( 'rafoo_peer_not_member' === $result->get_error_code() ) {
				Local::save_network( array( 'joined' => 0 ) );
			}
			Local::save_network( array( 'error' => $result->get_error_message() ) );
			return $result;
		}
		$data = Signature::open( $result['body'] );
		if ( ! is_array( $data ) || 'rafoo-shield-feed' !== ( isset( $data['kind'] ) ? $data['kind'] : '' ) || (int) $data['member'] !== (int) $net['member'] ) {
			return new \WP_Error( 'rafoo_shield_feed', __( 'The list from rafoo.ir could not be verified.', 'rafoo' ) );
		}
		if ( ! empty( $data['full'] ) ) {
			$list = array();
		}
		foreach ( isset( $data['remove'] ) ? (array) $data['remove'] : array() as $ip ) {
			unset( $list[ (string) $ip ] );
		}
		foreach ( isset( $data['add'] ) ? (array) $data['add'] : array() as $row ) {
			if ( is_array( $row ) && 3 === count( $row ) && Ip::valid( (string) $row[0] ) && ! Ip::local( (string) $row[0] ) ) {
				$list[ (string) $row[0] ] = array( 2 === (int) $row[1] ? Feed::CHALLENGE : Feed::BLOCK, (int) $row[2] );
			}
		}
		foreach ( $list as $ip => $row ) {
			if ( (int) $row[1] <= $now ) {
				unset( $list[ $ip ] );
			}
		}
		if ( count( $list ) > self::MAX_FEED ) {
			uasort(
				$list,
				static function ( $a, $b ) {
					return $b[1] - $a[1];
				}
			);
			$list = array_slice( $list, 0, self::MAX_FEED, true );
		}
		self::store( $list );
		$patches = array();
		foreach ( isset( $data['patches'] ) ? (array) $data['patches'] : array() as $patch ) {
			$clean = self::patch( $patch );
			if ( $clean ) {
				$patches[] = $clean;
			}
		}
		$six = array();
		foreach ( $list as $ip => $row ) {
			if ( false !== strpos( $ip, ':' ) && count( $six ) < 2000 ) {
				$six[ $ip ] = $row;
			}
		}
		Local::save_network(
			array(
				'rev'     => (int) $data['rev'],
				'remote'  => max( (int) $data['rev'], (int) $net['remote'] ),
				'count'   => count( $list ),
				'ipv6'    => $six,
				'patches' => $patches,
				'error'   => '',
			)
		);
		Config::compile();
		return true;
	}

	public static function load() {
		$dir  = Config::dir();
		$file = '' !== $dir ? $dir . '/' . self::LIST_FILE : '';
		$data = '' !== $file && is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the shield's own list in the private folder.
		return is_array( $data ) ? $data : array();
	}

	private static function store( array $list ) {
		$dir = Config::dir();
		if ( '' === $dir ) {
			return;
		}
		file_put_contents( $dir . '/' . self::LIST_FILE, wp_json_encode( $list ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the shield's own list in the private folder.
		$temp = $dir . '/' . Config::FEED . '.tmp';
		file_put_contents( $temp, Feed::pack( $list ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary lookup file read by the firewall before WordPress loads.
		rename( $temp, $dir . '/' . Config::FEED ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of the lookup file.
	}

	public static function patch( $patch ) {
		if ( ! is_array( $patch ) || empty( $patch['id'] ) || empty( $patch['plugin'] ) || empty( $patch['when'] ) || ! is_array( $patch['when'] ) ) {
			return null;
		}
		$when = array();
		foreach ( array_slice( $patch['when'], 0, 8 ) as $cond ) {
			if ( ! is_array( $cond ) || ! Patches::valid( $cond ) ) {
				return null;
			}
			$when[] = array(
				'in'    => (string) $cond['in'],
				'key'   => isset( $cond['key'] ) ? substr( (string) $cond['key'], 0, 100 ) : '',
				'op'    => (string) $cond['op'],
				'value' => isset( $cond['value'] ) ? substr( (string) $cond['value'], 0, 500 ) : '',
			);
		}
		return array(
			'id'     => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $patch['id'] ) ),
			'plugin' => sanitize_key( (string) $patch['plugin'] ),
			'below'  => isset( $patch['below'] ) ? substr( (string) $patch['below'], 0, 30 ) : '',
			'from'   => isset( $patch['from'] ) ? substr( (string) $patch['from'], 0, 30 ) : '',
			'title'  => isset( $patch['title'] ) ? sanitize_text_field( (string) $patch['title'] ) : '',
			'when'   => $when,
		);
	}

	public static function versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out    = array();
		$active = array_merge( (array) get_option( 'active_plugins', array() ), is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array() );
		foreach ( get_plugins() as $file => $plugin ) {
			if ( in_array( $file, $active, true ) ) {
				$slug         = sanitize_key( false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ) );
				$out[ $slug ] = isset( $plugin['Version'] ) ? (string) $plugin['Version'] : '';
			}
		}
		return $out;
	}

	public static function active_patches( array $patches ) {
		if ( ! $patches ) {
			return array();
		}
		$installed = self::versions();
		$out       = array();
		foreach ( $patches as $patch ) {
			$slug = isset( $patch['plugin'] ) ? (string) $patch['plugin'] : '';
			if ( ! isset( $installed[ $slug ] ) ) {
				continue;
			}
			$version = $installed[ $slug ];
			if ( '' !== $patch['below'] && ( '' === $version || version_compare( $version, $patch['below'], '>=' ) ) ) {
				continue;
			}
			if ( '' !== $patch['from'] && '' !== $version && version_compare( $version, $patch['from'], '<' ) ) {
				continue;
			}
			$out[] = $patch;
		}
		return $out;
	}
}
