<?php

namespace Rafoo\Backup;

use Rafoo\Settings;

defined( 'ABSPATH' ) || exit;

final class Schedule {

	const HOOK = 'rafoo_backup';

	public static function plan( $retry = 0 ) {
		wp_clear_scheduled_hook( self::HOOK );
		if ( ! Settings::get( 'backup' ) ) {
			return 0;
		}
		$at = $retry > 0 ? time() + $retry : self::next( time() );
		wp_schedule_single_event( $at, self::HOOK );
		return $at;
	}

	public static function next( $now, $hours = null, $hour = null, $last = null ) {
		$hours = null === $hours ? (int) Settings::get( 'backup_hours' ) : (int) $hours;
		$hour  = null === $hour ? (int) Settings::get( 'backup_hour' ) : (int) $hour;
		if ( null === $last ) {
			$latest = Catalog::latest();
			$last   = $latest ? (int) $latest['created'] : 0;
		}
		if ( $hours < 24 ) {
			return max( $now + 60, $last + $hours * HOUR_IN_SECONDS );
		}
		$zone = wp_timezone();
		$date = new \DateTime( '@' . max( $now, $last + ( $hours - 2 ) * HOUR_IN_SECONDS ) );
		$date->setTimezone( $zone );
		$slot = clone $date;
		$slot->setTime( $hour, 0 );
		if ( $slot->getTimestamp() <= $date->getTimestamp() ) {
			$slot->modify( '+1 day' );
		}
		return $slot->getTimestamp();
	}

	public static function run() {
		if ( Settings::get( 'backup' ) && ! Job::running() ) {
			$job = Maker::start();
			if ( is_wp_error( $job ) ) {
				self::plan( HOUR_IN_SECONDS );
			}
		}
	}

	public static function changed( $after, $before ) {
		foreach ( array( 'backup', 'backup_hours', 'backup_hour' ) as $key ) {
			if ( $after[ $key ] !== $before[ $key ] ) {
				self::plan();
				return;
			}
		}
	}
}
