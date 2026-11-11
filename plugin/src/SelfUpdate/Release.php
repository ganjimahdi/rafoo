<?php

namespace Rafoo\SelfUpdate;

use Rafoo\Http\Client;
use Rafoo\Signature;

defined( 'ABSPATH' ) || exit;

final class Release {

	const BASE = 'https://rafoo.ir';

	const CACHE = 'rafoo_release';

	const SEEN = 'rafoo_release_seen';

	const TTL = 21600;

	const VERSION = '/^\d+\.\d+\.\d+$/';

	public static function register() {
		add_filter( 'update_plugins_rafoo.ir', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 9, 2 );
		Details::register();
	}

	public static function slug() {
		return 'complete' === RAFOO_EDITION ? 'rafoo-complete' : 'rafoo';
	}

	public static function url() {
		return self::BASE . '/api/v1/releases/' . self::slug() . '.json';
	}

	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( RAFOO_FILE ) !== $plugin_file || ! Signature::available() ) {
			return $update;
		}
		$release = self::latest();
		return $release ? self::offer( $release ) : $update;
	}

	public static function offer( array $release ) {
		return array(
			'id'           => self::BASE . '/' . self::slug(),
			'slug'         => self::slug(),
			'version'      => $release['version'],
			'url'          => self::BASE,
			'package'      => Downloader::package_url( self::BASE, $release['file'], $release['sha256'], $release['size'] ),
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => self::tested( $release['tested'] ),
			'icons'        => Details::images()['icons'],
			'banners'      => Details::images()['banners'],
			'banners_rtl'  => array(),
		);
	}

	public static function tested( $tested, $current = null ) {
		$tested  = (string) $tested;
		$current = (string) strtok( null === $current ? (string) get_bloginfo( 'version' ) : (string) $current, '-' );
		if ( ! preg_match( '/^\d+\.\d+$/', $tested ) || ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $current ) ) {
			return $tested;
		}
		$branch = implode( '.', array_slice( explode( '.', $current ), 0, 2 ) );
		return $branch === $tested ? $current : $tested;
	}

	public static function download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::BASE . '/downloads/' ) ) {
			return $reply;
		}
		$signed = Downloader::parse( $package );
		return $signed ? Downloader::fetch( $signed ) : $reply;
	}

	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$body    = Client::body( self::url(), array( 'timeout' => 8 ), 65536 );
		$data    = is_string( $body ) ? Signature::open( $body ) : null;
		$release = self::valid( $data ) ? array(
			'version'      => (string) $data['version'],
			'file'         => (string) $data['file'],
			'sha256'       => strtolower( (string) $data['sha256'] ),
			'size'         => isset( $data['size'] ) ? (int) $data['size'] : 0,
			'requires'     => isset( $data['requires'] ) ? (string) $data['requires'] : '',
			'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
			'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '',
		) : array();
		set_site_transient( self::CACHE, $release, $release ? self::TTL : HOUR_IN_SECONDS );
		return $release ? $release : null;
	}

	public static function wanted( $versions ) {
		$slug = self::slug();
		if ( ! is_array( $versions ) || ! isset( $versions[ $slug ] ) || ! is_string( $versions[ $slug ] ) || ! preg_match( self::VERSION, $versions[ $slug ] ) ) {
			return '';
		}
		$version = $versions[ $slug ];
		return version_compare( $version, RAFOO_VERSION, '>' ) && get_site_option( self::SEEN ) !== $version ? $version : '';
	}

	public static function announce( $versions ) {
		$version = self::wanted( $versions );
		if ( '' === $version ) {
			return false;
		}
		update_site_option( self::SEEN, $version );
		delete_site_transient( self::CACHE );
		Details::forget();
		$release = self::latest();
		if ( ! $release || version_compare( $release['version'], RAFOO_VERSION, '<=' ) ) {
			return false;
		}
		$current = self::inject( get_site_transient( 'update_plugins' ), $release, plugin_basename( RAFOO_FILE ) );
		if ( $current ) {
			set_site_transient( 'update_plugins', $current );
		}
		return true;
	}

	public static function inject( $current, array $release, $file ) {
		if ( ! is_object( $current ) ) {
			return null;
		}
		$offer              = (object) self::offer( $release );
		$offer->plugin      = $file;
		$offer->new_version = $release['version'];
		$current->response  = isset( $current->response ) && is_array( $current->response ) ? $current->response : array();
		$current->no_update = isset( $current->no_update ) && is_array( $current->no_update ) ? $current->no_update : array();

		$current->response[ $file ] = $offer;
		unset( $current->no_update[ $file ] );
		return $current;
	}

	private static function valid( $data ) {
		return is_array( $data )
			&& isset( $data['slug'], $data['version'], $data['file'], $data['sha256'] )
			&& self::slug() === $data['slug']
			&& preg_match( self::VERSION, (string) $data['version'] )
			&& preg_match( '#^[a-z0-9/_.\-]+\.zip$#', (string) $data['file'] )
			&& preg_match( '/^[a-f0-9]{64}$/i', (string) $data['sha256'] );
	}
}
