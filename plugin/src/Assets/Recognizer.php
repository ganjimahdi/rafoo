<?php

namespace Rafoo\Assets;

use Rafoo\Data;

defined( 'ABSPATH' ) || exit;

final class Recognizer {

	const EXTENSIONS = array( 'js', 'mjs', 'css', 'map', 'woff2', 'woff', 'ttf', 'otf', 'eot', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'json' );

	private static $by_host = null;

	public static function hosts() {
		return array_keys( self::by_host() );
	}

	public static function needles() {
		$data = Data::get( 'cdn' );
		return isset( $data['needles'] ) ? (array) $data['needles'] : array();
	}

	public static function cdn_of( $host ) {
		$map = self::by_host();
		return isset( $map[ $host ] ) ? $map[ $host ] : '';
	}

	public static function key( $url ) {
		$parts = self::split( $url );
		return $parts ? $parts['host'] . $parts['path'] : '';
	}

	public static function parse( $url ) {
		$parts = self::split( $url );
		if ( ! $parts ) {
			return null;
		}
		$host = $parts['host'];
		$path = $parts['path'];

		switch ( self::cdn_of( $host ) ) {
			case 'jsdelivr':
				$asset = 0 === strpos( $path, '/npm/' ) ? self::npm( substr( $path, 5 ), 'jsdelivr' ) : null;
				break;
			case 'unpkg':
				$asset = self::npm( substr( $path, 1 ), 'unpkg' );
				break;
			case 'cdnjs':
				$asset = self::cdnjs( $path );
				break;
			case 'jquery':
				$asset = self::jquery( $path );
				break;
			case 'google':
				$asset = self::google( $path );
				break;
			case 'bootstrap':
				$asset = self::bootstrap_cdn( $path );
				break;
			case 'fontawesome':
				$asset = self::fontawesome( $path );
				break;
			default:
				$asset = null;
		}

		if ( ! $asset || ! self::allowed_file( $asset['local'] ) ) {
			return null;
		}

		return array_merge(
			$asset,
			array(
				'key'    => $host . $path,
				'origin' => 'https://' . $host . $path,
				'type'   => self::extension( $path ),
			)
		);
	}

