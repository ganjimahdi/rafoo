<?php

namespace Rafoo\Mail;

use Rafoo\Http\Hosts;
use Rafoo\Vault;

defined( 'ABSPATH' ) || exit;

final class Relay {

	const OPTION = 'rafoo_mail_relay';

	const SECURE = array( '', 'tls', 'ssl' );

	public static function get() {
		$stored = get_option( self::OPTION, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	public static function configured() {
		return '' !== self::get()['host'];
	}

	public static function save( array $in ) {
		$current = self::get();
		$next    = self::normalize( array_merge( $current, array_intersect_key( $in, array_flip( array( 'host', 'port', 'secure', 'user', 'from' ) ) ) ) );
		if ( '' === $next['host'] ) {
			delete_option( self::OPTION );
			return self::get();
		}
		$next['pass'] = $current['pass'];
		if ( isset( $in['pass'] ) && is_string( $in['pass'] ) && '' !== $in['pass'] ) {
			$next['pass'] = Vault::seal( $in['pass'] );
		}
		if ( '' === $next['user'] ) {
			$next['pass'] = '';
		}
		update_option( self::OPTION, $next, false );
		return $next;
	}

	public static function view() {
		$relay = self::get();
		return array(
			'host'     => $relay['host'],
			'port'     => $relay['port'],
			'secure'   => $relay['secure'],
			'user'     => $relay['user'],
			'from'     => $relay['from'],
			'has_pass' => '' !== $relay['pass'],
		);
	}

	public static function wanted( $phpmailer ) {
		if ( ! self::configured() || ! is_object( $phpmailer ) ) {
			return false;
		}
		if ( isset( $phpmailer->Mailer ) && 'smtp' === $phpmailer->Mailer && ! empty( $phpmailer->Host ) ) {
			$host = strtolower( trim( strtok( (string) $phpmailer->Host, ';:' ) ) );
			return ! Hosts::local( $host ) && ! Hosts::domestic( $host );
		}
		return true;
	}

	public static function apply( $phpmailer ) {
		$relay = self::get();
		$phpmailer->isSMTP();
		$phpmailer->Host        = $relay['host'];
		$phpmailer->Port        = $relay['port'];
		$phpmailer->SMTPSecure  = $relay['secure'];
		$phpmailer->SMTPAutoTLS = '' === $relay['secure'];
		$phpmailer->SMTPAuth    = '' !== $relay['user'];
		$phpmailer->Username    = $relay['user'];
		$phpmailer->Password    = Vault::open( $relay['pass'] );
		$phpmailer->Timeout     = 15;
		if ( '' !== $relay['from'] ) {
			$phpmailer->Sender = $relay['from'];
			if ( ! self::same_domain( $phpmailer->From, $relay['from'] ) ) {
				$phpmailer->From = $relay['from'];
			}
		}
	}

	public static function normalize( array $in ) {
		$host   = isset( $in['host'] ) ? strtolower( trim( (string) $in['host'] ) ) : '';
		$secure = isset( $in['secure'] ) && in_array( $in['secure'], self::SECURE, true ) ? $in['secure'] : 'tls';
		$port   = isset( $in['port'] ) ? (int) $in['port'] : 0;
		$from   = isset( $in['from'] ) ? sanitize_email( (string) $in['from'] ) : '';
		if ( ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$host = '';
		}
		if ( $port < 1 || $port > 65535 ) {
			$port = 'ssl' === $secure ? 465 : 587;
		}
		return array(
			'host'   => $host,
			'port'   => $port,
			'secure' => $secure,
			'user'   => isset( $in['user'] ) ? substr( sanitize_text_field( (string) $in['user'] ), 0, 190 ) : '',
			'pass'   => isset( $in['pass'] ) && is_string( $in['pass'] ) && 0 === strpos( $in['pass'], Vault::PREFIX ) ? $in['pass'] : '',
			'from'   => is_email( $from ) ? $from : '',
		);
	}

	private static function same_domain( $a, $b ) {
		$domain = static function ( $address ) {
			$at = strrpos( (string) $address, '@' );
			return false === $at ? '' : strtolower( substr( $address, $at + 1 ) );
		};
		return '' !== $domain( $a ) && $domain( $a ) === $domain( $b );
	}
}
