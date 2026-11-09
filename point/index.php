<?php

const RP_VERSION    = '2.1.0';
const RP_ENDPOINT   = 'https://rafoo.ir/api/v1/peers/';
const RP_PATH       = '/api/v1/peers/';
const RP_SIGNAL     = 'https://rafoo.ir/api/v1/status';
const RP_PUBLIC     = 'luWKblSfyFmOSN9JIv5loTSs2evI6AsMd1KA49sZF1w=';
const RP_QUERY      = 'rafoo-ping';
const RP_TIMEOUT    = 8;
const RP_CONNECT    = 6;
const RP_KEEP       = 172800;
const RP_FLUSH      = 900;
const RP_SENTINEL   = 600;
const RP_MIN_TICK   = 30;
const RP_MAX_BATCH  = 600;
const RP_MAX_TARGET = 20;
const RP_SIGNAL_TTL = 600;
const RP_REJOIN     = 600;
const RP_BLOCKED    = 86400;
const RP_GUARD      = "<?php exit; ?>\n";
const RP_WAKE       = 'rafoo-wake';
const RP_UPDATE     = 'https://rafoo.ir/api/v1/point/latest';
const RP_UPDATE_TTL = 21600;
const RP_BATCH      = 8;
const RP_SIGNAL_GAP = 300;
const RP_MAX_FILE   = 1048576;

error_reporting( 0 );
ini_set( 'display_errors', '0' );

function rp_config() {
	static $config = null;
	if ( null === $config ) {
		$file   = __DIR__ . '/config.php';
		$loaded = is_file( $file ) ? include $file : array();
		$config = array_merge(
			array(
				'url'         => '',
				'interval'    => 60,
				'endpoint'    => RP_ENDPOINT,
				'signal'      => RP_SIGNAL,
				'public'      => RP_PUBLIC,
				'allow_local' => false,
				'verify_tls'  => true,
				'update'      => RP_UPDATE,
				'auto_update' => true,
			),
			is_array( $loaded ) ? $loaded : array()
		);
	}
	return $config;
}

function rp_dir() {
	$dir = __DIR__ . '/data';
	if ( ! is_dir( $dir ) ) {
		@mkdir( $dir, 0755, true );
	}
	if ( ! is_file( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}
	if ( ! is_file( $dir . '/index.html' ) ) {
		@file_put_contents( $dir . '/index.html', '' );
	}
	return $dir;
}

function rp_read( $name ) {
	$file = rp_dir() . '/' . $name . '.php';
	$raw  = is_file( $file ) ? (string) @file_get_contents( $file ) : '';
	if ( 0 === strpos( $raw, RP_GUARD ) ) {
		$raw = substr( $raw, strlen( RP_GUARD ) );
	}
	$data = '' !== $raw ? json_decode( $raw, true ) : null;
	return is_array( $data ) ? $data : array();
}

function rp_write( $name, array $data ) {
	$file = rp_dir() . '/' . $name . '.php';
	$tmp  = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
	if ( false === @file_put_contents( $tmp, RP_GUARD . json_encode( $data, JSON_UNESCAPED_SLASHES ) ) ) {
		return false;
	}
	if ( ! @rename( $tmp, $file ) ) {
		@unlink( $tmp );
		return false;
	}
	return true;
}

function rp_state() {
	return rp_read( 'state' );
}

function rp_update( array $changes ) {
	$state = array_merge( rp_state(), $changes );
	rp_write( 'state', $state );
	return $state;
}

function rp_ready() {
	static $ready = null;
	if ( null === $ready ) {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) && is_file( __DIR__ . '/sodium_compat/autoload.php' ) ) {
			require_once __DIR__ . '/sodium_compat/autoload.php';
		}
		$ready = function_exists( 'sodium_crypto_sign_detached' ) && function_exists( 'sodium_crypto_sign_verify_detached' ) && function_exists( 'sodium_crypto_sign_keypair' );
	}
	return $ready;
}

function rp_sodium() {
	if ( extension_loaded( 'sodium' ) ) {
		return 'native';
	}
	return rp_ready() ? 'compat' : '';
}

function rp_jitter( $range ) {
	$state = rp_state();
	$seed  = isset( $state['pk'] ) ? (string) $state['pk'] : __DIR__;
	return (int) ( abs( crc32( $seed ) ) % max( 1, (int) $range ) );
}

function rp_keys() {
	$state = rp_state();
	if ( ! empty( $state['pk'] ) && ! empty( $state['sk'] ) ) {
		return $state;
	}
	if ( ! rp_ready() ) {
		return $state;
	}
	$pair = sodium_crypto_sign_keypair();
	return rp_update(
		array(
			'pk' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
			'sk' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
		)
	);
}

function rp_sign( $message ) {
	$state  = rp_state();
	$secret = isset( $state['sk'] ) ? base64_decode( (string) $state['sk'], true ) : false;
	if ( false === $secret || 64 !== strlen( $secret ) || ! rp_ready() ) {
		return '';
	}
	return base64_encode( sodium_crypto_sign_detached( (string) $message, $secret ) );
}

function rp_verify( $message, $signature, $public ) {
	$sig = base64_decode( (string) $signature, true );
	$key = base64_decode( (string) $public, true );
	if ( false === $sig || false === $key || 64 !== strlen( $sig ) || 32 !== strlen( $key ) || ! rp_ready() ) {
		return false;
	}
	try {
		return sodium_crypto_sign_verify_detached( $sig, (string) $message, $key );
	} catch ( Throwable $e ) {
		return false;
	}
}

function rp_open( $body ) {
	$envelope = json_decode( (string) $body, true );
	if ( ! is_array( $envelope ) || ! isset( $envelope['payload'], $envelope['sig'] ) || ! is_string( $envelope['payload'] ) ) {
		return null;
	}
	$payload = base64_decode( $envelope['payload'], true );
	if ( false === $payload || ! rp_verify( $payload, (string) $envelope['sig'], rp_config()['public'] ) ) {
		return null;
	}
	$data = json_decode( $payload, true );
	return is_array( $data ) ? $data : null;
}

function rp_nonce() {
	return rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
}

function rp_host( $url ) {
	$parts = parse_url( (string) $url );
	$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	if ( '' !== $host && ! empty( $parts['port'] ) ) {
		$host .= ':' . (int) $parts['port'];
	}
	return $host;
}

