<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Warnings {

	const SAMPLES = 5;

	public static function add( array $warnings, $code, $sample = '' ) {
		if ( ! isset( $warnings[ $code ] ) ) {
			$warnings[ $code ] = array(
				'count'   => 0,
				'samples' => array(),
			);
		}
		++$warnings[ $code ]['count'];
		if ( '' !== (string) $sample && count( $warnings[ $code ]['samples'] ) < self::SAMPLES ) {
			$warnings[ $code ]['samples'][] = base64_encode( (string) $sample );
		}
		return $warnings;
	}

	public static function view( array $warnings ) {
		$out = array();
		foreach ( $warnings as $code => $warning ) {
			$out[] = array(
				'code'    => (string) $code,
				'count'   => (int) $warning['count'],
				'samples' => array_map(
					static function ( $sample ) {
						return Journal::clean( (string) base64_decode( (string) $sample ) );
					},
					(array) $warning['samples']
				),
			);
		}
		return $out;
	}

	public static function summary( array $warnings ) {
		$out = array();
		foreach ( $warnings as $code => $warning ) {
			$out[] = $code . ' ' . (int) $warning['count'];
		}
		return implode( ', ', $out );
	}
}
