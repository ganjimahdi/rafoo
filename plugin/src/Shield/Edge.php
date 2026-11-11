<?php

namespace Rafoo\Shield;

use Rafoo\Geo\Store as Geo;
use Rafoo\Http\Client;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Edge {

	const ARVAN = 'https://napi.arvancloud.ir/cdn/4.0';

	const CLOUDFLARE = 'https://api.cloudflare.com/client/v4';

	const MAX = 200;

	const EVERY = 300;

	const NOTE = 'rafoo-shield';

	public static function data() {
		$data = get_option( Local::EDGE, array() );
		return array_merge(
			array(
				'provider' => '',
				'domain'   => '',
				'zone'     => '',
				'secret'   => '',
				'rule'     => '',
				'pushed'   => array(),
				'error'    => '',
				'at'       => 0,
			),
			is_array( $data ) ? $data : array()
		);
	}

	public static function save( array $changes ) {
		$data = array_merge( self::data(), $changes );
		update_option( Local::EDGE, $data, false );
		return $data;
	}

	public static function view() {
		$data    = self::data();
		$secrets = Geo::secrets();
		return array(
			'provider' => $data['provider'],
			'domain'   => $data['domain'],
			'count'    => count( (array) $data['pushed'] ),
			'error'    => $data['error'],
			'at'       => (int) $data['at'],
			'geo'      => array(
				'arvan'      => ! empty( $secrets['arvan']['key'] ),
				'cloudflare' => ! empty( $secrets['cloudflare']['token'] ),
			),
			'host'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		);
	}

	public static function connect( $provider, $domain, $secret, $reuse ) {
		$provider = in_array( $provider, array( 'arvan', 'cloudflare' ), true ) ? $provider : '';
		$domain   = strtolower( trim( (string) $domain ) );
		if ( '' === $provider || ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain ) ) {
			return new \WP_Error( 'rafoo_shield_edge', __( 'Choose a provider and enter the domain as it appears in its panel.', 'rafoo' ) );
		}
		if ( $reuse ) {
			$secrets = Geo::secrets();
			$secret  = 'arvan' === $provider ? ( isset( $secrets['arvan']['key'] ) ? $secrets['arvan']['key'] : '' ) : ( isset( $secrets['cloudflare']['token'] ) ? $secrets['cloudflare']['token'] : '' );
		}
		$secret = trim( (string) $secret );
		if ( '' === $secret || strlen( $secret ) > 400 ) {
			return new \WP_Error( 'rafoo_shield_edge', __( 'Enter the API key from the provider’s panel.', 'rafoo' ) );
		}
		self::disconnect();
		$data = array(
			'provider' => $provider,
			'domain'   => $domain,
			'secret'   => Vault::seal( $secret ),
			'zone'     => '',
			'rule'     => '',
			'pushed'   => array(),
			'error'    => '',
		);
		if ( 'cloudflare' === $provider ) {
			$reply = self::call( 'cloudflare', $secret, 'GET', '/zones?name=' . rawurlencode( $domain ) );
			if ( ! self::ok( $reply ) || empty( $reply['json']['result'][0]['id'] ) ) {
				return self::fail( $reply, __( 'Cloudflare did not find this domain with this token.', 'rafoo' ) );
			}
			$data['zone'] = (string) $reply['json']['result'][0]['id'];
		} else {
			$reply = self::call( 'arvan', $secret, 'GET', '/domains/' . rawurlencode( $domain ) . '/firewall/rules' );
			if ( ! self::ok( $reply ) ) {
				return self::fail( $reply, __( 'ArvanCloud did not accept this key for this domain.', 'rafoo' ) );
			}
		}
		self::save( $data );
		\Rafoo\State::event( 'shield_edge_on', array( 'name' => $provider ) );
		self::push( true );
		return true;
	}

	public static function disconnect() {
		$data = self::data();
		if ( '' !== $data['provider'] ) {
			self::clear( $data );
			\Rafoo\State::event( 'shield_edge_off', array( 'name' => $data['provider'] ) );
		}
		delete_option( Local::EDGE );
	}

	public static function tick( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$data = self::data();
		if ( '' === $data['provider'] || $now - (int) $data['at'] < self::EVERY ) {
			return;
		}
		self::push( false, $now );
	}

	public static function wanted( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$want = array();
		foreach ( (array) Local::get( 'deny' ) as $cidr ) {
			$want[ $cidr ] = $now + DAY_IN_SECONDS;
		}
		$list = Intel::load();
		uasort(
			$list,
			static function ( $a, $b ) {
				return $b[1] - $a[1];
			}
		);
		foreach ( $list as $ip => $row ) {
			if ( Feed::BLOCK === (int) $row[0] && (int) $row[1] - $now >= HOUR_IN_SECONDS && count( $want ) < self::MAX ) {
				$want[ $ip ] = (int) $row[1];
			}
		}
		if ( 'fortress' === Config::mode() ) {
			foreach ( Stats::events( 200 ) as $event ) {
				if ( 'block' === $event['a'] && empty( $event['k'] ) && $now - (int) $event['t'] < HOUR_IN_SECONDS && Ip::valid( $event['i'] ) && ! isset( $want[ $event['i'] ] ) && count( $want ) < self::MAX ) {
					$want[ $event['i'] ] = $now + 6 * HOUR_IN_SECONDS;
				}
			}
		}
		$admins = (array) Local::get( 'admins' );
		foreach ( array_keys( $want ) as $ip ) {
			if ( isset( $admins[ $ip ] ) || Ip::local( $ip ) || '' !== Crawler::range( $ip ) ) {
				unset( $want[ $ip ] );
			}
		}
		return array_slice( $want, 0, self::MAX, true );
	}

	public static function push( $force = false, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$data   = self::data();
		$secret = Vault::open( (string) $data['secret'] );
		if ( '' === $data['provider'] || '' === $secret ) {
			return false;
		}
		$want  = self::wanted( $now );
		$error = '';
		if ( 'arvan' === $data['provider'] ) {
			$same = array_keys( (array) $data['pushed'] ) === array_keys( $want );
			if ( $same && ! $force ) {
				self::save( array( 'at' => $now ) );
				return true;
			}
			$path = '/domains/' . rawurlencode( $data['domain'] ) . '/firewall/rules';
			if ( ! $want ) {
				if ( '' !== $data['rule'] ) {
					self::call( 'arvan', $secret, 'DELETE', $path . '/' . rawurlencode( $data['rule'] ) );
				}
				self::save( array( 'rule' => '', 'pushed' => array(), 'at' => $now, 'error' => '' ) );
				return true;
			}
			$body  = array(
				'name'        => 'Rafoo Shield',
				'filter_expr' => 'ip.src in {' . implode( ' ', array_keys( $want ) ) . '}',
				'action'      => 'deny',
				'is_enabled'  => true,
				'note'        => self::NOTE,
			);
			$reply = '' !== $data['rule'] ? self::call( 'arvan', $secret, 'PATCH', $path . '/' . rawurlencode( $data['rule'] ), $body ) : null;
			if ( null === $reply || ( ! self::ok( $reply ) && 404 === (int) $reply['code'] ) ) {
				$reply = self::call( 'arvan', $secret, 'POST', $path, $body );
			}
			if ( self::ok( $reply ) ) {
				$id           = isset( $reply['json']['data']['id'] ) ? (string) $reply['json']['data']['id'] : (string) $data['rule'];
				$data['rule'] = $id;
				$data['pushed'] = $want;
			} else {
				$error = self::fail( $reply, 'ArvanCloud' )->get_error_message();
			}
		} else {
			$pushed = (array) $data['pushed'];
			$calls  = 0;
			foreach ( $pushed as $ip => $row ) {
				if ( ! isset( $want[ $ip ] ) && $calls < 25 ) {
					++$calls;
					$reply = self::call( 'cloudflare', $secret, 'DELETE', '/zones/' . rawurlencode( $data['zone'] ) . '/firewall/access_rules/rules/' . rawurlencode( (string) $row['id'] ) );
					if ( self::ok( $reply ) || ( is_array( $reply ) && 404 === (int) $reply['code'] ) ) {
						unset( $pushed[ $ip ] );
					}
				}
			}
			foreach ( $want as $ip => $until ) {
				if ( isset( $pushed[ $ip ] ) || $calls >= 25 ) {
					continue;
				}
				++$calls;
				$reply = self::call(
					'cloudflare',
					$secret,
					'POST',
					'/zones/' . rawurlencode( $data['zone'] ) . '/firewall/access_rules/rules',
					array(
						'mode'          => 'block',
						'configuration' => array(
							'target' => false !== strpos( $ip, '/' ) ? 'ip_range' : ( false !== strpos( $ip, ':' ) ? 'ip6' : 'ip' ),
							'value'  => $ip,
						),
						'notes'         => self::NOTE,
					)
				);
				if ( self::ok( $reply ) && ! empty( $reply['json']['result']['id'] ) ) {
					$pushed[ $ip ] = array( 'id' => (string) $reply['json']['result']['id'], 'until' => (int) $until );
				} else {
					$error = self::fail( $reply, 'Cloudflare' )->get_error_message();
					break;
				}
			}
			$data['pushed'] = $pushed;
		}
		self::save(
			array(
				'rule'   => $data['rule'],
				'pushed' => $data['pushed'],
				'at'     => $now,
				'error'  => $error,
			)
		);
		return '' === $error;
	}

	private static function clear( array $data ) {
		$secret = Vault::open( (string) $data['secret'] );
		if ( '' === $secret ) {
			return;
		}
		if ( 'arvan' === $data['provider'] && '' !== $data['rule'] ) {
			self::call( 'arvan', $secret, 'DELETE', '/domains/' . rawurlencode( $data['domain'] ) . '/firewall/rules/' . rawurlencode( $data['rule'] ) );
			return;
		}
		if ( 'cloudflare' === $data['provider'] ) {
			foreach ( array_slice( (array) $data['pushed'], 0, 50, true ) as $row ) {
				self::call( 'cloudflare', $secret, 'DELETE', '/zones/' . rawurlencode( $data['zone'] ) . '/firewall/access_rules/rules/' . rawurlencode( (string) $row['id'] ) );
			}
		}
	}

	private static function call( $provider, $secret, $method, $path, $body = null ) {
		$base    = untrailingslashit( (string) apply_filters( 'rafoo_shield_edge_base', 'arvan' === $provider ? self::ARVAN : self::CLOUDFLARE, $provider ) );
		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'arvan' === $provider ? ( 0 === stripos( $secret, 'apikey ' ) ? $secret : 'Apikey ' . $secret ) : 'Bearer ' . $secret,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		$reply = wp_remote_request(
			$base . $path,
			Client::args(
				array(
					'method'      => $method,
					'headers'     => $headers,
					'body'        => null === $body ? null : wp_json_encode( $body ),
					'timeout'     => 20,
					'redirection' => 0,
					'rafoo_probe' => true,
				)
			)
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$raw  = (string) wp_remote_retrieve_body( $reply );
		$json = json_decode( $raw, true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $reply ),
			'json' => is_array( $json ) ? $json : null,
			'raw'  => $raw,
		);
	}

	private static function ok( $reply ) {
		return is_array( $reply ) && $reply['code'] >= 200 && $reply['code'] < 300;
	}

	private static function fail( $reply, $what ) {
		if ( is_wp_error( $reply ) ) {
			return new \WP_Error( 'rafoo_shield_edge', $what . ': ' . $reply->get_error_message() );
		}
		$detail = '';
		if ( is_array( $reply ) && is_array( $reply['json'] ) ) {
			if ( isset( $reply['json']['message'] ) && is_string( $reply['json']['message'] ) ) {
				$detail = $reply['json']['message'];
			} elseif ( isset( $reply['json']['errors'][0]['message'] ) ) {
				$detail = (string) $reply['json']['errors'][0]['message'];
			}
		}
		$code = is_array( $reply ) ? (int) $reply['code'] : 0;
		return new \WP_Error( 'rafoo_shield_edge', $what . ( $code ? ': HTTP ' . $code : '' ) . ( '' !== $detail ? ' ' . substr( sanitize_text_field( $detail ), 0, 200 ) : '' ) );
	}
}