function rp_agent() {
	return 'RafooPoint/' . RP_VERSION . ' (+https://rafoo.ir)';
}

function rp_public_url( $url ) {
	if ( rp_config()['allow_local'] ) {
		return (bool) preg_match( '#^https?://[^/\s]+/#i', $url );
	}
	$parts = parse_url( (string) $url );
	if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( 'localhost' === $host || false === strpos( $host, '.' ) ) {
		return false;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
	return true;
}

function rp_curl_options( $timeout ) {
	$options = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => min( RP_CONNECT, $timeout ),
		CURLOPT_TIMEOUT        => $timeout,
		CURLOPT_USERAGENT      => rp_agent(),
		CURLOPT_SSL_VERIFYPEER => (bool) rp_config()['verify_tls'],
		CURLOPT_SSL_VERIFYHOST => rp_config()['verify_tls'] ? 2 : 0,
		CURLOPT_NOSIGNAL       => true,
	);
	if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
		$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
	}
	return $options;
}

function rp_http( $method, $url, $body = null, array $headers = array(), $timeout = 10 ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch   = curl_init( $url );
		$list = array();
		foreach ( $headers as $name => $value ) {
			$list[] = $name . ': ' . $value;
		}
		$options = rp_curl_options( $timeout ) + array( CURLOPT_HTTPHEADER => $list );
		if ( 'POST' === $method ) {
			$options[ CURLOPT_POST ]       = true;
			$options[ CURLOPT_POSTFIELDS ] = (string) $body;
		}
		curl_setopt_array( $ch, $options );
		$text  = curl_exec( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error = curl_error( $ch );
		curl_close( $ch );
		if ( false === $text ) {
			return array( 'error' => '' !== $error ? $error : 'request failed' );
		}
		return array(
			'code' => $code,
			'body' => (string) $text,
		);
	}
	$lines = array( 'User-Agent: ' . rp_agent() );
	foreach ( $headers as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}
	$context = stream_context_create(
		array(
			'http' => array(
				'method'          => $method,
				'header'          => implode( "\r\n", $lines ),
				'content'         => null === $body ? '' : (string) $body,
				'timeout'         => $timeout,
				'ignore_errors'   => true,
				'follow_location' => 0,
			),
			'ssl'  => array(
				'verify_peer'      => (bool) rp_config()['verify_tls'],
				'verify_peer_name' => (bool) rp_config()['verify_tls'],
			),
		)
	);
	$text = @file_get_contents( $url, false, $context );
	if ( false === $text ) {
		return array( 'error' => 'request failed' );
	}
	$code = 0;
	if ( isset( $http_response_header[0] ) && preg_match( '#\s(\d{3})\s#', $http_response_header[0] . ' ', $m ) ) {
		$code = (int) $m[1];
	}
	return array(
		'code' => $code,
		'body' => (string) $text,
	);
}

function rp_api( $action, array $body = array(), $timeout = 10, $urgent = false ) {
	$now   = time();
	$state = rp_state();
	if ( ! $urgent && ! empty( $state['backoff'] ) && $now < (int) $state['backoff'] ) {
		return array(
			'error' => 'rafoo.ir asked to wait a little.',
			'kind'  => 'backoff',
		);
	}
	$raw   = json_encode( $body ? $body : new stdClass(), JSON_UNESCAPED_SLASHES );
	$time  = (string) time();
	$nonce = rp_nonce();
	$sig   = rp_sign( 'rafoo-peer|' . RP_PATH . $action . '|' . $time . '|' . $nonce . '|' . hash( 'sha256', $raw ) );
	$state = rp_state();
	if ( '' === $sig || empty( $state['pk'] ) ) {
		return array( 'error' => 'no signing key' );
	}
	$reply = rp_http(
		'POST',
		rtrim( rp_config()['endpoint'], '/' ) . '/' . $action,
		$raw,
		array(
			'Content-Type'  => 'application/json',
			'X-Rafoo-Key'   => $state['pk'],
			'X-Rafoo-Time'  => $time,
			'X-Rafoo-Nonce' => $nonce,
			'X-Rafoo-Sig'   => $sig,
		),
		$timeout
	);
	if ( isset( $reply['error'] ) ) {
		return $reply;
	}
	if ( 429 === $reply['code'] || 503 === $reply['code'] ) {
		rp_update( array( 'backoff' => $now + 120 + rp_jitter( 60 ) ) );
	}
	if ( $reply['code'] < 200 || $reply['code'] >= 300 ) {
		$data = json_decode( $reply['body'], true );
		return array(
			'error' => is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $reply['code'],
			'kind'  => is_array( $data ) && ! empty( $data['error'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $data['error'] ) ) : 'http_' . $reply['code'],
		);
	}
	return $reply;
}

function rp_fail( $message, array $extra = array() ) {
	return rp_update(
		array_merge(
			array(
				'error'    => substr( (string) $message, 0, 300 ),
				'error_at' => time(),
			),
			$extra
		)
	);
}

function rp_blocked( array $state, $now ) {
	return isset( $state['blocked_until'] ) && (int) $state['blocked_until'] > $now;
}
function rp_join( $url ) {
	$state = rp_keys();
	if ( empty( $state['pk'] ) ) {
		rp_fail( 'The sodium extension of PHP is missing.' );
		return false;
	}
	$interval = 300 === (int) rp_config()['interval'] ? 300 : 60;
	rp_update(
		array(
			'pending'   => $url,
			'join_try'  => time(),
		)
	);
	$reply = rp_api(
		'join',
		array(
			'url'      => $url,
			'interval' => $interval,
			'edition'  => 'point',
			'version'  => RP_VERSION,
		),
		25
	);
	if ( isset( $reply['error'] ) ) {
		if ( isset( $reply['kind'] ) && 'blocked' === $reply['kind'] ) {
			rp_update( array( 'blocked_until' => time() + RP_BLOCKED ) );
		}
		rp_fail( $reply['error'] );
		return false;
	}
	$data = json_decode( $reply['body'], true );
	if ( ! is_array( $data ) || empty( $data['id'] ) ) {
		rp_fail( 'rafoo.ir gave an answer that could not be read.' );
		return false;
	}
	rp_update(
		array(
			'member'    => (int) $data['id'],
			'url'       => $url,
			'pending'   => '',
			'interval'  => $interval,
			'country'   => isset( $data['country'] ) ? substr( (string) $data['country'], 0, 4 ) : '',
			'network'   => isset( $data['network'] ) ? substr( (string) $data['network'], 0, 80 ) : '',
			'scope'     => isset( $data['scope'] ) && 'intl' === $data['scope'] ? 'intl' : 'ir',
			'joined_at' => time(),
			'tasks'     => array(),
			'issued_at' => 0,
			'tasks_try' => 0,
			'next'          => 0,
			'error'         => '',
			'blocked_until' => 0,
		)
	);
	rp_write( 'log', array() );
	rp_refresh();
	return true;
}

