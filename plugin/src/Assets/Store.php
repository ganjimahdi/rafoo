<?php

namespace Rafoo\Assets;

defined( 'ABSPATH' ) || exit;

final class Store {

	const MAX_BYTES = 5242880;

	const FOLDER = 'rafoo';

	const HTACCESS = <<<'CONF'
<FilesMatch "\.(?i:php\d?|phtml|phar|pht|pl|py|cgi|sh|shtml|html?|htaccess)$">
	<IfModule mod_authz_core.c>
		Require all denied
	</IfModule>
	<IfModule !mod_authz_core.c>
		Order deny,allow
		Deny from all
	</IfModule>
</FilesMatch>
<IfModule mod_mime.c>
	AddType text/javascript .js .mjs
	AddType text/css .css
	AddType font/woff2 .woff2
	AddType font/woff .woff
</IfModule>
<IfModule mod_headers.c>
	Header set Cache-Control "public, max-age=31536000, immutable"
	Header set X-Content-Type-Options "nosniff"
	<FilesMatch "\.svg$">
		Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
	</FilesMatch>
</IfModule>
<IfModule mod_expires.c>
	ExpiresActive On
	ExpiresDefault "access plus 1 year"
</IfModule>
CONF;

	private static $base = null;

	private static $fs = null;

	public static function base() {
		if ( null === self::$base ) {
			$uploads    = wp_upload_dir( null, false );
			self::$base = array(
				'dir' => trailingslashit( $uploads['basedir'] ) . self::FOLDER,
				'url' => set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::FOLDER ),
			);
		}
		return self::$base;
	}

	public static function dir( $relative = '' ) {
		return self::base()['dir'] . ( '' === $relative ? '' : '/' . ltrim( $relative, '/' ) );
	}

	public static function url( $relative ) {
		$path = implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $relative, '/' ) ) ) );
		return self::base()['url'] . '/' . str_replace( '%40', '@', $path );
	}

	public static function ensure() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$fs = self::fs();
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$fs->put_contents( $dir . '/.htaccess', self::HTACCESS . "\n", self::mode() );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			$fs->put_contents( $dir . '/index.php', "<?php\n", self::mode() );
		}
		return $fs->is_writable( $dir );
	}

	public static function valid( $relative ) {
		return is_string( $relative ) && Recognizer::allowed_file( $relative ) && 0 !== strpos( $relative, '/' );
	}

	public static function write( $relative, $bytes ) {
		if ( ! self::valid( $relative ) || ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_BYTES ) {
			return false;
		}
		if ( 'svg' === Recognizer::extension( $relative ) && ! self::safe_svg( $bytes ) ) {
			return false;
		}
		if ( ! self::ensure() ) {
			return false;
		}
		$target = self::dir( $relative );
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return false;
		}
		$temp = $target . '.' . wp_generate_password( 8, false ) . '.part';
		$fs   = self::fs();
		if ( ! $fs->put_contents( $temp, $bytes, self::mode() ) ) {
			return false;
		}
		if ( ! $fs->move( $temp, $target, true ) ) {
			wp_delete_file( $temp );
			return false;
		}
		return true;
	}

	public static function private_dir( $name ) {
		$name    = sanitize_key( $name );
		$folders = get_option( 'rafoo_private', array() );
		$folders = is_array( $folders ) ? $folders : array();
		if ( empty( $folders[ $name ] ) || ! preg_match( '/^[a-z0-9]{16}$/', $folders[ $name ] ) ) {
			$folders[ $name ] = strtolower( wp_generate_password( 16, false ) );
			update_option( 'rafoo_private', $folders, false );
		}
		$dir = self::dir( $name . '-' . $folders[ $name ] );
		if ( ! self::ensure() || ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$fs = self::fs();
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$fs->put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n", self::mode() );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			$fs->put_contents( $dir . '/index.php', "<?php\n", self::mode() );
		}
		if ( ! file_exists( $dir . '/web.config' ) ) {
			$fs->put_contents( $dir . '/web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n", self::mode() );
		}
		return $dir;
	}

	public static function exists( $relative ) {
		return self::valid( $relative ) && is_file( self::dir( $relative ) );
	}

	public static function read( $relative ) {
		if ( ! self::exists( $relative ) ) {
			return null;
		}
		$bytes = self::fs()->get_contents( self::dir( $relative ) );
		return is_string( $bytes ) ? $bytes : null;
	}

	public static function hash( $relative ) {
		return self::exists( $relative ) ? hash_file( 'sha256', self::dir( $relative ) ) : '';
	}

	public static function delete( $relative ) {
		if ( self::exists( $relative ) ) {
			wp_delete_file( self::dir( $relative ) );
		}
	}

	public static function wipe() {
		$dir = self::dir();
		if ( is_dir( $dir ) ) {
			self::fs()->rmdir( $dir, true );
		}
	}

	public static function safe_svg( $bytes ) {
		return ! preg_match( '/<\s*script|\bon[a-z]+\s*=|javascript:|<\s*foreignObject|<!ENTITY/i', $bytes );
	}

	public static function flush() {
		self::$base = null;
	}

	public static function mode() {
		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	}

	public static function fs() {
		if ( null === self::$fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			self::$fs = new \WP_Filesystem_Direct( null );
		}
		return self::$fs;
	}
}
