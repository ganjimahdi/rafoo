<?php

namespace Rafoo\Safety;

use Rafoo\Assets\Store;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Rollback {

	const FOLDER = 'rollback';

	const INDEX = 'rafoo_rollback';

	const KEEP = 2;

	const MAX_BYTES = 314572800;

	const NOTICE = 'rafoo_rolled_back';

	private static $pending = array();

	public static function enabled() {
		return (bool) Settings::get( 'safe_updates' ) && class_exists( 'ZipArchive' );
	}

	public static function before( $response, $extra ) {
		if ( true !== $response || ! self::enabled() || ! is_array( $extra ) ) {
			return $response;
		}
		$item = self::item_from( $extra );
		if ( ! $item || isset( self::$pending[ $item['type'] . ':' . $item['slug'] ] ) ) {
			return $response;
		}
		$saved = self::save( $item['type'], $item['slug'] );
		if ( $saved ) {
			if ( 'plugin' === $item['type'] ) {
				$saved['file']    = $item['file'];
				$saved['active']  = is_plugin_active( $item['file'] );
				$saved['network'] = is_multisite() && is_plugin_active_for_network( $item['file'] );
			}
			self::$pending[ $item['type'] . ':' . $item['slug'] ] = $saved;
		}
		return $response;
	}

	private static function reactivate( array $item ) {
		if ( empty( $item['active'] ) || is_plugin_active( $item['file'] ) || ! is_file( WP_PLUGIN_DIR . '/' . $item['file'] ) ) {
			return;
		}
		if ( ! empty( $item['network'] ) ) {
			$active                  = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active[ $item['file'] ] = time();
			update_site_option( 'active_sitewide_plugins', $active );
			return;
		}
		$active   = (array) get_option( 'active_plugins', array() );
		$active[] = $item['file'];
		update_option( 'active_plugins', array_values( array_unique( $active ) ) );
	}

	public static function after( $upgrader, $extra ) {
		if ( ! self::$pending || ! is_array( $extra ) || ( isset( $extra['action'] ) && 'update' !== $extra['action'] ) ) {
			return;
		}
		$changed = array();
		foreach ( self::$pending as $key => $saved ) {
			$now = self::installed( $saved['type'], $saved['slug'] );
			if ( $now && $now['version'] !== $saved['version'] ) {
				$changed[ $key ] = $saved + array( 'new' => $now['version'] );
				if ( 'plugin' === $saved['type'] ) {
					self::reactivate( $saved );
				}
			}
		}
		self::$pending = array();
		if ( $changed ) {
			self::verify( array_values( $changed ) );
		}
	}

	public static function verify( array $items ) {
		$results = Health::probe();
		if ( Health::healthy( $results ) ) {
			return array( 'state' => 'ok' );
		}
		$reason = Health::summary( $results );
		$fresh  = array();
		$undone = array();
		foreach ( array_reverse( $items ) as $item ) {
			$fresh[ $item['type'] . ':' . $item['slug'] ] = self::snapshot( $item['type'], $item['slug'] );
			if ( ! self::restore( $item['type'], $item['slug'], $item['version'], false ) ) {
				continue;
			}
			$undone[] = $item;
			if ( Health::healthy( Health::probe() ) ) {
				break;
			}
		}
		if ( ! $undone || ! Health::healthy( Health::probe() ) ) {
			foreach ( $undone as $item ) {
				self::put_back( $item, $fresh );
			}
			State::event( 'update_check_unclear', array( 'reason' => $reason ) );
			self::cleanup( $fresh );
			return array( 'state' => 'unclear' );
		}
		$guilty = array_pop( $undone );
		$kept   = array( $guilty );
		foreach ( $undone as $item ) {
			self::put_back( $item, $fresh );
			if ( ! Health::healthy( Health::probe() ) ) {
				self::restore( $item['type'], $item['slug'], $item['version'], false );
				$kept[] = $item;
			}
		}
		self::cleanup( $fresh );
		foreach ( $kept as $item ) {
			State::event(
				'update_rolled_back',
				array(
					'type'   => $item['type'],
					'name'   => $item['name'],
					'from'   => $item['new'],
					'to'     => $item['version'],
					'reason' => $reason,
				)
			);
		}
		set_site_transient( self::NOTICE, wp_list_pluck( $kept, 'name' ), DAY_IN_SECONDS );
		return array(
			'state'  => 'rolled_back',
			'items'  => $kept,
			'reason' => $reason,
		);
	}

	public static function save( $type, $slug, $keep_name = '', $protect = '' ) {
		$item = self::installed( $type, $slug );
		$base = self::folder();
		if ( ! $item || '' === $base || ! is_readable( $item['path'] ) ) {
			return null;
		}
		$size = self::size( $item['path'] );
		if ( $size > (int) apply_filters( 'rafoo_rollback_max_bytes', self::MAX_BYTES ) ) {
			return null;
		}
		$dir = $base . '/' . $type . '/' . $slug;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$name = '' !== $keep_name ? $keep_name : sanitize_file_name( $item['version'] ) . '.zip';
		$file = $dir . '/' . $name;
		if ( ! self::zip( $item['path'], $file ) ) {
			return null;
		}
		$saved = array(
			'type'    => $type,
			'slug'    => $slug,
			'name'    => $item['name'],
			'version' => $item['version'],
			'file'    => $type . '/' . $slug . '/' . $name,
			'size'    => (int) filesize( $file ),
			'at'      => microtime( true ),
		);
		if ( '' === $keep_name ) {
			self::remember( $saved, $protect );
		}
		return $saved;
	}

	public static function restore( $type, $slug, $version, $keep_current = true ) {
		$index = self::index();
		$key   = $type . ':' . $slug;
		if ( empty( $index[ $key ][ $version ] ) ) {
			return false;
		}
		$entry = $index[ $key ][ $version ];
		$file  = self::folder() . '/' . $entry['file'];
		if ( ! is_file( $file ) ) {
			return false;
		}
		$current = self::installed( $type, $slug );
		if ( $keep_current && $current && $current['version'] !== $version ) {
			self::save( $type, $slug, '', $version );
		}
		$ok = self::unpack( $file, self::root( $type ), $slug );
		if ( $ok && $keep_current ) {
			State::event(
				'update_restored',
				array(
					'type' => $type,
					'name' => $entry['name'],
					'to'   => $version,
				)
			);
		}
		self::clear_caches( $type );
		return $ok;
	}

	public static function listing() {
		$out = array();
		foreach ( self::index() as $key => $versions ) {
			list( $type, $slug ) = explode( ':', $key, 2 );
			$current             = self::installed( $type, $slug );
			$list                = array();
			foreach ( $versions as $version => $entry ) {
				if ( ! is_file( self::folder() . '/' . $entry['file'] ) ) {
					continue;
				}
				$list[] = array(
					'version' => (string) $version,
					'size'    => (int) $entry['size'],
					'at'      => (int) $entry['at'],
				);
			}
			if ( ! $list ) {
				continue;
			}
			usort(
				$list,
				static function ( $a, $b ) {
					return $b['at'] <=> $a['at'];
				}
			);
			$out[] = array(
				'type'      => $type,
				'slug'      => $slug,
				'name'      => $current ? $current['name'] : reset( $versions )['name'],
				'installed' => $current ? $current['version'] : '',
				'versions'  => $list,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	public static function installed( $type, $slug ) {
		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			wp_clean_plugins_cache( false );
			foreach ( get_plugins() as $file => $data ) {
				$folder = false !== strpos( $file, '/' ) ? dirname( $file ) : '';
				if ( ( '' !== $folder && $folder === $slug ) || ( '' === $folder && preg_replace( '/\.php$/', '', $file ) === $slug ) ) {
					return array(
						'name'    => $data['Name'],
						'version' => (string) $data['Version'],
						'path'    => WP_PLUGIN_DIR . '/' . ( '' !== $folder ? $folder : $file ),
					);
				}
			}
			return null;
		}
		if ( 'theme' === $type ) {
			wp_clean_themes_cache( false );
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				return null;
			}
			return array(
				'name'    => $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'path'    => $theme->get_stylesheet_directory(),
			);
		}
		return null;
	}

	public static function notice() {
		$names = get_site_transient( self::NOTICE );
		if ( ! is_array( $names ) || ! $names || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: list of plugin or theme names */
					__( 'Rafoo put back the previous version of %s because the update broke the site. Details are on the Rafoo page.', 'rafoo' ),
					wp_sprintf( '%l', array_map( 'strval', $names ) )
				)
			)
		);
	}

	public static function screens() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
	}

	private static function item_from( array $extra ) {
		if ( ! empty( $extra['plugin'] ) && is_string( $extra['plugin'] ) ) {
			$file = $extra['plugin'];
			$slug = false !== strpos( $file, '/' ) ? dirname( $file ) : preg_replace( '/\.php$/', '', $file );
			return array(
				'type' => 'plugin',
				'slug' => $slug,
				'file' => $file,
			);
		}
		if ( ! empty( $extra['theme'] ) && is_string( $extra['theme'] ) ) {
			return array(
				'type' => 'theme',
				'slug' => $extra['theme'],
			);
		}
		return null;
	}

	private static function remember( array $saved, $protect = '' ) {
		$index = self::index();
		$key   = $saved['type'] . ':' . $saved['slug'];
		$list  = isset( $index[ $key ] ) ? $index[ $key ] : array();
		unset( $list[ $saved['version'] ] );
		$list[ $saved['version'] ] = $saved;
		uasort(
			$list,
			static function ( $a, $b ) {
				return $b['at'] <=> $a['at'];
			}
		);
		foreach ( array_slice( $list, self::KEEP, null, true ) as $version => $old ) {
			if ( (string) $version === (string) $protect ) {
				continue;
			}
			wp_delete_file( self::folder() . '/' . $old['file'] );
			unset( $list[ $version ] );
		}
		$index[ $key ] = $list;
		update_option( self::INDEX, $index, false );
	}

	private static function index() {
		$stored = get_option( self::INDEX, array() );
		return is_array( $stored ) ? $stored : array();
	}

	private static function folder() {
		return Store::private_dir( self::FOLDER );
	}

	private static function root( $type ) {
		return 'theme' === $type ? get_theme_root() : WP_PLUGIN_DIR;
	}

	private static function snapshot( $type, $slug ) {
		$item = self::installed( $type, $slug );
		if ( ! $item ) {
			return '';
		}
		$file = self::folder() . '/' . $type . '/' . $slug . '/next-' . wp_generate_password( 8, false ) . '.zip';
		return wp_mkdir_p( dirname( $file ) ) && self::zip( $item['path'], $file ) ? $file : '';
	}

	private static function put_back( array $item, array $fresh ) {
		$key = $item['type'] . ':' . $item['slug'];
		if ( ! empty( $fresh[ $key ] ) && is_file( $fresh[ $key ] ) ) {
			self::unpack( $fresh[ $key ], self::root( $item['type'] ), $item['slug'] );
			self::clear_caches( $item['type'] );
		}
	}

	private static function cleanup( array $fresh ) {
		foreach ( $fresh as $file ) {
			if ( '' !== $file ) {
				wp_delete_file( $file );
			}
		}
	}

	public static function zip( $source, $target ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$source = untrailingslashit( wp_normalize_path( $source ) );
		$parent = dirname( $source );
		if ( is_file( $source ) ) {
			$zip->addFile( $source, basename( $source ) );
			return $zip->close();
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isLink() ) {
				continue;
			}
			$path     = wp_normalize_path( $file->getPathname() );
			$relative = substr( $path, strlen( $parent ) + 1 );
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $relative );
			} elseif ( $file->isReadable() ) {
				$zip->addFile( $path, $relative );
			}
		}
		return $zip->close();
	}

	public static function unpack( $archive, $root, $slug ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			return false;
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) || ( 0 !== strpos( $name, $slug . '/' ) && $name !== $slug . '.php' ) ) {
				$zip->close();
				return false;
			}
		}
		$temp = $root . '/.rafoo-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $temp ) || ! $zip->extractTo( $temp ) ) {
			$zip->close();
			Store::fs()->rmdir( $temp, true );
			return false;
		}
		$zip->close();
		$single = is_file( $temp . '/' . $slug . '.php' ) && ! is_dir( $temp . '/' . $slug );
		$from   = $temp . '/' . ( $single ? $slug . '.php' : $slug );
		$to     = $root . '/' . ( $single ? $slug . '.php' : $slug );
		$old    = $root . '/.rafoo-old-' . wp_generate_password( 8, false );
		$fs     = Store::fs();
		if ( file_exists( $to ) && ! $fs->move( $to, $old ) ) {
			$fs->rmdir( $temp, true );
			return false;
		}
		if ( ! $fs->move( $from, $to ) ) {
			if ( file_exists( $old ) ) {
				$fs->move( $old, $to );
			}
			$fs->rmdir( $temp, true );
			return false;
		}
		$fs->rmdir( $temp, true );
		if ( file_exists( $old ) ) {
			$fs->delete( $old, true );
		}
		self::forget_opcache( $to );
		return true;
	}

	private static function forget_opcache( $path ) {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		if ( is_file( $path ) ) {
			@opcache_invalidate( $path, true );
			return;
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				@opcache_invalidate( $file->getPathname(), true );
			}
		}
	}

	private static function size( $path ) {
		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}
		$total = 0;
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$total += $file->getSize();
			}
		}
		return $total;
	}

	private static function clear_caches( $type ) {
		if ( 'theme' === $type ) {
			wp_clean_themes_cache( false );
			return;
		}
		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
	}
}