function rp_refresh() {
	$state = rp_state();
	$reply = rp_api(
		'tasks',
		array(
			'interval' => isset( $state['interval'] ) ? (int) $state['interval'] : 60,
			'version'  => RP_VERSION,
		)
	);
	if ( isset( $reply['error'] ) ) {
		$extra = array( 'tasks_try' => time() );
		if ( isset( $reply['kind'] ) && 'not_member' === $reply['kind'] ) {
			$extra['member'] = 0;
		}
		rp_fail( $reply['error'], $extra );
		return false;
	}
	$tasks = rp_open( $reply['body'] );
	if ( ! is_array( $tasks ) || 'rafoo-peer-tasks' !== ( isset( $tasks['kind'] ) ? $tasks['kind'] : '' ) || (int) $tasks['member'] !== (int) $state['member'] ) {
		rp_fail( 'The task list from rafoo.ir could not be verified.', array( 'tasks_try' => time() ) );
		return false;
	}
	$targets = array();
	foreach ( isset( $tasks['targets'] ) && is_array( $tasks['targets'] ) ? $tasks['targets'] : array() as $t ) {
		$target = is_array( $t ) ? rp_target( $t ) : null;
		if ( ! $target ) {
			continue;
		}
		$targets[] = $target;
		if ( count( $targets ) >= RP_MAX_TARGET ) {
			break;
		}
	}
	rp_update(
		array(
			'tasks'     => $targets,
			'epoch'     => (int) $tasks['epoch'],
			'issued_at' => (int) $tasks['issued_at'],
			'valid'     => (int) $tasks['valid_until'],
			'refresh'   => max( 600, (int) $tasks['refresh'] ),
			'intl'      => isset( $tasks['intl'] ) && 'down' === $tasks['intl'] ? 'down' : 'up',
			'scope'     => isset( $tasks['scope'] ) && 'intl' === $tasks['scope'] ? 'intl' : 'ir',
			'tasks_try' => time(),
			'next'      => 0,
			'error'     => '',
		)
	);
	return true;
}

function rp_target( array $t ) {
	if ( ! isset( $t['id'], $t['url'], $t['key'], $t['grant'] ) || ! rp_public_url( (string) $t['url'] ) ) {
		return null;
	}
	return array(
		'id'       => (int) $t['id'],
		'url'      => (string) $t['url'],
		'key'      => (string) $t['key'],
		'interval' => isset( $t['interval'] ) && 60 === (int) $t['interval'] ? 60 : 300,
		'scope'    => isset( $t['scope'] ) && 'intl' === $t['scope'] ? 'intl' : 'ir',
		'grant'    => (string) $t['grant'],
	);
}

function rp_signal( array $state, $now ) {
	if ( $now - ( isset( $state['signal_try'] ) ? (int) $state['signal_try'] : 0 ) < RP_SIGNAL_GAP + rp_jitter( 60 ) ) {
		return $state;
	}
	$reply   = rp_http( 'GET', rp_config()['signal'], null, array( 'Accept' => 'application/json' ), 6 );
	$changes = array( 'signal_try' => $now );
	if ( ! isset( $reply['error'] ) && 200 === $reply['code'] ) {
		$data = rp_open( $reply['body'] );
		if ( is_array( $data ) && isset( $data['state'], $data['issued_at'] ) && in_array( $data['state'], array( 'up', 'down' ), true ) ) {
			$changes['signal']    = $data['state'];
			$changes['signal_at'] = (int) $data['issued_at'];
		}
	}
	return rp_update( $changes );
}

function rp_offline( array $state, $now ) {
	if ( isset( $state['signal'], $state['signal_at'] ) && $now - (int) $state['signal_at'] <= RP_SIGNAL_TTL && (int) $state['signal_at'] <= $now + 60 ) {
		return 'down' === $state['signal'];
	}
	return isset( $state['intl'] ) && 'down' === $state['intl'];
}

function rp_shape( array $log ) {
	return array(
		'b'        => isset( $log['b'] ) && is_array( $log['b'] ) ? $log['b'] : array(),
		'last'     => isset( $log['last'] ) && is_array( $log['last'] ) ? $log['last'] : array(),
		'flush_at' => isset( $log['flush_at'] ) ? (int) $log['flush_at'] : 0,
		'dirty'    => ! empty( $log['dirty'] ),
		'sentinel' => isset( $log['sentinel'] ) ? (int) $log['sentinel'] : 0,
		'turn'     => isset( $log['turn'] ) ? (int) $log['turn'] : 0,
	);
}

function rp_due( array $targets, array $log, $now, $offline, $scope ) {
	$list = array();
	foreach ( $targets as $t ) {
		$id = (int) $t['id'];
		if ( 'ir' === $scope && $offline && 'intl' === $t['scope'] ) {
			continue;
		}
		$every = 60 === (int) $t['interval'] ? 60 : 300;
		$last  = isset( $log['last'][ $id ]['at'] ) ? (int) $log['last'][ $id ]['at'] : 0;
		if ( $now - $last >= $every - 5 ) {
			$list[] = $id;
		}
	}
	if ( 'intl' === $scope && $log['sentinel'] > 0 ) {
		$iran = array();
		foreach ( $targets as $t ) {
			if ( 'ir' === $t['scope'] ) {
				$iran[] = (int) $t['id'];
			}
		}
		$list = array_values( array_diff( $list, $iran ) );
		if ( $iran && $now - $log['sentinel'] >= RP_SENTINEL ) {
			$list[] = $iran[ $log['turn'] % count( $iran ) ];
		}
	}
	return $list;
}

