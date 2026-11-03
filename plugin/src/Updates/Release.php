<?php

namespace Rafoo\Updates;

use Rafoo\Http\Client;
use Rafoo\Signature;

defined( 'ABSPATH' ) || exit;

final class Release {

	const URL = 'https://rafoo.ir/api/v1/releases/rafoo-complete.json';

	const CACHE = 'rafoo_release';

	const TTL = 43200;

	public static function register() {
		add_filter( 'update_plugins_rafoo.ir', array( __CLASS__, 'check' ), 10, 3 );
	}

	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( RAFOO_FILE ) !== $plugin_file || ! Signature::available() ) {
			return $update;
		}
		$release = self::latest();
		if ( ! $release || version_compare( $release['version'], RAFOO_VERSION, '<=' ) ) {
			return $update;
		}
		return array(
			'id'           => 'rafoo.ir/rafoo-complete',
			'slug'         => 'rafoo-complete',
			'version'      => $release['version'],
			'url'          => 'https://rafoo.ir',
			'package'      => Downloader::package_url( 'https://rafoo.ir', $release['file'], $release['sha256'], $release['size'] ),
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$body    = Client::body( self::URL, array( 'timeout' => 8 ), 65536 );
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

	private static function valid( $data ) {
		return is_array( $data )
			&& isset( $data['slug'], $data['version'], $data['file'], $data['sha256'] )
			&& 'rafoo-complete' === $data['slug']
			&& preg_match( '/^\d+\.\d+\.\d+$/', (string) $data['version'] )
			&& preg_match( '#^[a-z0-9/_.\-]+\.zip$#', (string) $data['file'] )
			&& preg_match( '/^[a-f0-9]{64}$/i', (string) $data['sha256'] );
	}
}
