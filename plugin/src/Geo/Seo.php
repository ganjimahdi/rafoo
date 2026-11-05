<?php

namespace Rafoo\Geo;

defined( 'ABSPATH' ) || exit;

final class Seo {

	const BOTS = '/(Googlebot|AdsBot-Google|Google-InspectionTool|Storebot-Google|GoogleOther|bingbot|YandexBot|Applebot|DuckDuckBot)/i';

	const LOG_MAX = 2097152;

	const DAYS = 7;

	const DOMAINS = array(
		'google' => array( '.googlebot.com', '.google.com', '.googleusercontent.com' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Host names for checking that a crawler is really Google, nothing is loaded from them.
		'bing'   => array( '.search.msn.com' ),
		'yandex' => array( '.yandex.ru', '.yandex.net', '.yandex.com' ),
		'apple'  => array( '.applebot.apple.com' ),
	);

	private static $start = 0.0;

	private static $bot = '';

	public static function boot() {
		if ( ! Store::active() ) {
			return;
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $agent || ! preg_match( self::BOTS, $agent, $match ) ) {
			return;
		}
		self::$bot   = self::family( $match[1] );
		self::$start = microtime( true );
		register_shutdown_function( array( __CLASS__, 'log' ) );
	}

	private static function family( $name ) {
		$name = strtolower( (string) $name );
		if ( false !== strpos( $name, 'google' ) || 0 === strpos( $name, 'adsbot' ) || 0 === strpos( $name, 'storebot' ) ) {
			return 'google';
		}
		if ( 'bingbot' === $name ) {
			return 'bing';
		}
		if ( 'yandexbot' === $name ) {
			return 'yandex';
		}
		if ( 'applebot' === $name ) {
			return 'apple';
		}
		return 'other';
	}

	public static function log() {
		$file = Lister::path( 'crawl.log' );
		if ( '' === $file ) {
			return;
		}
		$code = (int) http_response_code();
		$path = substr( (string) preg_replace( '/[\t\r\n]+/', ' ', Edge::request_path() ), 0, 200 );
		$line = time() . "\t" . Proxy::client_ip() . "\t" . self::$bot . "\t" . ( $code > 0 ? $code : 200 ) . "\t" . (int) round( ( microtime( true ) - self::$start ) * 1000 ) . "\t" . $path . "\n";
		clearstatcache( true, $file );
		if ( is_file( $file ) && filesize( $file ) > self::LOG_MAX ) {
			rename( $file, Lister::path( 'crawl.old' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Rotates a log in the private folder during shutdown, where WP_Filesystem is not loaded.
		}
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	private static function lines() {
		$out = array();
		foreach ( array( 'crawl.old', 'crawl.log' ) as $name ) {
			$file = Lister::path( $name );
			if ( '' === $file || ! is_file( $file ) ) {
				continue;
			}
			foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $line ) {
				$parts = explode( "\t", $line );
				if ( 6 === count( $parts ) ) {
					$out[] = $parts;
				}
			}
		}
		return $out;
	}

	private static function verified() {
		$file = Lister::path( 'bots.json' );
		$data = '' !== $file && is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $data ) ? $data : array();
	}

	public static function summary() {
		$since    = time() - self::DAYS * DAY_IN_SECONDS;
		$verified = self::verified();
		$out      = array();
		foreach ( self::lines() as $parts ) {
			if ( (int) $parts[0] < $since ) {
				continue;
			}
			$bot = $parts[2];
			if ( ! isset( $out[ $bot ] ) ) {
				$out[ $bot ] = array(
					'hits'     => 0,
					'codes'    => array(
						'2' => 0,
						'3' => 0,
						'4' => 0,
						'5' => 0,
					),
					'ms'       => 0,
					'last'     => 0,
					'verified' => 0,
					'fake'     => 0,
				);
			}
			$class = substr( $parts[3], 0, 1 );
			++$out[ $bot ]['hits'];
			if ( isset( $out[ $bot ]['codes'][ $class ] ) ) {
				++$out[ $bot ]['codes'][ $class ];
			}
			$out[ $bot ]['ms']  += (int) $parts[4];
			$out[ $bot ]['last'] = max( $out[ $bot ]['last'], (int) $parts[0] );
			if ( isset( $verified[ $parts[1] ] ) ) {
				$verified[ $parts[1] ] ? ++$out[ $bot ]['verified'] : ++$out[ $bot ]['fake'];
			}
		}
		foreach ( $out as $bot => $row ) {
			$out[ $bot ]['ms'] = $row['hits'] ? (int) round( $row['ms'] / $row['hits'] ) : 0;
		}
		return $out;
	}

	public static function verify_batch( $limit = 10 ) {
		$verified = self::verified();
		$todo     = array();
		foreach ( self::lines() as $parts ) {
			if ( ! isset( $verified[ $parts[1] ] ) && isset( self::DOMAINS[ $parts[2] ] ) && filter_var( $parts[1], FILTER_VALIDATE_IP ) ) {
				$todo[ $parts[1] ] = $parts[2];
			}
			if ( count( $todo ) >= $limit ) {
				break;
			}
		}
		if ( ! $todo ) {
			return;
		}
		foreach ( $todo as $ip => $bot ) {
			$verified[ $ip ] = self::genuine( $ip, $bot ) ? 1 : 0;
		}
		if ( count( $verified ) > 5000 ) {
			$verified = array_slice( $verified, -4000, null, true );
		}
		file_put_contents( Lister::path( 'bots.json' ), wp_json_encode( $verified ) );
	}

	public static function genuine( $ip, $bot ) {
		$host = (string) gethostbyaddr( $ip );
		if ( '' === $host || $host === $ip ) {
			return false;
		}
		$ok = false;
		foreach ( isset( self::DOMAINS[ $bot ] ) ? self::DOMAINS[ $bot ] : array() as $suffix ) {
			if ( substr( strtolower( $host ), -strlen( $suffix ) ) === $suffix ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			return false;
		}
		if ( false !== strpos( $ip, ':' ) ) {
			$records = dns_get_record( $host, DNS_AAAA );
			foreach ( (array) $records as $record ) {
				if ( isset( $record['ipv6'] ) && inet_pton( $record['ipv6'] ) === inet_pton( $ip ) ) {
					return true;
				}
			}
			return false;
		}
		return in_array( $ip, (array) gethostbynamel( $host ), true );
	}

	public static function stale() {
		if ( ! is_404() ) {
			return;
		}
		$state = Syncer::state();
		$quiet = time() - (int) $state['ok_at'] > 90;
		if ( ! Syncer::behind() && ! $quiet ) {
			return;
		}
		$reply = Proxy::fetch( Edge::request_uri() );
		if ( is_wp_error( $reply ) ) {
			if ( Syncer::behind() ) {
				Proxy::unavailable();
			}
			return;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $reply ) ) {
			return;
		}
		status_header( 200 );
		$type = (string) wp_remote_retrieve_header( $reply, 'content-type' );
		header( 'Content-Type: ' . ( '' !== $type ? $type : 'text/html; charset=utf-8' ) );
		file_put_contents( 'php://output', (string) wp_remote_retrieve_body( $reply ) );
		exit;
	}

	public static function fingerprint() {
		$self = Store::self_node();
		if ( ! $self ) {
			return array( 'ok' => false );
		}
		$out = array( 'ok' => true );
		foreach ( array( 'robots' => '/robots.txt', 'sitemap' => '/wp-sitemap.xml', 'home' => '/' ) as $name => $path ) {
			$reply = Edge::local_get( $self, $path, 20 );
			if ( is_wp_error( $reply ) ) {
				$out[ $name ] = array( 'code' => 0 );
				continue;
			}
			$body = (string) wp_remote_retrieve_body( $reply );
			$row  = array( 'code' => (int) wp_remote_retrieve_response_code( $reply ) );
			if ( 'home' === $name ) {
				$row['canonical'] = preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)/i', $body, $m ) ? $m[1] : '';
				$row['robots']    = preg_match( '/<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']+)/i', $body, $m ) ? $m[1] : '';
				$row['title']     = preg_match( '/<title>(.*?)<\/title>/is', $body, $m ) ? md5( trim( $m[1] ) ) : '';
			} else {
				$row['hash'] = md5( (string) preg_replace( '/\s+/', ' ', $body ) );
			}
			$out[ $name ] = $row;
		}
		return $out;
	}

