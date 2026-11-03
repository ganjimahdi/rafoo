<?php

namespace Rafoo\Updates;

use Rafoo\Mirror\Index;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Source {

	const SLUG = '/^[a-z0-9][a-z0-9_\-]{0,99}$/';

	public static function plugins( $body ) {
		$sent    = self::decode( $body, 'plugins' );
		$plugins = isset( $sent['plugins'] ) && is_array( $sent['plugins'] ) ? $sent['plugins'] : array();
		$updates = array();
		$current = array();
		$locales = Translations::locales( $body );
		$have    = Translations::installed( $body );
		$packs   = array();
		foreach ( $plugins as $file => $headers ) {
			$slug = self::plugin_slug( $file );
			if ( '' === $slug || ( ! empty( $headers['UpdateURI'] ) && ! self::wporg_uri( $headers['UpdateURI'] ) ) ) {
				continue;
			}
			$installed = isset( $headers['Version'] ) ? (string) $headers['Version'] : '';
			$index     = Index::plugin( $slug );
			$best      = $index ? self::best( $index, $installed ) : null;
			$packs     = $index ? array_merge( $packs, Translations::pick( $index, 'plugin', $slug, $installed, $locales, $have ) ) : $packs;
			if ( ! $best ) {
				continue;
			}
			$item = array(
				'id'            => 'w.org/plugins/' . $slug,
				'slug'          => $slug,
				'plugin'        => $file,
				'new_version'   => $best['v'],
				'url'           => 'https://wordpress.org/plugins/' . $slug . '/',
				'package'       => self::package( $best ),
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'requires'      => isset( $best['requires'] ) ? (string) $best['requires'] : '',
				'requires_php'  => isset( $best['requires_php'] ) ? (string) $best['requires_php'] : '',
				'tested'        => isset( $best['tested'] ) ? (string) $best['tested'] : '',
				'compatibility' => array(),
			);
			if ( version_compare( $best['v'], $installed, '>' ) ) {
				$updates[ $file ] = $item;
			} else {
				$current[ $file ] = $item;
			}
		}
		return self::response(
			array(
				'plugins'      => $updates,
				'no_update'    => $current,
				'translations' => $packs,
			)
		);
	}

	public static function themes( $body ) {
		$sent    = self::decode( $body, 'themes' );
		$themes  = isset( $sent['themes'] ) && is_array( $sent['themes'] ) ? $sent['themes'] : array();
		$updates = array();
		$current = array();
		$locales = Translations::locales( $body );
		$have    = Translations::installed( $body );
		$packs   = array();
		foreach ( $themes as $slug => $headers ) {
			$slug = (string) $slug;
			if ( ! preg_match( self::SLUG, $slug ) || ( ! empty( $headers['UpdateURI'] ) && ! self::wporg_uri( $headers['UpdateURI'] ) ) ) {
				continue;
			}
			$installed = isset( $headers['Version'] ) ? (string) $headers['Version'] : '';
			$index     = Index::theme( $slug );
			$best      = $index ? self::best( $index, $installed ) : null;
			$packs     = $index ? array_merge( $packs, Translations::pick( $index, 'theme', $slug, $installed, $locales, $have ) ) : $packs;
			if ( ! $best ) {
				continue;
			}
			$item = array(
				'theme'        => $slug,
				'new_version'  => $best['v'],
				'url'          => 'https://wordpress.org/themes/' . $slug . '/',
				'package'      => self::package( $best ),
				'requires'     => isset( $best['requires'] ) ? (string) $best['requires'] : false,
				'requires_php' => isset( $best['requires_php'] ) ? (string) $best['requires_php'] : false,
			);
			if ( version_compare( $best['v'], $installed, '>' ) ) {
				$updates[ $slug ] = $item;
			} else {
				$current[ $slug ] = $item;
			}
		}
		return self::response(
			array(
				'themes'       => $updates,
				'no_update'    => $current,
				'translations' => $packs,
			)
		);
	}

	public static function core( $body = array() ) {
		global $wp_version;
		$index  = Index::core();
		$offers = $index ? self::core_offers( $index, get_locale(), $wp_version ) : array();
		if ( ! $offers ) {
			return null;
		}
		$packs = Translations::pick( $index, 'core', 'default', $wp_version, Translations::locales( $body ), Translations::installed( $body ) );
		return self::response(
			array(
				'offers'       => $offers,
				'translations' => $packs,
			)
		);
	}

	public static function core_offers( array $index, $locale, $installed ) {
		$local   = 'en_US' !== $locale ? self::newest_core( $index, $locale ) : null;
		$english = self::newest_core( $index, 'en_US' );
		$picks   = array_values( array_filter( array( $local, $english ) ) );
		if ( 2 === count( $picks ) ) {
			$picks = version_compare( $english['v'], $local['v'], '<=' ) ? array( $local ) : array( $english, $local );
		}
		$offers = array();
		foreach ( $picks as $pick ) {
			$offers[] = self::offer( $pick, $installed );
		}
		return $offers;
	}

	public static function core_offer( array $index, $locale, $installed ) {
		$offers = self::core_offers( $index, $locale, $installed );
		return $offers ? $offers[0] : null;
	}

	private static function newest_core( array $index, $locale ) {
		$pick = null;
		foreach ( isset( $index['versions'] ) && is_array( $index['versions'] ) ? $index['versions'] : array() as $entry ) {
			if ( ! self::usable( $entry ) || ! self::compatible( $entry ) || ( isset( $entry['locale'] ) ? $entry['locale'] : 'en_US' ) !== $locale ) {
				continue;
			}
			if ( null === $pick || version_compare( $entry['v'], $pick['v'], '>' ) ) {
				$pick = $entry;
			}
		}
		return $pick;
	}

	private static function offer( array $pick, $installed ) {
		$package = self::package( $pick );
		return array(
			'response'        => version_compare( $pick['v'], $installed, '>' ) ? 'upgrade' : 'latest',
			'download'        => $package,
			'locale'          => isset( $pick['locale'] ) ? $pick['locale'] : 'en_US',
			'packages'        => array(
				'full'        => $package,
				'no_content'  => false,
				'new_bundled' => false,
				'partial'     => false,
				'rollback'    => false,
			),
			'current'         => $pick['v'],
			'version'         => $pick['v'],
			'php_version'     => isset( $pick['requires_php'] ) ? (string) $pick['requires_php'] : '7.2.24',
			'mysql_version'   => isset( $pick['requires_mysql'] ) ? (string) $pick['requires_mysql'] : '5.5.5',
			'new_bundled'     => '6.7',
			'partial_version' => false,
		);
	}

	public static function best( array $index, $installed ) {
		$best = null;
		foreach ( isset( $index['versions'] ) && is_array( $index['versions'] ) ? $index['versions'] : array() as $entry ) {
			if ( ! self::usable( $entry ) || ! self::compatible( $entry ) ) {
				continue;
			}
			if ( null === $best || version_compare( $entry['v'], $best['v'], '>' ) ) {
				$best = $entry;
			}
		}
		if ( $best && '' !== $installed && version_compare( $best['v'], $installed, '<' ) ) {
			return null;
		}
		return $best;
	}

	public static function find( array $index, $version, $locale = '' ) {
		foreach ( isset( $index['versions'] ) && is_array( $index['versions'] ) ? $index['versions'] : array() as $entry ) {
			if ( ! self::usable( $entry ) || (string) $entry['v'] !== (string) $version ) {
				continue;
			}
			if ( '' !== $locale && ( isset( $entry['locale'] ) ? $entry['locale'] : 'en_US' ) !== $locale ) {
				continue;
			}
			return $entry;
		}
		return null;
	}

	public static function package( array $entry ) {
		$bases = Settings::get( 'wp_mirrors' );
		return Downloader::package_url( $bases[0], $entry['file'], strtolower( $entry['sha256'] ), isset( $entry['size'] ) ? (int) $entry['size'] : 0 );
	}

	public static function plugin_slug( $file ) {
		$file = (string) $file;
		$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
		return preg_match( self::SLUG, $slug ) ? $slug : '';
	}

	private static function usable( $entry ) {
		return is_array( $entry )
			&& isset( $entry['v'], $entry['file'], $entry['sha256'] )
			&& preg_match( '/^[0-9][0-9A-Za-z.\-]*$/', (string) $entry['v'] )
			&& preg_match( '#^[a-z]+/[A-Za-z0-9._\-]+\.zip$#', (string) $entry['file'] )
			&& preg_match( '/^[a-f0-9]{64}$/i', (string) $entry['sha256'] );
	}

	private static function compatible( array $entry ) {
		global $wp_version;
		if ( ! empty( $entry['requires_php'] ) && version_compare( PHP_VERSION, (string) $entry['requires_php'], '<' ) ) {
			return false;
		}
		if ( ! empty( $entry['requires'] ) && version_compare( $wp_version, (string) $entry['requires'], '<' ) ) {
			return false;
		}
		return true;
	}

	private static function wporg_uri( $uri ) {
		$host = wp_parse_url( (string) $uri, PHP_URL_HOST );
		return in_array( $host, array( 'w.org', 'wordpress.org' ), true );
	}

	private static function decode( $body, $field ) {
		if ( ! is_array( $body ) || ! isset( $body[ $field ] ) ) {
			return array();
		}
		$decoded = is_string( $body[ $field ] ) ? json_decode( $body[ $field ], true ) : $body[ $field ];
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function response( array $data ) {
		return array(
			'headers'  => array(
				'content-type' => 'application/json',
				'x-rafoo'      => 'mirror',
			),
			'body'     => wp_json_encode( $data ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
