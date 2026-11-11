<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Log {

	const FILE = 'events.log';

	const MAX = 2097152;

	public static function reference() {
		return strtoupper( bin2hex( random_bytes( 4 ) ) );
	}

	public static function write( $dir, Request $r, $action, array $verdict, $reference ) {
		if ( '' === (string) $dir ) {
			return false;
		}
		$file = rtrim( $dir, '/\\' ) . '/' . self::FILE;
		if ( is_file( $file ) && (int) @filesize( $file ) > self::MAX ) {
			@rename( $file, $file . '.1' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- runs before WordPress loads; rotates the shield's own log.
		}
		$at   = strpos( $r->uri, '?' );
		$line = array(
			't' => time(),
			'r' => $reference,
			'i' => $r->ip,
			'a' => $action,
			'g' => isset( $verdict['group'] ) ? (string) $verdict['group'] : '',
			'u' => isset( $verdict['rule'] ) ? (string) $verdict['rule'] : '',
			's' => isset( $verdict['score'] ) ? (int) $verdict['score'] : 0,
			'w' => isset( $verdict['where'] ) ? substr( (string) $verdict['where'], 0, 60 ) : '',
			'm' => $r->method,
			'p' => substr( $r->path, 0, 200 ),
			'q' => false === $at ? '' : (string) substr( $r->uri, $at + 1, 300 ),
			'b' => substr( $r->agent, 0, 160 ),
			'x' => isset( $verdict['plugin'] ) ? (string) $verdict['plugin'] : '',
		);
		$json = json_encode( $line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false !== $json && false !== @file_put_contents( $file, $json . "\n", FILE_APPEND | LOCK_EX );
	}
}
