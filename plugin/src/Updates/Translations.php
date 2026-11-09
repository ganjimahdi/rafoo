<?php

namespace Rafoo\Updates;

use Rafoo\SelfUpdate\Downloader;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Translations {

	const LOCALE = '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/';

	const FILE = '#^translation/(?:core|plugin|theme)/(?:[A-Za-z0-9._\-]+/)*[A-Za-z0-9._\-]+\.zip$#';

	public static function enabled() {
		return (bool) Settings::get( 'translations' );
	}

	public static function locales( $body ) {
		$sent    = is_array( $body ) && isset( $body['locale'] ) ? $body['locale'] : null;
		$decoded = is_string( $sent ) ? json_decode( $sent, true ) : $sent;
		$list    = is_array( $decoded ) ? $decoded : get_available_languages();
		$out     = array();
		foreach ( $list as $locale ) {
			$locale = (string) $locale;
			if ( 'en_US' !== $locale && preg_match( self::LOCALE, $locale ) ) {
				$out[] = $locale;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function installed( $body ) {
		$sent    = is_array( $body ) && isset( $body['translations'] ) ? $body['translations'] : null;
		$decoded = is_string( $sent ) ? json_decode( $sent, true ) : $sent;
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function pick( array $index, $type, $slug, $version, array $locales, array $installed ) {
		if ( ! self::enabled() || ! $locales || empty( $index['translations'] ) || ! is_array( $index['translations'] ) ) {
			return array();
		}
		$best = array();
		foreach ( $index['translations'] as $entry ) {
			if ( ! self::usable( $entry ) || ! in_array( $entry['language'], $locales, true ) ) {
				continue;
			}
			if ( '' !== (string) $version && version_compare( (string) $entry['version'], (string) $version, '>' ) ) {
				continue;
			}
			$current = isset( $best[ $entry['language'] ] ) ? $best[ $entry['language'] ] : null;
			if ( null === $current || version_compare( (string) $entry['version'], (string) $current['version'], '>' ) || ( (string) $entry['version'] === (string) $current['version'] && strtotime( $entry['updated'] ) > strtotime( $current['updated'] ) ) ) {
				$best[ $entry['language'] ] = $entry;
			}
		}
		$bases = Settings::get( 'wp_mirrors' );
		$key   = 'core' === $type ? 'default' : $slug;
		$out   = array();
		foreach ( $best as $locale => $entry ) {
			$have = isset( $installed[ $key ][ $locale ]['PO-Revision-Date'] ) ? strtotime( (string) $installed[ $key ][ $locale ]['PO-Revision-Date'] ) : false;
			if ( false !== $have && $have >= strtotime( $entry['updated'] ) ) {
				continue;
			}
			$out[] = array(
				'type'       => $type,
				'slug'       => 'core' === $type ? 'default' : $slug,
				'language'   => $locale,
				'version'    => (string) $entry['version'],
				'updated'    => gmdate( 'Y-m-d H:i:s', strtotime( $entry['updated'] ) ),
				'package'    => Downloader::package_url( $bases[0], $entry['file'], strtolower( $entry['sha256'] ), isset( $entry['size'] ) ? (int) $entry['size'] : 0 ),
				'autoupdate' => true,
			);
		}
		return $out;
	}

	private static function usable( $entry ) {
		return is_array( $entry )
			&& isset( $entry['language'], $entry['version'], $entry['updated'], $entry['file'], $entry['sha256'] )
			&& preg_match( self::LOCALE, (string) $entry['language'] )
			&& preg_match( '/^[0-9][0-9A-Za-z.\-]*$/', (string) $entry['version'] )
			&& false !== strtotime( (string) $entry['updated'] )
			&& false === strpos( (string) $entry['file'], '..' )
			&& preg_match( self::FILE, (string) $entry['file'] )
			&& preg_match( '/^[a-f0-9]{64}$/i', (string) $entry['sha256'] );
	}
}
