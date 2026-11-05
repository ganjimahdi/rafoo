<?php

namespace Rafoo\Geo;

defined( 'ABSPATH' ) || exit;

final class Relay {

	const PATTERN = '#^/\.well-known/(acme-challenge|pki-validation)/[A-Za-z0-9_.\-]{8,200}$#';

	const MAX = 8192;

	public static function is_challenge( $path ) {
		return 1 === preg_match( self::PATTERN, (string) $path ) && false === strpos( (string) $path, '..' );
	}

	private static function local( $path ) {
		$roots = array( untrailingslashit( ABSPATH ) );
		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = untrailingslashit( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
		}
		foreach ( array_unique( $roots ) as $root ) {
			$file = $root . $path;
			if ( is_file( $file ) && filesize( $file ) <= self::MAX ) {
				return (string) file_get_contents( $file );
			}
		}
		return null;
	}

	public static function lookup( $path ) {
		if ( ! self::is_challenge( $path ) ) {
			return array( 'ok' => false );
		}
		$body = self::local( $path );
		return null === $body ? array( 'ok' => false ) : array(
			'ok'   => true,
			'body' => base64_encode( $body ),
		);
	}

	public static function serve( $path ) {
		$body = self::local( $path );
		if ( null === $body ) {
			foreach ( Store::others() as $node ) {
				$reply = Link::call( $node, 'challenge', array( 'path' => $path ), false, 6 );
				if ( ! is_wp_error( $reply ) && ! empty( $reply['ok'] ) ) {
					$body = (string) base64_decode( (string) $reply['body'] );
					Store::save_state( array( 'relayed' => array( 'path' => $path, 'from' => $node['id'], 'at' => time() ) ) );
					break;
				}
			}
		}
		if ( null === $body ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Content-Length: ' . strlen( $body ) );
		file_put_contents( 'php://output', $body );
		exit;
	}
}