function rp_record( array $log, array $target, $epoch, $now, $ok, $ms, $code ) {
	$id   = (int) $target['id'];
	$hour = (int) ( floor( $now / 3600 ) * 3600 );
	$key  = $id . ':' . $hour . ':' . (int) $epoch;
	$b    = isset( $log['b'][ $key ] ) ? $log['b'][ $key ] : array(
		't'  => $id,
		'e'  => (int) $epoch,
		'g'  => (string) $target['grant'],
		'h'  => $hour,
		'n'  => 0,
		'ok' => 0,
		'ms' => 0,
	);
	++$b['n'];
	if ( $ok ) {
		++$b['ok'];
		$b['ms'] += max( 0, (int) $ms );
	}
	$b['at']            = (int) $now;
	$b['last']          = (bool) $ok;
	$b['code']          = (int) $code;
	$log['b'][ $key ]   = $b;
	$before             = isset( $log['last'][ $id ] ) ? (bool) $log['last'][ $id ]['ok'] : null;
	$log['last'][ $id ] = array(
		'at'   => (int) $now,
		'ok'   => (bool) $ok,
		'ms'   => (int) $ms,
		'code' => (int) $code,
	);
	if ( null !== $before && $before !== (bool) $ok ) {
		$log['dirty'] = true;
	}
	return $log;
}

function rp_sentinel( array $log, array $targets, array $results, $now, $scope ) {
	if ( 'intl' !== $scope ) {
		$log['sentinel'] = 0;
		return $log;
	}
	$iran = array();
	foreach ( $targets as $t ) {
		if ( 'ir' === $t['scope'] ) {
			$iran[ (int) $t['id'] ] = true;
		}
	}
	$tried  = 0;
	$failed = 0;
	foreach ( $results as $id => $r ) {
		if ( isset( $iran[ (int) $id ] ) ) {
			++$tried;
			if ( empty( $r['ok'] ) ) {
				++$failed;
			}
		}
	}
	if ( 0 === $tried ) {
		return $log;
	}
	if ( $log['sentinel'] > 0 ) {
		if ( $failed < $tried ) {
			$log['sentinel'] = 0;
			$log['dirty']    = true;
		} else {
			$log['sentinel'] = (int) $now;
			++$log['turn'];
		}
		return $log;
	}
	if ( count( $iran ) >= 2 && $tried >= 2 && $failed * 10 >= $tried * 8 ) {
		$log['sentinel'] = (int) $now;
		$log['dirty']    = true;
	}
	return $log;
}

function rp_items( array $log ) {
	$items = array();
	foreach ( $log['b'] as $b ) {
		$items[] = array(
			't'    => (int) $b['t'],
			'e'    => (int) $b['e'],
			'g'    => (string) $b['g'],
			'h'    => (int) $b['h'],
			'n'    => (int) $b['n'],
			'ok'   => (int) $b['ok'],
			'ms'   => $b['ok'] > 0 ? (int) round( $b['ms'] / $b['ok'] ) : 0,
			'at'   => (int) $b['at'],
			'last' => (bool) $b['last'],
			'code' => (int) $b['code'],
		);
		if ( count( $items ) >= RP_MAX_BATCH ) {
			break;
		}
	}
	return $items;
}

function rp_prune( array $log, array $targets, $now ) {
	$keep = array();
	foreach ( $targets as $t ) {
		$keep[ (int) $t['id'] ] = true;
	}
	foreach ( $log['b'] as $key => $b ) {
		if ( $now - (int) $b['at'] > RP_KEEP ) {
			unset( $log['b'][ $key ] );
		}
	}
	foreach ( $log['last'] as $id => $l ) {
		if ( ! isset( $keep[ (int) $id ] ) && $now - (int) $l['at'] > RP_KEEP ) {
			unset( $log['last'][ $id ] );
		}
	}
	return $log;
}

function rp_next( array $targets, array $log, $now, $offline, $scope ) {
	$soon = $now + RP_FLUSH;
	foreach ( $targets as $t ) {
		$id = (int) $t['id'];
		if ( 'ir' === $scope && $offline && 'intl' === $t['scope'] ) {
			continue;
		}
		if ( 'intl' === $scope && $log['sentinel'] > 0 && 'ir' === $t['scope'] ) {
			$soon = min( $soon, $log['sentinel'] + RP_SENTINEL );
			continue;
		}
		$every = 60 === (int) $t['interval'] ? 60 : 300;
		$last  = isset( $log['last'][ $id ]['at'] ) ? (int) $log['last'][ $id ]['at'] : 0;
		$soon  = min( $soon, $last + $every - 5 );
	}
	return max( $now + RP_MIN_TICK, $soon );
}

function rp_ping_url( $site, $nonce ) {
	return $site . ( false === strpos( $site, '?' ) ? '?' : '&' ) . RP_QUERY . '=' . rawurlencode( $nonce );
}

function rp_judge( array $target, $nonce, $code, $body, $ms ) {
	$fail = array(
		'ok'   => false,
		'ms'   => (int) $ms,
		'code' => (int) $code,
	);
	if ( rp_filter_page( $body ) ) {
		$fail['code'] = -2;
		return $fail;
	}
	if ( 200 !== (int) $code ) {
		return $fail;
	}
	$data = json_decode( substr( (string) $body, 0, 4096 ), true );
	if ( ! is_array( $data ) || 1 !== ( isset( $data['v'] ) ? (int) $data['v'] : 0 ) || ! isset( $data['n'], $data['t'], $data['s'] ) || $data['n'] !== $nonce ) {
		$fail['code'] = -1;
		return $fail;
	}
	$message = 'rafoo-ping|' . $nonce . '|' . (int) $data['t'] . '|' . rp_host( $target['url'] );
	if ( abs( time() - (int) $data['t'] ) > 600 || ! rp_verify( $message, $data['s'], $target['key'] ) ) {
		$fail['code'] = -1;
		return $fail;
	}
	return array(
		'ok'   => true,
		'ms'   => (int) $ms,
		'code' => 200,
	);
}

