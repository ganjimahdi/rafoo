<?php

namespace Rafoo\Backup;

use Rafoo\Assets\Store;
use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Files {

	const HEADER = "#rafoo-list 2\n";

	const STORED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'mp4', 'm4v', 'mkv', 'mov', 'avi', 'webm', 'mp3', 'm4a', 'aac', 'ogg', 'opus', 'flac', 'woff', 'woff2', 'pdf', 'jar', 'apk', 'wpress', 'rfb', 'rfa' );

	const SKIP_DIRS = array( '.git', '.svn', '.hg', 'node_modules', '.idea', '.vscode' );

	const SKIP_NAMES = array( 'error_log', 'php_errorlog', 'debug.log', '.DS_Store', 'Thumbs.db' );

	const LOG_LIMIT = 52428800;

	private static $excluded = null;

	public static function root() {
		return untrailingslashit( wp_normalize_path( ABSPATH ) );
	}

	public static function excluded() {
		if ( null !== self::$excluded ) {
			return self::$excluded;
		}
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
			$upload . '/backupbuddy_backups',
			$upload . '/pb_backupbuddy',
			$upload . '/backup-guard',
			$upload . '/ithemes-security/backups',
			$upload . '/wp-migrate-db',
			$upload . '/wp-staging',
			$upload . '/wc-logs',
			$upload . '/cache',
			'error_log',
		);
		self::$excluded = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'rafoo_backup_exclude', $list ) ) ) );
		return self::$excluded;
	}

	public static function rules( $user = true ) {
		$paths    = array_fill_keys( self::excluded(), true );
		$patterns = array();
		foreach ( $user ? (array) Settings::get( 'backup_exclude' ) : array() as $rule ) {
			if ( false !== strpbrk( $rule, '*?[' ) ) {
				$patterns[] = $rule;
			} else {
				$paths[ $rule ] = true;
			}
		}
		return array(
			'paths'    => $paths,
			'patterns' => $patterns,
			'dirs'     => array_fill_keys( self::SKIP_DIRS, true ),
			'names'    => array_fill_keys( self::SKIP_NAMES, true ),
		);
	}

	public static function relative( $path ) {
		$path = wp_normalize_path( (string) $path );
		$root = self::root();
		return 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : ltrim( $path, '/' );
	}

	public static function compare( $a, $b ) {
		return strcmp( strtr( $a, '/', "\0" ), strtr( $b, '/', "\0" ) );
	}

	public static function stored( $name ) {
		return in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::STORED, true );
	}

	public static function start_scan( $list ) {
		$out = Ledger::open( $list, 'wb' );
		if ( ! $out || strlen( self::HEADER ) !== $out->fwrite( self::HEADER ) ) {
			throw new Problem( 'not_writable', array( 'path' => Ledger::show( $list ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		return array(
			'stack'        => array( array( '', null ) ),
			'files'        => 0,
			'bytes'        => 0,
			'compressible' => 0,
			'done'         => false,
		);
	}

	public static function scan( $list, array $job, $deadline, array &$warnings, $rules = null ) {
		$root  = self::root();
		$rules = null === $rules ? self::rules() : $rules;
		$out   = Ledger::open( $list, 'ab' );
		if ( ! $out ) {
			throw new Problem( 'not_writable', array( 'path' => Ledger::show( $list ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
		}
		$cache = array();
		while ( $job['stack'] && microtime( true ) < $deadline ) {
			$top      = count( $job['stack'] ) - 1;
			$relative = (string) base64_decode( (string) $job['stack'][ $top ][0] );
			$last     = null === $job['stack'][ $top ][1] ? null : (string) base64_decode( (string) $job['stack'][ $top ][1] );
			$absolute = '' === $relative ? $root : $root . '/' . $relative;
			if ( ! array_key_exists( $relative, $cache ) ) {
				if ( count( $cache ) > 64 ) {
					$cache = array();
				}
				$cache[ $relative ] = self::names( $absolute );
			}
			$names = $cache[ $relative ];
			if ( false === $names ) {
				$warnings = Warnings::add( $warnings, 'unreadable_dir', $relative );
				array_pop( $job['stack'] );
				continue;
			}
			if ( '' !== $relative && null === $last && in_array( 'wp-config.php', $names, true ) && in_array( 'wp-includes', $names, true ) ) {
				array_pop( $job['stack'] );
				continue;
			}
			$count   = count( $names );
			$index   = null === $last ? 0 : self::after( $names, $last );
			$descend = false;
			for ( ; $index < $count; $index++ ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$name                      = $names[ $index ];
				$path                      = '' === $relative ? $name : $relative . '/' . $name;
				$full                      = $absolute . '/' . $name;
				$job['stack'][ $top ][1] = base64_encode( $name );
				if ( isset( $rules['paths'][ $path ] ) || is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					if ( ! isset( $rules['dirs'][ $name ] ) && ! self::matches( $rules['patterns'], $path, $name ) ) {
						$job['stack'][] = array( base64_encode( $path ), null );
						$descend        = true;
						break;
					}
					continue;
				}
				if ( isset( $rules['names'][ $name ] ) || self::matches( $rules['patterns'], $path, $name ) || ! is_file( $full ) ) {
					continue;
				}
				if ( false !== strpbrk( $path, "\t\n\r" ) ) {
					$warnings = Warnings::add( $warnings, 'odd_name', $path );
					continue;
				}
				if ( ! is_readable( $full ) ) {
					$warnings = Warnings::add( $warnings, 'unreadable', $path );
					continue;
				}
				$size = (int) @filesize( $full );
				if ( self::noise( $name, $size ) ) {
					$warnings = Warnings::add( $warnings, 'skipped_log', $path );
					continue;
				}
				$line = $path . "\t" . $size . "\t" . (int) @filemtime( $full ) . "\n";
				if ( strlen( $line ) !== $out->fwrite( $line ) ) {
					throw new Problem( 'disk_full', array( 'free' => Ledger::free_space() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
				}
				++$job['files'];
				$job['bytes'] += $size;
				if ( ! self::stored( $name ) ) {
					$job['compressible'] += $size;
				}
			}
			if ( ! $descend && $index >= $count ) {
				array_pop( $job['stack'] );
				unset( $cache[ $relative ] );
			}
		}
		$out         = null;
		$job['done'] = ! $job['stack'];
		return $job;
	}

	public static function protected_path( $relative ) {
		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		if ( '' === $relative || 'wp-config.php' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
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

	public static function remove_listed( $list, array $job, $deadline ) {
		$in = is_file( $list ) ? Ledger::open( $list, 'rb' ) : null;
		if ( ! $in ) {
			$job['done'] = true;
			return $job;
		}
		$root = self::root();
		$in->fseek( (int) $job['offset'] );
		$job['done'] = false;
		while ( microtime( true ) < $deadline ) {
			$line = $in->eof() ? '' : (string) $in->fgets();
			if ( '' === $line ) {
				$job['done'] = true;
				break;
			}
			$job['offset'] += strlen( $line );
			$relative       = rtrim( $line, "\n" );
			if ( '' !== $relative && ! self::protected_path( $relative ) && is_file( $root . '/' . $relative ) ) {
				wp_delete_file( $root . '/' . $relative );
				++$job['removed'];
			}
		}
		return $job;
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

	private static function names( $dir ) {
		$names = @scandir( $dir, SCANDIR_SORT_NONE );
		if ( false === $names ) {
			return false;
		}
		$names = array_values( array_diff( $names, array( '.', '..' ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	private static function after( array $names, $last ) {
		$low  = 0;
		$high = count( $names );
		while ( $low < $high ) {
			$middle = ( $low + $high ) >> 1;
			if ( strcmp( $names[ $middle ], $last ) <= 0 ) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}
		return $low;
	}

	private static function matches( array $patterns, $path, $name ) {
		foreach ( $patterns as $pattern ) {
			if ( fnmatch( $pattern, $path ) || ( false === strpos( $pattern, '/' ) && fnmatch( $pattern, $name ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function noise( $name, $size ) {
		if ( $size > self::LOG_LIMIT && preg_match( '/\.log(\.\d+)?$/i', $name ) ) {
			return true;
		}
		return (bool) preg_match( '/^core\.\d+$/', $name );
	}
}
