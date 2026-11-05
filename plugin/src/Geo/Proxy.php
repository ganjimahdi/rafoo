<?php

namespace Rafoo\Geo;

use Rafoo\Http\Client;

defined( 'ABSPATH' ) || exit;

final class Proxy {

	const WINDOW = 120;

	const COOKIES = '/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_|comment_author_|wp-settings-time|edd_items_in_cart|wordpress_test_cookie)/';

	const PATHS = '#^/(wp-admin(/|$)|wp-login\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php|wp-comments-post\.php|wp-trackback\.php|wp-mail\.php)#';

	const QUERY = array( 'wc-ajax', 'wc-api', 'add-to-cart', 'preview', 'preview_id', 'customize_changeset_uuid', 'customize_theme', 'doing_wp_cron', 'edd_action', 'rest_route' );

	const SKIP_HEADERS = array( 'host', 'connection', 'content-length', 'accept-encoding', 'keep-alive', 'transfer-encoding', 'te', 'upgrade', 'proxy-authorization', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'x-rafoo-relay', 'x-rafoo-relay-sign', 'x-rafoo-geo-local', 'cdn-loop', 'cf-connecting-ip', 'ar-real-ip' );

	public static function should( $path ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( preg_match( '#/wp-admin/admin-ajax\.php$#', $path ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; Rafoo's own signed endpoints stay on this host.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( in_array( $action, array( Link::ACTION, \Rafoo\Backup\Transfer::ACTION, \Rafoo\Backup\Job::ACTION ), true ) ) {
				return false;
			}
			return true;
		}
		if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) {
			return true;
		}
		if ( preg_match( self::PATHS, $path ) ) {
			return true;
		}
		foreach ( self::QUERY as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only.
			if ( isset( $_GET[ $name ] ) ) {
				return true;
			}
		}
		if ( preg_match( '#^/wp-json(/|$)#', $path ) && ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) || isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) ) {
			return true;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( preg_match( self::COOKIES, (string) $name ) && 'wordpress_test_cookie' !== $name ) {
				return true;
			}
		}
		foreach ( (array) Store::get( 'dynamic' ) as $prefix ) {
			if ( '' !== $prefix && '/' !== $prefix && 0 === strpos( $path, (string) $prefix ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'rafoo_geo_dynamic', false, $path );
	}

	public static function relay_headers( $method, $uri ) {
		$https = ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) ) ) || 'https' === Store::get( 'scheme' );
		$value = base64_encode(
			wp_json_encode(
				array(
					'i' => self::client_ip(),
					's' => $https ? 'https' : 'http',
					't' => time(),
					'n' => bin2hex( random_bytes( 8 ) ),
					'f' => (string) Store::get( 'self' ),
				)
			)
		);
		return array(
			'X-Rafoo-Relay'      => $value,
			'X-Rafoo-Relay-Sign' => hash_hmac( 'sha256', $value . '|' . strtoupper( $method ) . '|' . $uri, Store::key() ),
		);
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function forward() {
		$primary = Store::primary_node();
		if ( ! $primary || 32 !== strlen( Store::key() ) ) {
			return;
		}
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$uri     = Edge::request_uri();
		$headers = self::incoming_headers();
		$body    = null;
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';
			if ( 0 === stripos( $type, 'multipart/form-data' ) ) {
				$boundary                = '----RafooRelay' . bin2hex( random_bytes( 12 ) );
				$body                    = self::multipart( $boundary );
				$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			} else {
				$body = (string) file_get_contents( 'php://input' );
			}
		}
		$headers = array_merge( $headers, self::relay_headers( $method, $uri ) );
		$reply   = wp_remote_request(
			untrailingslashit( $primary['url'] ) . $uri,
			Client::args(
				array(
					'method'              => $method,
					'headers'             => $headers,
					'body'                => $body,
					'timeout'             => (int) apply_filters( 'rafoo_geo_relay_timeout', 45 ),
					'redirection'         => 0,
					'decompress'          => true,
					'limit_response_size' => 134217728,
					'sslverify'           => (bool) apply_filters( 'rafoo_geo_sslverify', true ),
					'rafoo_probe'         => true,
				)
			)
		);
		if ( is_wp_error( $reply ) ) {
			Store::save_state( array( 'relay_failed' => time() ) );
			self::unavailable();
		}
		self::emit( $reply, $primary );
	}

	public static function fetch( $uri ) {
		$primary = Store::primary_node();
		if ( ! $primary ) {
			return new \WP_Error( 'rafoo_geo_primary', 'no primary' );
		}
		$headers = array_merge( array( 'Accept' => 'text/html,*/*' ), self::relay_headers( 'GET', $uri ) );
		return wp_remote_get(
			untrailingslashit( $primary['url'] ) . $uri,
			Client::args(
				array(
					'headers'     => $headers,
					'timeout'     => 20,
					'redirection' => 0,
					'sslverify'   => (bool) apply_filters( 'rafoo_geo_sslverify', true ),
					'rafoo_probe' => true,
				)
			)
		);
	}

	private static function incoming_headers() {
		$out = array();
		foreach ( $_SERVER as $name => $value ) {
			if ( 0 !== strpos( (string) $name, 'HTTP_' ) ) {
				continue;
			}
			$header = strtolower( str_replace( '_', '-', substr( (string) $name, 5 ) ) );
			if ( in_array( $header, self::SKIP_HEADERS, true ) ) {
				continue;
			}
			$out[ ucwords( $header, '-' ) ] = (string) $value;
		}
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$out['Content-Type'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
		}
		return $out;
	}

	private static function flatten( $value, $name, array &$out ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				self::flatten( $item, $name . '[' . $key . ']', $out );
			}
			return;
		}
		$out[] = array( $name, (string) $value );
	}

	private static function files( array $files ) {
		$out = array();
		foreach ( $files as $field => $file ) {
			if ( ! is_array( $file['name'] ) ) {
				$out[] = array( $field, $file );
				continue;
			}
			$walk = static function ( $names, $prefix, $path ) use ( &$walk, $file, &$out ) {
				foreach ( $names as $key => $item ) {
					$next = array_merge( $path, array( $key ) );
					if ( is_array( $item ) ) {
						$walk( $item, $prefix . '[' . $key . ']', $next );
						continue;
					}
					$pick = static function ( $branch ) use ( $next ) {
						foreach ( $next as $step ) {
							$branch = isset( $branch[ $step ] ) ? $branch[ $step ] : '';
						}
						return $branch;
					};
					$out[] = array(
						$prefix . '[' . $key . ']',
						array(
							'name'     => $item,
							'type'     => $pick( $file['type'] ),
							'tmp_name' => $pick( $file['tmp_name'] ),
							'error'    => $pick( $file['error'] ),
						),
					);
				}
			};
			$walk( $file['name'], $field, array() );
		}
		return $out;
	}

	private static function multipart( $boundary ) {
		$fields = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The form is forwarded to the main host, where WordPress checks its nonce.
		foreach ( wp_unslash( $_POST ) as $name => $value ) {
			self::flatten( $value, (string) $name, $fields );
		}
		$body = '';
		foreach ( $fields as $field ) {
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . str_replace( '"', '%22', $field[0] ) . "\"\r\n\r\n" . $field[1] . "\r\n";
		}
		foreach ( self::files( $_FILES ) as $file ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Forwarded unchanged to the main host, which runs its own checks.
			list( $name, $info ) = $file;
			if ( (int) $info['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) $info['tmp_name'] ) ) {
				continue;
			}
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . str_replace( '"', '%22', $name ) . '"; filename="' . str_replace( '"', '%22', (string) $info['name'] ) . "\"\r\nContent-Type: " . ( '' !== (string) $info['type'] ? (string) $info['type'] : 'application/octet-stream' ) . "\r\n\r\n" . (string) file_get_contents( (string) $info['tmp_name'] ) . "\r\n";
		}
		return $body . '--' . $boundary . "--\r\n";
	}

	private static function emit( $reply, array $primary ) {
		$code = (int) wp_remote_retrieve_response_code( $reply );
		status_header( $code > 0 ? $code : 502 );
		$headers  = wp_remote_retrieve_headers( $reply );
		$tech     = Store::node_host( $primary );
		$main     = Store::main_host();
		$skip     = array( 'transfer-encoding', 'connection', 'content-length', 'content-encoding', 'keep-alive', 'server', 'x-powered-by' );
		$iterable = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		foreach ( $iterable as $name => $values ) {
			$name = strtolower( (string) $name );
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$value = (string) $value;
				if ( 'location' === $name ) {
					$value = str_replace( array( 'https://' . $tech, 'http://' . $tech ), Store::get( 'scheme' ) . '://' . $main, $value );
				}
				header( ucwords( $name, '-' ) . ': ' . $value, 'set-cookie' !== $name );
			}
		}
		$body = (string) wp_remote_retrieve_body( $reply );
		header( 'Content-Length: ' . strlen( $body ) );
		file_put_contents( 'php://output', $body );
		exit;
	}

	public static function unavailable() {
		if ( ! headers_sent() ) {
			status_header( 503 );
			header( 'Retry-After: 120' );
			header( 'Cache-Control: no-store' );
			header( 'X-Robots-Tag: noindex' );
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		load_plugin_textdomain( 'rafoo', false, dirname( plugin_basename( RAFOO_FILE ) ) . '/languages' );
		$title = __( 'This part of the site is not available right now', 'rafoo' );
		$text  = __( 'The main server of this site cannot be reached at the moment. Reading the site still works. Please try again in a few minutes.', 'rafoo' );
		$back  = __( 'Back to the home page', 'rafoo' );
		$dir   = is_rtl() ? 'rtl' : 'ltr';
		$html  = '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{margin:0;font-family:Tahoma,system-ui,sans-serif;background:#f8f9fa;color:#09090b;display:grid;place-items:center;min-height:100vh}main{max-width:480px;margin:24px;padding:32px;background:#fff;border:1px solid #e4e4e7;border-radius:12px;line-height:1.8}h1{font-size:18px;margin:0 0 8px}p{margin:0 0 20px;color:#52525b}a{color:#09090b}</style></head>'
			. '<body><main><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p><a href="' . esc_url( Store::main_url( '/' ) ) . '">' . esc_html( $back ) . '</a></main></body></html>';
		file_put_contents( 'php://output', $html );
		exit;
	}

	public static function accept() {
		if ( ! Store::is_primary() || ! isset( $_SERVER['HTTP_X_RAFOO_RELAY'] ) ) {
			return;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAFOO_RELAY'] ) );
		$sign  = isset( $_SERVER['HTTP_X_RAFOO_RELAY_SIGN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAFOO_RELAY_SIGN'] ) ) : '';
		$key   = Store::key();
		$data  = json_decode( (string) base64_decode( $value ), true );
		unset( $_SERVER['HTTP_X_RAFOO_RELAY'], $_SERVER['HTTP_X_RAFOO_RELAY_SIGN'] );
		if ( 32 !== strlen( $key ) || ! is_array( $data ) || abs( time() - (int) $data['t'] ) > self::WINDOW ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! hash_equals( hash_hmac( 'sha256', $value . '|' . $method . '|' . Edge::request_uri(), $key ), $sign ) ) {
			return;
		}
		$nonce = 'rafoo_gr_' . substr( preg_replace( '/[^a-f0-9]/', '', (string) $data['n'] ), 0, 16 );
		if ( get_transient( $nonce ) ) {
			return;
		}
		set_transient( $nonce, 1, 2 * self::WINDOW );
		if ( filter_var( $data['i'], FILTER_VALIDATE_IP ) ) {
			$_SERVER['REMOTE_ADDR']          = (string) $data['i'];
			$_SERVER['HTTP_X_FORWARDED_FOR'] = (string) $data['i'];
			$_SERVER['HTTP_X_REAL_IP']       = (string) $data['i'];
		}
		$host                    = Store::main_host();
		$_SERVER['HTTP_HOST']    = $host;
		$_SERVER['SERVER_NAME']  = (string) preg_replace( '/:\d+$/', '', $host );
		if ( 'https' === $data['s'] ) {
			$_SERVER['HTTPS']       = 'on';
			$_SERVER['SERVER_PORT'] = '443';
		}
		$_SERVER['HTTP_X_RAFOO_RELAYED'] = (string) $data['f'];
		add_filter( 'redirect_canonical', '__return_false' );
	}
}
