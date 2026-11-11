<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Crawler {

	const CLAIM = '/(Googlebot|Google-InspectionTool|GoogleOther|AdsBot-Google|Storebot-Google|Mediapartners-Google|FeedFetcher-Google|Google-Site-Verification|APIs-Google|bingbot|BingPreview|msnbot|adidxbot|YandexBot|YandexImages|YandexMobileBot|YandexAccessibilityBot|YandexMetrika|Applebot|DuckDuckBot|DuckAssistBot)/i';

	const DOMAINS = array(
		'g' => array( '.googlebot.com', '.google.com', '.googleusercontent.com' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Host names for checking that a crawler is really Google, nothing is loaded from them.
		'b' => array( '.search.msn.com' ),
		'y' => array( '.yandex.ru', '.yandex.net', '.yandex.com' ),
		'a' => array( '.applebot.apple.com' ),
		'd' => array( '.duckduckgo.com' ),
	);

	const YES = 'yes';

	const PENDING = 'pending';

	const FAKE = 'fake';

	const FILE = 'crawlers.json';

	const QUEUE = 'crawlers.queue';

	const QUEUE_MAX = 65536;

	const KEEP = 4000;

	const GOOD = 604800;

	const BAD = 86400;

	const TRIES = 3;

	private static $ranges = null;

	private static $known = array();

	public static function family( $agent ) {
		if ( '' === (string) $agent || ! preg_match( self::CLAIM, (string) $agent, $match ) ) {
			return '';
		}
		$name = strtolower( $match[1] );
		if ( 0 === strpos( $name, 'bing' ) || 0 === strpos( $name, 'msnbot' ) || 'adidxbot' === $name ) {
			return 'b';
		}
		if ( 0 === strpos( $name, 'yandex' ) ) {
			return 'y';
		}
		if ( 'applebot' === $name ) {
			return 'a';
		}
		if ( 0 === strpos( $name, 'duck' ) ) {
			return 'd';
		}
		return 'g';
	}

	public static function range( $ip ) {
		$raw = @inet_pton( (string) $ip );
		if ( false === $raw ) {
			return '';
		}
		$list = self::ranges();
		if ( 16 === strlen( $raw ) ) {
			foreach ( $list['v6'] as $row ) {
				if ( Ip::in( $ip, $row[0] ) ) {
					return $row[1];
				}
			}
			return '';
		}
		$hex  = bin2hex( $raw );
		$low  = 0;
		$high = count( $list['v4'] ) - 1;
		while ( $low <= $high ) {
			$mid = ( $low + $high ) >> 1;
			$row = $list['v4'][ $mid ];
			if ( strcmp( $hex, $row[0] ) < 0 ) {
				$high = $mid - 1;
			} elseif ( strcmp( $hex, $row[1] ) > 0 ) {
				$low = $mid + 1;
			} else {
				return $row[2];
			}
		}
		return '';
	}

	public static function check( Request $r, $dir ) {
		$family = self::family( $r->agent );
		if ( '' === $family ) {
			return '';
		}
		if ( '' !== self::range( $r->ip ) ) {
			return self::YES;
		}
		$known = self::known( $dir, $r->ip );
		if ( null !== $known ) {
			return $known ? self::YES : self::FAKE;
		}
		self::ask( $dir, $r->ip, $family );
		return self::PENDING;
	}

	public static function known( $dir, $ip ) {
		$list = self::results( $dir );
		if ( ! isset( $list[ $ip ] ) || (int) $list[ $ip ][1] < time() ) {
			return null;
		}
		return 1 === (int) $list[ $ip ][0];
	}

	public static function verify( $dir, $limit = 40, $budget = 20 ) {
		$queue = self::path( $dir, self::QUEUE );
		if ( '' === $queue || ! is_file( $queue ) ) {
			return 0;
		}
		$lines = (array) @file( $queue, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		@unlink( $queue ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- the shield's own queue; checks that do not finish are written back below.
		$wanted = array();
		foreach ( $lines as $line ) {
			$parts = explode( ' ', trim( (string) $line ) );
			if ( count( $parts ) >= 2 && Ip::valid( $parts[0] ) && isset( self::DOMAINS[ $parts[1] ] ) ) {
				$tries                = isset( $parts[2] ) ? (int) $parts[2] : 0;
				$wanted[ $parts[0] ] = array( $parts[1], max( $tries, isset( $wanted[ $parts[0] ] ) ? $wanted[ $parts[0] ][1] : 0 ) );
			}
		}
		$list  = self::results( $dir );
		$now   = time();
		$start = microtime( true );
		$done  = 0;
		$left  = array();
		foreach ( $wanted as $ip => $item ) {
			if ( isset( $list[ $ip ] ) && (int) $list[ $ip ][1] > $now ) {
				continue;
			}
			if ( $done >= $limit || microtime( true ) - $start > $budget ) {
				$left[] = $ip . ' ' . $item[0] . ' ' . $item[1];
				continue;
			}
			++$done;
			$answer = self::genuine( $ip, $item[0] );
			if ( null === $answer && $item[1] + 1 < self::TRIES ) {
				$left[] = $ip . ' ' . $item[0] . ' ' . ( $item[1] + 1 );
				continue;
			}
			$list[ $ip ] = $answer ? array( 1, $now + self::GOOD ) : array( 0, $now + self::BAD );
		}
		self::save( $dir, $list, $now );
		if ( $left ) {
			@file_put_contents( $queue, implode( "\n", $left ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the shield's own queue in its private folder.
		}
		return $done;
	}

	public static function genuine( $ip, $family ) {
		$host = (string) @gethostbyaddr( $ip );
		if ( '' === $host || $host === $ip ) {
			return null;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		$ok   = false;
		foreach ( isset( self::DOMAINS[ $family ] ) ? self::DOMAINS[ $family ] : array() as $suffix ) {
			$ok = $ok || substr( $host, -strlen( $suffix ) ) === $suffix;
		}
		if ( ! $ok ) {
			return false;
		}
		if ( false !== strpos( $ip, ':' ) ) {
			$records = @dns_get_record( $host, DNS_AAAA );
			if ( false === $records ) {
				return null;
			}
			foreach ( (array) $records as $record ) {
				if ( isset( $record['ipv6'] ) && @inet_pton( $record['ipv6'] ) === @inet_pton( $ip ) ) {
					return true;
				}
			}
			return false;
		}
		$forward = @gethostbynamel( $host );
		if ( false === $forward ) {
			return null;
		}
		return in_array( $ip, (array) $forward, true );
	}

	public static function reset() {
		self::$known  = array();
		self::$ranges = null;
	}

	private static function ranges() {
		if ( null === self::$ranges ) {
			$data         = include __DIR__ . '/crawlers.php';
			self::$ranges = is_array( $data ) && isset( $data['v4'], $data['v6'] ) ? $data : array(
				'v4' => array(),
				'v6' => array(),
			);
		}
		return self::$ranges;
	}

	private static function results( $dir ) {
		$file = self::path( $dir, self::FILE );
		if ( '' === $file ) {
			return array();
		}
		if ( ! isset( self::$known[ $file ] ) ) {
			$data                 = is_file( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
			self::$known[ $file ] = is_array( $data ) ? $data : array();
		}
		return self::$known[ $file ];
	}

	private static function save( $dir, array $list, $now ) {
		$file = self::path( $dir, self::FILE );
		if ( '' === $file ) {
			return;
		}
		foreach ( $list as $ip => $row ) {
			if ( ! is_array( $row ) || (int) $row[1] <= $now ) {
				unset( $list[ $ip ] );
			}
		}
		if ( count( $list ) > self::KEEP ) {
			uasort(
				$list,
				static function ( $a, $b ) {
					return (int) $b[1] - (int) $a[1];
				}
			);
			$list = array_slice( $list, 0, self::KEEP, true );
		}
		$temp = $file . '.tmp';
		if ( false !== @file_put_contents( $temp, json_encode( $list ), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- read by the firewall before WordPress loads.
			@rename( $temp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic replace of the shield's own file.
		}
		self::$known[ $file ] = $list;
	}

	private static function ask( $dir, $ip, $family ) {
		$queue = self::path( $dir, self::QUEUE );
		if ( '' === $queue || ! empty( Store::get( 'cq:' . $ip ) ) ) {
			return;
		}
		Store::put( 'cq:' . $ip, array( 1 ), 3600 );
		if ( is_file( $queue ) && (int) @filesize( $queue ) > self::QUEUE_MAX ) {
			return;
		}
		@file_put_contents( $queue, $ip . ' ' . $family . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the shield's own queue in its private folder.
	}

	private static function path( $dir, $name ) {
		return '' === (string) $dir ? '' : rtrim( (string) $dir, '/\\' ) . '/' . $name;
	}
}
