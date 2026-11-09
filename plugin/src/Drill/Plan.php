<?php

namespace Rafoo\Drill;

use Rafoo\Assets\Library;
use Rafoo\Connectivity\Monitor;
use Rafoo\Http\Hosts;
use Rafoo\Mail\Queue;
use Rafoo\Plugin;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Plan {

	const KINDS = array( 'home', 'post', 'page', 'shop', 'product', 'cart', 'checkout', 'account', 'login', 'dashboard', 'editor' );

	const CUSTOM = 5;

	public static function candidates() {
		$out  = array();
		$add  = static function ( $kind, $url, $admin = false, $title = '' ) use ( &$out ) {
			if ( is_string( $url ) && '' !== $url ) {
				$out[ $kind ] = array(
					'kind'  => $kind,
					'url'   => $url,
					'admin' => (bool) $admin,
					'title' => (string) $title,
				);
			}
		};
		$add( 'home', home_url( '/' ) );
		$post = self::latest( 'post' );
		if ( $post ) {
			$add( 'post', get_permalink( $post ), false, get_the_title( $post ) );
		}
		$page = self::latest( 'page', self::reserved_pages() );
		if ( $page ) {
			$add( 'page', get_permalink( $page ), false, get_the_title( $page ) );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$add( 'shop', wc_get_page_permalink( 'shop' ) );
			$product = self::latest( 'product' );
			if ( $product ) {
				$add( 'product', get_permalink( $product ), false, get_the_title( $product ) );
			}
			$add( 'cart', wc_get_page_permalink( 'cart' ) );
			$add( 'checkout', wc_get_page_permalink( 'checkout' ) );
			$add( 'account', wc_get_page_permalink( 'myaccount' ) );
		}
		$add( 'login', wp_login_url() );
		$add( 'dashboard', admin_url( 'index.php' ), true );
		if ( current_user_can( 'edit_posts' ) ) {
			$add( 'editor', admin_url( 'post-new.php' ), true );
		}
		foreach ( $out as $kind => $item ) {
			if ( ! self::same_site( $item['url'] ) ) {
				unset( $out[ $kind ] );
			}
		}
		return array_values( $out );
	}

	public static function build( array $kinds, array $custom ) {
		$pages = array();
		$id    = 1;
		$seen  = array();
		foreach ( self::candidates() as $item ) {
			if ( ! in_array( $item['kind'], $kinds, true ) || isset( $seen[ $item['url'] ] ) ) {
				continue;
			}
			$seen[ $item['url'] ] = true;
			$pages[ $id++ ]       = $item;
		}
		foreach ( array_slice( self::custom( $custom ), 0, self::CUSTOM ) as $url ) {
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ]   = true;
			$pages[ $id++ ] = array(
				'kind'  => 'custom',
				'url'   => $url,
				'admin' => false !== strpos( $url, '/wp-admin/' ),
				'title' => '',
			);
		}
		return $pages;
	}

	public static function custom( array $urls ) {
		$out = array();
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				$url = home_url( $url );
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' !== $url && self::same_site( $url ) && false === strpos( $url, 'wp-login.php?action=logout' ) && false === strpos( $url, 'action=logout' ) ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function same_site( $url ) {
		return in_array( Hosts::of( $url ), Hosts::own(), true );
	}

	public static function readiness() {
		$settings = Settings::all();
		$summary  = Library::summary();
		$state    = State::all();
		$late     = ! empty( $state['checked_at'] ) && time() - (int) $state['checked_at'] > 600;
		$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$items    = array(
			self::item( 'paused', ! $settings['paused'], 'blocker', 'resume' ),
			self::item( 'browser_guard', (bool) $settings['browser_guard'], 'warning', 'browser_guard' ),
			self::item( 'replace', 'off' !== $settings['replace'], 'warning', 'replace' ),
			self::item( 'html_scan', (bool) $settings['html_scan'], 'info', 'html_scan' ),
			self::item( 'fonts', (bool) $settings['fonts'], 'info', 'fonts' ),
			self::item( 'pending', 0 === (int) $summary['pending'] + (int) $summary['failed'], 'warning', 'sync', array( 'pending' => (int) $summary['pending'], 'failed' => (int) $summary['failed'] ) ),
			self::item( 'monitor', empty( $state['checked_at'] ) ? false : ! $late, 'warning', '', array( 'cron' => $cron_off, 'checked' => (int) $state['checked_at'] ) ),
			self::item( 'mail_queue', (bool) $settings['mail_queue'] && Queue::enabled(), 'info', 'mail_queue' ),
		);
		if ( Plugin::complete() ) {
			$items[] = self::item( 'updates', 'off' !== $settings['updates'], 'info', 'updates' );
		}
		$items[] = self::item( 'library', ( (int) $summary['ready'] ) > 0 || 0 === count( Library::records() ), 'info', 'scan', array( 'ready' => (int) $summary['ready'] ) );
		if ( State::offline() && 'offline' === $state['detected'] ) {
			$items[] = self::item( 'real', true, 'info', '' );
		}
		return array_values( $items );
	}

	public static function next_check() {
		return (int) wp_next_scheduled( Monitor::HOOK );
	}

	private static function item( $key, $ok, $severity, $fix, array $meta = array() ) {
		return array(
			'key'  => $key,
			'ok'   => (bool) $ok,
			'sev'  => $severity,
			'fix'  => $ok ? '' : $fix,
			'meta' => $meta,
		);
	}

	private static function latest( $type, array $exclude = array() ) {
		if ( ! post_type_exists( $type ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'post__not_in'     => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- at most a handful of reserved page IDs.
				'has_password'     => false,
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function reserved_pages() {
		$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
				$ids[] = (int) wc_get_page_id( $page );
			}
		}
		return array_values( array_filter( $ids, static function ( $id ) {
			return $id > 0;
		} ) );
	}
}