	public static function resolve( $base_file, $reference ) {
		$reference = trim( $reference, " \t\n\r\0\x0B\"'" );
		if ( '' === $reference || preg_match( '#^(data:|https?:|//|\#|/)#i', $reference ) ) {
			return '';
		}
		$reference = preg_replace( '/[?#].*$/', '', $reference );
		$segments  = explode( '/', dirname( $base_file ) );
		foreach ( explode( '/', $reference ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( count( $segments ) <= 1 ) {
					return '';
				}
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$path = implode( '/', $segments );
		return self::allowed_file( $path ) ? $path : '';
	}

	public static function allowed_file( $path ) {
		if ( '' === $path || false !== strpos( $path, '..' ) || ! preg_match( '#^[A-Za-z0-9@._~+\-/]+$#', $path ) ) {
			return false;
		}
		return in_array( self::extension( $path ), self::EXTENSIONS, true );
	}

	public static function extension( $path ) {
		return strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	private static function split( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || empty( $parts['path'] ) || ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( '' === self::cdn_of( $host ) ) {
			return null;
		}
		$path = rawurldecode( $parts['path'] );
		if ( false !== strpos( $path, '..' ) || ! preg_match( '#^/[A-Za-z0-9@._~+\-/]+$#', $path ) ) {
			return null;
		}
		return array(
			'host' => $host,
			'path' => $path,
		);
	}

	private static function npm( $spec, $cdn ) {
		if ( ! preg_match( '#^((?:@[a-z0-9][a-z0-9._\-]*/)?[a-z0-9][a-z0-9._\-]*)@(\d+\.\d+\.\d+(?:-[0-9A-Za-z.\-]+)?)/(.+)$#', $spec, $m ) ) {
			return null;
		}
		return self::package( $m[1], $m[2], $m[3], $cdn );
	}

	private static function cdnjs( $path ) {
		if ( ! preg_match( '#^/ajax/libs/([A-Za-z0-9._\-]+)/(\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.\-]+)?)/(.+)$#', $path, $m ) ) {
			return null;
		}
		$table = self::table( 'cdnjs_libs' );
		if ( isset( $table[ $m[1] ] ) ) {
			return self::mapped( $table[ $m[1] ], $m[2], $m[3], 'cdnjs' );
		}
		return array(
			'cdn'    => 'cdnjs',
			'pkg'    => $m[1],
			'ver'    => $m[2],
			'file'   => $m[3],
			'mirror' => '',
			'local'  => 'cdnjs/' . $m[1] . '/' . $m[2] . '/' . $m[3],
		);
	}

	private static function google( $path ) {
		if ( ! preg_match( '#^/ajax/libs/([a-z0-9]+)/(\d+(?:\.\d+){1,3})/(.+)$#', $path, $m ) ) {
			return null;
		}
		$table = self::table( 'google_libs' );
		if ( isset( $table[ $m[1] ] ) ) {
			return self::mapped( $table[ $m[1] ], $m[2], $m[3], 'google' );
		}
		return array(
			'cdn'    => 'google',
			'pkg'    => $m[1],
			'ver'    => $m[2],
			'file'   => $m[3],
			'mirror' => '',
			'local'  => 'google/' . $m[1] . '/' . $m[2] . '/' . $m[3],
		);
	}

	private static function jquery( $path ) {
		if ( preg_match( '#^/jquery-(\d+\.\d+\.\d+)((?:\.slim)?(?:\.min)?)\.(js|map)$#', $path, $m ) ) {
			return self::package( 'jquery', $m[1], 'dist/jquery' . $m[2] . '.' . $m[3], 'jquery' );
		}
		if ( preg_match( '#^/jquery-migrate-(\d+\.\d+\.\d+)((?:\.min)?)\.js$#', $path, $m ) ) {
			return self::package( 'jquery-migrate', $m[1], 'dist/jquery-migrate' . $m[2] . '.js', 'jquery' );
		}
		if ( preg_match( '#^/ui/(\d+\.\d+\.\d+)/(jquery-ui(?:\.min)?\.(?:js|css))$#', $path, $m ) ) {
			return self::package( 'jquery-ui-dist', $m[1], $m[2], 'jquery' );
		}
		if ( preg_match( '#^/ui/(\d+\.\d+\.\d+)/(themes/[a-z0-9\-]+/.+)$#', $path, $m ) ) {
			return self::package( 'jquery-ui', $m[1], 'dist/' . $m[2], 'jquery' );
		}
		return null;
	}

	private static function bootstrap_cdn( $path ) {
		if ( preg_match( '#^/bootstrap/(\d+\.\d+\.\d+)/((?:css|js)/.+)$#', $path, $m ) ) {
			return self::package( 'bootstrap', $m[1], 'dist/' . $m[2], 'bootstrap' );
		}
		if ( preg_match( '#^/font-awesome/(4\.\d+\.\d+)/((?:css|fonts)/.+)$#', $path, $m ) ) {
			return self::package( 'font-awesome', $m[1], $m[2], 'bootstrap' );
		}
		return null;
	}

	private static function fontawesome( $path ) {
		if ( preg_match( '#^/releases/v(\d+\.\d+\.\d+)/((?:css|js|webfonts|svgs)/.+)$#', $path, $m ) ) {
			return self::package( '@fortawesome/fontawesome-free', $m[1], $m[2], 'fontawesome' );
		}
		return null;
	}

	private static function mapped( array $entry, $version, $file, $cdn ) {
		$name = $entry['npm'];
		if ( ! empty( $entry['legacy'] ) ) {
			foreach ( $entry['legacy'] as $below => $legacy ) {
				if ( version_compare( $version, $below, '<' ) ) {
					$name = $legacy;
					break;
				}
			}
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.\-]+)?$/', $version ) ) {
			return null;
		}
		return self::package( $name, $version, $entry['dir'] . $file, $cdn );
	}

	private static function package( $name, $version, $file, $cdn ) {
		$path = 'npm/' . $name . '@' . $version . '/' . $file;
		return array(
			'cdn'    => $cdn,
			'pkg'    => $name,
			'ver'    => $version,
			'file'   => $file,
			'mirror' => $path,
			'local'  => $path,
		);
	}

	private static function table( $name ) {
		$data = Data::get( 'cdn' );
		return isset( $data[ $name ] ) ? (array) $data[ $name ] : array();
	}

	private static function by_host() {
		if ( null === self::$by_host ) {
			self::$by_host = array();
			foreach ( self::table( 'hosts' ) as $cdn => $hosts ) {
				foreach ( (array) $hosts as $host ) {
					self::$by_host[ strtolower( $host ) ] = $cdn;
				}
			}
		}
		return self::$by_host;
	}
}
