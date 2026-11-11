<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Request {

	const PATH   = 1;
	const QUERY  = 2;
	const BODY   = 4;
	const COOKIE = 8;
	const AGENT  = 16;
	const HEADER = 32;
	const FILE   = 64;
	const NAME   = 128;
	const XML    = 256;

	const VALUES = 600;

	const BYTES = 524288;

	public $method = 'GET';
	public $uri = '/';
	public $path = '/';
	public $raw_path = '/';
	public $query = array();
	public $ip = '';
	public $key = '';
	public $agent = '';
	public $referer = '';
	public $accept = '';
	public $type = '';
	public $navigation = false;
	public $cookies = array();
	public $files = array();
	public $args = array();
	public $flags = array();
	public $server = array();
	public $head = '';

	private $budget = self::BYTES;
	private $count = 0;

	public static function capture( array $server, array $get, array $post, array $cookies, array $files, $body, array $config ) {
		$r          = new self();
		$r->server  = $server;
		$r->method  = isset( $server['REQUEST_METHOD'] ) ? strtoupper( substr( (string) $server['REQUEST_METHOD'], 0, 16 ) ) : 'GET';
		$r->uri     = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$raw        = (string) strtok( $r->uri, '?' );
		$r->raw_path = '' === $raw ? '/' : $raw;
		$r->path    = self::normalize( $r->raw_path, false );
		$r->ip      = Ip::resolve( $server, $config );
		$r->key     = Ip::key( $r->ip );
		$r->agent   = isset( $server['HTTP_USER_AGENT'] ) ? substr( (string) $server['HTTP_USER_AGENT'], 0, 512 ) : '';
		$r->referer = isset( $server['HTTP_REFERER'] ) ? substr( (string) $server['HTTP_REFERER'], 0, 1024 ) : '';
		$r->accept  = isset( $server['HTTP_ACCEPT'] ) ? strtolower( substr( (string) $server['HTTP_ACCEPT'], 0, 256 ) ) : '';
		$r->type    = isset( $server['CONTENT_TYPE'] ) ? strtolower( substr( (string) $server['CONTENT_TYPE'], 0, 128 ) ) : '';
		$r->cookies = $cookies;
		$r->query   = $get;
		$r->navigation = in_array( $r->method, array( 'GET', 'HEAD' ), true ) && ( '' === $r->accept || false !== strpos( $r->accept, 'text/html' ) || false !== strpos( $r->accept, '*/*' ) ) && ! isset( $server['HTTP_X_REQUESTED_WITH'] ) && 'cors' !== ( isset( $server['HTTP_SEC_FETCH_MODE'] ) ? strtolower( (string) $server['HTTP_SEC_FETCH_MODE'] ) : '' );

		if ( false !== strpos( $r->uri, "\0" ) || false !== stripos( $r->uri, '%00' ) ) {
			$r->flags['null'] = true;
		}
		if ( preg_match( '/%0[ad]|[\r\n]/i', $r->uri ) ) {
			$r->flags['crlf'] = (bool) preg_match( '/(%0[ad]|[\r\n])+\s*(set-cookie|location|content-(type|length)|x-[a-z-]+)\s*(:|%3a)/i', rawurldecode( $r->uri ) );
		}
		if ( strlen( $r->uri ) > 4096 ) {
			$r->flags['long'] = true;
		}

		$r->head = is_string( $body ) ? substr( $body, 0, 8 ) : '';
		$r->add( self::PATH, 'path', $r->raw_path );
		$r->walk( self::QUERY, '', $get, 0 );
		$r->walk( self::BODY, '', $post, 0 );
		if ( ! $post && is_string( $body ) && '' !== $body ) {
			if ( false !== strpos( $r->type, 'json' ) ) {
				$data = json_decode( $body, true );
				if ( is_array( $data ) ) {
					$r->walk( self::BODY, '', $data, 0 );
				} else {
					$r->add( self::BODY, 'body', $body );
				}
			} elseif ( false !== strpos( $r->type, 'xml' ) || 0 === strpos( ltrim( $body ), '<?xml' ) ) {
				$r->add( self::XML, 'body', $body );
			} elseif ( false === strpos( $r->type, 'multipart' ) && false === strpos( $r->type, 'octet-stream' ) ) {
				$r->add( self::BODY, 'body', $body );
			}
		}
		foreach ( $cookies as $name => $value ) {
			if ( is_string( $value ) && ! preg_match( '/^(wordpress_|wp-settings|wp_woocommerce_session_|woocommerce_|rafoo_shield_|comment_author_|sbjs_|_ga|_gid|_gcl_|_fbp|_fbc|_clck|_clsk|_hj|_uet|_pk_|mp_|ajs_)/', (string) $name ) ) {
				$r->add( self::COOKIE, (string) $name, $value );
			}
		}
		$r->add( self::AGENT, 'user-agent', $r->agent );
		foreach ( array( 'HTTP_REFERER', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_API_VERSION', 'HTTP_X_FORWARDED_HOST', 'HTTP_ORIGIN', 'HTTP_X_CLIENT_IP' ) as $header ) {
			if ( isset( $server[ $header ] ) && is_string( $server[ $header ] ) ) {
				$r->add( self::HEADER, strtolower( substr( $header, 5 ) ), $server[ $header ] );
			}
		}
		foreach ( $files as $field => $file ) {
			if ( ! is_array( $file ) || ! isset( $file['name'] ) ) {
				continue;
			}
			$names = (array) $file['name'];
			$tmps  = isset( $file['tmp_name'] ) ? (array) $file['tmp_name'] : array();
			array_walk_recursive(
				$names,
				static function ( $name, $index ) use ( $r, $tmps, $field ) {
					$tmp        = isset( $tmps[ $index ] ) && is_string( $tmps[ $index ] ) ? $tmps[ $index ] : '';
					$r->files[] = array(
						'field' => (string) $field,
						'name'  => (string) $name,
						'tmp'   => $tmp,
					);
					$r->add( self::FILE, (string) $field, (string) $name );
				}
			);
		}
		return $r;
	}

	private function walk( $target, $prefix, $data, $depth ) {
		foreach ( (array) $data as $name => $value ) {
			$label = '' === $prefix ? (string) $name : $prefix . '[' . $name . ']';
			if ( $depth < 6 && '' !== (string) $name && ! ctype_digit( (string) $name ) ) {
				$this->add( self::NAME, $label, (string) $name );
			}
			if ( is_array( $value ) ) {
				if ( $depth < 6 ) {
					$this->walk( $target, $label, $value, $depth + 1 );
				}
				continue;
			}
			if ( is_scalar( $value ) && ! preg_match( '/^(pwd|pass|pass1|pass2|password|user_pass|password_\w+|account_password|new_password|old_password|confirm_password|current_password)$/i', (string) $name ) ) {
				$this->add( $target, $label, (string) $value );
			}
		}
	}

	public function add( $target, $name, $value ) {
		if ( $this->count >= self::VALUES || $this->budget <= 0 || '' === $value ) {
			return;
		}
		$value         = substr( $value, 0, min( 65536, $this->budget ) );
		$this->budget -= strlen( $value );
		++$this->count;
		if ( preg_match( '/^[A-Za-z0-9_\-.]{0,200}$/', $value ) ) {
			return;
		}
		$this->args[] = array( $target, substr( $name, 0, 80 ), self::normalize( $value, self::PATH !== $target ) );
	}

	public static function normalize( $value, $deep = true ) {
		$value = (string) $value;
		for ( $i = 0; $i < 3 && preg_match( '/%[0-9a-f]{2}/i', $value ); $i++ ) {
			$value = rawurldecode( $value );
		}
		if ( $deep && false !== strpos( $value, '&' ) ) {
			$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( $deep && false !== strpos( $value, '\\' ) ) {
			$value = (string) preg_replace_callback(
				'/\\\\(?:u00([0-9a-fA-F]{2})|x([0-9a-fA-F]{2}))/',
				static function ( $m ) {
					return chr( hexdec( '' !== $m[1] ? $m[1] : $m[2] ) );
				},
				$value
			);
			$value = str_replace( array( '\\/', '\\"', "\\'" ), array( '/', '"', "'" ), $value );
		}
		$value = strtolower( str_replace( "\0", '', $value ) );
		if ( $deep && false !== strpos( $value, '/*' ) ) {
			$value = (string) preg_replace( array( '#/\*!\d*#', '#/\*.*?\*/#s', '#\*/#' ), array( ' ', ' ', ' ' ), $value );
		}
		return (string) preg_replace( '/\s+/', ' ', $value );
	}

	public function header( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $this->server[ $key ] ) ? (string) $this->server[ $key ] : '';
	}

	public function cookie( $name ) {
		return isset( $this->cookies[ $name ] ) && is_string( $this->cookies[ $name ] ) ? $this->cookies[ $name ] : '';
	}

	public function param( $name ) {
		if ( isset( $this->query[ $name ] ) && is_string( $this->query[ $name ] ) ) {
			return $this->query[ $name ];
		}
		return '';
	}

	public function logged_in() {
		foreach ( array_keys( $this->cookies ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}
		return false;
	}

	public function script() {
		$path = $this->path;
		if ( preg_match( '#/(wp-login|xmlrpc|wp-cron|wp-comments-post|wp-signup|wp-activate|wp-trackback)\.php$#', $path, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#/wp-admin/admin-ajax\.php$#', $path ) ) {
			return 'ajax';
		}
		if ( preg_match( '#/wp-admin(/|$)#', $path ) ) {
			return 'admin';
		}
		if ( preg_match( '#/wp-json(/|$)#', $path ) || '' !== $this->param( 'rest_route' ) ) {
			return 'rest';
		}
		return 'front';
	}

	public function rest_route() {
		$route = $this->param( 'rest_route' );
		if ( '' !== $route ) {
			return strtolower( '/' . ltrim( $route, '/' ) );
		}
		return preg_match( '#/wp-json(/.*)?$#', $this->path, $m ) ? ( isset( $m[1] ) ? $m[1] : '/' ) : '';
	}
}
