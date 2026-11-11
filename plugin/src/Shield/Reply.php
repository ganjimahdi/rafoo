<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Reply {

	const TEXT = array(
		'lang'             => 'en',
		'dir'              => 'ltr',
		'block_title'      => 'This request was stopped',
		'block_text'       => 'The site’s security shield stopped this request because it looked like an attack. If you think this is a mistake, send the reference code below to the site administrator.',
		'limit_title'      => 'Too many requests',
		'limit_text'       => 'Requests from your connection arrived too quickly. Please wait a minute and try again.',
		'locked_title'     => 'Signing in is paused',
		'locked_text'      => 'There were too many failed sign-in attempts from your connection. Please try again later.',
		'challenge_title'  => 'Checking your browser',
		'challenge_text'   => 'The site is under heavy load. Your browser is solving a short check; the page opens by itself in a few seconds.',
		'challenge_failed' => 'The check could not be completed. Reload the page to try again.',
		'noscript'         => 'Turn on JavaScript to continue.',
		'reference'        => 'Reference',
		'home'             => 'Back to the home page',
	);

	public static function text( array $config, $key ) {
		return isset( $config['text'][ $key ] ) && is_string( $config['text'][ $key ] ) ? $config['text'][ $key ] : self::TEXT[ $key ];
	}

	public static function headers( $status, $type ) {
		if ( headers_sent() ) {
			return;
		}
		http_response_code( (int) $status );
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
	}

	public static function stop( array $config, Request $r, $kind, $reference, $retry = 0 ) {
		$status = 'limit' === $kind || 'locked' === $kind ? 429 : 403;
		if ( $retry > 0 && ! headers_sent() ) {
			header( 'Retry-After: ' . (int) $retry );
		}
		if ( ! $r->navigation || 'rest' === $r->script() || 'ajax' === $r->script() || 'xmlrpc' === $r->script() ) {
			self::headers( $status, 'application/json' );
			echo json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- a JSON reply sent before WordPress loads; values are fixed strings and a hex reference.
				array(
					'code'      => 'rafoo_shield_' . $kind,
					'message'   => self::text( $config, $kind . '_title' ),
					'data'      => array( 'status' => $status ),
					'reference' => $reference,
				)
			);
			return;
		}
		self::headers( $status, 'text/html' );
		$title = self::text( $config, $kind . '_title' );
		$body  = '<h1>' . self::e( $title ) . '</h1><p>' . self::e( self::text( $config, $kind . '_text' ) ) . '</p>';
		if ( '' !== $reference ) {
			$body .= '<p class="ref">' . self::e( self::text( $config, 'reference' ) ) . ': <code>' . self::e( $reference ) . '</code></p>';
		}
		$home  = isset( $config['home'] ) ? (string) $config['home'] : '/';
		$body .= '<p><a href="' . self::e( $home ) . '">' . self::e( self::text( $config, 'home' ) ) . '</a></p>';
		echo self::page( $config, $title, $body, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value in the page is escaped with self::e() before it is joined.
	}

	public static function challenge( array $config, Request $r, array $pow ) {
		self::headers( 403, 'text/html' );
		$title  = self::text( $config, 'challenge_title' );
		$action = $r->uri;
		$body   = '<h1>' . self::e( $title ) . '</h1><p>' . self::e( self::text( $config, 'challenge_text' ) ) . '</p>'
			. '<div class="bar" aria-hidden="true"><span id="rf-bar"></span></div>'
			. '<p id="rf-state" class="ref" role="status" aria-live="polite"></p>'
			. '<noscript><p>' . self::e( self::text( $config, 'noscript' ) ) . '</p></noscript>'
			. '<form id="rf-form" method="post" action="' . self::e( $action ) . '"><input type="hidden" name="' . Challenge::FIELD . '" id="rf-pow" value=""></form>';
		$data   = json_encode(
			array(
				'salt'      => $pow['salt'],
				'challenge' => $pow['challenge'],
				'max'       => $pow['max'],
				'sig'       => $pow['sig'],
				'failed'    => self::text( $config, 'challenge_failed' ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		echo self::page( $config, $title, $body, self::script( (string) $data ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text is escaped with self::e(); the script data is JSON with tags and quotes hex-encoded.
	}

	private static function page( array $config, $title, $body, $script ) {
		$lang = self::text( $config, 'lang' );
		$dir  = 'rtl' === self::text( $config, 'dir' ) ? 'rtl' : 'ltr';
		$css  = 'html{color-scheme:light dark}body{margin:0;font-family:Vazirmatn,Tahoma,system-ui,sans-serif;background:#f4f4f5;color:#09090b;display:grid;place-items:center;min-height:100vh}main{box-sizing:border-box;max-width:520px;width:calc(100% - 32px);margin:16px;padding:28px;background:#fff;border:1px solid #e4e4e7;border-radius:12px;line-height:1.9}h1{font-size:18px;margin:0 0 8px}p{margin:0 0 14px;color:#3f3f46}.ref{font-size:13px;color:#52525b}code{font-family:ui-monospace,monospace;direction:ltr;unicode-bidi:isolate}a{color:#09090b}.bar{height:4px;background:#e4e4e7;border-radius:4px;overflow:hidden;margin:18px 0 10px}.bar span{display:block;height:100%;width:0;background:#18181b;transition:width .2s ease-out}@media (prefers-color-scheme:dark){body{background:#09090b;color:#fafafa}main{background:#18181b;border-color:#27272a}p{color:#d4d4d8}.ref{color:#a1a1aa}a{color:#fafafa}.bar{background:#27272a}.bar span{background:#fafafa}}@media (prefers-reduced-motion:reduce){.bar span{transition:none}}';
		return '<!doctype html><html lang="' . self::e( $lang ) . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . self::e( $title ) . '</title><style>' . $css . '</style></head><body><main>' . $body . '</main>' . $script . '</body></html>';
	}

	private static function script( $data ) {
		$file   = dirname( __DIR__, 2 ) . '/assets/shield.js';
		$source = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		return '<script>window.rafooShield=' . $data . ';' . $source . '</script>';
	}

	public static function e( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
