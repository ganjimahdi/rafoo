<?php

namespace Rafoo\Backup;

use Rafoo\Http\Client;
use Rafoo\State;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Transfer {

	const INVITE = 'rafoo_transfer_invite';

	const OUTGOING = 'rafoo_transfer_outgoing';

	const FREEZE = 'rafoo_freeze_until';

	const ACTION = 'rafoo_transfer';

	const PAIR = 'https://rafoo.ir/api/v1/pair';

	const PREFIX = 'RAFOO1-';

	const WINDOW = 300;

	const CHUNK = 8388608;

	const LIFETIME = 86400;

	public static function invite( $pair = true, $endpoint = '' ) {
		$endpoint = '' !== $endpoint ? $endpoint : self::endpoint_url();
		$key      = random_bytes( 32 );
		$invite   = array(
			'key'     => Vault::seal( bin2hex( $key ) ),
			'created' => time(),
			'expires' => time() + self::LIFETIME,
		);
		update_option( self::INVITE, $invite, false );
		$code  = self::PREFIX . self::encode(
			wp_json_encode(
				array(
					'u' => $endpoint,
					'k' => self::encode( $key ),
				)
			)
		);
		$short = '';
		$reply = ! $pair ? null : wp_safe_remote_post(
			self::PAIR,
			Client::args(
				array(
					'timeout' => 8,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'url' => $endpoint,
							'key' => self::encode( $key ),
						)
					),
				)
			)
		);
		$short = '';
		if ( null !== $reply && ! is_wp_error( $reply ) && 200 === (int) wp_remote_retrieve_response_code( $reply ) ) {
			$body  = json_decode( wp_remote_retrieve_body( $reply ), true );
			$short = isset( $body['code'] ) && preg_match( '/^[A-Z0-9]{4}-?[A-Z0-9]{4}$/', (string) $body['code'] ) ? (string) $body['code'] : '';
		}
		return array(
			'code'    => $code,
			'short'   => $short,
			'expires' => $invite['expires'],
		);
	}

	public static function endpoint_url() {
		return (string) apply_filters( 'rafoo_transfer_endpoint', admin_url( 'admin-ajax.php' ) );
	}

	public static function cancel_invite() {
		delete_option( self::INVITE );
	}

	public static function invite_view() {
		$invite = get_option( self::INVITE );
		return is_array( $invite ) && $invite['expires'] > time() ? array( 'expires' => (int) $invite['expires'] ) : null;
	}

	public static function resolve( $code ) {
		$code = trim( (string) $code );
		if ( 0 === strpos( $code, self::PREFIX ) ) {
			$data = json_decode( (string) self::decode( substr( $code, strlen( self::PREFIX ) ) ), true );
			$url  = isset( $data['u'] ) ? esc_url_raw( $data['u'], array( 'http', 'https' ) ) : '';
			$key  = isset( $data['k'] ) ? self::decode( $data['k'] ) : '';
		} else {
			$short = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
			if ( 8 !== strlen( $short ) ) {
				return new \WP_Error( 'rafoo_code', __( 'This code is not valid. Copy it again from the other site.', 'rafoo' ) );
			}
			$reply = wp_safe_remote_get( self::PAIR . '/' . rawurlencode( $short ), Client::args( array( 'timeout' => 8 ) ) );
			if ( is_wp_error( $reply ) || 200 !== (int) wp_remote_retrieve_response_code( $reply ) ) {
				return new \WP_Error( 'rafoo_pair', __( 'rafoo.ir could not be reached or the code has expired. Use the long code shown under the short one.', 'rafoo' ) );
			}
			$data = json_decode( wp_remote_retrieve_body( $reply ), true );
			$url  = isset( $data['url'] ) ? esc_url_raw( $data['url'], array( 'http', 'https' ) ) : '';
			$key  = isset( $data['key'] ) ? self::decode( $data['key'] ) : '';
		}
		if ( '' === $url || 32 !== strlen( (string) $key ) ) {
			return new \WP_Error( 'rafoo_code', __( 'This code is not valid. Copy it again from the other site.', 'rafoo' ) );
		}
		if ( false === strpos( $url, 'admin-ajax.php' ) ) {
			$url = untrailingslashit( $url ) . '/wp-admin/admin-ajax.php';
		}
		if ( in_array( strtok( $url, '?' ), array( admin_url( 'admin-ajax.php' ), self::endpoint_url() ), true ) ) {
			return new \WP_Error( 'rafoo_code', __( 'This code belongs to this same site. Create the code on the site that should receive the copy.', 'rafoo' ) );
		}
		return array(
			'url' => strtok( $url, '?' ),
			'key' => $key,
		);
	}

	public static function call( $url, $key, $op, array $payload = array(), $raw = false, $timeout = 30 ) {
		$body  = wp_json_encode(
			array(
				'op'      => $op,
				'payload' => $payload,
				'time'    => time(),
				'nonce'   => bin2hex( random_bytes( 12 ) ),
			)
		);
		$reply = wp_remote_post(
			add_query_arg( 'action', self::ACTION, $url ),
			Client::args(
				array(
					'timeout'     => $timeout,
					'headers'     => array(
						'Content-Type'  => 'application/json',
						'X-Rafoo-Sign'  => hash_hmac( 'sha256', $body, $key ),
					),
					'body'        => $body,
					'rafoo_probe' => true,
				)
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$code = (int) wp_remote_retrieve_response_code( $reply );
		if ( 200 !== $code ) {
			return new \WP_Error( 'rafoo_transfer_http', 'HTTP ' . $code );
		}
		if ( $raw ) {
			$data = (string) wp_remote_retrieve_body( $reply );
			$mac  = (string) wp_remote_retrieve_header( $reply, 'x-rafoo-sign' );
			return hash_equals( hash_hmac( 'sha256', $data, $key ), $mac ) ? $data : new \WP_Error( 'rafoo_transfer_sign', 'bad signature' );
		}
		$data = json_decode( wp_remote_retrieve_body( $reply ), true );
		return is_array( $data ) ? $data : new \WP_Error( 'rafoo_transfer_reply', 'bad reply' );
	}

	public static function endpoint() {
		$body = (string) file_get_contents( 'php://input', false, null, 0, 65536 );
		$sign = isset( $_SERVER['HTTP_X_RAFOO_SIGN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAFOO_SIGN'] ) ) : '';
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['op'] ) || empty( $data['nonce'] ) || abs( time() - (int) $data['time'] ) > self::WINDOW ) {
			self::deny();
		}
		$role = '';
		$key  = '';
		foreach ( array( 'invite' => self::invite_key(), 'outgoing' => self::outgoing_key() ) as $name => $candidate ) {
			if ( '' !== $candidate && hash_equals( hash_hmac( 'sha256', $body, $candidate ), $sign ) ) {
				$role = $name;
				$key  = $candidate;
				break;
			}
		}
		$nonce = 'rafoo_tn_' . substr( preg_replace( '/[^a-f0-9]/', '', (string) $data['nonce'] ), 0, 24 );
		if ( '' === $role || get_transient( $nonce ) ) {
			self::deny();
		}
		set_transient( $nonce, 1, 2 * self::WINDOW );
		$payload = isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();
		if ( 'outgoing' === $role && 'file' === $data['op'] ) {
			self::serve( $payload, $key );
		}
		if ( 'invite' !== $role ) {
			self::deny();
		}
		switch ( $data['op'] ) {
			case 'hello':
				wp_send_json(
					array(
						'ok'   => true,
						'home' => home_url(),
						'wp'   => get_bloginfo( 'version' ),
						'php'  => PHP_VERSION,
						'free' => Ledger::free_space(),
						'busy'   => (bool) Job::running(),
						'zip'    => class_exists( 'ZipArchive' ),
						'format' => Maker::FORMAT,
					)
				);
				break;
			case 'start':
				wp_send_json( self::accept( $payload, $key ) );
				break;
			case 'status':
				wp_send_json( array( 'job' => Job::view() ) );
				break;
		}
		self::deny();
	}

	private static function accept( array $payload, $key ) {
		if ( isset( $payload['parts'] ) ) {
			return self::accept_parts( $payload, $key );
		}
		$name = isset( $payload['name'] ) ? sanitize_file_name( (string) $payload['name'] ) : '';
		$size = isset( $payload['size'] ) ? (int) $payload['size'] : 0;
		$hash = isset( $payload['sha256'] ) ? strtolower( (string) $payload['sha256'] ) : '';
		$from = isset( $payload['source'] ) ? esc_url_raw( (string) $payload['source'], array( 'http', 'https' ) ) : '';
		if ( '' === $name || $size <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || '' === $from ) {
			return array( 'ok' => false, 'error' => 'bad request' );
		}
		$free = Ledger::free_space();
		if ( $free >= 0 && $free < $size * 3 ) {
			return array( 'ok' => false, 'error' => 'disk_full' );
		}
		$data           = Restorer::data(
			'receive',
			array(
				array(
					'id'     => 'transfer',
					'file'   => 'incoming-' . $name,
					'size'   => $size,
					'sha256' => $hash,
					'mode'   => Crypt::KEY,
					'places' => array(),
					'kind'   => 'full',
				),
			),
			'',
			bin2hex( $key )
		);
		$data['source'] = untrailingslashit( $from );
		$data['remote'] = $name;
		$data['pass']   = isset( $payload['pass'] ) ? (int) $payload['pass'] : 1;
		wp_delete_file( Ledger::path( 'incoming-' . $name ) );
		$job = Job::start( 'receive', $data, 'pull' );
		return is_wp_error( $job ) ? array( 'ok' => false, 'error' => $job->get_error_code() ) : array( 'ok' => true );
	}

	private static function accept_parts( array $payload, $key ) {
		$from  = isset( $payload['source'] ) ? esc_url_raw( (string) $payload['source'], array( 'http', 'https' ) ) : '';
		$parts = array();
		$total = 0;
		$large = 0;
		foreach ( (array) $payload['parts'] as $part ) {
			$name = isset( $part['name'] ) ? sanitize_file_name( (string) $part['name'] ) : '';
			$size = isset( $part['size'] ) ? (int) $part['size'] : 0;
			$hash = isset( $part['sha256'] ) ? strtolower( (string) $part['sha256'] ) : '';
			if ( '' === $name || ! preg_match( Catalog::PATTERN, $name ) || $size <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				return array( 'ok' => false, 'error' => 'bad request' );
			}
			$parts[] = array(
				'name'   => $name,
				'size'   => $size,
				'sha256' => $hash,
			);
			$total  += $size;
			$large   = max( $large, $size );
		}
		if ( ! $parts || '' === $from || count( $parts ) > 5000 ) {
			return array( 'ok' => false, 'error' => 'bad request' );
		}
		$free = Ledger::free_space();
		if ( $free >= 0 && $free < 2 * $large + Packer::RESERVE ) {
			return array( 'ok' => false, 'error' => 'disk_full' );
		}
		$data           = Restorer::data(
			'receive',
			array(
				array(
					'id'     => 'transfer',
					'format' => Maker::FORMAT,
					'file'   => '',
					'parts'  => $parts,
					'size'   => $total,
					'sha256' => '',
					'mode'   => Crypt::KEY,
					'places' => array(),
					'kind'   => 'full',
				),
			),
			'',
			bin2hex( $key )
		);
		$data['source'] = untrailingslashit( $from );
		$data['remote'] = '';
		$data['pass']   = isset( $payload['pass'] ) ? (int) $payload['pass'] : 1;
		if ( ! empty( $payload['geo'] ) && \Rafoo\Geo\Store::is_copy() ) {
			$data['geo']     = true;
			$data['geo_gen'] = isset( $payload['gen'] ) ? (int) $payload['gen'] : 0;
		}
		foreach ( $parts as $part ) {
			wp_delete_file( Ledger::path( 'incoming-' . $part['name'] ) );
		}
		$job = Job::start( 'receive', $data, 'part' );
		return is_wp_error( $job ) ? array( 'ok' => false, 'error' => $job->get_error_code() ) : array( 'ok' => true );
	}

	public static function pull_part( array $job, $deadline, array $part, $local ) {
		$d   = &$job['data'];
		$key = hex2bin( $d['key'] );
		while ( microtime( true ) < $deadline ) {
			clearstatcache( true, $local );
			$have = is_file( $local ) ? (int) filesize( $local ) : 0;
			if ( $have >= $part['size'] ) {
				return $job;
			}
			$chunk = self::call(
				$d['source'],
				$key,
				'file',
				array(
					'name'   => $part['name'],
					'offset' => $have,
					'length' => self::CHUNK,
				),
				true,
				120
			);
			if ( is_wp_error( $chunk ) || '' === $chunk ) {
				$d['retries_pull'] = ( isset( $d['retries_pull'] ) ? $d['retries_pull'] : 0 ) + 1;
				if ( $d['retries_pull'] > 30 ) {
					return Job::fail( $job, 'unreachable', array( 'part' => $part['name'] ) );
				}
				$job['yield'] = true;
				return $job;
			}
			$d['retries_pull'] = 0;
			$out               = Ledger::open( $local, 'ab' );
			if ( ! $out || strlen( $chunk ) !== $out->fwrite( $chunk ) ) {
				return Job::fail( $job, 'disk_full', array( 'free' => Ledger::free_space() ) );
			}
			$out             = null;
			$job['progress'] = array(
				'done'  => $d['done'] + $have + strlen( $chunk ),
				'total' => max( 1, $d['total'] ),
			);
			$job['mark']     = 'pull' . $d['part'] . '-' . ( $have + strlen( $chunk ) );
		}
		return $job;
	}

	private static function serve( array $payload, $key ) {
		$outgoing = get_option( self::OUTGOING );
		$name     = isset( $payload['name'] ) ? sanitize_file_name( (string) $payload['name'] ) : '';
		if ( ! is_array( $outgoing ) || empty( $outgoing['files'] ) || ! in_array( $name, (array) $outgoing['files'], true ) ) {
			self::deny();
		}
		$file   = Ledger::path( $name );
		$offset = max( 0, (int) ( isset( $payload['offset'] ) ? $payload['offset'] : 0 ) );
		$length = min( self::CHUNK, max( 1, (int) ( isset( $payload['length'] ) ? $payload['length'] : self::CHUNK ) ) );
		if ( ! is_file( $file ) ) {
			self::deny();
		}
		$data = (string) file_get_contents( $file, false, null, $offset, $length );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Rafoo-Sign: ' . hash_hmac( 'sha256', $data, $key ) );
		header( 'Content-Length: ' . strlen( $data ) );
		file_put_contents( 'php://output', $data );
		exit;
	}

	public static function pull( array $job, $deadline ) {
		$d     = &$job['data'];
		$item  = $d['chain'][0];
		$local = Ledger::path( $item['file'] );
		$key   = hex2bin( $d['key'] );
		while ( microtime( true ) < $deadline ) {
			clearstatcache( true, $local );
			$have = is_file( $local ) ? (int) filesize( $local ) : 0;
			if ( $have >= $item['size'] ) {
				$job['step'] = 'verify';
				return $job;
			}
			$chunk = self::call(
				$d['source'],
				$key,
				'file',
				array(
					'name'   => $d['remote'],
					'offset' => $have,
					'length' => self::CHUNK,
				),
				true,
				120
			);
			if ( is_wp_error( $chunk ) || '' === $chunk ) {
				$d['retries'] = ( isset( $d['retries'] ) ? $d['retries'] : 0 ) + 1;
				if ( $d['retries'] > 30 ) {
					return Job::fail( $job, 'unreachable' );
				}
				$job['yield'] = true;
				return $job;
			}
			$d['retries'] = 0;
			file_put_contents( $local, $chunk, FILE_APPEND );
			$job['progress'] = array(
				'done'  => $have + strlen( $chunk ),
				'total' => $item['size'],
			);
		}
		return $job;
	}

	public static function received( array $job ) {
		$item = $job['data']['chain'][0];
		if ( '' !== (string) $item['file'] ) {
			wp_delete_file( Ledger::path( $item['file'] ) );
		}
		foreach ( isset( $item['parts'] ) ? (array) $item['parts'] : array() as $part ) {
			wp_delete_file( Ledger::path( 'incoming-' . $part['name'] ) );
		}
		\Rafoo\Geo\Nodes::received( $job );
		State::event( 'done' === $job['status'] ? 'transfer_received' : 'transfer_failed', array( 'name' => $job['data']['source'] ) );
	}

	public static function invite_key() {
		$invite = get_option( self::INVITE );
		if ( ! is_array( $invite ) || $invite['expires'] < time() ) {
			return '';
		}
		$hex = Vault::open( $invite['key'] );
		return 64 === strlen( $hex ) ? hex2bin( $hex ) : '';
	}

	public static function outgoing_key() {
		$outgoing = get_option( self::OUTGOING );
		if ( ! is_array( $outgoing ) || empty( $outgoing['key'] ) ) {
			return '';
		}
		$hex = Vault::open( $outgoing['key'] );
		return 64 === strlen( $hex ) ? hex2bin( $hex ) : '';
	}

	public static function frozen() {
		return (int) get_option( self::FREEZE, 0 ) > time();
	}

	public static function freeze( $on ) {
		if ( $on ) {
			update_option( self::FREEZE, time() + 30 * MINUTE_IN_SECONDS, false );
		} else {
			delete_option( self::FREEZE );
		}
	}

	public static function block_checkout() {
		if ( self::frozen() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::frozen_message(), 'error' );
		}
	}

	public static function block_store( $response, $handler, $request ) {
		if ( null === $response && self::frozen() && is_object( $request ) && 'POST' === $request->get_method() && preg_match( '#^/wc/store(?:/v\d+)?/checkout#', (string) $request->get_route() ) ) {
			return new \WP_Error( 'rafoo_moving', self::frozen_message(), array( 'status' => 503 ) );
		}
		return $response;
	}

	public static function frozen_message() {
		return __( 'The shop is moving to a new server. Please try again in a few minutes.', 'rafoo' );
	}

	private static function deny() {
		wp_die( '', '', array( 'response' => 403 ) );
	}

	public static function encode( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	public static function decode( $text ) {
		$text = strtr( (string) $text, '-_', '+/' );
		$data = base64_decode( $text . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true );
		return false === $data ? '' : $data;
	}
}
