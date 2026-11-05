<?php

namespace Rafoo\Geo\Dns;

defined( 'ABSPATH' ) || exit;

final class Route53 extends Driver {

	const NAME = 'route53';

	const BASE = 'https://route53.amazonaws.com/2013-04-01'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- DNS API endpoint chosen by the site owner, no content is loaded from it.

	const XMLNS = 'https://route53.amazonaws.com/doc/2013-04-01/'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- XML namespace name, never requested.

	public static function sign( $method, $url, $body, $access, $secret, $time = null ) {
		$time    = null === $time ? time() : (int) $time;
		$parts   = wp_parse_url( $url );
		$host    = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path    = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query   = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			ksort( $query );
		}
		$canonical_query = str_replace( '+', '%20', http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		$amz_date        = gmdate( 'Ymd\THis\Z', $time );
		$day             = gmdate( 'Ymd', $time );
		$payload         = hash( 'sha256', (string) $body );
		$headers         = "host:" . $host . "\nx-amz-content-sha256:" . $payload . "\nx-amz-date:" . $amz_date . "\n";
		$signed          = 'host;x-amz-content-sha256;x-amz-date';
		$request         = strtoupper( $method ) . "\n" . $path . "\n" . $canonical_query . "\n" . $headers . "\n" . $signed . "\n" . $payload;
		$scope           = $day . '/us-east-1/route53/aws4_request';
		$string          = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $request );
		$key             = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', 'route53', hash_hmac( 'sha256', 'us-east-1', hash_hmac( 'sha256', $day, 'AWS4' . $secret, true ), true ), true ), true );
		return array(
			'X-Amz-Date'           => $amz_date,
			'X-Amz-Content-Sha256' => $payload,
			'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . hash_hmac( 'sha256', $string, $key ),
		);
	}

	private function call( $method, $path, $body = '' ) {
		$url     = $this->base() . $path;
		$headers = self::sign( $method, $url, $body, (string) ( isset( $this->secrets['access'] ) ? $this->secrets['access'] : '' ), (string) ( isset( $this->secrets['secret'] ) ? $this->secrets['secret'] : '' ) );
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'text/xml';
		}
		$reply = $this->request( $method, $url, array_merge( array( 'Accept' => 'text/xml' ), $headers ), '' !== $body ? $body : null );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$reply['xml'] = '' !== $reply['raw'] ? @simplexml_load_string( $reply['raw'] ) : false;
		if ( ! $this->ok( $reply ) ) {
			$message = $reply['xml'] && isset( $reply['xml']->Error->Message ) ? (string) $reply['xml']->Error->Message : '';
			return new \WP_Error( 'rafoo_geo_dns', 'Route 53: HTTP ' . $reply['code'] . ( '' !== $message ? ' ' . $message : '' ) );
		}
		return $reply;
	}

	public function check( $host ) {
		foreach ( $this->candidates( $host ) as $zone ) {
			$reply = $this->call( 'GET', '/hostedzonesbyname?dnsname=' . rawurlencode( $zone ) . '&maxitems=1' );
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$found = $reply['xml'] && isset( $reply['xml']->HostedZones->HostedZone ) ? $reply['xml']->HostedZones->HostedZone : null;
			if ( $found && rtrim( strtolower( (string) $found->Name ), '.' ) === $zone ) {
				$id      = preg_replace( '#^/hostedzone/#', '', (string) $found->Id );
				$details = $this->call( 'GET', '/hostedzone/' . rawurlencode( $id ) );
				$servers = array();
				if ( ! is_wp_error( $details ) && $details['xml'] && isset( $details['xml']->DelegationSet->NameServers->NameServer ) ) {
					foreach ( $details['xml']->DelegationSet->NameServers->NameServer as $server ) {
						$servers[] = (string) $server;
					}
				}
				return array(
					'zone'        => $zone,
					'id'          => $id,
					'nameservers' => $servers,
				);
			}
		}
		return new \WP_Error( 'rafoo_geo_zone', 'Route 53: hosted zone not found' );
	}

	private function existing( $id, $name ) {
		$reply = $this->call( 'GET', '/hostedzone/' . rawurlencode( $id ) . '/rrset?name=' . rawurlencode( $name . '.' ) . '&type=A&maxitems=300' );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$out = array();
		if ( $reply['xml'] && isset( $reply['xml']->ResourceRecordSets->ResourceRecordSet ) ) {
			foreach ( $reply['xml']->ResourceRecordSets->ResourceRecordSet as $set ) {
				if ( rtrim( strtolower( (string) $set->Name ), '.' ) !== strtolower( $name ) || 'A' !== (string) $set->Type ) {
					continue;
				}
				$out[] = $set;
			}
		}
		return $out;
	}

	private static function set_xml( $action, $name, $ttl, array $ips, $identifier = '', array $geo = array() ) {
		$xml = '<Change><Action>' . $action . '</Action><ResourceRecordSet><Name>' . esc_html( $name ) . '.</Name><Type>A</Type>';
		if ( '' !== $identifier ) {
			$xml .= '<SetIdentifier>' . esc_html( $identifier ) . '</SetIdentifier><GeoLocation>';
			foreach ( $geo as $key => $value ) {
				$xml .= '<' . $key . '>' . esc_html( $value ) . '</' . $key . '>';
			}
			$xml .= '</GeoLocation>';
		}
		$xml .= '<TTL>' . (int) $ttl . '</TTL><ResourceRecords>';
		foreach ( $ips as $ip ) {
			$xml .= '<ResourceRecord><Value>' . esc_html( $ip ) . '</Value></ResourceRecord>';
		}
		return $xml . '</ResourceRecords></ResourceRecordSet></Change>';
	}

	private static function existing_xml( $set ) {
		$xml = '<Change><Action>DELETE</Action><ResourceRecordSet>';
		foreach ( array( 'Name', 'Type', 'SetIdentifier' ) as $field ) {
			if ( isset( $set->$field ) ) {
				$xml .= '<' . $field . '>' . esc_html( (string) $set->$field ) . '</' . $field . '>';
			}
		}
		if ( isset( $set->GeoLocation ) ) {
			$xml .= '<GeoLocation>';
			foreach ( array( 'ContinentCode', 'CountryCode', 'SubdivisionCode' ) as $field ) {
				if ( isset( $set->GeoLocation->$field ) ) {
					$xml .= '<' . $field . '>' . esc_html( (string) $set->GeoLocation->$field ) . '</' . $field . '>';
				}
			}
			$xml .= '</GeoLocation>';
		}
		if ( isset( $set->TTL ) ) {
			$xml .= '<TTL>' . (int) $set->TTL . '</TTL>';
		}
		$xml .= '<ResourceRecords>';
		foreach ( $set->ResourceRecords->ResourceRecord as $record ) {
			$xml .= '<ResourceRecord><Value>' . esc_html( (string) $record->Value ) . '</Value></ResourceRecord>';
		}
		return $xml . '</ResourceRecords></ResourceRecordSet></Change>';
	}

	public function apply( array $plan ) {
		$zone = $this->check( $plan['host'] );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$wanted = array();
		foreach ( $plan['countries'] as $country => $id ) {
			$wanted[ 'rafoo-c-' . $country ] = array( array( 'CountryCode' => $country ), $plan['nodes'][ $id ]['ip'] );
		}
		foreach ( $plan['continents'] as $continent => $id ) {
			if ( 'AN' !== $continent ) {
				$wanted[ 'rafoo-k-' . $continent ] = array( array( 'ContinentCode' => $continent ), $plan['nodes'][ $id ]['ip'] );
			}
		}
		if ( $plan['default'] ) {
			$wanted['rafoo-default'] = array( array( 'CountryCode' => '*' ), $plan['nodes'][ $plan['default'] ]['ip'] );
		}
		$existing = $this->existing( $zone['id'], $plan['host'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		$changes = '';
		foreach ( $existing as $set ) {
			$identifier = isset( $set->SetIdentifier ) ? (string) $set->SetIdentifier : '';
			if ( '' === $identifier || ( 0 === strpos( $identifier, 'rafoo-' ) && ! isset( $wanted[ $identifier ] ) ) ) {
				$changes .= self::existing_xml( $set );
			}
		}
		foreach ( $wanted as $identifier => $route ) {
			$changes .= self::set_xml( 'UPSERT', $plan['host'], $plan['ttl'], array( $route[1] ), $identifier, $route[0] );
		}
		foreach ( $this->subs( $plan, $zone['zone'] ) as $host => $ip ) {
			$changes .= self::set_xml( 'UPSERT', $host, $plan['ttl'], array( $ip ) );
		}
		$body  = '<?xml version="1.0" encoding="UTF-8"?><ChangeResourceRecordSetsRequest xmlns="' . self::XMLNS . '"><ChangeBatch><Comment>Rafoo</Comment><Changes>' . $changes . '</Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';
		$reply = $this->call( 'POST', '/hostedzone/' . rawurlencode( $zone['id'] ) . '/rrset', $body );
		return is_wp_error( $reply ) ? $reply : true;
	}
}
