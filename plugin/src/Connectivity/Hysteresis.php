<?php

namespace Rafoo\Connectivity;

defined( 'ABSPATH' ) || exit;

final class Hysteresis {

	const ROUNDS = 3;

	const HINT_TTL = 600;

	const UP_QUORUM = 2;

	public static function next( array $state, $external_up, $domestic_ok, array $hint, $now ) {
		$state       = array_merge( self::initial(), $state );
		$external_up = max( 0, (int) $external_up );

		$state['checked_at'] = $now;

		if ( 0 === $external_up && ! $domestic_ok ) {
			$state['streak_down'] = 0;
			$state['streak_up']   = 0;
			$state['local_down']  = true;
			return $state;
		}

		$state['local_down'] = false;
		$hint_state          = self::hint_state( $hint, $now );

		if ( 0 === $external_up ) {
			++$state['streak_down'];
			$state['streak_up'] = 0;
		} elseif ( $external_up >= self::UP_QUORUM ) {
			++$state['streak_up'];
			$state['streak_down'] = 0;
		} else {
			$state['streak_down'] = 0;
			$state['streak_up']   = 0;
		}

		$down_needed = 'down' === $hint_state ? 1 : self::ROUNDS;
		$up_needed   = 'up' === $hint_state || 'unknown' === $state['detected'] ? 1 : self::ROUNDS;

		if ( 'offline' !== $state['detected'] && $state['streak_down'] >= $down_needed ) {
			$state['detected']   = 'offline';
			$state['changed_at'] = $now;
		} elseif ( 'online' !== $state['detected'] && $state['streak_up'] >= $up_needed ) {
			if ( 'offline' === $state['detected'] && $state['changed_at'] > 0 ) {
				$state['outage_total'] += max( 0, $now - $state['changed_at'] );
			}
			$state['detected']   = 'online';
			$state['changed_at'] = $now;
		}

		return $state;
	}

	public static function hint_state( array $hint, $now ) {
		if ( ! isset( $hint['state'], $hint['issued_at'] ) ) {
			return '';
		}
		$issued = (int) $hint['issued_at'];
		if ( $now - $issued > self::HINT_TTL || $issued > $now + 60 ) {
			return '';
		}
		return in_array( $hint['state'], array( 'up', 'down' ), true ) ? $hint['state'] : '';
	}

	public static function initial() {
		return array(
			'detected'     => 'unknown',
			'override'     => 'auto',
			'streak_down'  => 0,
			'streak_up'    => 0,
			'local_down'   => false,
			'checked_at'   => 0,
			'changed_at'   => 0,
			'outage_total' => 0,
		);
	}

	public static function offline( array $state ) {
		$state = array_merge( self::initial(), $state );
		if ( 'offline' === $state['override'] ) {
			return true;
		}
		if ( 'online' === $state['override'] ) {
			return false;
		}
		return 'offline' === $state['detected'];
	}
}