function rp_filter_page( $body ) {
	$body = strtolower( substr( (string) $body, 0, 4096 ) );
	return false !== strpos( $body, '10.10.34.3' ) || false !== strpos( $body, 'peyvandha.ir' ) || false !== strpos( $body, 'internet.ir/403' );
}

function rp_filtered_ips( array $ips ) {
	foreach ( $ips as $ip ) {
		if ( 0 === strpos( (string) $ip, '10.10.34.' ) ) {
			return true;
		}
	}
	return false;
}

function rp_filtering( array $targets, array $out ) {
	foreach ( $targets as $t ) {
		$id = (int) $t['id'];
		if ( ! isset( $out[ $id ] ) || $out[ $id ]['ok'] || -2 === $out[ $id ]['code'] ) {
			continue;
		}
		$host = parse_url( (string) $t['url'], PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) && rp_filtered_ips( (array) @gethostbynamel( $host ) ) ) {
			$out[ $id ]['code'] = -2;
		}
	}
	return $out;
}

function rp_check( array $targets ) {
	$out = array();
	foreach ( array_chunk( $targets, RP_BATCH ) as $chunk ) {
		$out += rp_check_batch( $chunk );
	}
	return rp_filtering( $targets, $out );
}

function rp_check_batch( array $targets ) {
	$out    = array();
	$nonces = array();
	if ( ! $targets ) {
		return $out;
	}
	if ( ! function_exists( 'curl_multi_init' ) ) {
		foreach ( $targets as $t ) {
			$id            = (int) $t['id'];
			$nonces[ $id ] = rp_nonce();
			$start         = microtime( true );
			$reply         = rp_http( 'GET', rp_ping_url( $t['url'], $nonces[ $id ] ), null, array( 'Accept' => 'application/json' ), RP_TIMEOUT );
			$ms            = (int) round( ( microtime( true ) - $start ) * 1000 );
			$out[ $id ]    = isset( $reply['error'] ) ? array(
				'ok'   => false,
				'ms'   => $ms,
				'code' => 0,
			) : rp_judge( $t, $nonces[ $id ], $reply['code'], $reply['body'], $ms );
		}
		return $out;
	}
	$multi   = curl_multi_init();
	$handles = array();
	foreach ( $targets as $t ) {
		$id            = (int) $t['id'];
		$nonces[ $id ] = rp_nonce();
		$ch            = curl_init( rp_ping_url( $t['url'], $nonces[ $id ] ) );
		curl_setopt_array( $ch, rp_curl_options( RP_TIMEOUT ) + array( CURLOPT_HTTPHEADER => array( 'Accept: application/json' ) ) );
		curl_multi_add_handle( $multi, $ch );
		$handles[ $id ] = $ch;
	}
	$running = 0;
	do {
		$status = curl_multi_exec( $multi, $running );
		if ( $running > 0 ) {
			curl_multi_select( $multi, 0.2 );
		}
	} while ( $running > 0 && CURLM_OK === $status );
	foreach ( $targets as $t ) {
		$id   = (int) $t['id'];
		$ch   = $handles[ $id ];
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$ms   = (int) round( (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000 );
		$body = curl_errno( $ch ) ? false : curl_multi_getcontent( $ch );
		$out[ $id ] = false === $body || 0 === $code ? array(
			'ok'   => false,
			'ms'   => $ms,
			'code' => 0,
		) : rp_judge( $t, $nonces[ $id ], $code, (string) $body, $ms );
		curl_multi_remove_handle( $multi, $ch );
		curl_close( $ch );
	}
	curl_multi_close( $multi );
	return $out;
}

function rp_flush( array $log, $now, $urgent = false ) {
	$items = rp_items( $log );
	if ( ! $items ) {
		return $log;
	}
	$reply = rp_api( 'receipts', array( 'items' => $items ), 10, $urgent );
	if ( isset( $reply['error'] ) ) {
		$extra = array();
		if ( isset( $reply['kind'] ) && 'not_member' === $reply['kind'] ) {
			$extra['member'] = 0;
		}
		rp_fail( $reply['error'], $extra );
		$log['flush_at'] = $now;
		return $log;
	}
	foreach ( $items as $it ) {
		unset( $log['b'][ $it['t'] . ':' . $it['h'] . ':' . $it['e'] ] );
	}
	$log['flush_at'] = (int) $now;
	$log['dirty']    = false;
	rp_update( array( 'sent_at' => $now ) );
	return $log;
}

function rp_tick( $force = false ) {
	$lock = @fopen( rp_dir() . '/tick.lock', 'c' );
	if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
		return 'busy';
	}
	@set_time_limit( 60 );
	$now   = time();
	$state = rp_keys();
	$url   = '' !== rp_config()['url'] ? rp_site( rp_config()['url'] ) : ( isset( $state['url'] ) ? (string) $state['url'] : '' );
	if ( empty( $state['member'] ) ) {
		$tried = isset( $state['join_try'] ) ? (int) $state['join_try'] : 0;
		if ( '' !== $url && ( $force || ( $now - $tried >= RP_REJOIN && ! rp_blocked( $state, $now ) ) ) ) {
			rp_join( $url );
		}
		flock( $lock, LOCK_UN );
		return empty( rp_state()['member'] ) ? 'not a member' : 'joined';
	}
	if ( ! $force && ! empty( $state['next'] ) && $now < (int) $state['next'] ) {
		flock( $lock, LOCK_UN );
		return 'waiting';
	}
	$few     = ! isset( $state['tasks'] ) || ! is_array( $state['tasks'] ) || count( $state['tasks'] ) < 4;
	$refresh = $few ? 120 : ( isset( $state['refresh'] ) ? (int) $state['refresh'] : 21600 );
	$tried   = isset( $state['tasks_try'] ) ? (int) $state['tasks_try'] : 0;
	$stale   = empty( $state['issued_at'] ) || $now - (int) $state['issued_at'] >= $refresh;
	if ( ( $stale && $now - $tried >= ( $few ? 120 : 300 ) ) || ( $force && $now - $tried >= 60 ) ) {
		rp_refresh();
		$state = rp_state();
	}
	if ( empty( $state['member'] ) ) {
		flock( $lock, LOCK_UN );
		return 'not a member';
	}
	$state   = rp_signal( $state, $now );
	$targets = isset( $state['tasks'] ) && is_array( $state['tasks'] ) ? $state['tasks'] : array();
	if ( ! empty( $state['valid'] ) && $now > (int) $state['valid'] + 172800 ) {
		$targets = array();
	}
	$scope   = isset( $state['scope'] ) ? $state['scope'] : 'ir';
	$offline = rp_offline( $state, $now );
	$log     = rp_shape( rp_read( 'log' ) );
	$ids     = rp_due( $targets, $log, $now, $offline, $scope );
	$batch   = array();
	foreach ( $targets as $t ) {
		if ( in_array( (int) $t['id'], $ids, true ) ) {
			$batch[ (int) $t['id'] ] = $t;
		}
	}
	$results = rp_check( array_values( $batch ) );
	$epoch   = isset( $state['epoch'] ) ? (int) $state['epoch'] : 0;
	foreach ( $results as $id => $r ) {
		$log = rp_record( $log, $batch[ $id ], $epoch, $now, $r['ok'], $r['ms'], $r['code'] );
	}
	$log = rp_sentinel( $log, $targets, $results, $now, $scope );
	$log = rp_prune( $log, $targets, $now );
	if ( $log['b'] && ( $log['dirty'] || $now - $log['flush_at'] >= RP_FLUSH ) ) {
		$log = rp_flush( $log, $now );
	}
	rp_write( 'log', $log );
	rp_upgrade( $now );
	$ok = 0;
	foreach ( $results as $r ) {
		$ok += $r['ok'] ? 1 : 0;
	}
	rp_update(
		array(
			'next'    => rp_next( $targets, $log, $now, $offline, $scope ) + rp_jitter( 20 ),
			'ticked'  => $now,
			'checked' => $results ? $now : ( isset( $state['checked'] ) ? (int) $state['checked'] : 0 ),
			'round'   => $results ? array( count( $results ), $ok ) : ( isset( $state['round'] ) ? $state['round'] : array( 0, 0 ) ),
		)
	);
	flock( $lock, LOCK_UN );
	return 'checked ' . count( $results ) . ', ok ' . $ok;
}