	public static function checks() {
		$results = array();
		$prints  = array();
		foreach ( Store::nodes() as $id => $node ) {
			if ( 'ready' !== $node['status'] ) {
				continue;
			}
			$print         = (string) Store::get( 'self' ) === $id ? self::fingerprint() : Link::call( $node, 'fingerprint', array(), false, 60 );
			$prints[ $id ] = is_wp_error( $print ) ? null : $print;
			$plain         = wp_remote_get(
				untrailingslashit( $node['url'] ) . '/',
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'sslverify'   => (bool) apply_filters( 'rafoo_geo_sslverify', true ),
					'rafoo_probe' => true,
				)
			);
			$location      = is_wp_error( $plain ) ? '' : (string) wp_remote_retrieve_header( $plain, 'location' );
			$robots        = is_wp_error( $plain ) ? '' : (string) wp_remote_retrieve_header( $plain, 'x-robots-tag' );
			$results[ $id ] = array(
				'redirect' => ! is_wp_error( $plain ) && 301 === (int) wp_remote_retrieve_response_code( $plain ) && 0 === strpos( $location, Store::main_url( '/' ) ) && false !== stripos( $robots, 'noindex' ),
				'print'    => null !== $prints[ $id ],
			);
		}
		$base = null;
		foreach ( $prints as $print ) {
			if ( $print ) {
				$base = $print;
				break;
			}
		}
		foreach ( $prints as $id => $print ) {
			$same = $print && $base;
			foreach ( array( 'robots', 'sitemap' ) as $name ) {
				$same = $same && isset( $print[ $name ]['hash'], $base[ $name ]['hash'] ) && $print[ $name ]['hash'] === $base[ $name ]['hash'] && $print[ $name ]['code'] === $base[ $name ]['code'];
			}
			$canonical                 = $print && isset( $print['home']['canonical'] ) ? (string) $print['home']['canonical'] : '';
			$results[ $id ]['same']    = (bool) $same;
			$results[ $id ]['home']    = $print ? (int) $print['home']['code'] : 0;
			$results[ $id ]['canonical'] = '' === $canonical || 0 === strpos( $canonical, Store::main_url( '/' ) );
			$results[ $id ]['noindex'] = $print && false !== stripos( (string) $print['home']['robots'], 'noindex' );
		}
		Store::save_state(
			array(
				'checks' => array(
					'at'      => time(),
					'results' => $results,
				),
			)
		);
		return $results;
	}
}
