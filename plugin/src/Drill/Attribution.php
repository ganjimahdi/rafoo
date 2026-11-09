<?php

namespace Rafoo\Drill;

use Rafoo\Http\Log;

defined( 'ABSPATH' ) || exit;

final class Attribution {

	const BUDGET = 4.0;

	const MAX_FILES = 25000;

	const MAX_SIZE = 2097152;

	const HOSTS = 20;

	const EXTENSIONS = array( 'php', 'js', 'mjs', 'css', 'json', 'html', 'htm', 'twig', 'tpl' );

	const SKIP = array( 'node_modules', '.git', '.svn', 'tests', 'test', 'languages', 'lang', 'docs' );

	public static function find( array $hosts, $budget = self::BUDGET ) {
		$hosts = array_slice( array_values( array_unique( array_filter( array_map( 'strval', $hosts ) ) ) ), 0, self::HOSTS );
		$found = array();
		if ( ! $hosts ) {
			return $found;
		}
		$deadline = microtime( true ) + $budget;
		$files    = 0;
		foreach ( self::roots() as $origin => $root ) {
			if ( microtime( true ) >= $deadline || $files >= self::MAX_FILES ) {
				break;
			}
			$left = $hosts;
			foreach ( self::files( $root ) as $file ) {
				if ( ++$files >= self::MAX_FILES || microtime( true ) >= $deadline || ! $left ) {
					break;
				}
				$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are skipped.
				if ( $size <= 0 || $size > self::MAX_SIZE ) {
					continue;
				}
				$body = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads local plugin and theme files to find who loads a host.
				foreach ( $left as $index => $host ) {
					if ( false !== stripos( $body, $host ) ) {
						$found[ $host ][] = $origin;
						unset( $left[ $index ] );
					}
				}
			}
		}
		return $found;
	}

	public static function needle( array $issue ) {
		if ( 'library' === $issue['cat'] ) {
			$path    = (string) wp_parse_url( 'https://' . $issue['subject'], PHP_URL_PATH );
			$version = isset( $issue['meta']['version'] ) ? (string) $issue['meta']['version'] : '';
			$at      = '' !== $version ? strpos( $path, $version ) : false;
			return false === $at ? $path : substr( $path, 0, $at + strlen( $version ) );
		}
		return empty( $issue['meta']['host'] ) ? '' : (string) $issue['meta']['host'];
	}

	public static function owners( array $search ) {
		$found = self::find( array_values( $search ) );
		$out   = array();
		foreach ( $search as $id => $needle ) {
			if ( isset( $found[ $needle ] ) ) {
				$out[ $id ] = $found[ $needle ];
			}
		}
		return $out;
	}

	public static function roots() {
		$roots   = array();
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( array_unique( $plugins ) as $file ) {
			$file = (string) $file;
			$dir  = dirname( $file );
			if ( '.' === $dir ) {
				$path = WP_PLUGIN_DIR . '/' . $file;
				$slug = preg_replace( '/\.php$/', '', $file );
			} else {
				$path = WP_PLUGIN_DIR . '/' . $dir;
				$slug = $dir;
			}
			if ( 'rafoo' === $slug || 'rafoo-complete' === $slug || ! file_exists( $path ) ) {
				continue;
			}
			$roots[ 'plugin:' . sanitize_key( $slug ) ] = $path;
		}
		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $slug ) {
			$path = get_theme_root( $slug ) . '/' . $slug;
			if ( is_dir( $path ) ) {
				$roots[ 'theme:' . sanitize_key( $slug ) ] = $path;
			}
		}
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			foreach ( (array) glob( WPMU_PLUGIN_DIR . '/*.php' ) as $file ) {
				$roots[ 'mu:' . sanitize_key( basename( $file, '.php' ) ) ] = $file;
			}
		}
		return $roots;
	}

	public static function names( array $origins ) {
		$names = Log::names();
		$out   = array();
		foreach ( array_unique( $origins ) as $origin ) {
			$parts          = explode( ':', $origin, 2 );
			$out[ $origin ] = isset( $names[ $origin ] ) ? $names[ $origin ] : ( isset( $parts[1] ) ? $parts[1] : $origin );
		}
		return $out;
	}

	private static function files( $root ) {
		if ( is_file( $root ) ) {
			yield $root;
			return;
		}
		$stack = array( $root );
		while ( $stack ) {
			$dir     = array_pop( $stack );
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable folders are skipped.
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_dir( $path ) ) {
					if ( ! in_array( strtolower( $entry ), self::SKIP, true ) && ! is_link( $path ) ) {
						$stack[] = $path;
					}
					continue;
				}
				if ( in_array( strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true ) ) {
					yield $path;
				}
			}
		}
	}
}
