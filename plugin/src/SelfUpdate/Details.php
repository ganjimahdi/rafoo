<?php

namespace Rafoo\SelfUpdate;

use Rafoo\Http\Client;
use Rafoo\Signature;

defined( 'ABSPATH' ) || exit;

final class Details {

	const CACHE = 'rafoo_release_info_';

	const TTL = 21600;

	public static function register() {
		add_filter( 'plugins_api', array( __CLASS__, 'api' ), 20, 3 );
		add_action( 'admin_head', array( __CLASS__, 'style' ) );
	}

	public static function style() {
		if ( ! is_rtl() || 'fa' !== self::lang() || 'plugin-information' !== filter_input( INPUT_GET, 'tab' ) || Release::slug() !== filter_input( INPUT_GET, 'plugin' ) ) {
			return;
		}
		echo '<style>#plugin-information #section-holder .section{direction:rtl;text-align:right}#plugin-information #section-holder .section ul{margin-left:0;margin-right:2em;padding:0}</style>';
	}

	public static function images() {
		$base = plugins_url( 'assets/brand/', RAFOO_FILE );
		$rtl  = function_exists( 'is_rtl' ) && is_rtl() ? '-rtl' : '';
		return array(
			'icons'   => array(
				'svg' => $base . 'icon.svg',
				'1x'  => $base . 'icon-128x128.png',
				'2x'  => $base . 'icon-256x256.png',
			),
			'banners' => array(
				'low'  => $base . 'banner-772x250' . $rtl . '.png',
				'high' => $base . 'banner-1544x500' . $rtl . '.png',
			),
		);
	}