function rp_site( $url ) {
	$parts = parse_url( trim( (string) $url ) );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
	$path = (string) preg_replace( '#/index\.php$#', '', $path );
	return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path . '/';
}

function rp_request_url() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	if ( ! preg_match( '/^[a-z0-9.\-]+(:\d{1,5})?$/', $host ) ) {
		return '';
	}
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] );
	$dir   = str_replace( '\\', '/', dirname( isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php' ) );
	$dir   = '/' === $dir || '.' === $dir ? '' : rtrim( $dir, '/' );
	return rp_site( ( $https ? 'https' : 'http' ) . '://' . $host . $dir . '/' );
}

function rp_detach() {
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		return fastcgi_finish_request();
	}
	if ( function_exists( 'litespeed_finish_request' ) ) {
		return litespeed_finish_request();
	}
	return false;
}

function rp_no_cache() {
	header( 'Cache-Control: no-store, private, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-Robots-Tag: noindex, nofollow' );
}

function rp_ping() {
	$nonce = isset( $_GET[ RP_QUERY ] ) ? (string) $_GET[ RP_QUERY ] : '';
	$state = rp_state();
	if ( ! preg_match( '/^[A-Za-z0-9_-]{16,48}$/', $nonce ) || empty( $state['pk'] ) ) {
		http_response_code( 404 );
		exit;
	}
	$site = ! empty( $state['url'] ) ? $state['url'] : ( ! empty( $state['pending'] ) ? $state['pending'] : rp_request_url() );
	$time = time();
	$sig  = rp_sign( 'rafoo-ping|' . $nonce . '|' . $time . '|' . rp_host( $site ) );
	if ( '' === $sig ) {
		http_response_code( 500 );
		exit;
	}
	rp_no_cache();
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'v' => 1,
			'n' => $nonce,
			't' => $time,
			's' => $sig,
		)
	);
	$wake     = isset( $_GET[ RP_WAKE ] ) ? (string) $_GET[ RP_WAKE ] : '';
	$wake     = preg_match( '/^[A-Za-z0-9_-]{40,8000}$/', $wake ) ? $wake : '';
	$detached = rp_detach();
	if ( ! empty( $state['member'] ) && ( $detached || '' !== $wake ) ) {
		ignore_user_abort( true );
		if ( ! $detached ) {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			flush();
		}
		if ( '' !== $wake ) {
			rp_woken( $wake );
		} else {
			rp_tick();
		}
	}
	exit;
}

function rp_woken( $raw ) {
	$now   = time();
	$state = rp_state();
	$json  = base64_decode( strtr( $raw, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $raw ) % 4 ) % 4 ), true );
	$data  = false === $json ? null : rp_open( $json );
	if ( ! is_array( $data ) || 'rafoo-peer-wake' !== ( isset( $data['kind'] ) ? $data['kind'] : '' ) || (int) $data['watcher'] !== (int) ( isset( $state['member'] ) ? $state['member'] : 0 ) ) {
		return 'invalid';
	}
	if ( (int) $data['valid_until'] < $now || (int) $data['issued_at'] > $now + 60 || empty( $data['target'] ) || ! is_array( $data['target'] ) ) {
		return 'expired';
	}
	$target = rp_target( $data['target'] );
	if ( ! $target ) {
		return 'invalid';
	}
	$seen = rp_read( 'wakes' );
	foreach ( $seen as $key => $at ) {
		if ( $now - (int) $at > 300 ) {
			unset( $seen[ $key ] );
		}
	}
	$hash = hash( 'sha256', $raw );
	if ( isset( $seen[ $hash ] ) || ( isset( $seen[ 't' . $target['id'] ] ) && $now - (int) $seen[ 't' . $target['id'] ] < 20 ) ) {
		return 'repeat';
	}
	$seen[ $hash ]                 = $now;
	$seen[ 't' . $target['id'] ] = $now;
	rp_write( 'wakes', array_slice( $seen, -100, null, true ) );
	@set_time_limit( 40 );
	$lock = @fopen( rp_dir() . '/tick.lock', 'c' );
	$held = false;
	for ( $i = 0; $lock && $i < 40 && ! $held; $i++ ) {
		$held = flock( $lock, LOCK_EX | LOCK_NB );
		if ( ! $held ) {
			usleep( 500000 );
		}
	}
	if ( ! $held ) {
		return 'busy';
	}
	if ( ! empty( $data['refresh'] ) && $now - ( isset( $state['tasks_try'] ) ? (int) $state['tasks_try'] : 0 ) >= 60 ) {
		rp_refresh();
	}
	$results = rp_check( array( $target ) );
	$result  = 'checked';
	if ( isset( $results[ $target['id'] ] ) ) {
		$r   = $results[ $target['id'] ];
		$log = rp_shape( rp_read( 'log' ) );
		$log = rp_record( $log, $target, isset( $data['epoch'] ) ? (int) $data['epoch'] : 0, $now, $r['ok'], $r['ms'], $r['code'] );
		$log = rp_flush( $log, $now, true );
		rp_write( 'log', $log );
		rp_update( array( 'checked' => $now, 'woken' => $now ) );
		$result = $r['ok'] ? 'ok' : 'down';
	}
	flock( $lock, LOCK_UN );
	return $result;
}

