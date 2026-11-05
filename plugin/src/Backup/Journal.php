<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Journal {

	const NAME = 'job.log';

	const MAX = 262144;

	public static function start( $title ) {
		$file = Ledger::path( self::NAME );
		if ( '' === $file ) {
			return;
		}
		$out = Ledger::open( $file, 'wb' );
		if ( $out ) {
			$out->fwrite( self::line( $title ) );
		}
		self::add( 'Rafoo ' . RAFOO_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', memory_limit ' . ini_get( 'memory_limit' ) . ', max_execution_time ' . ini_get( 'max_execution_time' ) . ', step ' . Ledger::budget() . ' s' );
		$free = Ledger::free_space();
		self::add( 'free space ' . ( $free >= 0 ? size_format( $free, 1 ) : 'unknown' ) . ', zlib ' . ( Archive\Sink::gzip() ? 'yes' : 'no' ) . ', sodium ' . ( Crypt::available() ? 'yes' : 'no' ) . ', ZipArchive ' . ( class_exists( 'ZipArchive' ) ? 'yes' : 'no' ) );
	}

	public static function add( $message ) {
		$file = Ledger::path( self::NAME );
		if ( '' === $file ) {
			return;
		}
		clearstatcache( true, $file );
		if ( is_file( $file ) && filesize( $file ) > self::MAX ) {
			$keep = (string) file_get_contents( $file, false, null, (int) ( filesize( $file ) - self::MAX / 2 ) );
			$keep = (string) substr( $keep, (int) strpos( $keep, "\n" ) + 1 );
			$out  = Ledger::open( $file, 'wb' );
			if ( $out ) {
				$out->fwrite( $keep );
			}
		}
		$out = Ledger::open( $file, 'ab' );
		if ( $out ) {
			$out->fwrite( self::line( $message ) );
		}
	}

	public static function tail( $lines = 300 ) {
		$file = Ledger::path( self::NAME );
		if ( '' === $file || ! is_file( $file ) ) {
			return '';
		}
		$text = (string) file_get_contents( $file );
		$all  = explode( "\n", rtrim( $text, "\n" ) );
		return implode( "\n", array_slice( $all, -$lines ) );
	}

	public static function clean( $text ) {
		$text = str_replace( array( wp_normalize_path( ABSPATH ), ABSPATH ), '', (string) $text );
		$text = wp_check_invalid_utf8( $text, true );
		return substr( (string) preg_replace( '/\s+/', ' ', $text ), 0, 400 );
	}

	private static function line( $message ) {
		return gmdate( 'Y-m-d H:i:s' ) . ' ' . self::clean( $message ) . "\n";
	}
}