	public static function api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || Release::slug() !== $args->slug ) {
			return $result;
		}
		return self::info();
	}

	public static function lang() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return 0 === strpos( (string) $locale, 'fa' ) ? 'fa' : 'en';
	}

	public static function remote( $lang ) {
		$key    = self::CACHE . Release::slug() . '_' . $lang;
		$cached = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$body = Client::body( Release::BASE . '/api/v1/releases/' . Release::slug() . '/info?lang=' . $lang, array( 'timeout' => 8 ), 262144 );
		$data = is_string( $body ) ? Signature::open( $body ) : null;
		$ok   = is_array( $data ) && 'rafoo-release-info' === ( isset( $data['kind'] ) ? $data['kind'] : '' ) && Release::slug() === ( isset( $data['slug'] ) ? $data['slug'] : '' ) && isset( $data['version'] ) && preg_match( Release::VERSION, (string) $data['version'] );
		$info = $ok ? $data : array();
		set_site_transient( $key, $info, $info ? self::TTL : 10 * MINUTE_IN_SECONDS );
		return $info;
	}

	public static function forget() {
		foreach ( array( 'fa', 'en' ) as $lang ) {
			delete_site_transient( self::CACHE . Release::slug() . '_' . $lang );
		}
	}

	public static function info() {
		$remote  = self::remote( self::lang() );
		$release = Release::latest();
		$version = ! empty( $remote['version'] ) ? (string) $remote['version'] : ( $release ? $release['version'] : RAFOO_VERSION );
		$images  = self::images();
		$info    = array(
			'name'          => 'complete' === RAFOO_EDITION ? __( 'Rafoo Complete', 'rafoo' ) : __( 'Rafoo', 'rafoo' ),
			'slug'          => Release::slug(),
			'version'       => $version,
			'author'        => '<a href="https://wbweb.ir">Wbweb</a>',
			'homepage'      => Release::BASE,
			'requires'      => self::pick( $remote, $release, 'requires' ),
			'requires_php'  => self::pick( $remote, $release, 'requires_php' ),
			'tested'        => Release::tested( self::pick( $remote, $release, 'tested' ) ),
			'last_updated'  => ! empty( $remote['released'] ) ? gmdate( 'Y-m-d H:i:s', (int) $remote['released'] ) . ' GMT' : '',
			'sections'      => array(
				'description' => self::description(),
				'changelog'   => self::changelog( $remote ),
			),
			'banners'       => $images['banners'],
			'icons'         => $images['icons'],
			'external'      => true,
		);
		if ( $release && $release['version'] === $version ) {
			$info['download_link'] = Downloader::package_url( Release::BASE, $release['file'], $release['sha256'], $release['size'] );
		}
		return (object) $info;
	}

	private static function pick( array $remote, $release, $key ) {
		if ( ! empty( $remote[ $key ] ) && is_string( $remote[ $key ] ) ) {
			return $remote[ $key ];
		}
		return is_array( $release ) && ! empty( $release[ $key ] ) ? (string) $release[ $key ] : '';
	}

	public static function description() {
		$items = array(
			__( 'Rafoo Shield: a firewall that stops attacks before they reach WordPress, with Smart, Strict and Fortress modes and optional community defense.', 'rafoo' ),
			__( 'Libraries and fonts from foreign CDNs are served from your own site, and unreachable outside requests fail at once during an outage.', 'rafoo' ),
			__( 'Outage rehearsal, automatic outage mode, a built-in captcha, safe updates, backups and site transfer.', 'rafoo' ),
			__( 'Monitoring from inside and outside Iran, and full copies on several hosts with Geo DNS.', 'rafoo' ),
		);
		if ( 'complete' === RAFOO_EDITION ) {
			$items[] = __( 'Updates for WordPress, plugins, themes and translations from the signed Iranian mirror.', 'rafoo' );
		}
		$html = '<p>' . esc_html__( 'Rafoo keeps WordPress sites in Iran fast and working when the international internet is cut off, and protects them from attacks.', 'rafoo' ) . '</p><ul>';
		foreach ( $items as $item ) {
			$html .= '<li>' . esc_html( $item ) . '</li>';
		}
		$link = '<a href="' . esc_url( Release::BASE . '/guide' ) . '">rafoo.ir/guide</a>';
		/* translators: %s: link to the Rafoo guide */
		return $html . '</ul><p>' . sprintf( esc_html__( 'Guide, downloads and support: %s', 'rafoo' ), $link ) . '</p>';
	}

	public static function day( $time ) {
		if ( 'fa' === self::lang() && class_exists( 'IntlDateFormatter' ) ) {
			$format = new \IntlDateFormatter( 'fa_IR@calendar=persian', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL );
			$text   = $format->format( (int) $time );
			if ( is_string( $text ) && '' !== $text ) {
				return $text;
			}
		}
		return wp_date( (string) get_option( 'date_format', 'Y-m-d' ), (int) $time );
	}

	public static function changelog( array $remote ) {
		$notes = isset( $remote['notes'] ) && is_array( $remote['notes'] ) ? $remote['notes'] : array();
		$html  = '';
		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) || empty( $note['version'] ) || ! preg_match( Release::VERSION, (string) $note['version'] ) || empty( $note['items'] ) || ! is_array( $note['items'] ) ) {
				continue;
			}
			$time  = ! empty( $note['date'] ) ? strtotime( $note['date'] . ' 12:00:00 UTC' ) : false;
			$html .= '<h4>' . esc_html( $note['version'] ) . ( $time ? ' · ' . esc_html( self::day( $time ) ) : '' ) . '</h4><ul>';
			foreach ( array_slice( $note['items'], 0, 20 ) as $item ) {
				$html .= '<li>' . esc_html( (string) $item ) . '</li>';
			}
			$html .= '</ul>';
		}
		$link = '<a href="' . esc_url( Release::BASE . '/guide#changes' ) . '">rafoo.ir/guide</a>';
		if ( '' === $html ) {
			/* translators: %s: link to the list of changes on rafoo.ir */
			return '<p>' . sprintf( esc_html__( 'The list of changes could not be loaded from rafoo.ir right now. You can read it at %s.', 'rafoo' ), $link ) . '</p>';
		}
		/* translators: %s: link to the list of changes on rafoo.ir */
		return $html . '<p>' . sprintf( esc_html__( 'Changes of older versions: %s', 'rafoo' ), $link ) . '</p>';
	}
}
