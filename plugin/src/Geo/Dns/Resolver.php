<?php

namespace Rafoo\Geo\Dns;

defined( 'ABSPATH' ) || exit;

final class Resolver {

	const A = 1;

	const NS = 2;

	public static function ipv4( $host ) {
		$host = (string) preg_replace( '/:\d+$/', '', strtolower( (string) $host ) );
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $host;
		}
		if ( '' === $host ) {
			return '';
		}
		$ip = gethostbyname( $host );
		return $ip !== $host && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $ip : '';
	}

	public static function packet( $name, $type, $id ) {
		$out = pack( 'nnnnnn', $id, 0x0000, 1, 0, 0, 0 );
		foreach ( explode( '.', rtrim( $name, '.' ) ) as $label ) {
			$out .= chr( strlen( $label ) ) . $label;
		}
		return $out . "\0" . pack( 'nn', $type, 1 );
	}

	private static function skip_name( $data, $at ) {
		$length = strlen( $data );
		while ( $at < $length ) {
			$size = ord( $data[ $at ] );
			if ( 0 === $size ) {
				return $at + 1;
			}
			if ( 0xC0 === ( $size & 0xC0 ) ) {
				return $at + 2;
			}
			$at += $size + 1;
		}
		return $length;
	}

	private static function read_name( $data, $at, $depth = 0 ) {
		$labels = array();
		$length = strlen( $data );
		while ( $at < $length && $depth < 10 ) {
			$size = ord( $data[ $at ] );
			if ( 0 === $size ) {
				break;
			}
			if ( 0xC0 === ( $size & 0xC0 ) ) {
				$pointer  = ( ( $size & 0x3F ) << 8 ) | ord( $data[ $at + 1 ] );
				$labels[] = self::read_name( $data, $pointer, $depth + 1 );
				break;
			}
			$labels[] = substr( $data, $at + 1, $size );
			$at      += $size + 1;
		}
		return implode( '.', array_filter( $labels, 'strlen' ) );
	}

	public static function parse( $data, $type ) {
		if ( strlen( $data ) < 12 ) {
			return null;
		}
		$head = unpack( 'nid/nflags/nqd/nan/nns/nar', substr( $data, 0, 12 ) );
		if ( ( $head['flags'] & 0x000F ) !== 0 ) {
			return array();
		}
		$at = 12;
		for ( $i = 0; $i < $head['qd']; $i++ ) {
			$at = self::skip_name( $data, $at ) + 4;
		}
		$out   = array();
		$total = $head['an'] + $head['ns'];
		for ( $i = 0; $i < $total && $at < strlen( $data ); $i++ ) {
			$at    = self::skip_name( $data, $at );
			$meta  = unpack( 'ntype/nclass/Nttl/nlength', substr( $data, $at, 10 ) );
			$at   += 10;
			$rdata = substr( $data, $at, $meta['length'] );
			if ( (int) $meta['type'] === $type ) {
				if ( self::A === $type && 4 === strlen( $rdata ) ) {
					$out[] = inet_ntop( $rdata );
				} elseif ( self::NS === $type ) {
					$out[] = self::read_name( $data, $at );
				}
			}
			$at += $meta['length'];
		}
		return $out;
	}

	public static function query( $server, $name, $type = self::A, $timeout = 3 ) {
		$server = self::ipv4( $server );
		if ( '' === $server ) {
			return null;
		}
		$id     = wp_rand( 1, 65535 );
		$socket = @stream_socket_client( 'udp://' . $server . ':53', $errno, $errstr, $timeout );
		if ( ! $socket ) {
			return null;
		}
		stream_set_timeout( $socket, $timeout );
		fwrite( $socket, self::packet( $name, $type, $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UDP socket, not a file.
		$reply = fread( $socket, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- UDP socket, not a file.
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- UDP socket, not a file.
		if ( ! is_string( $reply ) || strlen( $reply ) < 12 || unpack( 'n', substr( $reply, 0, 2 ) )[1] !== $id ) {
			return null;
		}
		return self::parse( $reply, $type );
	}

	public static function nameservers( $zone ) {
		$records = @dns_get_record( $zone, DNS_NS );
		$out     = array();
		foreach ( (array) $records as $record ) {
			if ( isset( $record['target'] ) ) {
				$out[] = strtolower( rtrim( (string) $record['target'], '.' ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function verify( array $plan ) {
		$zone    = '';
		$servers = array();
		foreach ( \Rafoo\Geo\Planner::zone_candidates( $plan['host'] ) as $candidate ) {
			$servers = self::nameservers( $candidate );
			if ( $servers ) {
				$zone = $candidate;
				break;
			}
		}
		$ips     = array();
		foreach ( $plan['nodes'] as $node ) {
			$ips[] = $node['ip'];
		}
		$answers = array();
		foreach ( $servers as $server ) {
			$answer             = self::query( $server, $plan['host'] );
			$answers[ $server ] = array(
				'ips'   => null === $answer ? null : $answer,
				'known' => null !== $answer && $answer && ! array_diff( $answer, $ips ),
			);
		}
		$local = gethostbynamel( $plan['host'] );
		$subs  = array();
		foreach ( $plan['subs'] as $host => $ip ) {
			$subs[ $host ] = array(
				'want' => $ip,
				'got'  => self::ipv4( $host ),
			);
		}
		return array(
			'zone'        => $zone,
			'nameservers' => $answers,
			'local'       => false === $local ? array() : $local,
			'subs'        => $subs,
			'at'          => time(),
		);
	}
}