function rp_upgrade( $now, $force = false ) {
	$config = rp_config();
	$state  = rp_state();
	if ( ! $config['auto_update'] || '' === (string) $config['update'] ) {
		return 'off';
	}
	if ( ! $force && $now - ( isset( $state['update_try'] ) ? (int) $state['update_try'] : 0 ) < RP_UPDATE_TTL + rp_jitter( 3600 ) ) {
		return 'later';
	}
	rp_update( array( 'update_try' => $now ) );
	$reply = rp_http( 'GET', $config['update'], null, array( 'Accept' => 'application/json' ), 10 );
	if ( isset( $reply['error'] ) || 200 !== $reply['code'] ) {
		return 'unreachable';
	}
	$info = rp_open( $reply['body'] );
	if ( ! is_array( $info ) || 'rafoo-point-release' !== ( isset( $info['kind'] ) ? $info['kind'] : '' ) || ! isset( $info['version'], $info['sha256'], $info['url'] ) ) {
		return 'invalid';
	}
	$version = (string) $info['version'];
	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ! version_compare( $version, RP_VERSION, '>' ) ) {
		rp_update( array( 'latest' => $version ) );
		return 'current';
	}
	$base = parse_url( (string) $config['update'] );
	$file = parse_url( (string) $info['url'] );
	if ( empty( $file['host'] ) || empty( $base['host'] ) || strtolower( $file['host'] ) !== strtolower( $base['host'] ) || strtolower( (string) $file['scheme'] ) !== strtolower( (string) $base['scheme'] ) ) {
		return 'invalid';
	}
	$download = rp_http( 'GET', (string) $info['url'], null, array(), 30 );
	if ( isset( $download['error'] ) || 200 !== $download['code'] ) {
		rp_fail( 'The new point version could not be downloaded.' );
		return 'unreachable';
	}
	$body = (string) $download['body'];
	if ( strlen( $body ) > RP_MAX_FILE || ! hash_equals( strtolower( (string) $info['sha256'] ), hash( 'sha256', $body ) ) ) {
		rp_fail( 'The new point version did not match its signature.' );
		return 'mismatch';
	}
	if ( 0 !== strpos( $body, '<?php' ) || false === strpos( $body, "const RP_VERSION    = '" . $version . "';" ) ) {
		rp_fail( 'The new point version is not a point script.' );
		return 'mismatch';
	}
	$self = __FILE__;
	$next = $self . '.' . bin2hex( random_bytes( 4 ) ) . '.new';
	if ( ! is_writable( __DIR__ ) || false === @file_put_contents( $next, $body ) ) {
		rp_fail( 'The point folder is not writable, so the update to ' . $version . ' was skipped.' );
		return 'readonly';
	}
	@copy( $self, rp_dir() . '/previous.php' );
	if ( ! @rename( $next, $self ) ) {
		@unlink( $next );
		rp_fail( 'The point script could not be replaced.' );
		return 'readonly';
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $self, true );
	}
	rp_update(
		array(
			'updated_at'   => $now,
			'updated_from' => RP_VERSION,
			'latest'       => $version,
			'error'        => '',
		)
	);
	return 'updated ' . $version;
}

function rp_ago( $at ) {
	if ( $at <= 0 ) {
		return '—';
	}
	$d = time() - (int) $at;
	if ( $d < 60 ) {
		return $d . ' ثانیه پیش';
	}
	if ( $d < 3600 ) {
		return (int) floor( $d / 60 ) . ' دقیقه پیش';
	}
	return (int) floor( $d / 3600 ) . ' ساعت پیش';
}

function rp_visit() {
	$state = rp_keys();
	$url   = '' !== rp_config()['url'] ? rp_site( rp_config()['url'] ) : rp_request_url();
	if ( empty( $state['member'] ) && '' !== $url && rp_ready() && ! rp_blocked( $state, time() ) ) {
		$tried = isset( $state['join_try'] ) ? (int) $state['join_try'] : 0;
		if ( time() - $tried >= 60 ) {
			rp_join( $url );
			$state = rp_state();
		}
	}
	return array( $state, $url );
}

function rp_home() {
	list( $state ) = rp_visit();
	$active = ! empty( $state['member'] ) && ! empty( $state['ticked'] ) && time() - (int) $state['ticked'] < 900;
	$file   = __DIR__ . '/home.html';
	$html   = is_file( $file ) ? (string) file_get_contents( $file ) : '<!doctype html><title>Rafoo Point</title>';
	$html   = strtr(
		$html,
		array(
			'{{state}}'       => $active ? 'فعال و در حال پایش' : 'در حال آماده شدن',
			'{{state_class}}' => $active ? 'ok' : 'wait',
			'{{version}}'     => htmlspecialchars( RP_VERSION ),
		)
	);
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-cache, max-age=0' );
	header( 'X-Robots-Tag: noindex' );
	echo $html;
	if ( rp_detach() && ! empty( $state['member'] ) ) {
		ignore_user_abort( true );
		rp_tick();
	}
	exit;
}

