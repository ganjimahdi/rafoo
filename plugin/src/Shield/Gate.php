<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Gate {

	const SAMPLE = 4;

	const GATEWAYS = '/(^|\.)(shaparak\.ir|zarinpal\.com|idpay\.ir|payping\.ir|mydigipay\.com|digipay\.ir|zibal\.ir|nextpay\.org|vandar\.io|sizpay\.ir|pec\.ir|sep\.ir|irankish\.com|asanpardakht\.ir|sadadpsp\.ir|pay\.ir|jibit\.ir|paystar\.ir|aqayepardakht\.ir)$/';

	const SIGNED = array( 'rafoo_transfer', 'rafoo_backup_tick', 'rafoo_geo' );

	private static $request = null;

	private static $config = array();

	private static $trust = -1;

	private static $crawler = '';

	public static function crawler() {
		return self::$crawler;
	}

	public static function request() {
		return self::$request;
	}

	public static function config() {
		return self::$config;
	}

	public static function trust() {
		return self::$trust;
	}

	public static function run( array $config ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- the firewall reads raw input before anything uses it; nothing is saved or printed.
		$decision = self::decide( $config, $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, null );
		if ( ! $decision ) {
			return null;
		}
		$r = self::$request;
		if ( 'pass' === $decision['do'] ) {
			if ( ! headers_sent() ) {
				setcookie( Challenge::PASS, $decision['cookie'], self::cookie_options( (int) $decision['until'] ) );
				header( 'Cache-Control: no-store', true );
				header( 'Location: ' . $r->uri, true, 303 );
			}
			exit;
		}
		if ( 'redirect' === $decision['do'] ) {
			if ( ! headers_sent() ) {
				header( 'Location: ' . ( isset( $config['home'] ) ? $config['home'] : '/' ), true, 301 );
			}
			exit;
		}
		if ( 'challenge' === $decision['do'] ) {
			Reply::challenge( $config, $r, $decision['pow'] );
			exit;
		}
		if ( in_array( $decision['do'], array( 'block', 'limit', 'locked' ), true ) ) {
			Reply::stop( $config, $r, $decision['do'], $decision['ref'], isset( $decision['retry'] ) ? (int) $decision['retry'] : 0 );
			exit;
		}
		return $decision;
	}

	public static function decide( array $config, array $server, array $get, array $post, array $cookies, array $files, $body ) {
		$name = isset( $config['mode'] ) ? (string) $config['mode'] : 'off';
		if ( 'cli' === PHP_SAPI && empty( $config['test'] ) || ! in_array( $name, array( 'smart', 'strict', 'fortress' ), true ) ) {
			return null;
		}
		self::$config = $config;
		$mode         = Modes::get( $name );
		$secret       = isset( $config['secret'] ) ? (string) $config['secret'] : '';
		Store::init( isset( $config['dir'] ) ? (string) $config['dir'] : '', isset( $config['site'] ) ? (string) $config['site'] : '' );
		$r             = Request::capture( $server, $get, $post, $cookies, $files, null === $body ? self::body( $server ) : (string) $body, $config );
		self::$request = $r;
		if ( '' === $r->ip || '' === $secret ) {
			return null;
		}
		if ( self::listed( $r->ip, isset( $config['allow'] ) ? (array) $config['allow'] : array() ) || self::own( $r, $server ) ) {
			return array( 'do' => 'allow' );
		}
		self::$trust   = Trust::read( $secret, $r->cookie( Trust::COOKIE ) );
		$trust         = self::$trust;
		$admin         = $trust >= Trust::ADMIN || isset( $config['admins'][ $r->ip ] );
		$now           = time();
		$key           = 'ip:' . $r->key;
		$dir           = isset( $config['dir'] ) ? (string) $config['dir'] : '';
		$stored        = Store::get( $key );
		$rec           = self::decay( $stored, $now );
		$dirty         = $rec !== $stored;
		self::$crawler = $admin || $trust >= Trust::USER ? '' : Crawler::check( $r, $dir );

		if ( 'POST' === $r->method && isset( $post[ Challenge::FIELD ] ) && is_string( $post[ Challenge::FIELD ] ) ) {
			$solved = Challenge::verify( $secret, $r->ip, $post[ Challenge::FIELD ], $now );
			if ( '' !== $solved && empty( Store::get( 'pow:' . $solved ) ) ) {
				Store::put( 'pow:' . $solved, array( 1 ), Challenge::LIFETIME + 60 );
				$hours     = (int) $mode['pass'];
				$rec['r']  = isset( $rec['r'] ) ? intdiv( (int) $rec['r'], 2 ) : 0;
				$rec['cn'] = 0;
				Store::put( $key, $rec, 86400 );
				return array(
					'do'     => 'pass',
					'cookie' => Challenge::pass( $secret, $r->ip, $r->agent, $hours, $now ),
					'until'  => $now + $hours * 3600,
				);
			}
			$r->navigation = true;
			return self::challenge( $config, $r, $mode, $rec, $key, $now, 'failed' );
		}

		if ( ! $admin && self::listed( $r->ip, isset( $config['deny'] ) ? (array) $config['deny'] : array() ) ) {
			return self::stop( $r, 'block', Rules::verdict( array( Rules::hit( 'l_deny', 'list', 100, 'ip' ) ) ), $rec, $key, $now, false );
		}
		if ( Crawler::YES === self::$crawler ) {
			return self::crawling( $config, $r, $mode, $get, $post, $trust, $dir );
		}
		if ( ! $admin && ! empty( $rec['b'] ) && (int) $rec['b'] > $now ) {
			$verdict = Rules::verdict( array( Rules::hit( isset( $rec['br'] ) ? (string) $rec['br'] : 'l_ban', 'reputation', 100, 'ip' ) ) );
			return self::stop( $r, 'limit' === ( isset( $rec['bk'] ) ? $rec['bk'] : '' ) ? 'limit' : 'block', $verdict, $rec, $key, $now, false, (int) $rec['b'] - $now );
		}
		if ( ! empty( $rec['l'] ) && (int) $rec['l'] > $now && self::signing_in( $r, $post ) ) {
			return self::stop( $r, 'locked', Rules::verdict( array( Rules::hit( 'w_lock', 'login', 100, 'login' ) ) ), $rec, $key, $now, false, (int) $rec['l'] - $now );
		}
		$passed = $trust >= Trust::USER || Challenge::passed( $secret, $r->ip, $r->agent, $r->cookie( Challenge::PASS ), $now );
		$listed = $admin || $trust >= Trust::USER ? null : self::feed( $config, $r->ip, $now );
		if ( $listed ) {
			$soft = 'mixed' === $mode['feed'];
			if ( ! $soft || ( Feed::BLOCK === $listed[0] && ! $passed && ! self::reading( $r ) ) ) {
				return self::stop( $r, 'block', Rules::verdict( array( Rules::hit( 'n_feed', 'network', 100, 'ip' ) ) ), $rec, $key, $now, false );
			}
		}

		$signed = self::signed( $r, $get, $post, $server );
		$extra  = self::wordpress( $config, $r, $mode, $get, $trust );
		$patch  = empty( $config['patches'] ) ? null : Patches::check( (array) $config['patches'], $r, $get, $post );
		if ( $patch ) {
			$extra[] = $patch;
		}
		if ( Crawler::FAKE === self::$crawler ) {
			$extra[] = Rules::hit( 'b_fake', 'fakebot', (int) $mode['suspect'], 'agent' );
		}
		if ( isset( $extra['redirect'] ) ) {
			return array( 'do' => 'redirect' );
		}
		$verdict = $signed ? Rules::verdict( $extra ) : Rules::scan( $r, max( 0, $trust ), $extra );
		$limit   = (int) $mode['reputation'] * ( $passed ? 2 : 1 );
		if ( $verdict['score'] >= $mode['block'] ) {
			if ( ! $admin ) {
				$rec['r']  = ( isset( $rec['r'] ) ? (int) $rec['r'] : 0 ) + min( 50, (int) $verdict['score'] );
				$rec['rt'] = $now;
			}
			return self::stop( $r, 'block', $verdict, $rec, $key, $now, ! $admin, 0, $mode, $limit );
		}
		if ( $verdict['score'] >= $mode['suspect'] && ! $admin ) {
			$rec['r']  = ( isset( $rec['r'] ) ? (int) $rec['r'] : 0 ) + (int) $verdict['score'];
			$rec['rt'] = $now;
			$dirty     = true;
			if ( $rec['r'] >= $limit ) {
				if ( ! $passed && $rec['r'] < 2 * $limit && self::browsing( $r ) ) {
					return self::challenge( $config, $r, $mode, $rec, $key, $now, 'c_suspect' );
				}
				return self::stop( $r, 'block', $verdict, $rec, $key, $now, true, 0, $mode, $limit );
			}
			Log::write( $dir, $r, 'watch', $verdict, '' );
		}

		$free = $trust >= Trust::EDITOR || $signed || self::pinged( $r, $get ) || self::paying( $r, $get );
		$rate = self::count( $rec, $now, $mode, $dirty );
		if ( ! $free && $rate > $mode['limit'] ) {
			$rec['bk'] = 'limit';
			$rec['br'] = 'l_rate';
			$rec['b']  = $now + min( 3600, max( 300, (int) $mode['ban'] / 6 ) );
			return self::stop( $r, 'limit', Rules::verdict( array( Rules::hit( 'l_rate', 'flood', 100, (string) $rate ) ) ), $rec, $key, $now, false, (int) $rec['b'] - $now );
		}
		$needs = ! $free && ( $listed || $mode['everyone'] || ( $mode['challenge'] > 0 && $rate > $mode['challenge'] ) );
		if ( $needs && ! $passed && self::browsing( $r ) ) {
			return self::challenge( $config, $r, $mode, $rec, $key, $now, $listed ? 'n_feed' : ( $mode['everyone'] ? 'c_fortress' : 'c_rate' ) );
		}
		if ( $dirty ) {
			Store::put( $key, $rec, 86400 );
		}
		return array(
			'do'      => 'allow',
			'score'   => $verdict['score'],
			'trusted' => $trust,
		);
	}

	private static function crawling( array $config, Request $r, array $mode, array $get, array $post, $trust, $dir ) {
		$extra = self::wordpress( $config, $r, $mode, $get, $trust );
		$patch = empty( $config['patches'] ) ? null : Patches::check( (array) $config['patches'], $r, $get, $post );
		if ( $patch ) {
			$extra[] = $patch;
		}
		if ( isset( $extra['redirect'] ) ) {
			return array( 'do' => 'redirect' );
		}
		$verdict = Rules::scan( $r, 0, $extra );
		if ( $verdict['score'] >= $mode['block'] ) {
			$verdict['crawler'] = 1;
			$reference          = Log::reference();
			Log::write( $dir, $r, 'block', $verdict, $reference );
			return array(
				'do'      => 'block',
				'ref'     => $reference,
				'retry'   => 0,
				'verdict' => $verdict,
			);
		}
		return array(
			'do'      => 'allow',
			'score'   => $verdict['score'],
			'trusted' => $trust,
			'crawler' => 1,
		);
	}

	private static function browsing( Request $r ) {
		return $r->navigation && ( 'front' === $r->script() || 'wp-login' === $r->script() );
	}

	private static function reading( Request $r ) {
		return in_array( $r->method, array( 'GET', 'HEAD' ), true ) && ! in_array( $r->script(), array( 'wp-login', 'xmlrpc' ), true );
	}

	private static function stop( Request $r, $kind, array $verdict, array $rec, $key, $now, $learn, $retry = 0, $mode = null, $limit = 0 ) {
		$reference = Log::reference();
		$limit     = $limit > 0 ? (int) $limit : ( $mode ? (int) $mode['reputation'] : 0 );
		if ( $learn && $mode && isset( $rec['r'] ) && (int) $rec['r'] >= $limit && ( empty( $rec['b'] ) || (int) $rec['b'] < $now ) ) {
			$rec['b']  = $now + (int) $mode['ban'];
			$rec['br'] = $verdict['rule'];
			$rec['bk'] = 'block';
		}
		$quiet = 'reputation' === $verdict['group'] || 'flood' === $verdict['group'] || 'login' === $verdict['group'] || 'list' === $verdict['group'] || 'network' === $verdict['group'];
		if ( ! $quiet || empty( $rec['s'] ) || $now - (int) $rec['s'] >= 60 ) {
			$rec['s'] = $now;
			Log::write( isset( self::$config['dir'] ) ? self::$config['dir'] : '', $r, $kind, $verdict, $reference );
		}
		Store::put( $key, $rec, 86400 );
		return array(
			'do'      => $kind,
			'ref'     => $reference,
			'retry'   => max( 0, (int) $retry ),
			'verdict' => $verdict,
		);
	}

	private static function challenge( array $config, Request $r, array $mode, array $rec, $key, $now, $reason ) {
		if ( empty( $rec['c'] ) || $now - (int) $rec['c'] >= 600 ) {
			$rec['c'] = $now;
			Log::write( isset( $config['dir'] ) ? $config['dir'] : '', $r, 'challenge', Rules::verdict( array( Rules::hit( $reason, 'challenge', 0, '' ) ) ), '' );
		}
		$rec['cn'] = ( isset( $rec['cn'] ) ? (int) $rec['cn'] : 0 ) + 1;
		Store::put( $key, $rec, 86400 );
		$work = (int) $mode['work'] * ( $rec['cn'] > 20 ? 4 : ( $rec['cn'] > 5 ? 2 : 1 ) );
		return array(
			'do'  => 'challenge',
			'pow' => Challenge::create( (string) $config['secret'], $r->ip, $work, $now ),
		);
	}

	private static function decay( array $rec, $now ) {
		if ( ! empty( $rec['r'] ) && ! empty( $rec['rt'] ) ) {
			$hours = intdiv( $now - (int) $rec['rt'], 3600 );
			if ( $hours > 0 ) {
				$rec['r']  = $hours > 10 ? 0 : (int) ( (int) $rec['r'] >> $hours );
				$rec['rt'] = $now;
			}
		}
		if ( ! empty( $rec['cn'] ) && ! empty( $rec['c'] ) && $now - (int) $rec['c'] > 3600 ) {
			$rec['cn'] = 0;
		}
		return $rec;
	}

	private static function count( array &$rec, $now, array $mode, &$dirty ) {
		$minute = intdiv( $now, 60 );
		$count  = isset( $rec['m'], $rec['n'] ) && (int) $rec['m'] === $minute ? (int) $rec['n'] : 0;
		$step   = 1;
		if ( ! Store::apcu() && empty( self::$config['exact'] ) ) {
			$first = (int) ( $mode['challenge'] > 0 ? min( $mode['challenge'], $mode['limit'] ) : $mode['limit'] );
			if ( $count < intdiv( $first, 4 ) ) {
				if ( 0 !== random_int( 0, self::SAMPLE - 1 ) ) {
					return $count + 1;
				}
				$step = self::SAMPLE;
			}
		}
		$rec['m'] = $minute;
		$rec['n'] = $count + $step;
		$dirty    = true;
		return $rec['n'];
	}

	private static function listed( $ip, array $list ) {
		return $list && Ip::any( $ip, $list );
	}

	private static function own( Request $r, array $server ) {
		$self = isset( $server['SERVER_ADDR'] ) ? (string) $server['SERVER_ADDR'] : '';
		return in_array( $r->ip, array( '127.0.0.1', '::1' ), true ) || ( '' !== $self && $self === $r->ip );
	}

	private static function feed( array $config, $ip, $now ) {
		if ( empty( $config['network'] ) ) {
			return null;
		}
		if ( false !== strpos( $ip, ':' ) ) {
			$six = isset( $config['feed6'][ $ip ] ) ? (array) $config['feed6'][ $ip ] : null;
			return $six && (int) $six[1] > $now ? array( (int) $six[0], (int) $six[1] ) : null;
		}
		return Feed::find( isset( $config['feed'] ) ? (string) $config['feed'] : '', $ip, $now );
	}

	private static function signing_in( Request $r, array $post ) {
		$script = $r->script();
		if ( 'xmlrpc' === $script ) {
			return true;
		}
		if ( 'POST' !== $r->method ) {
			return false;
		}
		return ( 'wp-login' === $script && isset( $post['log'] ) ) || isset( $post['woocommerce-login-nonce'] ) || '' !== $r->header( 'Authorization' ) || ( isset( $post['username'], $post['password'] ) );
	}

	private static function signed( Request $r, array $get, array $post, array $server ) {
		if ( 'ajax' !== $r->script() ) {
			return false;
		}
		$action = isset( $get['action'] ) ? $get['action'] : ( isset( $post['action'] ) ? $post['action'] : '' );
		if ( ! is_string( $action ) || ! in_array( $action, self::SIGNED, true ) ) {
			return false;
		}
		if ( 'rafoo_transfer' === $action ) {
			return isset( $server['HTTP_X_RAFOO_SIGN'] );
		}
		if ( 'rafoo_backup_tick' === $action ) {
			return isset( $post['token'], $post['job'] );
		}
		return 0 === strpos( $r->head, 'RG1' ) || isset( $server['HTTP_X_RAFOO_GEO_LOCAL'] );
	}

	private static function pinged( Request $r, array $get ) {
		return 'GET' === $r->method && isset( $get['rafoo-ping'] ) && is_string( $get['rafoo-ping'] ) && preg_match( '/^[A-Za-z0-9_-]{16,48}$/', $get['rafoo-ping'] );
	}

	private static function paying( Request $r, array $get ) {
		if ( isset( $get['wc-api'] ) || false !== strpos( $r->path, '/wc-api/' ) ) {
			return true;
		}
		$host = '' !== $r->referer ? strtolower( (string) parse_url( $r->referer, PHP_URL_HOST ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs before WordPress loads, where wp_parse_url does not exist.
		return '' !== $host && (bool) preg_match( self::GATEWAYS, $host );
	}

	private static function wordpress( array $config, Request $r, array $mode, array $get, $trust ) {
		$out    = array();
		$script = $r->script();
		if ( 'xmlrpc' === $script && 'all' === $mode['xmlrpc'] && $trust < Trust::ADMIN ) {
			$out[] = Rules::hit( 'w_xmlrpc', 'wordpress', 100, 'xmlrpc' );
		}
		if ( $trust < Trust::USER && 'front' === $script && isset( $get['author'] ) && ! $r->logged_in() ) {
			if ( 'redirect' === $mode['authors'] ) {
				$out['redirect'] = true;
			} else {
				$out[] = Rules::hit( 'w_authors', 'wordpress', 100, 'author' );
			}
		}
		$route = $r->rest_route();
		if ( $trust < Trust::USER && '' !== $route && preg_match( '#^/wp/v2/users(/|$|\?)#', $route ) && ! $r->logged_in() ) {
			$out[] = Rules::hit( 'w_users', 'wordpress', 100, 'rest' );
		}
		return $out;
	}

	public static function cookie_options( $expires ) {
		$https = ( isset( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared with fixed values only.
		return array(
			'expires'  => (int) $expires,
			'path'     => '/',
			'secure'   => $https,
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	public static function body( array $server ) {
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET';
		$type   = isset( $server['CONTENT_TYPE'] ) ? strtolower( (string) $server['CONTENT_TYPE'] ) : '';
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) || false !== strpos( $type, 'multipart/' ) || false !== strpos( $type, 'x-www-form-urlencoded' ) ) {
			return '';
		}
		$length = isset( $server['CONTENT_LENGTH'] ) ? (int) $server['CONTENT_LENGTH'] : 0;
		if ( $length <= 0 || $length > 1048576 ) {
			return '';
		}
		$body = @file_get_contents( 'php://input', false, null, 0, 262144 );
		return is_string( $body ) ? $body : '';
	}
}
