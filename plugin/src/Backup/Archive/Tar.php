<?php

namespace Rafoo\Backup\Archive;

defined( 'ABSPATH' ) || exit;

final class Tar {

	const BLOCK = 512;

	const OCTAL_MAX = 8589934591;

	public static function header( $path, $size, $mtime, $mode = 420 ) {
		$path   = (string) $path;
		$size   = max( 0, (int) $size );
		$mtime  = max( 0, min( (int) $mtime, self::OCTAL_MAX ) );
		$pax    = array();
		$name   = $path;
		$prefix = '';
		if ( ! self::plain( $path ) ) {
			$pax['path'] = $path;
			$name        = self::fallback( $path );
		} elseif ( strlen( $path ) > 100 ) {
			$split = self::split( $path );
			if ( null === $split ) {
				$pax['path'] = $path;
				$name        = self::fallback( $path );
			} else {
				list( $prefix, $name ) = $split;
			}
		}
		if ( $size > self::OCTAL_MAX ) {
			$pax['size'] = (string) $size;
		}
		$out = '';
		if ( $pax ) {
			$records = self::records( $pax );
			$out    .= self::block( 'PaxHeader/' . substr( $name, 0, 80 ), strlen( $records ), $mtime, 420, 'x', '' ) . $records . self::padding( strlen( $records ) );
		}
		return $out . self::block( $name, $size > self::OCTAL_MAX ? 0 : $size, $mtime, $mode, '0', $prefix );
	}

	public static function padding( $size ) {
		return str_repeat( "\0", ( self::BLOCK - $size % self::BLOCK ) % self::BLOCK );
	}

	public static function end() {
		return str_repeat( "\0", 2 * self::BLOCK );
	}

	public static function parse( $block, array $pax = array() ) {
		if ( self::BLOCK !== strlen( $block ) ) {
			return null;
		}
		if ( str_repeat( "\0", self::BLOCK ) === $block ) {
			return array( 'type' => 'end' );
		}
		$stored = self::number( substr( $block, 148, 8 ) );
		$sum    = array_sum( unpack( 'C*', substr_replace( $block, '        ', 148, 8 ) ) );
		if ( $stored !== $sum ) {
			return null;
		}
		$name   = self::field( $block, 0, 100 );
		$prefix = 'ustar' === substr( $block, 257, 5 ) ? self::field( $block, 345, 155 ) : '';
		$type   = "\0" === $block[156] ? '0' : $block[156];
		$entry  = array(
			'type'  => $type,
			'path'  => '' !== $prefix ? $prefix . '/' . $name : $name,
			'size'  => self::number( substr( $block, 124, 12 ) ),
			'mtime' => self::number( substr( $block, 136, 12 ) ),
			'mode'  => self::number( substr( $block, 100, 8 ) ),
		);
		if ( isset( $pax['path'] ) && '' !== $pax['path'] ) {
			$entry['path'] = $pax['path'];
		}
		if ( isset( $pax['size'] ) && preg_match( '/^\d+$/', $pax['size'] ) ) {
			$entry['size'] = (int) $pax['size'];
		}
		if ( isset( $pax['mtime'] ) && is_numeric( $pax['mtime'] ) ) {
			$entry['mtime'] = (int) $pax['mtime'];
		}
		return $entry;
	}

	public static function pax( $data ) {
		$out    = array();
		$at     = 0;
		$length = strlen( $data );
		while ( $at < $length ) {
			$space = strpos( $data, ' ', $at );
			if ( false === $space ) {
				break;
			}
			$size = (int) substr( $data, $at, $space - $at );
			if ( $size <= $space - $at + 1 || $at + $size > $length ) {
				break;
			}
			$record = substr( $data, $space + 1, $size - ( $space - $at ) - 2 );
			$equals = strpos( $record, '=' );
			if ( false !== $equals ) {
				$out[ substr( $record, 0, $equals ) ] = substr( $record, $equals + 1 );
			}
			$at += $size;
		}
		return $out;
	}

	private static function block( $name, $size, $mtime, $mode, $type, $prefix ) {
		$head = str_pad( $name, 100, "\0" )
			. sprintf( "%07o\0", $mode & 4095 )
			. sprintf( "%07o\0", 0 )
			. sprintf( "%07o\0", 0 )
			. sprintf( "%011o\0", $size )
			. sprintf( "%011o\0", $mtime )
			. '        '
			. $type
			. str_repeat( "\0", 100 )
			. "ustar\0" . '00'
			. str_pad( 'rafoo', 32, "\0" )
			. str_pad( 'rafoo', 32, "\0" )
			. str_repeat( "\0", 16 )
			. str_pad( $prefix, 155, "\0" )
			. str_repeat( "\0", 12 );
		return substr_replace( $head, sprintf( "%06o\0 ", array_sum( unpack( 'C*', $head ) ) ), 148, 8 );
	}

	private static function records( array $pax ) {
		$out = '';
		foreach ( $pax as $key => $value ) {
			$body   = ' ' . $key . '=' . $value . "\n";
			$length = strlen( $body ) + 1;
			while ( strlen( (string) $length ) + strlen( $body ) !== $length ) {
				$length = strlen( (string) $length ) + strlen( $body );
			}
			$out .= $length . $body;
		}
		return $out;
	}

	private static function plain( $path ) {
		return '' !== $path && ! preg_match( '/[^\x20-\x7e]/', $path );
	}

	private static function split( $path ) {
		$length = strlen( $path );
		$at     = -1;
		while ( false !== ( $at = strpos( $path, '/', $at + 1 ) ) ) {
			if ( $at > 155 ) {
				return null;
			}
			if ( $at > 0 && $length - $at - 1 <= 100 && $length - $at - 1 > 0 ) {
				return array( substr( $path, 0, $at ), substr( $path, $at + 1 ) );
			}
		}
		return null;
	}

	private static function fallback( $path ) {
		$base = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '_', basename( $path ) );
		return substr( 'rafoo-' . hash( 'crc32b', $path ) . '-' . $base, 0, 99 );
	}

	private static function field( $block, $at, $length ) {
		$value = substr( $block, $at, $length );
		$nul   = strpos( $value, "\0" );
		return false === $nul ? $value : substr( $value, 0, $nul );
	}

	private static function number( $raw ) {
		if ( '' !== $raw && ( ord( $raw[0] ) & 128 ) ) {
			$value = ord( $raw[0] ) & 127;
			$count = strlen( $raw );
			for ( $i = 1; $i < $count; $i++ ) {
				$value = $value * 256 + ord( $raw[ $i ] );
			}
			return (int) $value;
		}
		$raw = trim( $raw, " \0" );
		return '' === $raw || preg_match( '/[^0-7]/', $raw ) ? 0 : (int) octdec( $raw );
	}
}