function rp_page() {
	list( $state, $url ) = rp_visit();
	$checks = array(
		'PHP 7.2+'        => version_compare( PHP_VERSION, '7.2', '>=' ),
		'sodium'          => rp_ready(),
		'cURL'            => function_exists( 'curl_multi_init' ),
		'auto update'     => rp_config()['auto_update'] && is_writable( __DIR__ ),
		'data'            => is_writable( rp_dir() ),
		'background'      => function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ),
	);
	$member = ! empty( $state['member'] );
	$round  = isset( $state['round'] ) && is_array( $state['round'] ) ? $state['round'] : array( 0, 0 );
	$log    = rp_shape( rp_read( 'log' ) );
	$cron   = 'curl -s -o /dev/null ' . ( '' !== $url ? $url : 'https://YOUR-HOST/' ) . '?rafoo-tick=1';
	$rows   = array(
		'نشانی نقطه'            => isset( $state['url'] ) ? $state['url'] : $url,
		'شناسه عضو'             => $member ? '#' . (int) $state['member'] : 'هنوز عضو نیست',
		'کشور و شبکه'           => $member ? trim( ( isset( $state['country'] ) ? $state['country'] : '' ) . ' · ' . ( isset( $state['network'] ) ? $state['network'] : '' ), ' ·' ) : '—',
		'دامنه بررسی'           => $member ? ( 'intl' === ( isset( $state['scope'] ) ? $state['scope'] : 'ir' ) ? 'خارج از ایران' : 'داخل ایران' ) : '—',
		'سایت‌هایی که می‌سنجد'  => isset( $state['tasks'] ) && is_array( $state['tasks'] ) ? count( $state['tasks'] ) : 0,
		'آخرین دور بررسی'       => rp_ago( isset( $state['checked'] ) ? (int) $state['checked'] : 0 ) . ( $round[0] ? ' · ' . (int) $round[1] . ' از ' . (int) $round[0] . ' در دسترس' : '' ),
		'آخرین ارسال نتیجه'     => rp_ago( isset( $state['sent_at'] ) ? (int) $state['sent_at'] : 0 ),
		'نتیجه‌های در صف'       => count( $log['b'] ),
		'اینترنت بین‌الملل'     => rp_offline( $state, time() ) ? 'قطع' : 'وصل',
		'امضا'                  => 'native' === rp_sodium() ? 'افزونه sodium هاست' : ( rp_ready() ? 'sodium_compat همراه نقطه' : 'در دسترس نیست' ),
		'آخرین نسخه'            => ( ! empty( $state['latest'] ) ? (string) $state['latest'] : RP_VERSION ) . ( ! empty( $state['updated_at'] ) ? ' · به‌روز شده ' . rp_ago( (int) $state['updated_at'] ) : '' ),
	);
	rp_no_cache();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Rafoo Point</title><style>'
		. '@font-face{font-family:Vazirmatn;src:url(fonts/Vazirmatn-Regular.woff2) format("woff2");font-weight:400;font-display:swap}@font-face{font-family:Vazirmatn;src:url(fonts/Vazirmatn-Bold.woff2) format("woff2");font-weight:700;font-display:swap}'
		. 'body{margin:0;background:#f8f9fa;color:#09090b;font:14px/1.8 Vazirmatn,Tahoma,sans-serif}main{max-width:640px;margin:40px auto;padding:0 16px}'
		. 'section{background:#fff;border:1px solid #e4e4e7;border-radius:12px;padding:20px;margin-bottom:16px}h1{font-size:18px;margin:0 0 4px}p{margin:0;color:#52525b}'
		. 'dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:12px 0 0}dt{color:#52525b}dd{margin:0;overflow-wrap:anywhere}'
		. '.ok{color:#2e6a4f}.bad{color:#8f3434}code{direction:ltr;unicode-bidi:isolate;display:block;padding:8px 10px;background:#f8f9fa;border:1px solid #e4e4e7;border-radius:8px;overflow-wrap:anywhere;font-size:12.5px}'
		. '</style></head><body><main><section><h1>نقطه پایش رفو</h1><p>این هاست یک نقطه شبکه هم‌یاری رفو است و هر دقیقه سایت‌های عضو را می‌سنجد. نسخه ' . htmlspecialchars( RP_VERSION ) . '</p><dl>';
	foreach ( $rows as $label => $value ) {
		echo '<dt>' . htmlspecialchars( $label ) . '</dt><dd>' . htmlspecialchars( (string) $value ) . '</dd>';
	}
	echo '</dl>';
	if ( ! empty( $state['error'] ) ) {
		echo '<p class="bad" style="margin-top:12px">آخرین خطا (' . htmlspecialchars( rp_ago( isset( $state['error_at'] ) ? (int) $state['error_at'] : 0 ) ) . '): ' . htmlspecialchars( (string) $state['error'] ) . '</p>';
	}
	echo '</section><section><h1>پیش‌نیازها</h1><dl>';
	foreach ( $checks as $label => $ok ) {
		echo '<dt>' . htmlspecialchars( $label ) . '</dt><dd class="' . ( $ok ? 'ok' : 'bad' ) . '">' . ( $ok ? 'درست' : ( 'background' === $label ? 'نیست؛ cron لازم است' : 'نیست' ) ) . '</dd>';
	}
	echo '</dl></section><section><h1>cron هر دقیقه</h1><p>برای اطمینان، در کنترل پنل هاست یک cron job با زمان‌بندی <b>* * * * *</b> و این دستور بسازید:</p><code>' . htmlspecialchars( $cron ) . '</code></section></main></body></html>';
	if ( rp_detach() ) {
		ignore_user_abort( true );
		rp_tick();
	}
	exit;
}

if ( 'cli' === PHP_SAPI ) {
	$args = isset( $argv ) ? $argv : array();
	if ( in_array( '--update', $args, true ) ) {
		echo rp_upgrade( time(), true ) . "\n";
		exit;
	}
	echo rp_tick( in_array( '--force', $args, true ) ) . "\n";
	exit;
}

if ( isset( $_GET[ RP_QUERY ] ) ) {
	rp_ping();
}

if ( isset( $_GET['rafoo-tick'] ) ) {
	rp_no_cache();
	header( 'Content-Type: text/plain; charset=utf-8' );
	if ( rp_detach() ) {
		echo "ok\n";
		ignore_user_abort( true );
		rp_tick();
	} else {
		echo rp_tick() . "\n";
	}
	exit;
}

if ( isset( $_GET['rafoo-status'] ) ) {
	rp_page();
}

rp_home();
