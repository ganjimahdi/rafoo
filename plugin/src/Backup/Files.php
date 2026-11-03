<?php

namespace Rafoo\Backup;

use Rafoo\Assets\Store;

defined( 'ABSPATH' ) || exit;

final class Files {

	const STEP_BYTES = 134217728;

	const STEP_FILES = 3000;

	const STORE_ABOVE = 33554432;

	const STORED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'mp4', 'mp3', 'mov', 'webm', 'woff', 'woff2', 'pdf' );

	const SKIP_DIRS = array( '.git', '.svn', 'node_modules', '.idea' );

	public static function root() {
		return untrailingslashit( wp_normalize_path( ABSPATH ) );
	}

	public static function excluded() {
		$content = self::relative( WP_CONTENT_DIR );
		$uploads = wp_upload_dir( null, false );
		$upload  = self::relative( $uploads['basedir'] );
		$list    = array(
			$upload . '/rafoo',
			$content . '/cache',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/updraft',
			$content . '/ai1wm-backups',
			$content . '/backups-dup-lite',
			$content . '/backups-dup-pro',
			$content . '/wpvividbackups',
			$content . '/backup-db',
			$content . '/backupwordpress',
			$content . '/et-cache',
			$content . '/litespeed',
			$content . '/wflogs',
			$content . '/debug.log',
			$upload . '/backwpup',
			$upload . '/wp-staging',
			$upload . '/cache',
			'error_log',
		);
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'rafoo_backup_exclude', $list ) ) ) );
	}

	public static function relative( $path ) {
		$path = wp_normalize_path( (string) $path );
		$root = self::root();
		return 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : ltrim( $path, '/' );
	}

	public static function start_scan( $list ) {
		if ( false === file_put_contents( $list, '' ) ) {
			return null;
		}
		return array(
			'stack' => array( '' ),
			'files' => 0,
			'bytes' => 0,
		);
	}

	public static function scan( $list, array $job, $deadline ) {
		$root     = self::root();
		$excluded = array_flip( self::excluded() );
		$out      = Ledger::open( $list, 'ab' );
		if ( ! $out ) {
			return false;
		}
		while ( $job['stack'] && microtime( true ) < $deadline ) {
			$relative = array_pop( $job['stack'] );
			$absolute = '' === $relative ? $root : $root . '/' . $relative;
			$entries  = @scandir( $absolute );
			if ( false === $entries ) {
				continue;
			}
			if ( '' !== $relative && in_array( 'wp-config.php', $entries, true ) && in_array( 'wp-includes', $entries, true ) ) {
				continue;
			}
			foreach ( $entries as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = '' === $relative ? $name : $relative . '/' . $name;
				$full = $absolute . '/' . $name;
				if ( isset( $excluded[ $path ] ) || is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					if ( ! in_array( $name, self::SKIP_DIRS, true ) ) {
						$job['stack'][] = $path;
					}
					continue;
				}
				if ( ! is_file( $full ) || ! is_readable( $full ) || false !== strpos( $path, "\t" ) || false !== strpos( $path, "\n" ) ) {
					continue;
				}
				$size = (int) @filesize( $full );
				$out->fwrite( $path . "\t" . $size . "\t" . (int) @filemtime( $full ) . "\n" );
				++$job['files'];
				$job['bytes'] += $size;
			}
		}
		$out         = null;
		$job['done'] = ! $job['stack'];
		return $job;
	}

	public static function manifest( $list ) {
		$map = array();
		$in  = is_file( $list ) ? Ledger::open( $list, 'rb' ) : null;
		if ( ! $in ) {
			return $map;
		}
		foreach ( $in as $line ) {
			$parts = explode( "\t", rtrim( $line, "\n" ) );
			if ( 3 === count( $parts ) ) {
				$map[ $parts[0] ] = $parts[1] . '|' . $parts[2];
			}
		}
		return $map;
	}

	public static function pack( $archive, $list, array $job, $deadline, array $previous = array() ) {
		$in = Ledger::open( $list, 'rb' );
		if ( ! $in ) {
			return false;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive, \ZipArchive::CREATE ) ) {
			return false;
		}
		$in->fseek( $job['offset'] );
		$root        = self::root();
		$bytes       = 0;
		$count       = 0;
		$added       = array();
		$job['done'] = false;
		while ( microtime( true ) < $deadline && $bytes < self::STEP_BYTES && $count < self::STEP_FILES ) {
			$line = $in->eof() ? '' : (string) $in->fgets();
			if ( '' === $line ) {
				$job['done'] = true;
				break;
			}
			$job['offset'] += strlen( $line );
			$parts          = explode( "\t", rtrim( $line, "\n" ) );
			if ( 3 !== count( $parts ) ) {
				continue;
			}
			if ( isset( $previous[ $parts[0] ] ) && $previous[ $parts[0] ] === $parts[1] . '|' . $parts[2] ) {
				continue;
			}
			$full = $root . '/' . $parts[0];
			clearstatcache( true, $full );
			$size = is_readable( $full ) ? (int) @filesize( $full ) : -1;
			if ( $size < 0 ) {
				continue;
			}
			$length = min( $size, (int) $parts[1] );
			$name   = 'files/' . $parts[0];
			if ( ! $zip->addFile( $full, $name, 0, $length > 0 ? $length : 0 ) ) {
				continue;
			}
			$added[ $name ] = array( $full, $length );
			$extension = strtolower( pathinfo( $parts[0], PATHINFO_EXTENSION ) );
			if ( (int) $parts[1] > self::STORE_ABOVE || in_array( $extension, self::STORED, true ) ) {
				$zip->setCompressionName( $name, \ZipArchive::CM_STORE );
			}
			$bytes += (int) $parts[1];
			++$count;
			++$job['packed'];
		}
		$in = null;
		foreach ( $added as $name => $file ) {
			clearstatcache( true, $file[0] );
			if ( ! is_readable( $file[0] ) || (int) @filesize( $file[0] ) < $file[1] ) {
				$zip->deleteName( $name );
				--$job['packed'];
			}
		}
		if ( ! $zip->close() ) {
			return false;
		}
		return $job;
	}

	public static function deleted( array $previous, array $current ) {
		return array_keys( array_diff_key( $previous, $current ) );
	}

	public static function protected_path( $relative ) {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( 'wp-config.php' === $relative || false !== strpos( $relative, '..' ) ) {
			return true;
		}
		$plugins = self::relative( WP_PLUGIN_DIR );
		foreach ( array( $plugins . '/rafoo/', $plugins . '/rafoo-complete/' ) as $own ) {
			if ( 0 === strpos( $relative, $own ) ) {
				return true;
			}
		}
		foreach ( self::excluded() as $path ) {
			if ( $relative === $path || 0 === strpos( $relative, $path . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function extract( $archive, array $job, $deadline ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			return false;
		}
		$root        = self::root();
		$job['done'] = false;
		$total       = $zip->numFiles;
		while ( $job['index'] < $total && microtime( true ) < $deadline ) {
			$name = (string) $zip->getNameIndex( $job['index'] );
			++$job['index'];
			if ( 0 !== strpos( $name, 'files/' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}
			$relative = substr( $name, 6 );
			if ( self::protected_path( $relative ) ) {
				continue;
			}
			$target = $root . '/' . $relative;
			$stat   = $zip->statIndex( $job['index'] - 1 );
			if ( $stat && is_file( $target ) && filesize( $target ) === (int) $stat['size'] && hash_file( 'crc32b', $target ) === sprintf( '%08x', $stat['crc'] ) ) {
				++$job['restored'];
				continue;
			}
			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				$job['errors'][] = $relative;
				continue;
			}
			if ( ! self::copy_out( $archive, $name, $target . '.rafoo-part' ) ) {
				$job['errors'][] = $relative;
				continue;
			}
			if ( ! Ledger::move( $target . '.rafoo-part', $target ) ) {
				wp_delete_file( $target . '.rafoo-part' );
				$job['errors'][] = $relative;
				continue;
			}
			++$job['restored'];
		}
		$job['done'] = $job['index'] >= $total;
		$zip->close();
		return $job;
	}

	public static function copy_out( $archive, $name, $target ) {
		return false === strpos( $archive, '#' ) && Store::fs()->copy( 'zip://' . $archive . '#' . $name, $target, true, Store::mode() );
	}

	public static function remove( array $paths ) {
		$root    = self::root();
		$removed = 0;
		foreach ( $paths as $relative ) {
			if ( ! self::protected_path( $relative ) && is_file( $root . '/' . $relative ) ) {
				wp_delete_file( $root . '/' . $relative );
				++$removed;
			}
		}
		return $removed;
	}
}
